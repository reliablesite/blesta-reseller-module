<?php
/**
 * Builds and submits ReliableSite Order API orders for a pending Blesta service.
 *
 * The order is placed against the reseller's ReliableSite account; the customer
 * has already been billed by Blesta, so this is admin-initiated from the Pending
 * Orders screen. The service stays in its pending-assignment state after
 * ordering - the provisioned server is still linked later via the existing
 * Assign Server action ({@see ReliablesiteServerAssignment}). The order is
 * recorded as service fields so the row can show "Ordered" and re-ordering is
 * blocked.
 *
 * Blesta port of the Paymenter module's Support\OrderService.
 *
 * @package reliablesite.lib
 */
class ReliablesiteOrderService
{
    /** Internal-name prefix marking a ReliableSite-managed configurable option. */
    const OPTION_PREFIX = 'rs_opt_';

    /**
     * Blesta pricing "term|period" => Order API billingCycle enum.
     */
    private static $cycleMap = [
        '1|month' => 'monthly',
        '3|month' => 'quarterly',
        '6|month' => 'semiannually',
        '1|year' => 'annually',
        '2|year' => 'biennially',
        '3|year' => 'triennially',
    ];

    /** @var Reliablesite The owning module */
    private $module;

    /**
     * @param Reliablesite $module
     */
    public function __construct($module)
    {
        $this->module = $module;
        Loader::loadModels($this->module, ['Services', 'Record']);
    }

    /**
     * Places an Order API order for a pending service.
     *
     * @param int $serviceId The Blesta service id
     * @param array $opts ['hostname' => string, 'paymentMethod' => string, 'promoCode' => ?string]
     * @return array { success, errors, data }
     */
    public function placeOrder($serviceId, array $opts)
    {
        $result = ['success' => false, 'errors' => [], 'data' => []];

        $service = $this->module->Services->get((int) $serviceId);
        if (!$service) {
            $result['errors'][] = 'Service not found.';

            return $result;
        }

        $fields = $this->fieldsToArray($service->fields);

        // Guards.
        if (isset($fields['rs_order_placed']) && (string) $fields['rs_order_placed'] === '1') {
            $result['errors'][] = 'An order has already been placed for this service.';

            return $result;
        }
        if (!empty($fields['reliablesite_server_id'])) {
            $result['errors'][] = 'This service already has a server assigned; an order is not needed.';

            return $result;
        }

        try {
            $request = $this->buildRequest($service, $opts);
        } catch (Exception $e) {
            $result['errors'][] = $e->getMessage();

            return $result;
        }

        $idempotencyKey = $this->idempotencyKey((int) $serviceId, $fields);

        $api = $this->module->getOrderApi();
        $response = $api->createOrder($request, $idempotencyKey);

        if (empty($response['success'])) {
            $error = !empty($response['errors'])
                ? implode('; ', $response['errors'])
                : 'The ReliableSite Order API rejected the request.';
            $this->setField($serviceId, 'rs_order_status', 'failed');
            $this->setField($serviceId, 'rs_order_last_error', $error);
            $result['errors'][] = $error;

            return $result;
        }

        $data = is_array($response['data']) ? $response['data'] : [];
        $orderId = isset($data['orderId']) ? $data['orderId'] : null;
        $invoiceId = isset($data['invoiceId']) ? $data['invoiceId'] : null;

        $this->setField($serviceId, 'rs_order_placed', '1');
        $this->setField($serviceId, 'rs_order_status', 'submitted');
        if ($orderId !== null) {
            $this->setField($serviceId, 'rs_order_id', (string) $orderId);
        }
        if ($invoiceId !== null) {
            $this->setField($serviceId, 'rs_order_invoice_id', (string) $invoiceId);
        }
        // Clear any stale failure note from a previous attempt.
        $this->setField($serviceId, 'rs_order_last_error', '');

        $result['success'] = true;
        $result['data'] = ['order_id' => $orderId, 'invoice_id' => $invoiceId];

        return $result;
    }

    /**
     * Builds the Order API request payload from the service.
     *
     * @param stdClass $service
     * @param array $opts
     * @return array
     * @throws Exception on any missing/invalid input
     */
    private function buildRequest($service, array $opts)
    {
        // productId - the inventory id stored on the package at import time.
        $inventoryId = 0;
        if (isset($service->package->meta->reliablesite_product_id)) {
            $inventoryId = (int) $service->package->meta->reliablesite_product_id;
        }
        if ($inventoryId <= 0) {
            throw new Exception(
                'This package is not linked to ReliableSite inventory (missing product id); an order cannot be created.'
            );
        }
        $request = ['productId' => $inventoryId];

        // billingCycle - from the service's package pricing.
        $term = isset($service->package_pricing->term) ? (int) $service->package_pricing->term : 0;
        $period = isset($service->package_pricing->period) ? $service->package_pricing->period : '';
        $cycleKey = $term . '|' . $period;
        if (!isset(self::$cycleMap[$cycleKey])) {
            throw new Exception("This service's billing cycle is not supported by the Order API.");
        }
        $request['billingCycle'] = self::$cycleMap[$cycleKey];

        // hostname - from the modal.
        $hostname = trim((string) (isset($opts['hostname']) ? $opts['hostname'] : ''));
        if ($hostname === '') {
            throw new Exception('A hostname is required to place the order.');
        }
        $request['hostname'] = $hostname;

        // paymentMethod - modal selection, else the configured default.
        $paymentMethod = trim((string) (isset($opts['paymentMethod']) ? $opts['paymentMethod'] : ''));
        if ($paymentMethod === '') {
            $paymentMethod = trim((string) $this->module->getSetting('order_default_payment_method'));
        }
        if ($paymentMethod === '') {
            throw new Exception('No payment method selected and no default is configured in the ReliableSite settings.');
        }
        $request['paymentMethod'] = $paymentMethod;

        // configOptions - { (string) group_id : (int) addon_id } from the
        // customer's configurable-option selections. Cast to object so JSON
        // serializes an empty set as {} rather than [].
        $request['configOptions'] = (object) $this->collectConfigOptions($service);

        $promo = trim((string) (isset($opts['promoCode']) ? $opts['promoCode'] : ''));
        if ($promo !== '') {
            $request['promoCode'] = $promo;
        }

        return $request;
    }

    /**
     * Reads the service's ReliableSite-managed configurable-option selections and
     * maps them to { group_id => addon_id }.
     *
     * @param stdClass $service A service from Services::get() (has ->options)
     * @return array<string,int>
     */
    private function collectConfigOptions($service)
    {
        $configOptions = [];
        $options = isset($service->options) && is_array($service->options) ? $service->options : [];

        foreach ($options as $option) {
            $name = isset($option->option_name) ? (string) $option->option_name : '';
            if (strpos($name, self::OPTION_PREFIX) !== 0) {
                continue;
            }
            $groupId = substr($name, strlen(self::OPTION_PREFIX));
            $addonId = isset($option->option_value) ? $option->option_value : null;
            if ($groupId === '' || !is_numeric($groupId) || !is_numeric($addonId)) {
                continue;
            }
            $configOptions[(string) (int) $groupId] = (int) $addonId;
        }

        return $configOptions;
    }

    /**
     * Returns the order-tracking state for a set of services, for list rendering.
     *
     * @param array $serviceIds
     * @return array service_id => { placed, order_id, invoice_id, status, last_error }
     */
    public function getOrderStates(array $serviceIds)
    {
        $serviceIds = array_values(array_filter(array_map('intval', $serviceIds)));
        if (empty($serviceIds)) {
            return [];
        }

        $keys = ['rs_order_placed', 'rs_order_id', 'rs_order_invoice_id', 'rs_order_status', 'rs_order_last_error'];
        $rows = $this->module->Record->select(['service_id', 'key', 'value'])
            ->from('service_fields')
            ->where('service_id', 'in', $serviceIds)
            ->where('key', 'in', $keys)
            ->fetchAll();

        $states = [];
        foreach ($rows as $r) {
            $sid = (int) $r->service_id;
            if (!isset($states[$sid])) {
                $states[$sid] = [
                    'placed' => false,
                    'order_id' => '',
                    'invoice_id' => '',
                    'status' => '',
                    'last_error' => '',
                ];
            }
            switch ($r->key) {
                case 'rs_order_placed':
                    $states[$sid]['placed'] = ((string) $r->value === '1');
                    break;
                case 'rs_order_id':
                    $states[$sid]['order_id'] = (string) $r->value;
                    break;
                case 'rs_order_invoice_id':
                    $states[$sid]['invoice_id'] = (string) $r->value;
                    break;
                case 'rs_order_status':
                    $states[$sid]['status'] = (string) $r->value;
                    break;
                case 'rs_order_last_error':
                    $states[$sid]['last_error'] = (string) $r->value;
                    break;
            }
        }

        return $states;
    }

    /**
     * Returns the stored idempotency key for a service, generating and persisting
     * one on first use so retries reuse the same key.
     *
     * @param int $serviceId
     * @param array $fields The service's current fields (key => value)
     * @return string
     */
    private function idempotencyKey($serviceId, array $fields)
    {
        $existing = isset($fields['rs_order_idempotency_key']) ? (string) $fields['rs_order_idempotency_key'] : '';
        if ($existing !== '') {
            return $existing;
        }

        $key = 'reliablesite-' . $serviceId . '-' . bin2hex(function_exists('random_bytes')
            ? random_bytes(8)
            : pack('N*', mt_rand(), mt_rand()));
        $this->setField($serviceId, 'rs_order_idempotency_key', $key);

        return $key;
    }

    /**
     * Writes (adds or updates) a service field.
     */
    private function setField($serviceId, $key, $value)
    {
        $this->module->Services->editField((int) $serviceId, ['key' => $key, 'value' => $value, 'encrypted' => 0]);
    }

    /**
     * Maps a service's fields array (stdClass {key,value}) to an associative array.
     *
     * @param array $fields
     * @return array
     */
    private function fieldsToArray($fields)
    {
        $out = [];
        if (!is_array($fields)) {
            return $out;
        }
        foreach ($fields as $field) {
            if (is_object($field)) {
                $out[$field->key] = $field->value;
            } elseif (is_array($field)) {
                $out[$field['key']] = $field['value'];
            }
        }

        return $out;
    }
}
