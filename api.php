<?php
/**
 * Public JSON endpoints for the external ReliableSite widget and reseller
 * portal.
 *
 * These are the Blesta equivalents of the public actions the WHMCS module
 * serves from rspanel_clientarea() before its access check:
 *
 *   WHMCS                                   Blesta
 *   index.php?m=rspanel&action=currencies    api.php?action=currencies
 *   index.php?m=rspanel&action=pricing       api.php?action=pricing
 *   index.php?m=rspanel&action=widget&id=..  api.php?action=widget&id=..
 *   index.php?m=rspanel&action=chat          api.php?action=chat
 *
 * The URLs differ because Blesta has no query-string front controller - it
 * dispatches on the request path only, and modules (unlike plugins) get no
 * routes at all. This file is requested directly instead, which works because
 * the webroot .htaccess only rewrites paths that do not resolve to a real file.
 * The response bodies are byte-compatible with the WHMCS ones so existing
 * consumers need no changes; see the per-endpoint notes for the two fields
 * Blesta cannot express as integers.
 *
 * The three catalog endpoints are public and unauthenticated, matching WHMCS:
 * the widget is served from other origins, so every response carries permissive
 * CORS headers and only exposes catalog data that is already public on the order
 * form.
 *
 * ?action=chat is the exception, and is built differently on purpose. It spends
 * the reseller's API key and their AI quota on behalf of whoever calls it, so it
 * is off until an admin enables it, answers only to the websites they list, and
 * is rate limited per visitor. See chat().
 *
 * @package reliablesite
 */

// Nothing routes to this file, so bootstrap the framework by hand. init.php
// sets error_reporting(0), registers the autoloader and returns the container.
require_once dirname(__FILE__) . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . '..'
    . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'lib' . DIRECTORY_SEPARATOR . 'init.php';

/**
 * Serves the module's public endpoints.
 */
class ReliablesiteWidgetApi
{
    /**
     * Blesta (term, period) => the WHMCS billing-cycle column the external
     * consumers already read.
     */
    private static $cycleMap = [
        '1|month' => 'monthly',
        '3|month' => 'quarterly',
        '6|month' => 'semiannually',
        '1|year' => 'annually',
        '2|year' => 'biennially',
    ];

    /** @var string Table holding the chat endpoint's rate-limit counters */
    const RATE_TABLE = 'mod_reliablesite_chat_rate';

    /** @var stdClass Host object for Blesta models/components */
    private $host;

    /** @var int The resolved company id */
    private $companyId = 0;

    /** @var int The resolved module id, for module-log entries */
    private $moduleId = 0;

    /** @var ReliablesiteApiLogger|null Memoized module-log writer */
    private $logger = null;

    public function __construct()
    {
        $this->host = new stdClass();
        Loader::loadComponents($this->host, ['Record']);
        Loader::loadModels($this->host, ['Companies']);
    }

    /**
     * Dispatches on ?action=, mirroring the WHMCS action names.
     */
    public function run()
    {
        $action = isset($_REQUEST['action']) ? (string) $_REQUEST['action'] : '';

        // The widget action answers with a redirect, so its headers differ.
        if ($action === 'widget') {
            $this->widget();

            return;
        }

        // The chat action is a POST with an origin-scoped CORS policy, so it
        // owns its headers too.
        if ($action === 'chat') {
            $this->chat();

            return;
        }

        $this->cors();
        header('Content-Type: application/json');

        try {
            $this->companyId = $this->resolveCompany();

            switch ($action) {
                case 'currencies':
                    $this->currencies();
                    break;
                case 'pricing':
                    $this->pricing();
                    break;
                default:
                    $this->fail('Unknown action.');
            }
        } catch (Throwable $e) {
            // Never surface an exception message: this endpoint is public and
            // the message can carry SQL and absolute paths.
            $this->fail('An error occurred while handling the request.');
        }
    }

    /**
     * All currencies configured on the company, for the widget's price
     * conversion. WHMCS shape:
     *
     *   {"success":1,"currencies":[{"id":1,"code":"USD","prefix":"$","suffix":"","rate":1.0}]}
     *
     * The one unavoidable difference: Blesta keys currencies by their ISO code,
     * not a numeric id - there is no integer to return. "id" therefore carries
     * the code, and is exactly the value ?currency= expects back on the widget
     * endpoint, so a consumer that round-trips id -> currency keeps working.
     */
    private function currencies()
    {
        $rows = $this->host->Record->select(['code', 'prefix', 'suffix', 'exchange_rate'])
            ->from('currencies')
            ->where('company_id', '=', $this->companyId)
            ->order(['code' => 'ASC'])
            ->fetchAll();

        $currencies = [];
        foreach ($rows as $row) {
            $currencies[] = [
                'id' => $row->code,
                'code' => $row->code,
                'prefix' => (string) $row->prefix,
                'suffix' => (string) $row->suffix,
                'rate' => (float) $row->exchange_rate,
            ];
        }

        echo json_encode(['success' => 1, 'currencies' => $currencies]);
    }

    /**
     * Live retail pricing for every synced, in-stock product. WHMCS shape:
     *
     *   {"success":1,"items":[{
     *      "inventory_id":262,"whmcs_product_id":41,"name":"...","stock":3,
     *      "skip_price_sync":false,
     *      "pricing":[{"currency_id":1,"monthly":0.0,"quarterly":0.0,
     *                  "semiannually":0.0,"annually":0.0,"biennially":0.0}]
     *   }]}
     *
     * Every WHMCS key is present and carries the same meaning. Blesta's package
     * id fills whmcs_product_id (aliased as package_id for new consumers), the
     * frozen flag fills skip_price_sync, and currency_id carries the ISO code
     * for the same reason as above. pricing_ids is additive: it maps each cycle
     * to the package_pricing id an order URL needs, which WHMCS had no analogue
     * for.
     */
    private function pricing()
    {
        $rows = $this->host->Record->select([
                'mod_reliablesite_packages.rs_inventory_id' => 'inventory_id',
                'mod_reliablesite_packages.package_id' => 'package_id',
                'mod_reliablesite_packages.frozen' => 'frozen',
                'packages.qty' => 'qty',
                'package_names.name' => 'name',
                'package_pricing.id' => 'pricing_id',
                'pricings.currency' => 'currency',
                'pricings.term' => 'term',
                'pricings.period' => 'period',
                'pricings.price' => 'price',
            ])
            ->from('mod_reliablesite_packages')
            ->innerJoin(
                'packages',
                'packages.id',
                '=',
                'mod_reliablesite_packages.package_id',
                false
            )
            ->on('package_names.lang', '=', 'en_us')
            ->leftJoin('package_names', 'package_names.package_id', '=', 'packages.id', false)
            ->leftJoin('package_pricing', 'package_pricing.package_id', '=', 'packages.id', false)
            ->leftJoin('pricings', 'pricings.id', '=', 'package_pricing.pricing_id', false)
            ->where('packages.company_id', '=', $this->companyId)
            ->where('packages.qty', '>', 0)
            ->fetchAll();

        $items = [];
        foreach ($rows as $row) {
            $key = (int) $row->inventory_id;
            if (!isset($items[$key])) {
                $items[$key] = [
                    'inventory_id' => (int) $row->inventory_id,
                    'whmcs_product_id' => (int) $row->package_id,
                    'package_id' => (int) $row->package_id,
                    'name' => (string) $row->name,
                    'stock' => (int) $row->qty,
                    'skip_price_sync' => (bool) $row->frozen,
                    'frozen' => (bool) $row->frozen,
                    'pricing' => [],
                ];
            }
            if ($row->currency === null) {
                continue;
            }

            $cycle = $this->cycleFor($row->term, $row->period);
            if ($cycle === null) {
                continue;
            }

            $currency = (string) $row->currency;
            if (!isset($items[$key]['pricing'][$currency])) {
                $items[$key]['pricing'][$currency] = [
                    'currency_id' => $currency,
                    'currency' => $currency,
                    'monthly' => 0.0,
                    'quarterly' => 0.0,
                    'semiannually' => 0.0,
                    'annually' => 0.0,
                    'biennially' => 0.0,
                    'pricing_ids' => [],
                ];
            }
            $items[$key]['pricing'][$currency][$cycle] = (float) $row->price;
            $items[$key]['pricing'][$currency]['pricing_ids'][$cycle] = (int) $row->pricing_id;
        }

        // Re-index so both levels serialize as JSON arrays, as WHMCS returns.
        $out = [];
        foreach ($items as $item) {
            $item['pricing'] = array_values($item['pricing']);
            $out[] = $item;
        }

        echo json_encode(['success' => 1, 'items' => $out]);
    }

    /**
     * Maps an inventory id to this install's order form and redirects into it,
     * the way the WHMCS widget redirects to cart.php?a=add&pid=.
     *
     * Blesta's cart needs a pricing term rather than just a product, so the
     * monthly price in the requested currency is selected, falling back to any
     * price the package has. ?currency= takes an ISO code (what the currencies
     * endpoint returns as id) and is only honoured by Blesta while the visitor's
     * cart is empty.
     */
    private function widget()
    {
        try {
            $this->companyId = $this->resolveCompany();

            $inventoryId = isset($_REQUEST['id']) ? (int) $_REQUEST['id'] : 0;
            $currency = isset($_REQUEST['currency'])
                ? strtoupper(preg_replace('/[^A-Za-z]/', '', (string) $_REQUEST['currency']))
                : '';

            $target = $inventoryId > 0 ? $this->orderUrl($inventoryId, $currency) : null;
            if ($target !== null) {
                header('Location: ' . $target);

                return;
            }
        } catch (Throwable $e) {
            // Fall through to the not-found response.
        }

        // Unknown product: answer like the rest of the endpoints rather than
        // dumping the visitor somewhere arbitrary.
        $this->cors();
        header('Content-Type: application/json');
        http_response_code(404);
        $this->fail('The requested product was not found.');
    }

    /**
     * Builds the order-form URL for a tracked inventory id.
     *
     * @param int $inventoryId
     * @param string $currency ISO code, or '' for the visitor's default
     * @return string|null Absolute URL, or null when the product is not orderable
     */
    private function orderUrl($inventoryId, $currency)
    {
        $mapping = $this->host->Record->select(['package_id'])
            ->from('mod_reliablesite_packages')
            ->where('rs_inventory_id', '=', (string) $inventoryId)
            ->fetch();
        if (!$mapping) {
            return null;
        }
        $packageId = (int) $mapping->package_id;

        $group = $this->host->Record->select(['package_group.package_group_id' => 'group_id'])
            ->from('package_group')
            ->innerJoin(
                'package_groups',
                'package_groups.id',
                '=',
                'package_group.package_group_id',
                false
            )
            ->where('package_group.package_id', '=', $packageId)
            ->where('package_groups.company_id', '=', $this->companyId)
            ->fetch();
        if (!$group) {
            return null;
        }
        $groupId = (int) $group->group_id;

        $label = $this->orderFormLabel($groupId);
        if ($label === '') {
            return null;
        }

        $pricingId = $this->pricingIdFor($packageId, $currency);
        if ($pricingId <= 0) {
            return null;
        }

        $url = $this->baseUrl() . 'order/config/index/' . rawurlencode($label)
            . '/?pricing_id=' . $pricingId . '&group_id=' . $groupId;
        if ($currency !== '') {
            $url .= '&currency=' . rawurlencode($currency);
        }

        return $url;
    }

    /**
     * The order form serving a package group. An explicit ?form= wins, so a
     * campaign can point the widget at a specific form.
     *
     * @param int $groupId
     * @return string The form label, or '' when none serves the group
     */
    private function orderFormLabel($groupId)
    {
        $requested = isset($_REQUEST['form'])
            ? preg_replace('/[^A-Za-z0-9_\-]/', '', (string) $_REQUEST['form'])
            : '';
        if ($requested !== '') {
            $form = $this->host->Record->select(['label'])
                ->from('order_forms')
                ->where('company_id', '=', $this->companyId)
                ->where('label', '=', $requested)
                ->fetch();

            return $form ? (string) $form->label : '';
        }

        $form = $this->host->Record->select(['order_forms.label' => 'label'])
            ->from('order_form_groups')
            ->innerJoin(
                'order_forms',
                'order_forms.id',
                '=',
                'order_form_groups.order_form_id',
                false
            )
            ->where('order_form_groups.package_group_id', '=', $groupId)
            ->where('order_forms.company_id', '=', $this->companyId)
            ->where('order_forms.status', '=', 'active')
            ->fetch();

        return $form ? (string) $form->label : '';
    }

    /**
     * The package_pricing id the order form should open on: monthly in the
     * requested currency, else monthly in any currency, else anything priced.
     *
     * @param int $packageId
     * @param string $currency
     * @return int
     */
    private function pricingIdFor($packageId, $currency)
    {
        $rows = $this->host->Record->select([
                'package_pricing.id' => 'pricing_id',
                'pricings.currency' => 'currency',
                'pricings.term' => 'term',
                'pricings.period' => 'period',
            ])
            ->from('package_pricing')
            ->innerJoin('pricings', 'pricings.id', '=', 'package_pricing.pricing_id', false)
            ->where('package_pricing.package_id', '=', $packageId)
            ->fetchAll();

        $fallback = 0;
        $monthlyAnyCurrency = 0;
        foreach ($rows as $row) {
            $isMonthly = ((int) $row->term === 1 && $row->period === 'month');
            $matchesCurrency = ($currency === '' || strtoupper((string) $row->currency) === $currency);

            if ($isMonthly && $matchesCurrency) {
                return (int) $row->pricing_id;
            }
            if ($isMonthly && $monthlyAnyCurrency === 0) {
                $monthlyAnyCurrency = (int) $row->pricing_id;
            }
            if ($fallback === 0) {
                $fallback = (int) $row->pricing_id;
            }
        }

        return $monthlyAnyCurrency > 0 ? $monthlyAnyCurrency : $fallback;
    }

    // =================================================================
    // White-label chat (?action=chat)
    // =================================================================

    /**
     * Proxies one chat turn to the white-label Brian endpoint and answers the
     * caller's website with the reply.
     *
     * The reseller's API key stays here. A site embedding this only ever posts
     * to its own billing host, so the key is never in a page, a bundle, or a
     * browser request - which is the whole reason this endpoint exists rather
     * than the site calling Brian directly.
     *
     * Unlike the catalog feeds, this one costs money to call, so it is gated
     * three ways: an explicit enable flag, an admin-listed set of websites, and
     * per-visitor plus store-wide rate limits.
     *
     * Errors are deliberately brand-neutral. The body is written straight into a
     * chat bubble on the reseller's site, so it must never name ReliableSite or
     * describe their configuration; the real cause goes to the module log.
     */
    private function chat()
    {
        Loader::load(dirname(__FILE__) . DIRECTORY_SEPARATOR . 'lib'
            . DIRECTORY_SEPARATOR . 'reliablesite_brian.php');

        $origin = isset($_SERVER['HTTP_ORIGIN']) ? trim((string) $_SERVER['HTTP_ORIGIN']) : '';
        $method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper((string) $_SERVER['REQUEST_METHOD']) : 'GET';

        $row = null;
        try {
            $this->companyId = $this->resolveCompany();
            $row = $this->moduleRow();
        } catch (Throwable $e) {
            // Fall through: an unconfigured install answers 503 below.
        }

        $config = ReliablesiteBrian::whiteLabelConfig($row ? $row->meta : null);
        $apiKey = ($row && isset($row->meta->api_key)) ? (string) $row->meta->api_key : '';

        // A browser only accepts the reply if the origin is echoed back, so the
        // allow decision has to happen before anything is written.
        $allowed = ($origin === '')
            || ($config['enabled'] && ReliablesiteBrian::originAllowed($origin, $config['allowed_origins']));

        header('Vary: Origin');
        if ($allowed && $origin !== '') {
            header('Access-Control-Allow-Origin: ' . $origin);
            header('Access-Control-Allow-Methods: POST, OPTIONS');
            header('Access-Control-Allow-Headers: Content-Type');
            header('Access-Control-Max-Age: 86400');
        }
        header('Content-Type: application/json');
        header('Cache-Control: no-store');

        if ($method === 'OPTIONS') {
            http_response_code($allowed ? 200 : 403);

            return;
        }
        if ($method !== 'POST') {
            $this->chatFail(405, 'This endpoint accepts POST requests only.');

            return;
        }
        if (!$config['enabled']) {
            $this->chatFail(403, 'The assistant is not available right now.');

            return;
        }
        if (!$allowed) {
            $this->chatFail(403, 'This website is not authorised to use the assistant.');

            return;
        }
        if ($apiKey === '' || $config['agent_name'] === '' || $config['company_name'] === '') {
            $this->logChat('config', 'incomplete white-label configuration', false);
            $this->chatFail(503, 'The assistant is temporarily unavailable.');

            return;
        }

        $turn = $this->chatInput();
        if (isset($turn['error'])) {
            $this->chatFail(400, $turn['error']);

            return;
        }

        $ip = $this->chatClientIp($config['behind_proxy']);
        $retryAfter = $this->chatRateLimit($config, $ip);
        if ($retryAfter > 0) {
            header('Retry-After: ' . $retryAfter);
            $this->chatFail(
                429,
                'You have sent too many messages. Please wait a moment and try again.',
                ['retryAfter' => $retryAfter]
            );

            return;
        }

        // No session id means a new visitor: mint one carrying an
        // install-identifying prefix, so conversations group by reseller
        // upstream without any visitor sharing another's history.
        if ($turn['sessionId'] === '') {
            $turn['sessionId'] = ReliablesiteBrian::whiteLabelSessionId(
                isset($_SERVER['HTTP_HOST']) ? (string) $_SERVER['HTTP_HOST'] : '',
                $apiKey
            );
        }

        $baseUrl = $this->chatBaseUrl($config);
        $body = ReliablesiteBrian::buildWhiteLabelBody($config, $baseUrl, $this->ownPath(), $turn);

        $brian = new ReliablesiteBrian($apiKey, $this->chatLogger());
        $result = $brian->sendWhiteLabel($body, $ip);

        if ($result['ok']) {
            $reply = $result['body'];
            echo json_encode([
                'success' => true,
                'response' => isset($reply['response']) ? $reply['response'] : '',
                'sessionId' => !empty($reply['sessionId']) ? $reply['sessionId'] : $turn['sessionId'],
                'assignmentId' => !empty($reply['assignmentId']) ? $reply['assignmentId'] : $turn['assignmentId'],
            ]);

            return;
        }

        $this->chatUpstreamFailure($result);
    }

    /**
     * Translates an upstream failure into a status the caller can act on.
     *
     * @param array $result From ReliablesiteBrian::sendWhiteLabel()
     */
    private function chatUpstreamFailure(array $result)
    {
        if ($result['timed_out']) {
            $this->chatFail(504, 'The assistant took too long to reply. Please try again.');

            return;
        }
        if ($result['error'] !== '' || $result['status'] === 0) {
            $this->chatFail(503, 'The assistant is temporarily unavailable.');

            return;
        }
        if ($result['status'] === 429) {
            $retry = 30;
            if (isset($result['body']['retryAfter'])) {
                $retry = max(1, (int) $result['body']['retryAfter']);
            }
            header('Retry-After: ' . $retry);
            $this->chatFail(
                429,
                'You have sent too many messages. Please wait a moment and try again.',
                ['retryAfter' => $retry]
            );

            return;
        }
        // 400 here is our own malformed request, and 401/403/404 mean the key is
        // wrong or the channel is not enabled. All are configuration faults on
        // this side, so the visitor sees the same neutral "unavailable" and the
        // detail is left in the module log.
        if ($result['status'] >= 400 && $result['status'] < 500) {
            $this->chatFail(503, 'The assistant is temporarily unavailable.');

            return;
        }

        $this->chatFail(502, 'The assistant could not answer that. Please try again.');
    }

    /**
     * Reads and validates one chat turn from the request body.
     *
     * JSON is the documented format; a form-encoded body is accepted too because
     * it avoids a CORS preflight, which matters for sites embedding the widget
     * on a slow connection.
     *
     * @return array The turn, or ['error' => string]
     */
    private function chatInput()
    {
        $raw = file_get_contents('php://input');
        $body = json_decode((string) $raw, true);
        if (!is_array($body)) {
            $body = $_POST;
        }

        $read = function ($key) use ($body) {
            return isset($body[$key]) && is_scalar($body[$key]) ? trim((string) $body[$key]) : '';
        };

        $message = $read('message');
        if ($message === '') {
            return ['error' => 'Please enter a message.'];
        }
        $length = function_exists('mb_strlen') ? mb_strlen($message, 'UTF-8') : strlen($message);
        if ($length > ReliablesiteBrian::MAX_MESSAGE_LENGTH) {
            return ['error' => 'Message must be ' . ReliablesiteBrian::MAX_MESSAGE_LENGTH
                . ' characters or fewer.'];
        }

        $sessionId = $read('sessionId');
        if ($sessionId !== '' && !ReliablesiteBrian::validId($sessionId)) {
            return ['error' => 'Invalid session id.'];
        }
        $assignmentId = $read('assignmentId');
        if ($assignmentId !== '' && !ReliablesiteBrian::validId($assignmentId)) {
            return ['error' => 'Invalid visitor id.'];
        }
        $email = $read('email');
        if ($email !== '' && (strlen($email) > 190 || !filter_var($email, FILTER_VALIDATE_EMAIL))) {
            return ['error' => 'Please enter a valid email address.'];
        }

        return [
            'message' => $message,
            'sessionId' => $sessionId,
            'assignmentId' => $assignmentId,
            'email' => $email,
        ];
    }

    /**
     * The public base URL Brian should read this install's catalog from.
     *
     * @param array $config From whiteLabelConfig()
     * @return string Absolute URL, no trailing slash
     */
    private function chatBaseUrl(array $config)
    {
        if ($config['base_url'] !== '') {
            return $config['base_url'];
        }

        // Brian fetches the catalog over the public internet and rejects plain
        // http, so the scheme is forced rather than taken from this request -
        // which may well have arrived over http from behind a TLS proxy.
        return rtrim(preg_replace('#^http://#i', 'https://', $this->baseUrl()), '/');
    }

    /**
     * Whose message this is, for rate-limiting purposes.
     *
     * Proxy headers are trusted only when the admin has said this install sits
     * behind a proxy. Trusting them by default would let any caller mint a fresh
     * bucket per request by varying a header.
     *
     * @param bool $behindProxy
     * @return string
     */
    private function chatClientIp($behindProxy)
    {
        if ($behindProxy) {
            foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP'] as $header) {
                if (empty($_SERVER[$header])) {
                    continue;
                }
                $parts = explode(',', (string) $_SERVER[$header]);
                $ip = trim($parts[0]);
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }

        return isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '';
    }

    /**
     * Applies the per-visitor and (optional) store-wide limits.
     *
     * Every bucket is checked before any is charged, so a message refused by the
     * daily cap is not also counted against the visitor's window.
     *
     * @param array $config From whiteLabelConfig()
     * @param string $ip
     * @return int Seconds to wait, or 0 when the message is allowed
     */
    private function chatRateLimit(array $config, $ip)
    {
        $now = time();
        $buckets = [
            [
                'key' => 'ip:' . sha1($this->companyId . '|' . $ip),
                'limit' => $config['rate_limit'],
                'window' => $config['rate_window'],
            ],
        ];
        if ($config['daily_cap'] > 0) {
            $buckets[] = [
                'key' => 'store:' . $this->companyId,
                'limit' => $config['daily_cap'],
                'window' => 86400,
            ];
        }

        try {
            $rows = [];
            foreach ($buckets as $bucket) {
                $row = $this->host->Record->select()
                    ->from(self::RATE_TABLE)
                    ->where('bucket', '=', $bucket['key'])
                    ->fetch();

                if ($row && ((int) $row->window_start + $bucket['window']) > $now
                    && (int) $row->hits >= $bucket['limit']) {
                    return ((int) $row->window_start + $bucket['window']) - $now;
                }
                $rows[$bucket['key']] = $row;
            }

            foreach ($buckets as $bucket) {
                $this->chatChargeBucket($bucket, $rows[$bucket['key']], $now);
            }

            // Buckets are never read again once their window has passed, so
            // prune occasionally rather than on every message.
            if (mt_rand(1, 50) === 1) {
                $this->host->Record->from(self::RATE_TABLE)
                    ->where('window_start', '<', $now - 172800)
                    ->delete();
            }
        } catch (Throwable $e) {
            // The counter table is created on install and upgrade. If it is
            // missing, build it and let this one message through rather than
            // taking the assistant down.
            $this->chatEnsureRateTable();
        }

        return 0;
    }

    /**
     * Counts one message against a bucket.
     *
     * @param array $bucket
     * @param stdClass|null $row The bucket's current row, if any
     * @param int $now
     */
    private function chatChargeBucket(array $bucket, $row, $now)
    {
        if (!$row) {
            $this->host->Record->insert(self::RATE_TABLE, [
                'bucket' => $bucket['key'],
                'window_start' => $now,
                'hits' => 1,
            ]);

            return;
        }

        $expired = (((int) $row->window_start + $bucket['window']) <= $now);
        $this->host->Record->where('bucket', '=', $bucket['key'])->update(self::RATE_TABLE, [
            'window_start' => $expired ? $now : (int) $row->window_start,
            'hits' => $expired ? 1 : ((int) $row->hits + 1),
        ]);
    }

    /**
     * Creates the rate-limit table when an install predates it.
     */
    private function chatEnsureRateTable()
    {
        try {
            $this->host->Record
                ->setField('bucket', ['type' => 'varchar', 'size' => 80])
                ->setField('window_start', ['type' => 'int', 'size' => 11, 'unsigned' => true])
                ->setField('hits', ['type' => 'int', 'size' => 11, 'unsigned' => true, 'default' => 0])
                ->setKey(['bucket'], 'primary')
                ->setKey(['window_start'], 'index')
                ->create(self::RATE_TABLE, true);
        } catch (Throwable $e) {
            // Nothing more to try; the next message will attempt again.
        }
    }

    /**
     * The module row holding this company's ReliableSite credentials, with its
     * meta decrypted.
     *
     * @return stdClass|null
     */
    private function moduleRow()
    {
        Loader::loadModels($this->host, ['ModuleManager']);

        foreach ((array) $this->host->ModuleManager->getByClass('reliablesite', $this->companyId) as $module) {
            foreach ((array) $this->host->ModuleManager->getRows($module->id) as $row) {
                if (isset($row->meta->api_key) && $row->meta->api_key !== '') {
                    $this->moduleId = (int) $module->id;

                    return $row;
                }
            }
        }

        return null;
    }

    /**
     * A logger the chat client can write module-log entries through. Memoized so
     * every entry from one request shares a log group.
     *
     * @return ReliablesiteApiLogger
     */
    private function chatLogger()
    {
        if ($this->logger === null) {
            $this->logger = new ReliablesiteApiLogger($this->host, $this->moduleId);
        }

        return $this->logger;
    }

    /**
     * Records a local (non-upstream) chat problem in the module log.
     *
     * @param string $url A label for the log's URL column
     * @param string $data
     * @param bool $success
     */
    private function logChat($url, $data, $success)
    {
        $this->chatLogger()->myLog('chat:' . $url, $data, 'input', $success);
    }

    /**
     * The chat endpoint's failure body.
     *
     * `errors` is an array of display-safe strings, matching the shape the other
     * endpoints use; `success` is a real boolean here because this endpoint is
     * new and has no WHMCS-compatible consumers to keep happy.
     *
     * @param int $status
     * @param string $message
     * @param array $extra Additional top-level fields
     */
    private function chatFail($status, $message, array $extra = [])
    {
        http_response_code($status);
        echo json_encode(array_merge(['success' => false, 'errors' => [$message]], $extra));
    }

    /**
     * The company serving this hostname, so a multi-company install answers
     * with the right catalog.
     *
     * @return int
     */
    private function resolveCompany()
    {
        $host = isset($_SERVER['HTTP_HOST']) ? (string) $_SERVER['HTTP_HOST'] : '';
        // Strip any port; Companies::getByHostname() handles the www variant.
        $host = preg_replace('/:\d+$/', '', $host);

        if ($host !== '') {
            $company = $this->host->Companies->getByHostname($host);
            if ($company) {
                return (int) $company->id;
            }
        }

        $first = $this->host->Record->select(['id'])->from('companies')->fetch();

        return $first ? (int) $first->id : 1;
    }

    /**
     * Absolute base URL of the Blesta install.
     *
     * WEBDIR is not usable here: Blesta derives it from the requested script,
     * which for this file is the module directory, so it would send visitors to
     * /components/modules/reliablesite/order/... Strip this file's own known
     * path off the request instead, which also keeps subdirectory installs
     * working.
     *
     * @return string Absolute URL ending in a slash
     */
    private function baseUrl()
    {
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
        $host = isset($_SERVER['HTTP_HOST']) ? (string) $_SERVER['HTTP_HOST'] : '';

        $script = isset($_SERVER['SCRIPT_NAME']) ? (string) $_SERVER['SCRIPT_NAME'] : '';
        $ownPath = $this->ownPath();
        $position = strrpos($script, $ownPath);
        $webDir = ($position !== false) ? substr($script, 0, $position) : '/';
        if ($webDir === '' || substr($webDir, -1) !== '/') {
            $webDir .= '/';
        }

        return ($https ? 'https://' : 'http://') . $host . $webDir;
    }

    /**
     * This file's path relative to the Blesta web root.
     *
     * Built from the real paths so a renamed module directory still works.
     *
     * @return string
     */
    private function ownPath()
    {
        return 'components/modules/' . basename(dirname(__FILE__)) . '/' . basename(__FILE__);
    }

    /**
     * Maps a Blesta pricing term to its WHMCS cycle column.
     *
     * @param mixed $term
     * @param mixed $period
     * @return string|null
     */
    private function cycleFor($term, $period)
    {
        $key = (int) $term . '|' . $period;

        return isset(self::$cycleMap[$key]) ? self::$cycleMap[$key] : null;
    }

    /**
     * The WHMCS failure body, verbatim.
     *
     * @param string $message
     */
    private function fail($message)
    {
        echo json_encode(['success' => 0, 'errors' => [$message]]);
    }

    /**
     * Permissive CORS, as WHMCS sends: the widget runs on other origins.
     */
    private function cors()
    {
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type');

        if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
            http_response_code(200);
            exit;
        }
    }
}

/**
 * Writes module-log entries from outside the module.
 *
 * Module::log() is protected and needs the module instance, which this file
 * deliberately never builds. Chat turns still belong in the same place an admin
 * already looks - Tools > Logs > Module Log - so this writes there directly,
 * with the same shape Module::log() produces.
 */
class ReliablesiteApiLogger
{
    /** @var stdClass Host object carrying the Logs model */
    private $host;

    /** @var int */
    private $moduleId;

    /** @var string|null 8-character identifier linking one request's entries */
    private $group = null;

    /**
     * @param stdClass $host
     * @param int $moduleId
     */
    public function __construct($host, $moduleId)
    {
        $this->host = $host;
        $this->moduleId = (int) $moduleId;
    }

    /**
     * @param string $url
     * @param string $data
     * @param string $direction input or output
     * @param bool $success
     */
    public function myLog($url, $data = null, $direction = 'input', $success = false)
    {
        if ($this->moduleId <= 0) {
            return;
        }
        if ($this->group === null) {
            $this->group = substr(md5(mt_rand()), 0, 8);
        }

        try {
            Loader::loadModels($this->host, ['Logs']);
            $this->host->Logs->addModule([
                'module_id' => $this->moduleId,
                'direction' => $direction,
                'url' => $url,
                'data' => $data,
                'status' => ($success ? 'success' : 'error'),
                'group' => $this->group,
            ]);
        } catch (Throwable $e) {
            // Logging must never break a chat turn.
        }
    }
}

$reliablesiteApi = new ReliablesiteWidgetApi();
$reliablesiteApi->run();
