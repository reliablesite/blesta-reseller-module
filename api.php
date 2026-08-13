<?php
/**
 * Public JSON endpoints for the external ReliableSite widget and reseller
 * portal.
 *
 * These are the Blesta equivalents of the three public actions the WHMCS module
 * serves from rspanel_clientarea() before its access check:
 *
 *   WHMCS                                   Blesta
 *   index.php?m=rspanel&action=currencies    api.php?action=currencies
 *   index.php?m=rspanel&action=pricing       api.php?action=pricing
 *   index.php?m=rspanel&action=widget&id=..  api.php?action=widget&id=..
 *
 * The URLs differ because Blesta has no query-string front controller - it
 * dispatches on the request path only, and modules (unlike plugins) get no
 * routes at all. This file is requested directly instead, which works because
 * the webroot .htaccess only rewrites paths that do not resolve to a real file.
 * The response bodies are byte-compatible with the WHMCS ones so existing
 * consumers need no changes; see the per-endpoint notes for the two fields
 * Blesta cannot express as integers.
 *
 * Public and unauthenticated, matching WHMCS: the widget is served from other
 * origins, so every response carries permissive CORS headers and only exposes
 * catalog data that is already public on the order form.
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

    /** @var stdClass Host object for Blesta models/components */
    private $host;

    /** @var int The resolved company id */
    private $companyId = 0;

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
        // Built from the real paths so a renamed module directory still works.
        $ownPath = 'components/modules/' . basename(dirname(__FILE__)) . '/' . basename(__FILE__);
        $position = strrpos($script, $ownPath);
        $webDir = ($position !== false) ? substr($script, 0, $position) : '/';
        if ($webDir === '' || substr($webDir, -1) !== '/') {
            $webDir .= '/';
        }

        return ($https ? 'https://' : 'http://') . $host . $webDir;
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

$reliablesiteApi = new ReliablesiteWidgetApi();
$reliablesiteApi->run();
