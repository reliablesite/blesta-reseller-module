<?php
/**
 * Single source of truth for assigning / unassigning ReliableSite servers to
 * Blesta services. Both the Pending Orders screen (order-first) and the Servers
 * screen (server-first) call into this class so the two workflows can't drift.
 *
 * Blesta port of the Paymenter module's Support\ServerAssignment. The ordering
 * guarantee is identical: the ReliableSite API call happens FIRST, and Blesta
 * service fields are only written once the API call has succeeded.
 *
 * @package reliablesite.lib
 */
class ReliablesiteServerAssignment
{
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
     * Assigns a ReliableSite server to a Blesta service.
     *
     * @param int $serviceId The Blesta service id
     * @param mixed $rsServerId The ReliableSite server id
     * @param string|null $label An optional friendly label
     * @return array { success, server_id, label, errors }
     */
    public function assign($serviceId, $rsServerId, $label = null)
    {
        $result = ['success' => false, 'server_id' => $rsServerId, 'label' => '', 'errors' => []];

        $service = $this->module->Services->get($serviceId);
        if (!$service) {
            $result['errors'][] = 'Service not found.';

            return $result;
        }

        $fields = $this->fieldsToArray($service->fields);

        $username = isset($fields['reliablesite_username']) ? trim((string) $fields['reliablesite_username']) : '';
        if ($username === '') {
            $result['errors'][] = 'Service is missing the ReliableSite username - cannot assign.';

            return $result;
        }

        // Race-condition guards: refuse before touching the API.
        $existing = isset($fields['reliablesite_server_id']) ? (string) $fields['reliablesite_server_id'] : '';
        if ($existing !== '' && $existing !== (string) $rsServerId) {
            $result['errors'][] = 'Service #' . $serviceId . ' is already assigned to server #' . $existing . '.';

            return $result;
        }

        // API call FIRST - if it fails, nothing is written to Blesta.
        $api = $this->module->getApi();
        $apiResp = $api->assignServer($username, $rsServerId);
        if (empty($apiResp['success'])) {
            $result['errors'] = !empty($apiResp['errors'])
                ? $apiResp['errors']
                : ['ReliableSite rejected the server assignment.'];

            return $result;
        }

        $label = ($label !== null && trim((string) $label) !== '') ? trim((string) $label) : (string) $rsServerId;

        $this->module->Services->editField($serviceId, ['key' => 'reliablesite_server_id', 'value' => $rsServerId]);
        $this->module->Services->editField($serviceId, ['key' => 'reliablesite_server_label', 'value' => $label]);
        $this->module->Services->editField($serviceId, ['key' => 'reliablesite_pending', 'value' => '0']);
        $this->module->Services->editField($serviceId, ['key' => 'reliablesite_pending_since', 'value' => '']);

        $result['success'] = true;
        $result['label'] = $label;

        return $result;
    }

    /**
     * Unassigns a ReliableSite server. If no service references the server id,
     * the API call still runs and the result returns orphan=true so the UI can
     * show a warning instead of a hard error.
     *
     * @param mixed $rsServerId The ReliableSite server id
     * @param int|null $serviceId Optional pre-resolved service id
     * @return array { success, server_id, prior_label, service_id, orphan, errors }
     */
    public function unassign($rsServerId, $serviceId = null)
    {
        $result = [
            'success' => false,
            'server_id' => $rsServerId,
            'prior_label' => null,
            'service_id' => null,
            'orphan' => false,
            'errors' => [],
        ];

        if ($serviceId === null) {
            $serviceId = $this->findServiceIdByServerId($rsServerId);
        }

        // API first regardless of linkage - operators must be able to clean orphans.
        $api = $this->module->getApi();
        $apiResp = $api->unassignServer($rsServerId);
        if (empty($apiResp['success'])) {
            $result['errors'] = !empty($apiResp['errors'])
                ? $apiResp['errors']
                : ['ReliableSite rejected the unassignment.'];

            return $result;
        }

        if ($serviceId) {
            $service = $this->module->Services->get($serviceId);
            if ($service) {
                $fields = $this->fieldsToArray($service->fields);
                $result['prior_label'] = isset($fields['reliablesite_server_label'])
                    ? $fields['reliablesite_server_label']
                    : null;
            }

            $this->module->Services->editField($serviceId, ['key' => 'reliablesite_server_id', 'value' => '']);
            $this->module->Services->editField($serviceId, ['key' => 'reliablesite_server_label', 'value' => '']);
            $this->module->Services->editField($serviceId, ['key' => 'reliablesite_pending', 'value' => '1']);
            $this->module->Services->editField(
                $serviceId,
                ['key' => 'reliablesite_pending_since', 'value' => gmdate('Y-m-d\TH:i:s\Z')]
            );

            $result['service_id'] = $serviceId;
        } else {
            $result['orphan'] = true;
        }

        $result['success'] = true;

        return $result;
    }

    /**
     * Finds the Blesta service id currently linked to a ReliableSite server id,
     * scoped to packages provisioned by this module.
     *
     * @param mixed $rsServerId
     * @return int|null
     */
    public function findServiceIdByServerId($rsServerId)
    {
        $row = $this->module->Record->select(['services.id'])
            ->from('services')
            ->innerJoin('service_fields', 'service_fields.service_id', '=', 'services.id', false)
            ->innerJoin('package_pricing', 'package_pricing.id', '=', 'services.pricing_id', false)
            ->innerJoin('packages', 'packages.id', '=', 'package_pricing.package_id', false)
            ->where('packages.module_id', '=', $this->module->getModuleId())
            ->where('service_fields.key', '=', 'reliablesite_server_id')
            ->where('service_fields.value', '=', (string) $rsServerId)
            ->where('services.status', '!=', 'canceled')
            ->fetch();

        return $row ? (int) $row->id : null;
    }

    /**
     * Builds the shared "awaiting server assignment" query, so the list and the
     * count cannot drift apart.
     *
     * @return Record The Record object with the query staged (not yet fetched)
     */
    private function pendingQuery()
    {
        return $this->module->Record->select([
                'services.id',
                'services.id_value' => 'service_number',
                'services.date_added',
                'package_pricing.package_id' => 'package_id',
                'services.client_id',
                'clients.id_value' => 'client_number',
                'contacts.first_name',
                'contacts.last_name',
                'contacts.email',
            ])
            ->from('services')
            ->innerJoin('service_fields', 'service_fields.service_id', '=', 'services.id', false)
            ->innerJoin('package_pricing', 'package_pricing.id', '=', 'services.pricing_id', false)
            ->innerJoin('packages', 'packages.id', '=', 'package_pricing.package_id', false)
            ->leftJoin('clients', 'clients.id', '=', 'services.client_id', false)
            ->on('contacts.contact_type', '=', 'primary')
            ->leftJoin('contacts', 'contacts.client_id', '=', 'clients.id', false)
            ->where('packages.module_id', '=', $this->module->getModuleId())
            ->where('service_fields.key', '=', 'reliablesite_pending')
            ->where('service_fields.value', '=', '1')
            ->where('services.status', 'in', ['active', 'pending'])
            ->group(['services.id']);
    }

    /**
     * Returns services awaiting server assignment for this module's packages.
     *
     * @return array Array of stdClass service rows (with client + package data)
     */
    public function pendingServices()
    {
        return $this->pendingQuery()
            ->order(['services.date_added' => 'ASC'])
            ->fetchAll();
    }

    /**
     * Returns the number of services awaiting assignment.
     *
     * The navigation badge asks for this on every admin screen, so it must not
     * hydrate rows: numResults() wraps the query as a COUNT(*) subquery, which
     * also counts the GROUP BY correctly.
     *
     * @return int
     */
    public function pendingCount()
    {
        return $this->pendingQuery()->numResults();
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
