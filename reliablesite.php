<?php
/**
 * ReliableSite dedicated-server module for Blesta.
 *
 * v2.0 — ground-up rewrite. Implements the full Blesta module service
 * lifecycle, a pending-assignment provisioning model (create/find a ReliableSite
 * customer on order, then an admin assigns a physical server), JSON
 * inventory-widget catalog sync with markup/freeze/cycles, client self-service
 * (power, KVM, OS reinstall, rDNS, MAC, backups, bandwidth, DDoS profiles), and
 * admin management screens. Feature baseline: the ReliableSite Paymenter module.
 *
 * @package blesta
 * @subpackage blesta.components.modules.reliablesite
 * @copyright Copyright (c) 2024 ReliableSite.Net
 * @link https://www.reliablesite.net
 */
class Reliablesite extends Module
{
    /** @var string Module version */
    const RS_VERSION = '2.2.0';

    /** @var string AskBrian (Sales Engineer) reseller web-chat endpoint */
    const BRIAN_API_URL = 'https://api-brian.reliablesite.net/api/web-chat-reslr';

    /** @var int Resolved module id */
    private $my_module_id = 0;

    /** @var int Resolved module row id */
    private $my_module_row_id = 0;

    /** @var object|null Resolved module row meta */
    private $my_module_meta = null;

    /** @var array Buffer of sync-log lines for the current run */
    private $sync_log_lines = [];

    /**
     * Initializes the module.
     */
    public function __construct()
    {
        Loader::loadComponents($this, ['Input']);
        Language::loadLang('reliablesite', null, dirname(__FILE__) . DS . 'language' . DS);

        // Welcome-email templates
        Configure::load('reliablesite', dirname(__FILE__) . DS . 'config' . DS);

        // Module metadata (version, name keys, email tags)
        $this->loadConfig(dirname(__FILE__) . DS . 'config.json');

        $this->setMyModuleData();
    }

    // =================================================================
    // Metadata
    // =================================================================

    public function getName()
    {
        return Language::_('Reliablesite.name', true);
    }

    public function getVersion()
    {
        return self::RS_VERSION;
    }

    public function getAuthors()
    {
        return [['name' => 'ReliableSite.Net', 'url' => 'https://www.reliablesite.net']];
    }

    // =================================================================
    // Install / upgrade / uninstall
    // =================================================================

    /**
     * Performs any necessary bootstrapping actions.
     */
    public function install()
    {
        $this->createDBTables();
        $this->addCronTasks($this->getCronTasks());
    }

    /**
     * Migrates data when the file-set version changes.
     *
     * @param string $current_version The currently installed version
     */
    public function upgrade($current_version)
    {
        // Clean v2.0: ensure the new tables and cron task exist. No data is
        // migrated from the legacy 1.x sync tables (documented re-setup).
        $this->createDBTables();
        $this->addCronTasks($this->getCronTasks());
    }

    /**
     * Performs cleanup on uninstall.
     *
     * @param int $module_id The module id being uninstalled
     * @param bool $last_instance True if this is the last instance
     */
    public function uninstall($module_id, $last_instance)
    {
        if (!isset($this->Record)) {
            Loader::loadComponents($this, ['Record']);
        }
        Loader::loadModels($this, ['CronTasks']);

        $cron_tasks = $this->getCronTasks();

        if ($last_instance) {
            foreach ($cron_tasks as $task) {
                $cron_task = $this->CronTasks->getByKey($task['key'], $task['dir'], $task['task_type']);
                if ($cron_task) {
                    $this->CronTasks->deleteTask($cron_task->id, $task['task_type'], $task['dir']);
                }
            }

            try {
                $this->Record->drop('mod_reliablesite_packages');
                $this->Record->drop('mod_reliablesite_sync_logs');
                $this->Record->drop('mod_reliablesite_option_groups');
            } catch (Exception $e) {
                // best effort
            }
        }

        foreach ($cron_tasks as $task) {
            $cron_task_run = $this->CronTasks->getTaskRunByKey($task['key'], $task['dir'], false, $task['task_type']);
            if ($cron_task_run) {
                $this->CronTasks->deleteTaskRun($cron_task_run->task_run_id);
            }
        }
    }

    /**
     * Creates the module's database tables.
     */
    private function createDBTables()
    {
        Loader::loadComponents($this, ['Record']);

        // Tracked catalog packages: maps an inventory id to a Blesta package and
        // holds the per-package sync state (frozen flag, last synced base price).
        $this->Record
            ->setField('id', ['type' => 'int', 'size' => 10, 'unsigned' => true, 'auto_increment' => true])
            ->setField('rs_inventory_id', ['type' => 'varchar', 'size' => 64])
            ->setField('package_id', ['type' => 'int', 'size' => 10, 'unsigned' => true])
            ->setField('tracked', ['type' => 'tinyint', 'size' => 1, 'default' => 1])
            ->setField('frozen', ['type' => 'tinyint', 'size' => 1, 'default' => 0])
            ->setField('last_synced_price', ['type' => 'decimal', 'size' => '12,2', 'is_null' => true, 'default' => null])
            ->setField('last_sync', ['type' => 'datetime', 'is_null' => true, 'default' => null])
            ->setKey(['id'], 'primary')
            ->setKey(['package_id'], 'index')
            ->create('mod_reliablesite_packages', true);

        // Sync run logs (powers the admin Sync Log screen + report emails).
        $this->Record
            ->setField('id', ['type' => 'int', 'size' => 10, 'unsigned' => true, 'auto_increment' => true])
            ->setField('date', ['type' => 'int', 'size' => 11, 'unsigned' => true])
            ->setField('log', ['type' => 'text'])
            ->setKey(['id'], 'primary')
            ->create('mod_reliablesite_sync_logs', true);

        // Maps a ReliableSite option group to the Blesta configurable option
        // group + option it was synced into (see ReliablesiteOptionSync).
        $this->Record
            ->setField('id', ['type' => 'int', 'size' => 10, 'unsigned' => true, 'auto_increment' => true])
            ->setField('rs_group_id', ['type' => 'varchar', 'size' => 64])
            ->setField('option_group_id', ['type' => 'int', 'size' => 10, 'unsigned' => true])
            ->setField('option_id', ['type' => 'int', 'size' => 10, 'unsigned' => true])
            ->setField('last_hash', ['type' => 'varchar', 'size' => 64, 'is_null' => true, 'default' => null])
            ->setField('last_sync', ['type' => 'datetime', 'is_null' => true, 'default' => null])
            ->setKey(['id'], 'primary')
            ->setKey(['rs_group_id'], 'index')
            ->create('mod_reliablesite_option_groups', true);
    }

    /**
     * Returns the cron tasks owned by this module.
     *
     * @return array
     */
    private function getCronTasks()
    {
        return [
            [
                'key' => 'rs_catalog_sync',
                'task_type' => 'module',
                'dir' => 'reliablesite',
                'name' => Language::_('Reliablesite.cron.catalog_sync_name', true),
                'description' => Language::_('Reliablesite.cron.catalog_sync_desc', true),
                'type' => 'interval',
                'type_value' => '60',
                'enabled' => 1,
            ],
        ];
    }

    /**
     * Registers the module's cron tasks.
     *
     * @param array $tasks
     */
    private function addCronTasks(array $tasks)
    {
        Loader::loadModels($this, ['CronTasks']);
        foreach ($tasks as $task) {
            $task_id = $this->CronTasks->add($task);
            if (!$task_id) {
                $cron_task = $this->CronTasks->getByKey($task['key'], $task['dir'], $task['task_type']);
                if ($cron_task) {
                    $task_id = $cron_task->id;
                }
            }
            if ($task_id) {
                $task_run = $this->CronTasks->getTaskRunByKey($task['key'], $task['dir'], false, $task['task_type']);
                if (!$task_run) {
                    $task_vars = ['enabled' => $task['enabled']];
                    if ($task['type'] === 'time') {
                        $task_vars['time'] = $task['type_value'];
                    } else {
                        $task_vars['interval'] = $task['type_value'];
                    }
                    $this->CronTasks->addTaskRun($task_id, $task_vars);
                }
            }
        }
    }

    /**
     * Aligns the catalog-sync cron interval with the configured sync_frequency.
     */
    private function syncCronInterval($frequency = 0)
    {
        Loader::loadModels($this, ['CronTasks']);
        $frequency = (int) $frequency;
        if ($frequency <= 0) {
            $frequency = (int) $this->getSetting('sync_frequency');
        }
        if ($frequency <= 0) {
            $frequency = 60;
        }
        $task = $this->CronTasks->getByKey('rs_catalog_sync', 'reliablesite', 'module');
        if (!$task) {
            return;
        }
        $task_run = $this->CronTasks->getTaskRunByKey('rs_catalog_sync', 'reliablesite', false, 'module');
        if ($task_run) {
            $this->CronTasks->editTaskRun($task_run->task_run_id, ['interval' => (string) $frequency, 'enabled' => 1]);
        }
    }

    /**
     * Runs the cron task identified by the given key.
     *
     * @param string $key
     */
    public function cron($key)
    {
        if ($key === 'rs_catalog_sync' || $key === 'rs_product_sync') {
            if ($this->getSetting('catalog_sync_enabled') != '1') {
                return;
            }
            $sync = $this->loadCatalogSync();
            $sync->run('scheduler');
        }
    }

    // =================================================================
    // Module rows (credential + settings)
    // =================================================================

    public function moduleRowName()
    {
        return Language::_('Reliablesite.module_row', true);
    }

    public function moduleRowNamePlural()
    {
        return Language::_('Reliablesite.module_row_plural', true);
    }

    public function moduleGroupName()
    {
        return null;
    }

    public function moduleRowMetaKey()
    {
        return 'account_name';
    }

    /**
     * Returns the fields stored on a module row, with encryption flags.
     *
     * @return array
     */
    private function rowMetaFields()
    {
        return [
            'account_name' => 0,
            'api_key' => 1,
            'api_token' => 0,
            'api_token_validity' => 0,
            'order_api_token' => 0,
            'order_api_token_validity' => 0,
            'order_default_payment_method' => 0,
            'admin_notify_enabled' => 0,
            'admin_notify_emails' => 0,
            'catalog_sync_enabled' => 0,
            'options_sync_enabled' => 0,
            'sync_report_enabled' => 0,
            'sync_frequency' => 0,
            'import_package_group' => 0,
            'import_currency_code' => 0,
            'markup_type' => 0,
            'markup_value' => 0,
            'cycle_mode' => 0,
        ];
    }

    /**
     * Validates and adds a module row.
     *
     * @param array $vars
     * @return array Meta field list
     */
    public function addModuleRow(array &$vars)
    {
        $this->Input->setRules($this->getRowRules($vars));
        if (!$this->Input->validates($vars)) {
            return;
        }

        return $this->buildRowMeta($vars);
    }

    /**
     * Validates and edits a module row.
     *
     * @param stdClass $module_row
     * @param array $vars
     * @return array Meta field list
     */
    public function editModuleRow($module_row, array &$vars)
    {
        // Dedicated POST action: create a ReliableSite customer without modifying
        // the row. Routed through edit-row so the password travels in the POST
        // body (not the URL / access logs). The row is returned unchanged.
        if (isset($vars['rs_action']) && $vars['rs_action'] === 'add_customer') {
            $this->handleCustomerAddPost($vars);

            return $this->metaFromRow($module_row);
        }

        $this->Input->setRules($this->getRowRules($vars));
        if (!$this->Input->validates($vars)) {
            return;
        }

        // Preserve the cached token unless the API key changed.
        if (isset($module_row->meta->api_key)
            && isset($vars['api_key'])
            && $vars['api_key'] === $module_row->meta->api_key) {
            if (!isset($vars['api_token'])) {
                $vars['api_token'] = isset($module_row->meta->api_token) ? $module_row->meta->api_token : '';
            }
            if (!isset($vars['api_token_validity'])) {
                $vars['api_token_validity'] = isset($module_row->meta->api_token_validity)
                    ? $module_row->meta->api_token_validity : '';
            }
            // Preserve the cached Order API token the same way.
            if (!isset($vars['order_api_token'])) {
                $vars['order_api_token'] = isset($module_row->meta->order_api_token)
                    ? $module_row->meta->order_api_token : '';
            }
            if (!isset($vars['order_api_token_validity'])) {
                $vars['order_api_token_validity'] = isset($module_row->meta->order_api_token_validity)
                    ? $module_row->meta->order_api_token_validity : '';
            }
        }

        $meta = $this->buildRowMeta($vars);

        // Realign the cron interval to the (possibly changed) sync frequency.
        $this->my_module_meta = (object) $vars;
        $this->my_module_row_id = $module_row->id;
        $this->syncCronInterval(isset($vars['sync_frequency']) ? (int) $vars['sync_frequency'] : 0);

        return $meta;
    }

    /**
     * Builds the module-row meta array from input.
     *
     * @param array $vars
     * @return array
     */
    private function buildRowMeta(array $vars)
    {
        $meta = [];
        foreach ($this->rowMetaFields() as $key => $encrypted) {
            $meta[] = [
                'key' => $key,
                'value' => isset($vars[$key]) ? $vars[$key] : '',
                'encrypted' => $encrypted,
            ];
        }

        return $meta;
    }

    /**
     * Rebuilds the row meta array unchanged from the existing row (used by the
     * dedicated add-customer POST so the edit-row save is a no-op).
     *
     * @param stdClass $module_row
     * @return array
     */
    private function metaFromRow($module_row)
    {
        $meta = [];
        foreach ($this->rowMetaFields() as $key => $encrypted) {
            $meta[] = [
                'key' => $key,
                'value' => (isset($module_row->meta->{$key}) ? $module_row->meta->{$key} : ''),
                'encrypted' => $encrypted,
            ];
        }

        return $meta;
    }

    /**
     * Handles the dedicated add-customer POST and stores the outcome in the
     * session so it can be shown after the edit-row redirect.
     *
     * @param array $vars
     */
    private function handleCustomerAddPost(array $vars)
    {
        Loader::loadComponents($this, ['Session']);
        $api = $this->getApi();
        $resp = $api->addCustomer(
            isset($vars['username']) ? trim($vars['username']) : '',
            isset($vars['email']) ? trim($vars['email']) : '',
            isset($vars['password']) ? $vars['password'] : ''
        );

        if (!empty($resp['success'])) {
            $this->Session->write('reliablesite_flash', json_encode([
                'type' => 'success',
                'msg' => Language::_('Reliablesite.customers.added', true),
            ]));
        } else {
            $msg = !empty($resp['errors'])
                ? implode('; ', $resp['errors'])
                : Language::_('Reliablesite.customers.add_failed', true);
            $this->Session->write('reliablesite_flash', json_encode(['type' => 'error', 'msg' => $msg]));
        }
    }

    /**
     * Validation rules for a module row.
     *
     * @param array $vars
     * @return array
     */
    private function getRowRules(array &$vars)
    {
        return [
            'account_name' => [
                'valid' => [
                    'rule' => 'isEmpty',
                    'negate' => true,
                    'message' => Language::_('Reliablesite.!error.account_name.valid', true),
                ],
            ],
            'api_key' => [
                'valid' => [
                    'last' => true,
                    'rule' => 'isEmpty',
                    'negate' => true,
                    'message' => Language::_('Reliablesite.!error.api_key.valid', true),
                ],
                'connection' => [
                    'rule' => [[$this, 'validateConnection']],
                    'message' => Language::_('Reliablesite.!error.api_key.connection', true),
                ],
            ],
        ];
    }

    /**
     * Validates that an API key can authenticate against the ReliableSite API.
     *
     * @param string $api_key
     * @return bool
     */
    public function validateConnection($api_key)
    {
        $token = $this->fetchToken($api_key);
        if (empty($token['token'])) {
            return false;
        }

        Loader::load(dirname(__FILE__) . DS . 'lib' . DS . 'reliablesite_api.php');
        $api = new ReliablesiteApi($token['token'], $this);
        $resp = $api->GetProfile();

        return !empty($resp['success']);
    }

    // =================================================================
    // Module management UI (admin)
    // =================================================================

    /**
     * Renders the module management landing page.
     *
     * @param stdClass $module
     * @param array $vars
     * @return string
     */
    public function manageModule($module, array &$vars)
    {
        $this->view = new View('manage', 'default');
        $this->view->base_uri = $this->base_uri;
        $this->view->setDefaultView('components' . DS . 'modules' . DS . 'reliablesite' . DS);
        Loader::loadHelpers($this, ['Form', 'Html', 'Widget']);

        // Surface a one-shot result message left by a dedicated POST action.
        Loader::loadComponents($this, ['Session']);
        $flash = $this->Session->read('reliablesite_flash');
        if (!empty($flash)) {
            $this->Session->clear('reliablesite_flash');
            $flash = json_decode($flash, true);
        } else {
            $flash = null;
        }
        $this->view->set('flash', $flash);

        $row_id = 0;
        $account_name = '';
        foreach ($module->rows as $row) {
            if (isset($row->meta->api_key) && $row->meta->api_key !== '') {
                $row_id = $row->id;
                $account_name = isset($row->meta->account_name) ? $row->meta->account_name : '';
                break;
            }
        }

        $base = $this->base_uri . 'settings/company/modules/addrow/' . $module->id;

        // No credential yet: show a getting-started prompt.
        if (!$row_id) {
            $this->view->set('module', $module);
            $this->view->set('needs_credential', true);
            $this->view->set('add_link', $base);

            return $this->wrap($this->view->fetch());
        }

        // Dashboard stats (database only - no API calls so the page is always fast).
        Loader::loadModels($this, ['Record']);
        $pending = 0;
        try {
            $pending = $this->loadAssignment()->pendingCount();
        } catch (Exception $e) {
            // Non-fatal; show 0.
        }
        $tracked = $this->Record->select()->from('mod_reliablesite_packages')->numResults();
        $last_sync_row = $this->Record->select()
            ->from('mod_reliablesite_sync_logs')
            ->order(['id' => 'DESC'])
            ->fetch();
        $last_sync = $last_sync_row ? date('Y-m-d H:i', (int) $last_sync_row->date) : 'Never';

        $this->view->set('module', $module);
        $this->view->set('needs_credential', false);
        $this->view->set('nav', $this->manageNav('home'));
        $this->view->set('account_name', $account_name);
        $this->view->set('stats', [
            'pending' => (int) $pending,
            'tracked' => (int) $tracked,
            'last_sync' => $last_sync,
        ]);
        $this->view->set('links', [
            'pending' => $base . '/?scr=pendingorders',
            'catalog' => $base . '/?scr=catalog',
            'servers' => $base . '/?scr=servers',
        ]);

        return $this->wrap($this->view->fetch());
    }

    /**
     * Routes the various admin management screens (selected via ?scr=).
     *
     * @param array $vars
     * @return string
     */
    public function manageAddRow(array &$vars)
    {
        $this->setMyModule();
        $scr = isset($_GET['scr']) ? preg_replace('/[^a-z]/', '', $_GET['scr']) : '';

        switch ($scr) {
            case '':
                // Default add-row page: the credential/settings form.
                return $this->settingsScreen($vars, false);
            case 'pendingorders':
                return $this->pendingOrdersScreen($vars);
            case 'servers':
                return $this->serversScreen($vars);
            case 'manageserver':
                return $this->manageServerScreen($vars);
            case 'catalog':
                return $this->catalogScreen($vars);
            case 'customers':
                return $this->customersScreen($vars);
            case 'ddosprofiles':
                return $this->ddosProfilesScreen($vars);
            case 'ddoshistory':
                return $this->ddosHistoryScreen($vars);
            case 'nullroutes':
                return $this->nullRoutesScreen($vars);
            case 'synclog':
                return $this->syncLogScreen($vars);
            case 'askbrian':
                return $this->askBrianScreen($vars);
            default:
                return $this->renderView('invalid_action', []);
        }
    }

    public function manageEditRow($module_row, array &$vars)
    {
        // Editing happens through the settings screen; the controller persists.
        return $this->settingsScreen($vars, true, $module_row);
    }

    // =================================================================
    // Packages
    // =================================================================

    /**
     * Defines package configuration fields.
     *
     * @param stdClass|null $vars
     * @return ModuleFields
     */
    public function getPackageFields($vars = null)
    {
        Loader::loadHelpers($this, ['Html']);
        $fields = new ModuleFields();

        $product_id = $fields->label(
            Language::_('Reliablesite.package_fields.product_id', true),
            'reliablesite_product_id'
        );
        $product_id->attach(
            $fields->fieldText(
                'meta[reliablesite_product_id]',
                (isset($vars->meta['reliablesite_product_id']) ? $vars->meta['reliablesite_product_id'] : null),
                ['id' => 'reliablesite_product_id']
            )
        );
        $product_id->attach(
            $fields->tooltip(Language::_('Reliablesite.package_fields.product_id.tooltip', true))
        );
        $fields->setField($product_id);

        return $fields;
    }

    public function addPackage(array $vars = null)
    {
        $meta = [];
        if (isset($vars['meta']) && is_array($vars['meta'])) {
            foreach ($vars['meta'] as $key => $value) {
                $meta[] = ['key' => $key, 'value' => $value, 'encrypted' => 0];
            }
        }

        return $meta;
    }

    public function editPackage($package, array $vars = null)
    {
        return $this->addPackage($vars);
    }

    // =================================================================
    // Service lifecycle
    // =================================================================

    /**
     * Service field definitions used across the lifecycle.
     *
     * @return array
     */
    private function serviceFieldKeys()
    {
        return [
            'reliablesite_username',
            'reliablesite_server_id',
            'reliablesite_server_label',
            'reliablesite_pending',
            'reliablesite_pending_since',
        ];
    }

    /**
     * Provisions a service: ensures a ReliableSite customer exists and queues the
     * order for manual server assignment.
     *
     * @return array Service field list
     */
    public function addService($package, array $vars = null, $parent_package = null, $parent_service = null, $status = 'pending')
    {
        $use_module = !isset($vars['use_module']) || $vars['use_module'] === 'true';
        $username = '';

        if ($use_module) {
            try {
                $client_id = isset($vars['client_id']) ? $vars['client_id'] : null;
                $username = $this->ensureCustomer($client_id);
            } catch (Exception $e) {
                $this->Input->setErrors(['api' => ['create' => $e->getMessage()]]);

                return;
            }

            $this->dispatchAdminNotification($package, $username, isset($vars['client_id']) ? $vars['client_id'] : null);
        } else {
            // Admin may pre-fill these when adding the service manually.
            $username = isset($vars['reliablesite_username']) ? $vars['reliablesite_username'] : '';
        }

        $server_id = isset($vars['reliablesite_server_id']) ? $vars['reliablesite_server_id'] : '';
        $pending = $server_id !== '' ? '0' : '1';

        return [
            ['key' => 'reliablesite_username', 'value' => $username, 'encrypted' => 0],
            ['key' => 'reliablesite_server_id', 'value' => $server_id, 'encrypted' => 0],
            [
                'key' => 'reliablesite_server_label',
                'value' => isset($vars['reliablesite_server_label']) ? $vars['reliablesite_server_label'] : '',
                'encrypted' => 0,
            ],
            ['key' => 'reliablesite_pending', 'value' => $pending, 'encrypted' => 0],
            [
                'key' => 'reliablesite_pending_since',
                'value' => $pending === '1' ? gmdate('Y-m-d\TH:i:s\Z') : '',
                'encrypted' => 0,
            ],
        ];
    }

    /**
     * Lets an admin manually adjust the ReliableSite service fields.
     *
     * @return array Service field list
     */
    public function editService($package, $service, array $vars = null, $parent_package = null, $parent_service = null)
    {
        $current = $this->serviceFieldsToObject($service->fields);
        $out = [];
        foreach ($this->serviceFieldKeys() as $key) {
            $value = isset($vars[$key]) ? $vars[$key] : (isset($current->{$key}) ? $current->{$key} : '');
            $out[] = ['key' => $key, 'value' => $value, 'encrypted' => 0];
        }

        return $out;
    }

    /**
     * Suspends a service by powering off the assigned server.
     */
    public function suspendService($package, $service, $parent_package = null, $parent_service = null)
    {
        $fields = $this->serviceFieldsToObject($service->fields);
        $server_id = isset($fields->reliablesite_server_id) ? $fields->reliablesite_server_id : '';
        if ($server_id !== '') {
            $api = $this->getApi();
            $this->log('reliablesite|poweroff', serialize(['serverId' => $server_id]), 'input', true);
            $api->ServerPoweOff($server_id);
        }

        return null;
    }

    /**
     * Unsuspends a service. Powering the server back on is left to the customer.
     */
    public function unsuspendService($package, $service, $parent_package = null, $parent_service = null)
    {
        return null;
    }

    /**
     * Cancels a service by returning the assigned server to the unassigned pool.
     */
    public function cancelService($package, $service, $parent_package = null, $parent_service = null)
    {
        $fields = $this->serviceFieldsToObject($service->fields);
        $server_id = isset($fields->reliablesite_server_id) ? $fields->reliablesite_server_id : '';
        if ($server_id !== '') {
            $api = $this->getApi();
            $this->log('reliablesite|unassign', serialize(['serverId' => $server_id]), 'input', true);
            $api->unassignServer($server_id);
        }

        return null;
    }

    /**
     * Renews a service. Nothing to do upstream for physical servers.
     */
    public function renewService($package, $service, $parent_package = null, $parent_service = null)
    {
        return null;
    }

    /**
     * Physical dedicated servers cannot be upgraded in place; the billing
     * package may still change without touching the assigned hardware.
     */
    public function changeServicePackage($package_from, $package_to, $service, $parent_package = null, $parent_service = null)
    {
        return null;
    }

    public function validateService($package, array $vars = null)
    {
        return true;
    }

    public function validateServiceEdit($service, array $vars = null)
    {
        return true;
    }

    // =================================================================
    // Service order/edit fields
    // =================================================================

    public function getClientAddFields($package, $vars = null)
    {
        Loader::loadHelpers($this, ['Html']);
        $fields = new ModuleFields();
        $fields->setHtml(
            '<p class="alert alert-info">' . Language::_('Reliablesite.service_field.client_notice', true) . '</p>'
        );

        return $fields;
    }

    public function getAdminAddFields($package, $vars = null)
    {
        Loader::loadHelpers($this, ['Html']);
        $fields = new ModuleFields();

        $username = $fields->label(
            Language::_('Reliablesite.service_field.username', true),
            'reliablesite_username'
        );
        $username->attach(
            $fields->fieldText(
                'reliablesite_username',
                (isset($vars->reliablesite_username) ? $vars->reliablesite_username : null),
                ['id' => 'reliablesite_username']
            )
        );
        $fields->setField($username);

        $server = $fields->label(
            Language::_('Reliablesite.service_field.server_id', true),
            'reliablesite_server_id'
        );
        $server->attach(
            $fields->fieldText(
                'reliablesite_server_id',
                (isset($vars->reliablesite_server_id) ? $vars->reliablesite_server_id : null),
                ['id' => 'reliablesite_server_id']
            )
        );
        $fields->setField($server);

        return $fields;
    }

    public function getAdminEditFields($package, $vars = null)
    {
        return $this->getAdminAddFields($package, $vars);
    }

    // =================================================================
    // Service info panels
    // =================================================================

    public function getAdminServiceInfo($service, $package)
    {
        return $this->serviceInfo($service, $package, false);
    }

    public function getClientServiceInfo($service, $package)
    {
        return $this->serviceInfo($service, $package, true);
    }

    private function serviceInfo($service, $package, $client)
    {
        $this->view = new View($client ? 'client_service_info' : 'admin_service_info', 'default');
        $this->view->base_uri = $this->base_uri;
        $this->view->setDefaultView('components' . DS . 'modules' . DS . 'reliablesite' . DS);
        Loader::loadHelpers($this, ['Form', 'Html']);

        $fields = $this->serviceFieldsToObject($service->fields);
        $server = [];
        $server_id = isset($fields->reliablesite_server_id) ? $fields->reliablesite_server_id : '';
        if ($server_id !== '') {
            $resp = $this->getApi()->getServerDetails($server_id);
            if (!empty($resp['success']) && is_array($resp['data'])) {
                $server = isset($resp['data']['server']) ? $resp['data']['server'] : $resp['data'];
            }
        }

        $this->view->set('service', $service);
        $this->view->set('service_fields', $fields);
        $this->view->set('server', $server);
        $this->view->set('pending', (isset($fields->reliablesite_pending) && $fields->reliablesite_pending === '1'));

        return $this->wrap($this->view->fetch(), !$client);
    }

    // =================================================================
    // Service tabs
    // =================================================================

    public function getClientTabs($package)
    {
        return [
            'tabClientManage' => [
                'name' => Language::_('Reliablesite.tab.client_manage', true),
                'icon' => 'fas fa-server',
            ],
        ];
    }

    public function getAdminTabs($package)
    {
        return [
            'tabAdminManage' => Language::_('Reliablesite.tab.admin_manage', true),
        ];
    }

    /**
     * Client self-service "Manage Server" tab with sub-page routing (?p=).
     */
    public function tabClientManage($package, $service, array $get = null, array $post = null, array $files = null)
    {
        $fields = $this->serviceFieldsToObject($service->fields);
        $server_id = isset($fields->reliablesite_server_id) ? $fields->reliablesite_server_id : '';

        if ($service->status !== 'active' || $server_id === '') {
            return $this->renderView('client_pending', ['service' => $service]);
        }

        $page = isset($get['p']) ? preg_replace('/[^a-z]/', '', $get['p']) : 'overview';

        return $this->clientManage($package, $service, $server_id, $page, $post);
    }

    /**
     * Admin per-service tab: links to assign/manage the server.
     */
    public function tabAdminManage($package, $service, array $get = null, array $post = null, array $files = null)
    {
        $this->setMyModule();
        $fields = $this->serviceFieldsToObject($service->fields);
        $server_id = isset($fields->reliablesite_server_id) ? $fields->reliablesite_server_id : '';

        $base = $this->base_uri . 'settings/company/modules/addrow/' . $this->getModuleId();

        return $this->renderView('tab_admin_manage', [
            'is_assigned' => ($server_id !== ''),
            'server_id' => $server_id,
            'server_label' => isset($fields->reliablesite_server_label) ? $fields->reliablesite_server_label : '',
            'username' => isset($fields->reliablesite_username) ? $fields->reliablesite_username : '',
            'manage_link' => $base . '/?scr=manageserver&rsid=' . urlencode($server_id),
            'assign_link' => $base . '/?scr=pendingorders',
        ]);
    }

    /**
     * Renders the client management sub-pages and handles their POST actions.
     */
    private function clientManage($package, $service, $server_id, $page, $post)
    {
        $api = $this->getApi();
        $notice = null;
        $error = null;

        // Handle POST actions.
        if (!empty($post['action'])) {
            $result = $this->handleClientAction($api, $server_id, $service, $post);
            $notice = $result['notice'];
            $error = $result['error'];
            if (!empty($result['page'])) {
                $page = $result['page'];
            }
        }

        $data = [
            'service' => $service,
            'package' => $package,
            'server_id' => $server_id,
            'page' => $page,
            'notice' => $notice,
            'error' => $error,
            'client_ip' => $this->clientIp(),
        ];

        switch ($page) {
            case 'os':
                $data['compatible_os'] = $this->apiData($api->GetCompatibleOS($server_id));
                $data['install_status'] = $this->apiData($api->GetOSInstallStatus($server_id));

                return $this->renderView('client_os', $data, true);
            case 'kvm':
                $data['kvm'] = $this->apiData($api->getKVMDetails($server_id));

                return $this->renderView('client_kvm', $data, true);
            case 'bandwidth':
                $period = isset($post['period']) ? preg_replace('/[^A-Za-z]/', '', $post['period']) : 'Day';
                $data['period'] = $period;
                $data['bandwidth_series'] = $this->bandwidthSeries(
                    $this->apiData($api->GetBandwidthGraph($server_id, $period))
                );

                return $this->renderView('client_bandwidth', $data, true);
            case 'backups':
                $data['backups'] = $api->extractList($this->apiData($api->GetCustomerBackupStorage($server_id)));

                return $this->renderView('client_backups', $data, true);
            case 'rdns':
                $details = $this->apiData($api->getServerDetails($server_id));
                $data['ip_blocks'] = isset($details['ipAddressList']) ? $details['ipAddressList'] : [];

                return $this->renderView('client_rdns', $data, true);
            case 'mac':
                $details = $this->apiData($api->getServerDetails($server_id));
                $data['ip_blocks'] = isset($details['ipAddressList']) ? $details['ipAddressList'] : [];

                return $this->renderView('client_mac', $data, true);
            case 'ddos':
                $data = array_merge($data, $this->buildDdosData($api, $server_id));

                return $this->renderView('client_ddos', $data, true);
            case 'overview':
            default:
                $details = $this->apiData($api->getServerDetails($server_id));
                $data['server'] = isset($details['server']) ? $details['server'] : $details;
                $data['ip_blocks'] = isset($details['ipAddressList']) ? $details['ipAddressList'] : [];

                return $this->renderView('client_overview', $data, true);
        }
    }

    /**
     * Dispatches a client POST action to the API.
     *
     * @return array { notice, error, page }
     */
    private function handleClientAction($api, $server_id, $service, array $post)
    {
        $out = ['notice' => null, 'error' => null, 'page' => null];
        $action = preg_replace('/[^a-z_]/', '', $post['action']);

        $resp = ['success' => true, 'errors' => []];
        switch ($action) {
            case 'power_on':
                $resp = $api->ServerPoweOn($server_id);
                $out['notice'] = Language::_('Reliablesite.client.power_on_ok', true);
                break;
            case 'power_off':
                $resp = $api->ServerPoweOff($server_id);
                $out['notice'] = Language::_('Reliablesite.client.power_off_ok', true);
                break;
            case 'enable_kvm':
                $ip = isset($post['remote_ip']) ? trim($post['remote_ip']) : $this->clientIp();
                $resp = $api->ServerEnableKVM($server_id, $ip);
                $out['notice'] = Language::_('Reliablesite.client.kvm_enabled', true);
                $out['page'] = 'kvm';
                break;
            case 'disable_kvm':
                $resp = $api->ServerDisableKVM($server_id);
                $out['notice'] = Language::_('Reliablesite.client.kvm_disabled', true);
                $out['page'] = 'kvm';
                break;
            case 'os_install':
                $resp = $api->OSInstallStart(
                    $server_id,
                    isset($post['server_ip']) ? trim($post['server_ip']) : '',
                    isset($post['os_id']) ? $post['os_id'] : '',
                    isset($post['partitioning_scheme_id']) ? $post['partitioning_scheme_id'] : '',
                    isset($post['license_key']) ? trim($post['license_key']) : ''
                );
                $out['notice'] = Language::_('Reliablesite.client.os_started', true);
                $out['page'] = 'os';
                break;
            case 'os_cancel':
                $resp = $api->OSInstallCancel($server_id);
                $out['notice'] = Language::_('Reliablesite.client.os_canceled', true);
                $out['page'] = 'os';
                break;
            case 'set_rdns':
                $resp = $api->setReverseDNSRecord(
                    isset($post['ip_address_id']) ? $post['ip_address_id'] : '',
                    isset($post['ip_address']) ? trim($post['ip_address']) : '',
                    isset($post['rdns']) ? trim($post['rdns']) : ''
                );
                $out['notice'] = Language::_('Reliablesite.client.rdns_set', true);
                $out['page'] = 'rdns';
                break;
            case 'set_backup':
                $resp = $api->SetFTPAccount(
                    isset($post['ftp_account_id']) ? $post['ftp_account_id'] : '',
                    !empty($post['enable']),
                    isset($post['password']) ? $post['password'] : ''
                );
                $out['notice'] = Language::_('Reliablesite.client.backup_saved', true);
                $out['page'] = 'backups';
                break;
            case 'set_mac':
                $custom = !empty($post['custom_mac_enable']);
                $resp = $api->SetMacAddess(
                    $server_id,
                    isset($post['ip']) ? trim($post['ip']) : '',
                    $custom,
                    isset($post['custom_mac']) ? trim($post['custom_mac']) : ''
                );
                $out['notice'] = Language::_('Reliablesite.client.mac_saved', true);
                $out['page'] = 'mac';
                break;
            case 'ddos_assign':
                $resp = $api->assignDdosProfileIp(
                    isset($post['data_source_id']) ? $post['data_source_id'] : '',
                    isset($post['profile_id']) ? $post['profile_id'] : '',
                    isset($post['ip_input']) ? trim($post['ip_input']) : ''
                );
                $out['notice'] = Language::_('Reliablesite.client.ddos_assigned', true);
                $out['page'] = 'ddos';
                break;
            case 'ddos_remove':
                $resp = $api->removeDdosProfileIp(
                    isset($post['data_source_id']) ? $post['data_source_id'] : '',
                    isset($post['profile_id']) ? $post['profile_id'] : '',
                    isset($post['ip_id']) ? $post['ip_id'] : ''
                );
                $out['notice'] = Language::_('Reliablesite.client.ddos_removed', true);
                $out['page'] = 'ddos';
                break;
        }

        if (empty($resp['success'])) {
            $out['notice'] = null;
            $out['error'] = !empty($resp['errors']) ? implode('; ', $resp['errors']) : Language::_('Reliablesite.client.action_failed', true);
        }

        return $out;
    }

    // =================================================================
    // Admin screens
    // =================================================================

    /**
     * Renders the credential + settings screen (add or edit).
     */
    /**
     * Renders the credential + settings form (render-only). Persistence is handled
     * by Blesta's add-row / edit-row controller flow, which invokes
     * addModuleRow()/editModuleRow() and shows any validation errors itself.
     *
     * @param array $vars Posted values on error redisplay (else empty)
     * @param bool $edit Whether this is an edit
     * @param stdClass|null $module_row The existing row (edit mode)
     * @return string
     */
    private function settingsScreen(array &$vars, $edit, $module_row = null)
    {
        Loader::loadModels($this, ['PackageGroups', 'Currencies']);

        // Posted values (error redisplay) win; otherwise show the stored row meta.
        if (empty($vars) && $module_row && isset($module_row->meta)) {
            $vars = (array) $module_row->meta;
        }

        $module_id = $module_row ? $module_row->module_id : $this->getModuleId();
        if ($edit && $module_row) {
            $form_action = $this->base_uri . 'settings/company/modules/editrow/' . $module_id . '/' . $module_row->id;
        } else {
            $form_action = $this->base_uri . 'settings/company/modules/addrow/' . $module_id;
        }

        $company_id = Configure::get('Blesta.company_id');
        $groups = $this->PackageGroups->getAll($company_id, 'standard');
        $group_options = ['' => Language::_('Reliablesite.settings.select_group', true)];
        foreach ((array) $groups as $g) {
            $group_options[$g->id] = $g->name;
        }
        $currency_options = [];
        foreach ((array) $this->Currencies->getAll($company_id) as $c) {
            $currency_options[$c->code] = $c->code;
        }

        // Offer the Order API payment gateways as a dropdown when they can be
        // fetched (credentials saved + API reachable); otherwise the view falls
        // back to a free-text field.
        $payment_methods = [];
        if ($edit) {
            try {
                $payment_methods = $this->getOrderApi()->paymentMethodOptions();
            } catch (Exception $e) {
                // Non-fatal; the settings form still renders with a text field.
                $this->recoverRecord();
            }
        }

        $content = $this->renderViewRaw('settings', [
            'vars' => (object) $vars,
            'edit' => $edit,
            'form_action' => $form_action,
            'group_options' => $group_options,
            'currency_options' => $currency_options,
            'payment_methods' => $payment_methods,
            'home_link' => $this->base_uri . 'settings/company/modules/manage/' . $module_id,
        ]);

        // Show the tab bar only when a credential exists (edit mode), inside the wrapper.
        return $this->wrap(($edit ? $this->manageNav('settings') : '') . $content);
    }

    /**
     * Pending Orders screen: list awaiting-assignment services + assign/cancel.
     */
    private function pendingOrdersScreen(array &$vars)
    {
        $assignment = $this->loadAssignment();
        $notice = null;
        $error = null;

        if (!empty($_GET['action'])) {
            if ($_GET['action'] === 'assign' && !empty($_GET['service_id']) && !empty($_GET['rs_server_id'])) {
                $res = $assignment->assign(
                    (int) $_GET['service_id'],
                    $_GET['rs_server_id'],
                    isset($_GET['rs_server_label']) ? $_GET['rs_server_label'] : null
                );
                $notice = !empty($res['success']) ? Language::_('Reliablesite.pending.assigned', true) : null;
                $error = !empty($res['errors']) ? implode('; ', $res['errors']) : null;
            } elseif ($_GET['action'] === 'cancel' && !empty($_GET['service_id'])) {
                Loader::loadModels($this, ['Services']);
                $this->Services->cancel((int) $_GET['service_id'], ['date_canceled' => 'now', 'use_module' => 'true']);
                $notice = Language::_('Reliablesite.pending.canceled', true);
            } elseif ($_GET['action'] === 'order' && !empty($_GET['service_id'])) {
                $res = $this->loadOrderService()->placeOrder((int) $_GET['service_id'], [
                    'hostname' => isset($_GET['hostname']) ? $_GET['hostname'] : '',
                    'paymentMethod' => isset($_GET['payment_method']) ? $_GET['payment_method'] : '',
                    'promoCode' => isset($_GET['promo_code']) ? $_GET['promo_code'] : '',
                ]);
                $notice = !empty($res['success']) ? Language::_('Reliablesite.order.placed', true) : null;
                $error = !empty($res['errors']) ? implode('; ', $res['errors']) : null;
            }
        }

        $pending = $assignment->pendingServices();

        // Order tracking state + available payment gateways for the Create Order
        // modal. Both only matter when there are orders to place.
        $order_states = [];
        $payment_methods = [];
        if (!empty($pending)) {
            $ids = [];
            foreach ($pending as $svc) {
                $ids[] = (int) $svc->id;
            }
            $order_states = $this->loadOrderService()->getOrderStates($ids);
            $payment_methods = $this->getOrderApi()->paymentMethodOptions();
        }

        // Build a dropdown of unassigned servers so admins can pick by name.
        // Only fetched when there are orders to assign (avoids needless API calls).
        $server_options = [];
        if (!empty($pending)) {
            $api = $this->getApi();
            $page = 1;
            do {
                $resp = $api->getServers($page, 'true');
                $list = $api->extractList($this->apiData($resp));
                foreach ($list as $srv) {
                    $sid = isset($srv['serverId']) ? $srv['serverId'] : (isset($srv['id']) ? $srv['id'] : '');
                    if ($sid === '' || isset($server_options[(string) $sid])) {
                        continue;
                    }
                    $label = isset($srv['serverLabel']) ? $srv['serverLabel']
                        : (isset($srv['label']) ? $srv['label'] : '');
                    $dc = isset($srv['dataCenterLabel']) ? $srv['dataCenterLabel']
                        : (isset($srv['dataCenterName']) ? $srv['dataCenterName'] : '');
                    $display = '#' . $sid
                        . ($label !== '' ? ' - ' . $label : '')
                        . ($dc !== '' ? ' (' . $dc . ')' : '');
                    $server_options[(string) $sid] = [
                        'display' => $display,
                        'label' => ($label !== '' ? $label : $display),
                    ];
                }
                $page++;
            } while (!empty($list) && $page <= 20);
        }

        return $this->renderManageScreen('pending_orders', 'pendingorders', [
            'pending' => $pending,
            'server_options' => $server_options,
            'order_states' => $order_states,
            'payment_methods' => $payment_methods,
            'notice' => $notice,
            'error' => $error,
            'home_link' => $this->base_uri . 'settings/company/modules/manage/' . $this->getModuleId(),
        ]);
    }

    /**
     * Servers screen: list ReliableSite servers + assignment state.
     */
    private function serversScreen(array &$vars)
    {
        $api = $this->getApi();
        $page = isset($_GET['page']) ? max(1, (int) $_GET['page']) : 1;
        $search = isset($_GET['search']) ? trim($_GET['search']) : '';
        $unassigned = isset($_GET['unassigned']) ? ($_GET['unassigned'] === '1' ? 'true' : 'false') : 'true';

        $resp = $api->getServers($page, $unassigned, $search);
        $servers = $this->apiData($resp);
        $list = $api->extractList($servers);

        $assignment = $this->loadAssignment();

        // The list endpoint doesn't include datacenter, but it's far more useful
        // to show which local client a server is assigned to (derived locally).
        $server_ids = [];
        foreach ($list as $srv) {
            $sid = isset($srv['serverId']) ? $srv['serverId'] : (isset($srv['id']) ? $srv['id'] : '');
            if ($sid !== '') {
                $server_ids[] = (string) $sid;
            }
        }
        $assigned = $this->assignedServersByServerId($server_ids);

        return $this->renderManageScreen('servers', 'servers', [
            'servers' => $list,
            'assigned' => $assigned,
            'client_base' => $this->base_uri . 'clients/view/',
            'page' => $page,
            'search' => $search,
            'unassigned' => $unassigned,
            'pending_count' => $assignment->pendingCount(),
            'errors' => !empty($resp['errors']) ? $resp['errors'] : [],
            'manage_base' => $this->base_uri . 'settings/company/modules/addrow/' . $this->getModuleId(),
            'home_link' => $this->base_uri . 'settings/company/modules/manage/' . $this->getModuleId(),
        ]);
    }

    /**
     * Maps ReliableSite server ids to the local Blesta service/client they are
     * assigned to (scoped to this module's packages).
     *
     * @param array $serverIds
     * @return array server_id => { service_id, client_id, name }
     */
    private function assignedServersByServerId(array $serverIds)
    {
        $serverIds = array_values(array_filter(array_map('strval', $serverIds), 'strlen'));
        if (empty($serverIds)) {
            return [];
        }

        Loader::loadModels($this, ['Record']);
        $rows = $this->Record->select([
                'service_fields.value' => 'server_id',
                'services.id' => 'service_id',
                'clients.id' => 'client_id',
                'contacts.first_name',
                'contacts.last_name',
                'contacts.email',
            ])
            ->from('service_fields')
            ->innerJoin('services', 'services.id', '=', 'service_fields.service_id', false)
            ->innerJoin('package_pricing', 'package_pricing.id', '=', 'services.pricing_id', false)
            ->innerJoin('packages', 'packages.id', '=', 'package_pricing.package_id', false)
            ->leftJoin('clients', 'clients.id', '=', 'services.client_id', false)
            ->on('contacts.contact_type', '=', 'primary')
            ->leftJoin('contacts', 'contacts.client_id', '=', 'clients.id', false)
            ->where('packages.module_id', '=', $this->getModuleId())
            ->where('service_fields.key', '=', 'reliablesite_server_id')
            ->where('service_fields.value', 'in', $serverIds)
            ->where('services.status', '!=', 'canceled')
            ->fetchAll();

        $map = [];
        foreach ($rows as $r) {
            $name = trim($r->first_name . ' ' . $r->last_name);
            if ($name === '') {
                $name = $r->email;
            }
            $map[(string) $r->server_id] = [
                'service_id' => $r->service_id,
                'client_id' => $r->client_id,
                'name' => $name,
            ];
        }

        return $map;
    }

    /**
     * Manage one assigned server with a tabbed layout mirroring the client-area
     * management tabs (overview, OS, KVM, bandwidth, backups, rDNS, MAC, DDoS).
     * Actions use GET (so the add-row controller doesn't treat them as a row add).
     */
    private function manageServerScreen(array &$vars)
    {
        $api = $this->getApi();
        $server_id = isset($_GET['rsid']) ? preg_replace('/[^A-Za-z0-9_-]/', '', $_GET['rsid']) : '';
        $page = isset($_GET['p']) ? preg_replace('/[^a-z]/', '', $_GET['p']) : 'overview';
        $notice = null;
        $error = null;

        $base = $this->base_uri . 'settings/company/modules/addrow/' . $this->getModuleId();
        $srv_base = $base . '/?scr=manageserver&rsid=' . urlencode($server_id) . '&';

        $data = [
            'server_id' => $server_id,
            'page' => $page,
            'srv_base' => $srv_base,
            'back_link' => $base . '/?scr=servers',
            'client_ip' => $this->clientIp(),
        ];

        if ($server_id === '') {
            $data['notice'] = null;
            $data['error'] = null;

            return $this->renderManageScreen('manage_server', 'servers', $data);
        }

        // Handle GET actions.
        if (!empty($_GET['action'])) {
            if ($_GET['action'] === 'unassign') {
                $res = $this->loadAssignment()->unassign($server_id);
                $notice = !empty($res['success']) ? Language::_('Reliablesite.servers.unassigned', true) : null;
                $error = !empty($res['errors']) ? implode('; ', $res['errors']) : null;
            } else {
                $result = $this->handleClientAction($api, $server_id, null, $_GET);
                $notice = $result['notice'];
                $error = $result['error'];
                if (!empty($result['page'])) {
                    $page = $result['page'];
                    $data['page'] = $page;
                }
            }
        }

        // Per-tab data.
        switch ($page) {
            case 'os':
                $data['compatible_os'] = $this->apiData($api->GetCompatibleOS($server_id));
                break;
            case 'kvm':
                $data['kvm'] = $this->apiData($api->getKVMDetails($server_id));
                break;
            case 'bandwidth':
                $bwperiod = isset($_GET['period']) ? preg_replace('/[^A-Za-z]/', '', $_GET['period']) : 'Day';
                $data['period'] = $bwperiod;
                $data['bandwidth_series'] = $this->bandwidthSeries(
                    $this->apiData($api->GetBandwidthGraph($server_id, $bwperiod))
                );
                break;
            case 'backups':
                $data['backups'] = $api->extractList($this->apiData($api->GetCustomerBackupStorage($server_id)));
                break;
            case 'rdns':
            case 'mac':
                $details = $this->apiData($api->getServerDetails($server_id));
                $data['ip_blocks'] = isset($details['ipAddressList']) ? $details['ipAddressList'] : [];
                break;
            case 'ddos':
                $data = array_merge($data, $this->buildDdosData($api, $server_id));
                break;
            case 'overview':
            default:
                $details = $this->apiData($api->getServerDetails($server_id));
                $data['server'] = isset($details['server']) ? $details['server'] : $details;
                $data['ip_blocks'] = isset($details['ipAddressList']) ? $details['ipAddressList'] : [];
                break;
        }

        $data['notice'] = $notice;
        $data['error'] = $error;

        return $this->renderManageScreen('manage_server', 'servers', $data);
    }

    /**
     * Catalog screen: import / freeze / untrack / sync now.
     */
    private function catalogScreen(array &$vars)
    {
        $sync = $this->loadCatalogSync();
        $notice = null;
        $error = null;
        $group_configured = ((int) $this->getSetting('import_package_group') > 0);

        // Drop mappings for packages deleted in Blesta so the list stays accurate.
        $sync->pruneOrphanedMappings();

        if (!empty($_GET['action'])) {
            $action = $_GET['action'];
            if ($action === 'syncnow') {
                $stats = $sync->run('manual');
                $notice = Language::_('Reliablesite.catalog.synced', true) . ' '
                    . sprintf('(updated %d, frozen %d, not found %d, failed %d)',
                        $stats['updated'], $stats['skipped_frozen'], $stats['not_found'], $stats['failed']);
            } elseif ($action === 'importall') {
                if (!$group_configured) {
                    $error = Language::_('Reliablesite.catalog.no_group', true);
                } else {
                    $catalog = $sync->fetchCatalog();
                    $stats = $sync->importAll($catalog['products']);
                    $notice = sprintf(
                        Language::_('Reliablesite.catalog.import_all_done', true),
                        $stats['created'], $stats['skipped'], $stats['failed']
                    );
                }
            } elseif ($action === 'reformat') {
                $catalog = $sync->fetchCatalog();
                $stats = $sync->reformatNames($catalog['products']);
                $notice = sprintf(
                    Language::_('Reliablesite.catalog.reformat_done', true),
                    $stats['updated'], $stats['frozen'], $stats['not_found']
                );
            } elseif ($action === 'import' && !empty($_GET['inventory_id'])) {
                $catalog = $sync->fetchCatalog();
                $row = $this->findCatalogRow($catalog['products'], $_GET['inventory_id']);
                if ($row) {
                    $res = $sync->importProduct($row);
                    $notice = !empty($res['success']) ? Language::_('Reliablesite.catalog.imported', true) : null;
                    $error = !empty($res['errors']) ? implode('; ', $res['errors']) : null;
                } else {
                    $error = Language::_('Reliablesite.catalog.row_missing', true);
                }
            } elseif ($action === 'freeze' && !empty($_GET['package_id'])) {
                $sync->setFrozen((int) $_GET['package_id'], $_GET['frozen'] == '1');
                $notice = Language::_('Reliablesite.catalog.freeze_saved', true);
            } elseif ($action === 'untrack' && !empty($_GET['package_id'])) {
                $sync->untrack((int) $_GET['package_id']);
                $notice = Language::_('Reliablesite.catalog.untracked', true);
            }
        }

        $catalog = $sync->fetchCatalog(!empty($_GET['refresh']));
        $mappings = $sync->getMappings();
        $products = !empty($catalog['success']) ? $catalog['products'] : [];

        // Annotate rows with derived fields used by the table + filters.
        $locations = [];
        $annotated = [];
        foreach ($products as $row) {
            if (!is_array($row)) {
                continue;
            }
            $invId = isset($row['product_id']) ? (string) $row['product_id']
                : (isset($row['id']) ? (string) $row['id'] : '');
            $mapping = isset($mappings[$invId]) ? $mappings[$invId] : null;
            $row['_inv_id'] = $invId;
            $row['_profile'] = $sync->productProfile($row);
            $row['_stock'] = isset($row['stock']) ? (int) $row['stock'] : 0;
            $row['_base_price'] = isset($row['pricing']['monthly'])
                ? (float) $row['pricing']['monthly']
                : (isset($row['monthly_price']) ? (float) $row['monthly_price'] : 0.0);
            $row['_imported'] = (bool) $mapping;
            $row['_frozen'] = $mapping ? ((int) $mapping->frozen === 1) : false;
            $row['_package_id'] = $mapping ? $mapping->package_id : null;
            $loc = isset($row['data_center']) ? $row['data_center'] : '';
            if ($loc !== '') {
                $locations[$loc] = $loc;
            }
            $annotated[] = $row;
        }
        ksort($locations);

        // Read filters from the query string.
        $filters = [
            'search' => isset($_GET['search']) ? trim($_GET['search']) : '',
            'in_stock' => isset($_GET['in_stock']) ? $_GET['in_stock'] : '',
            'location' => isset($_GET['location']) ? $_GET['location'] : '',
            'profile' => isset($_GET['profile']) ? $_GET['profile'] : '',
            'imported' => isset($_GET['imported']) ? $_GET['imported'] : '',
        ];

        $filtered = [];
        foreach ($annotated as $row) {
            if ($filters['in_stock'] === '1' && $row['_stock'] <= 0) {
                continue;
            }
            if ($filters['in_stock'] === '0' && $row['_stock'] > 0) {
                continue;
            }
            if ($filters['location'] !== ''
                && (!isset($row['data_center']) || $row['data_center'] !== $filters['location'])) {
                continue;
            }
            if ($filters['profile'] !== '' && $row['_profile'] !== $filters['profile']) {
                continue;
            }
            if ($filters['imported'] === '1' && !$row['_imported']) {
                continue;
            }
            if ($filters['imported'] === '0' && $row['_imported']) {
                continue;
            }
            if ($filters['search'] !== '') {
                $hay = strtolower(implode(' ', [
                    isset($row['description']) ? $row['description'] : '',
                    $row['_inv_id'],
                    isset($row['data_center']) ? $row['data_center'] : '',
                    isset($row['cpu']['raw_string']) ? $row['cpu']['raw_string'] : '',
                ]));
                if (strpos($hay, strtolower($filters['search'])) === false) {
                    continue;
                }
            }
            $filtered[] = $row;
        }

        return $this->renderManageScreen('catalog', 'catalog', [
            'products' => $filtered,
            'total_count' => count($annotated),
            'shown_count' => count($filtered),
            'locations' => $locations,
            'filters' => $filters,
            'fetch_error' => empty($catalog['success']) ? implode('; ', $catalog['errors']) : null,
            'notice' => $notice,
            'error' => $error,
            'group_configured' => $group_configured,
            'home_link' => $this->base_uri . 'settings/company/modules/manage/' . $this->getModuleId(),
        ]);
    }

    /**
     * Customers screen: list ReliableSite reseller customers.
     */
    private function customersScreen(array &$vars)
    {
        $api = $this->getApi();
        $notice = null;
        $error = null;

        // Unassign a server from a customer here, returning the service to pending.
        if (!empty($_GET['action']) && $_GET['action'] === 'unassign' && !empty($_GET['rsid'])) {
            $res = $this->loadAssignment()->unassign($_GET['rsid']);
            $notice = !empty($res['success']) ? Language::_('Reliablesite.servers.unassigned', true) : null;
            $error = !empty($res['errors']) ? implode('; ', $res['errors']) : null;
        }

        $page = isset($_GET['page']) ? max(1, (int) $_GET['page']) : 1;
        $search = isset($_GET['search']) ? trim($_GET['search']) : '';
        $resp = $api->getCustomers($page, $search);
        $customers = $api->extractList($this->apiData($resp));

        // Match each ReliableSite customer to a local Blesta client by primary
        // contact email, so admins can see who a customer maps to (mirrors the
        // Paymenter "Linked user" column - far more useful than a blank status).
        $linked = $this->matchClientsByEmail($customers);

        // Servers currently assigned to each customer (so admins can unassign here).
        $usernames = [];
        foreach ($customers as $c) {
            $u = isset($c['userName']) ? $c['userName'] : (isset($c['username']) ? $c['username'] : '');
            if ($u !== '') {
                $usernames[] = $u;
            }
        }
        $assigned_servers = $this->assignedServersByUsername($usernames);

        // Adding a customer is a dedicated POST to the edit-row endpoint so the
        // password is never placed in the URL/access logs. The result is shown
        // via a one-shot flash on the module home after Blesta's redirect.
        $add_action = $this->base_uri . 'settings/company/modules/editrow/'
            . $this->getModuleId() . '/' . $this->getModuleRowId();

        return $this->renderManageScreen('customers', 'customers', [
            'customers' => $customers,
            'linked' => $linked,
            'assigned_servers' => $assigned_servers,
            'client_base' => $this->base_uri . 'clients/view/',
            'page' => $page,
            'search' => $search,
            'add_action' => $add_action,
            'notice' => $notice,
            'error' => $error,
            'errors' => !empty($resp['errors']) ? $resp['errors'] : [],
            'home_link' => $this->base_uri . 'settings/company/modules/manage/' . $this->getModuleId(),
        ]);
    }

    /**
     * Maps ReliableSite usernames to the servers currently assigned to them
     * (scoped to this module's packages). Returns lowercased-username => list of
     * { service_id, server_id, server_label }.
     *
     * @param array $usernames
     * @return array
     */
    private function assignedServersByUsername(array $usernames)
    {
        $want = [];
        foreach ($usernames as $u) {
            if ($u !== '') {
                $want[strtolower((string) $u)] = true;
            }
        }
        if (empty($want)) {
            return [];
        }

        Loader::loadModels($this, ['Record']);
        $rows = $this->Record->select(['service_fields.service_id', 'service_fields.key', 'service_fields.value'])
            ->from('service_fields')
            ->innerJoin('services', 'services.id', '=', 'service_fields.service_id', false)
            ->innerJoin('package_pricing', 'package_pricing.id', '=', 'services.pricing_id', false)
            ->innerJoin('packages', 'packages.id', '=', 'package_pricing.package_id', false)
            ->where('packages.module_id', '=', $this->getModuleId())
            ->where('services.status', '!=', 'canceled')
            ->where('service_fields.key', 'in', [
                'reliablesite_username',
                'reliablesite_server_id',
                'reliablesite_server_label',
            ])
            ->fetchAll();

        $byService = [];
        foreach ($rows as $r) {
            $byService[$r->service_id][$r->key] = $r->value;
        }

        $map = [];
        foreach ($byService as $service_id => $f) {
            $u = isset($f['reliablesite_username']) ? strtolower($f['reliablesite_username']) : '';
            $srv = isset($f['reliablesite_server_id']) ? $f['reliablesite_server_id'] : '';
            if ($u === '' || $srv === '' || !isset($want[$u])) {
                continue;
            }
            $map[$u][] = [
                'service_id' => $service_id,
                'server_id' => $srv,
                'server_label' => isset($f['reliablesite_server_label']) ? $f['reliablesite_server_label'] : '',
            ];
        }

        return $map;
    }

    /**
     * Maps ReliableSite customer emails to local Blesta clients (by primary
     * contact email). Returns lowercased-email => { client_id, id_code, name }.
     *
     * @param array $customers
     * @return array
     */
    private function matchClientsByEmail(array $customers)
    {
        $emails = [];
        foreach ($customers as $c) {
            $email = isset($c['emailAddress']) ? $c['emailAddress'] : (isset($c['email']) ? $c['email'] : '');
            if ($email !== '') {
                $emails[] = $email;
            }
        }
        if (empty($emails)) {
            return [];
        }

        Loader::loadModels($this, ['Record']);
        $rows = $this->Record->select([
                'contacts.email',
                'clients.id' => 'client_id',
                'clients.id_value',
                'contacts.first_name',
                'contacts.last_name',
            ])
            ->from('contacts')
            ->innerJoin('clients', 'clients.id', '=', 'contacts.client_id', false)
            ->where('contacts.contact_type', '=', 'primary')
            ->where('contacts.email', 'in', $emails)
            ->fetchAll();

        $map = [];
        foreach ($rows as $r) {
            $name = trim($r->first_name . ' ' . $r->last_name);
            $map[strtolower($r->email)] = [
                'client_id' => $r->client_id,
                'id_code' => $r->id_value,
                'name' => ($name !== '' ? $name : $r->email),
            ];
        }

        return $map;
    }

    private function ddosProfilesScreen(array &$vars)
    {
        $api = $this->getApi();
        $resp = $api->ddosProfiles();
        $data = $this->apiData($resp);

        return $this->renderManageScreen('ddos_profiles', 'ddosprofiles', [
            'profiles' => isset($data['profiles']) ? $data['profiles'] : [],
            'errors' => !empty($resp['errors']) ? $resp['errors'] : [],
            'home_link' => $this->base_uri . 'settings/company/modules/manage/' . $this->getModuleId(),
        ]);
    }

    private function ddosHistoryScreen(array &$vars)
    {
        $api = $this->getApi();
        $page = isset($_GET['page']) ? max(1, (int) $_GET['page']) : 1;
        $search = isset($_GET['search']) ? trim($_GET['search']) : '';
        $resp = $api->GetDDoSAttacks($page, $search);

        return $this->renderManageScreen('ddos_history', 'ddoshistory', [
            'attacks' => $api->extractList($this->apiData($resp)),
            'page' => $page,
            'search' => $search,
            'errors' => !empty($resp['errors']) ? $resp['errors'] : [],
            'home_link' => $this->base_uri . 'settings/company/modules/manage/' . $this->getModuleId(),
        ]);
    }

    private function nullRoutesScreen(array &$vars)
    {
        $api = $this->getApi();
        $notice = null;
        $error = null;

        if (!empty($_GET['action'])) {
            if ($_GET['action'] === 'add' && !empty($_GET['ip'])) {
                $resp = $api->AddNullRoute(trim($_GET['ip']), null, false);
                $notice = !empty($resp['success']) ? Language::_('Reliablesite.null_routes.added', true) : null;
                $error = empty($resp['success']) && !empty($resp['errors']) ? implode('; ', $resp['errors']) : null;
            } elseif ($_GET['action'] === 'remove' && !empty($_GET['ip'])) {
                $resp = $api->RemoveNullRoute(trim($_GET['ip']));
                $notice = !empty($resp['success']) ? Language::_('Reliablesite.null_routes.removed', true) : null;
                $error = empty($resp['success']) && !empty($resp['errors']) ? implode('; ', $resp['errors']) : null;
            }
        }

        $page = isset($_GET['page']) ? max(1, (int) $_GET['page']) : 1;
        $search = isset($_GET['search']) ? trim($_GET['search']) : '';
        $resp = $api->getNullRoutes($page, $search, 'false');

        return $this->renderManageScreen('null_routes', 'nullroutes', [
            'routes' => $api->extractList($this->apiData($resp)),
            'page' => $page,
            'search' => $search,
            'notice' => $notice,
            'error' => $error,
            'home_link' => $this->base_uri . 'settings/company/modules/manage/' . $this->getModuleId(),
        ]);
    }

    private function syncLogScreen(array &$vars)
    {
        Loader::loadModels($this, ['Record']);
        $logs = $this->Record->select()
            ->from('mod_reliablesite_sync_logs')
            ->order(['id' => 'DESC'])
            ->limit(50)
            ->fetchAll();

        return $this->renderManageScreen('sync_log', 'synclog', [
            'logs' => $logs,
            'home_link' => $this->base_uri . 'settings/company/modules/manage/' . $this->getModuleId(),
        ]);
    }

    // =================================================================
    // Email templates
    // =================================================================

    public function getEmailTemplate()
    {
        Configure::load('reliablesite', dirname(__FILE__) . DS . 'config' . DS);

        return Configure::get('Reliablesite.email_templates');
    }

    // =================================================================
    // API + token handling
    // =================================================================

    /**
     * Returns an API client bound to a valid bearer token.
     *
     * @return ReliablesiteApi
     */
    public function getApi()
    {
        Loader::load(dirname(__FILE__) . DS . 'lib' . DS . 'reliablesite_api.php');
        $token = $this->getRSAPIToken();
        $api = new ReliablesiteApi($token['apiToken'], $this);
        $api->setError($token['errors']);

        return $api;
    }

    /**
     * Builds an Order API client with a resolved (and cached) bearer token.
     *
     * @return ReliablesiteOrderApi
     */
    public function getOrderApi()
    {
        Loader::load(dirname(__FILE__) . DS . 'lib' . DS . 'reliablesite_order_api.php');
        $token = $this->getOrderApiToken();
        $api = new ReliablesiteOrderApi($token['apiToken'], $this);
        $api->setError($token['errors']);

        return $api;
    }

    /**
     * Loads and returns the Order API order-placement service.
     *
     * @return ReliablesiteOrderService
     */
    public function loadOrderService()
    {
        Loader::load(dirname(__FILE__) . DS . 'lib' . DS . 'reliablesite_order_api.php');
        Loader::load(dirname(__FILE__) . DS . 'lib' . DS . 'reliablesite_order_service.php');

        return new ReliablesiteOrderService($this);
    }

    /**
     * Loads and returns the configurable-options sync helper.
     *
     * @return ReliablesiteOptionSync
     */
    public function loadOptionSync()
    {
        Loader::load(dirname(__FILE__) . DS . 'lib' . DS . 'reliablesite_api.php');
        Loader::load(dirname(__FILE__) . DS . 'lib' . DS . 'reliablesite_inventory_options.php');
        Loader::load(dirname(__FILE__) . DS . 'lib' . DS . 'reliablesite_option_sync.php');

        return new ReliablesiteOptionSync($this);
    }

    /**
     * Resolves (and refreshes when expired) the Order API bearer token, caching
     * it on the module row meta. The Order API lives on a different host than the
     * v2 API and issues its own token (note the lowercase apiKey param and the
     * boolean status flag).
     *
     * @return array { apiToken, errors }
     */
    private function getOrderApiToken()
    {
        $return = ['apiToken' => '', 'errors' => []];
        list($meta, $row_id) = $this->resolveRow();

        if (empty($meta) || empty($meta->api_key)) {
            $return['errors'][] = Language::_('Reliablesite.!error.api_key.missing', true);

            return $return;
        }

        $api_key = $meta->api_key;
        $api_token = isset($meta->order_api_token) ? $meta->order_api_token : '';
        $validity = isset($meta->order_api_token_validity) ? (int) $meta->order_api_token_validity : 0;
        $now = strtotime(gmdate('Y-m-d\TH:i:s\Z'));

        if ($validity <= $now || empty($api_token)) {
            $fetched = $this->fetchOrderToken($api_key);
            if (!empty($fetched['token'])) {
                $api_token = $fetched['token'];
                $validity = $fetched['validity'];
                if ($row_id) {
                    Loader::loadModels($this, ['Record']);
                    $this->Record->where('module_row_id', '=', $row_id)
                        ->where('key', '=', 'order_api_token')
                        ->update('module_row_meta', ['value' => $api_token]);
                    $this->Record->where('module_row_id', '=', $row_id)
                        ->where('key', '=', 'order_api_token_validity')
                        ->update('module_row_meta', ['value' => $validity]);
                }
            } else {
                $return['errors'] = !empty($fetched['errors'])
                    ? $fetched['errors']
                    : [Language::_('Reliablesite.!error.api_key.connection', true)];

                return $return;
            }
        }

        $return['apiToken'] = $api_token;

        return $return;
    }

    /**
     * Requests a fresh Order API bearer token for an API key.
     *
     * @param string $api_key
     * @return array { token, validity, errors }
     */
    private function fetchOrderToken($api_key)
    {
        $result = ['token' => '', 'validity' => 0, 'errors' => []];
        if (!function_exists('curl_version')) {
            $result['errors'][] = 'cURL extension is not available';

            return $result;
        }

        $url = 'https://order-api.reliablesite.dev/v1/Login/Token?apiKey=' . urlencode($api_key);
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Length: 0', 'Accept: application/json']);
        curl_setopt($ch, CURLOPT_TIMEOUT, 20);
        $verify = class_exists('Configure') ? (bool) Configure::get('Blesta.curl_verify_ssl') : false;
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, $verify);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, $verify ? 2 : 0);

        $response = curl_exec($ch);
        $curl_error = curl_error($ch);
        curl_close($ch);

        if ($curl_error) {
            $result['errors'][] = $curl_error;
            $this->log($url, $response, 'output', false);

            return $result;
        }

        $parsed = json_decode($response, true);
        // LoginTokenResponse: { status: bool, message: JWT, tokenExpirationTimestamp }
        if (is_array($parsed) && !empty($parsed['status']) && !empty($parsed['message'])) {
            $result['token'] = $parsed['message'];
            $result['validity'] = !empty($parsed['tokenExpirationTimestamp'])
                ? strtotime($parsed['tokenExpirationTimestamp'])
                : (strtotime(gmdate('Y-m-d\TH:i:s\Z')) + 300);
        } else {
            $msg = is_array($parsed)
                ? (isset($parsed['message']) ? $parsed['message'] : (isset($parsed['title']) ? $parsed['title'] : 'Invalid API key'))
                : 'Could not parse token response';
            $result['errors'][] = $msg;
            $this->log($url, $response, 'output', false);
        }

        return $result;
    }

    /**
     * Resolves (and refreshes when expired) the bearer token, caching it on the
     * module row meta.
     *
     * @return array { apiToken, errors }
     */
    private function getRSAPIToken()
    {
        $return = ['apiToken' => '', 'errors' => []];
        list($meta, $row_id) = $this->resolveRow();

        if (empty($meta) || empty($meta->api_key)) {
            $return['errors'][] = Language::_('Reliablesite.!error.api_key.missing', true);

            return $return;
        }

        $api_key = $meta->api_key;
        $api_token = isset($meta->api_token) ? $meta->api_token : '';
        $validity = isset($meta->api_token_validity) ? (int) $meta->api_token_validity : 0;
        $now = strtotime(gmdate('Y-m-d\TH:i:s\Z'));

        if ($validity <= $now || empty($api_token)) {
            $fetched = $this->fetchToken($api_key);
            if (!empty($fetched['token'])) {
                $api_token = $fetched['token'];
                $validity = $fetched['validity'];
                if ($row_id) {
                    Loader::loadModels($this, ['Record']);
                    $this->Record->where('module_row_id', '=', $row_id)
                        ->where('key', '=', 'api_token')
                        ->update('module_row_meta', ['value' => $api_token]);
                    $this->Record->where('module_row_id', '=', $row_id)
                        ->where('key', '=', 'api_token_validity')
                        ->update('module_row_meta', ['value' => $validity]);
                }
            } else {
                $return['errors'] = !empty($fetched['errors'])
                    ? $fetched['errors']
                    : [Language::_('Reliablesite.!error.api_key.connection', true)];

                return $return;
            }
        }

        $return['apiToken'] = $api_token;

        return $return;
    }

    /**
     * Requests a fresh bearer token for an API key.
     *
     * @param string $api_key
     * @return array { token, validity, errors }
     */
    private function fetchToken($api_key)
    {
        $result = ['token' => '', 'validity' => 0, 'errors' => []];
        if (!function_exists('curl_version')) {
            $result['errors'][] = 'cURL extension is not available';

            return $result;
        }

        $url = 'https://dedicated-servers.reliablesite.dev/v2/Login/Token?ApiKey=' . urlencode($api_key);
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Length: 0']);
        curl_setopt($ch, CURLOPT_TIMEOUT, 20);
        $verify = class_exists('Configure') ? (bool) Configure::get('Blesta.curl_verify_ssl') : false;
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, $verify);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, $verify ? 2 : 0);

        $response = curl_exec($ch);
        $curl_error = curl_error($ch);
        curl_close($ch);

        if ($curl_error) {
            $result['errors'][] = $curl_error;
            $this->log($url, $response, 'output', false);

            return $result;
        }

        $parsed = json_decode($response, true);
        if (is_array($parsed) && isset($parsed['status']) && (int) $parsed['status'] === 1 && !empty($parsed['message'])) {
            $result['token'] = $parsed['message'];
            $result['validity'] = isset($parsed['tokenExpirationTimestamp'])
                ? strtotime($parsed['tokenExpirationTimestamp'])
                : (strtotime(gmdate('Y-m-d\TH:i:s\Z')) + 3000);
        } else {
            $msg = is_array($parsed)
                ? (isset($parsed['title']) ? $parsed['title'] : (isset($parsed['message']) ? $parsed['message'] : 'Invalid API key'))
                : 'Could not parse token response';
            $result['errors'][] = $msg;
            $this->log($url, $response, 'output', false);
        }

        return $result;
    }

    // =================================================================
    // Public accessors used by lib/* helpers
    // =================================================================

    /**
     * Ensures the option-group mapping table exists (idempotent, CREATE TABLE IF
     * NOT EXISTS). Lets the configurable-options feature work even if the Blesta
     * module upgrade that adds the table hasn't been run yet.
     */
    public function ensureOptionGroupsTable()
    {
        if (!isset($this->Record)) {
            Loader::loadComponents($this, ['Record']);
        }
        try {
            $this->Record
                ->setField('id', ['type' => 'int', 'size' => 10, 'unsigned' => true, 'auto_increment' => true])
                ->setField('rs_group_id', ['type' => 'varchar', 'size' => 64])
                ->setField('option_group_id', ['type' => 'int', 'size' => 10, 'unsigned' => true])
                ->setField('option_id', ['type' => 'int', 'size' => 10, 'unsigned' => true])
                ->setField('last_hash', ['type' => 'varchar', 'size' => 64, 'is_null' => true, 'default' => null])
                ->setField('last_sync', ['type' => 'datetime', 'is_null' => true, 'default' => null])
                ->setKey(['id'], 'primary')
                ->setKey(['rs_group_id'], 'index')
                ->create('mod_reliablesite_option_groups', true);
        } catch (Exception $e) {
            $this->recoverRecord();
        }
    }

    /**
     * Recovers the shared Record after a caught failure so a swallowed error
     * can't leave stale bindings / an open transaction that break later queries
     * in the same request. Safe to call anytime.
     */
    public function recoverRecord()
    {
        if (!isset($this->Record)) {
            Loader::loadComponents($this, ['Record']);
        }
        try {
            $connection = $this->Record->getConnection();
            if ($connection && $connection->inTransaction()) {
                $connection->rollBack();
            }
        } catch (Exception $e) {
            // best effort
        }
        try {
            $this->Record->reset();
        } catch (Exception $e) {
            // best effort
        }
    }

    public function getModuleId()
    {
        $module = $this->getModule();
        if ($module && isset($module->id)) {
            return $module->id;
        }

        return $this->my_module_id;
    }

    public function getModuleRowId()
    {
        list(, $row_id) = $this->resolveRow();

        return $row_id;
    }

    /**
     * Reads a setting from the active module row meta.
     *
     * @param string $key
     * @return mixed|null
     */
    public function getSetting($key)
    {
        list($meta) = $this->resolveRow();
        if ($meta && isset($meta->{$key})) {
            return $meta->{$key};
        }

        return null;
    }

    /**
     * Public logging hook used by the API client.
     */
    public function myLog($url, $data = null, $direction = 'input', $success = false)
    {
        $this->log($url, $data, $direction, $success);
    }

    public function startSyncLog()
    {
        $this->sync_log_lines = [];
    }

    public function addSyncLog($message)
    {
        $this->sync_log_lines[] = '[' . gmdate('Y-m-d H:i:s') . ' UTC] ' . $message;
    }

    public function saveSyncLog()
    {
        Loader::loadModels($this, ['Record']);
        $text = implode("\n", $this->sync_log_lines);
        $this->Record->insert('mod_reliablesite_sync_logs', ['date' => time(), 'log' => $text]);

        return $this->Record->lastInsertId();
    }

    /**
     * Sends the catalog sync report email when configured.
     */
    public function sendSyncReport(array $stats, $trigger, $logId)
    {
        if ($this->getSetting('sync_report_enabled') != '1') {
            return;
        }
        $recipients = $this->getNotificationRecipients();
        if (empty($recipients)) {
            return;
        }

        Loader::loadModels($this, ['Emails', 'Companies']);
        $company_id = Configure::get('Blesta.company_id');
        $from = '';
        $setting = $this->Companies->getSetting($company_id, 'sendmail_from');
        if ($setting) {
            $from = $setting->value;
        }

        $summary = sprintf(
            "Trigger: %s\nTotal tracked: %d\nUpdated: %d\nFrozen (stock only): %d\nNot found: %d\nUnchanged: %d\nFailed: %d\nDuration: %ss",
            $trigger,
            $stats['total'],
            $stats['updated'],
            isset($stats['skipped_frozen']) ? $stats['skipped_frozen'] : 0,
            $stats['not_found'],
            isset($stats['unchanged']) ? $stats['unchanged'] : 0,
            $stats['failed'],
            isset($stats['duration_seconds']) ? $stats['duration_seconds'] : 0
        );

        $this->Emails->sendCustom(
            $from,
            'System',
            $recipients,
            'ReliableSite Catalog Sync Report',
            ['html' => nl2br($summary), 'text' => $summary]
        );
    }

    /**
     * Returns the admin notification recipients.
     *
     * @return array
     */
    public function getNotificationRecipients()
    {
        $configured = trim((string) $this->getSetting('admin_notify_emails'));
        if ($configured !== '') {
            return array_values(array_filter(array_map('trim', explode(',', $configured))));
        }

        Loader::loadModels($this, ['Companies']);
        $company_id = Configure::get('Blesta.company_id');
        $setting = $this->Companies->getSetting($company_id, 'sendmail_from');
        if ($setting && !empty($setting->value)) {
            return [$setting->value];
        }

        return [];
    }

    // =================================================================
    // Internal helpers
    // =================================================================

    /**
     * Resolves the active module + first credentialed row + its meta.
     */
    private function setMyModuleData()
    {
        Loader::loadModels($this, ['ModuleManager']);
        $modules = $this->ModuleManager->getInstalled();
        foreach ($modules as $module) {
            if ($module->class == 'reliablesite') {
                $this->setModule($module);
                $this->my_module_id = $module->id;
            }
        }

        $module_rows = $this->getModuleRows();
        if ($module_rows) {
            foreach ($module_rows as $row) {
                if (isset($row->meta->api_key) && $row->meta->api_key !== '') {
                    $this->my_module_meta = $row->meta;
                    $this->my_module_id = $row->module_id;
                    $this->my_module_row_id = $row->id;
                    break;
                }
            }
        }
    }

    /**
     * Ensures the active module is set (used by management screens).
     */
    private function setMyModule()
    {
        Loader::loadModels($this, ['ModuleManager']);
        $modules = $this->ModuleManager->getInstalled();
        foreach ($modules as $module) {
            if ($module->class == 'reliablesite') {
                $this->setModule($module);
                $this->my_module_id = $module->id;
            }
        }
    }

    /**
     * Resolves the active module row meta + id, preferring a Blesta-set row.
     *
     * @return array [object|null $meta, int $row_id]
     */
    private function resolveRow()
    {
        $row = $this->getModuleRow();
        if ($row && isset($row->meta) && isset($row->meta->api_key) && $row->meta->api_key !== '') {
            return [$row->meta, $row->id];
        }
        if ($this->my_module_meta && isset($this->my_module_meta->api_key)) {
            return [$this->my_module_meta, $this->my_module_row_id];
        }
        $rows = $this->getModuleRows();
        if ($rows) {
            foreach ($rows as $r) {
                if (isset($r->meta->api_key) && $r->meta->api_key !== '') {
                    $this->my_module_meta = $r->meta;
                    $this->my_module_row_id = $r->id;

                    return [$r->meta, $r->id];
                }
            }
        }

        return [null, 0];
    }

    /**
     * Find-or-create a ReliableSite customer for a Blesta client.
     *
     * @param int|null $client_id
     * @return string The ReliableSite username
     */
    private function ensureCustomer($client_id)
    {
        Loader::loadModels($this, ['Clients']);
        $client = $client_id ? $this->Clients->get($client_id) : null;
        if (!$client) {
            throw new Exception(Language::_('Reliablesite.!error.client.missing', true));
        }

        // 1) Honor a manual link stored as a client setting, if present.
        $linked = $this->getClientSetting($client_id, 'reliablesite_username');
        if (is_string($linked) && trim($linked) !== '') {
            return $linked;
        }

        $api = $this->getApi();
        $email = $client->email;

        // 2) Email lookup.
        $existing = $api->findCustomerByEmail($email);
        if ($existing) {
            $username = isset($existing['userName'])
                ? $existing['userName']
                : (isset($existing['username']) ? $existing['username'] : '');
            if ($username !== '') {
                return $username;
            }
        }

        // 3) Create a new customer.
        $username = $this->deriveUsername($client);
        $password = $this->randomString(16);
        $resp = $api->addCustomer($username, $email, $password);
        if (empty($resp['success'])) {
            $msg = !empty($resp['errors']) ? implode('; ', $resp['errors']) : 'Unable to create ReliableSite customer';
            throw new Exception($msg);
        }

        return $username;
    }

    private function deriveUsername($client)
    {
        $name = isset($client->first_name) ? ($client->first_name . $client->last_name) : '';
        $base = preg_replace('/[^a-zA-Z0-9]/', '', strtolower($name));
        if (strlen($base) < 5) {
            $base = 'rs' . str_pad((string) $client->id, 3, '0', STR_PAD_LEFT);
        }

        return substr($base, 0, 16) . $this->randomString(4);
    }

    private function randomString($length)
    {
        $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        $out = '';
        $max = strlen($chars) - 1;
        for ($i = 0; $i < $length; $i++) {
            $out .= $chars[random_int(0, $max)];
        }

        return $out;
    }

    /**
     * Reads a per-client setting, when the Clients model supports it.
     *
     * @param int $client_id
     * @param string $key
     * @return string|null
     */
    private function getClientSetting($client_id, $key)
    {
        if (!$client_id) {
            return null;
        }
        Loader::loadModels($this, ['Clients']);
        if (method_exists($this->Clients, 'getSetting')) {
            $setting = $this->Clients->getSetting($client_id, $key);
            if ($setting && isset($setting->value)) {
                return $setting->value;
            }
        }

        return null;
    }

    /**
     * Sends the admin "new pending order" notification.
     */
    private function dispatchAdminNotification($package, $username, $client_id)
    {
        if ($this->getSetting('admin_notify_enabled') != '1') {
            return;
        }
        $recipients = $this->getNotificationRecipients();
        if (empty($recipients)) {
            return;
        }

        Loader::loadModels($this, ['Emails', 'Companies', 'Clients']);
        $company_id = Configure::get('Blesta.company_id');
        $from = '';
        $setting = $this->Companies->getSetting($company_id, 'sendmail_from');
        if ($setting) {
            $from = $setting->value;
        }

        $client_label = '';
        if ($client_id) {
            $client = $this->Clients->get($client_id);
            if ($client) {
                $client_label = trim($client->first_name . ' ' . $client->last_name) . ' <' . $client->email . '>';
            }
        }
        $product = isset($package->name) ? $package->name : '';

        $text = "A new ReliableSite order is awaiting server assignment.\n\n"
            . 'Customer: ' . $client_label . "\n"
            . 'Product: ' . $product . "\n"
            . 'ReliableSite username: ' . $username . "\n\n"
            . 'Assign a server from the module management screen (Pending Orders).';

        $this->Emails->sendCustom(
            $from,
            'System',
            $recipients,
            'ReliableSite: new order awaiting server assignment',
            ['html' => nl2br($text), 'text' => $text]
        );
    }

    private function loadCatalogSync()
    {
        Loader::load(dirname(__FILE__) . DS . 'lib' . DS . 'reliablesite_product_formatter.php');
        Loader::load(dirname(__FILE__) . DS . 'lib' . DS . 'reliablesite_api.php');
        Loader::load(dirname(__FILE__) . DS . 'lib' . DS . 'reliablesite_catalog_sync.php');

        return new ReliablesiteCatalogSync($this);
    }

    private function loadAssignment()
    {
        Loader::load(dirname(__FILE__) . DS . 'lib' . DS . 'reliablesite_api.php');
        Loader::load(dirname(__FILE__) . DS . 'lib' . DS . 'reliablesite_server_assignment.php');

        return new ReliablesiteServerAssignment($this);
    }

    /**
     * Returns the data portion of a normalized API response (array form).
     *
     * @param array $resp
     * @return array
     */
    private function apiData($resp)
    {
        if (!empty($resp['success']) && is_array($resp['data'])) {
            return $resp['data'];
        }

        return [];
    }

    private function findCatalogRow(array $products, $inventoryId)
    {
        foreach ($products as $row) {
            $candidate = isset($row['product_id']) ? (string) $row['product_id']
                : (isset($row['id']) ? (string) $row['id'] : '');
            if ($candidate !== '' && $candidate === (string) $inventoryId) {
                return $row;
            }
        }

        return null;
    }

    private function clientIp()
    {
        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $parts = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);

            return trim($parts[0]);
        }

        return isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '';
    }

    /**
     * Parses a BandwidthGraph response into chart-ready arrays. The API returns
     * data.bandwidthLogs as { "<timestamp>": [inMbps, outMbps, inGB, outGB] }.
     *
     * @param array $data The "data" portion of the BandwidthGraph response
     * @return array { labels, in_mbps, out_mbps, in_gb, out_gb }
     */
    private function bandwidthSeries($data)
    {
        $logs = [];
        if (is_array($data)) {
            if (isset($data['bandwidthLogs']) && is_array($data['bandwidthLogs'])) {
                $logs = $data['bandwidthLogs'];
            } elseif (isset($data['BandwidthLogs']) && is_array($data['BandwidthLogs'])) {
                $logs = $data['BandwidthLogs'];
            }
        }
        ksort($logs);

        $series = ['labels' => [], 'in_mbps' => [], 'out_mbps' => [], 'in_gb' => [], 'out_gb' => []];
        foreach ($logs as $timestamp => $values) {
            $series['labels'][] = substr(str_replace('T', ' ', (string) $timestamp), 0, 16);
            $series['in_mbps'][] = isset($values[0]) ? (float) $values[0] : 0;
            $series['out_mbps'][] = isset($values[1]) ? (float) $values[1] : 0;
            $series['in_gb'][] = isset($values[2]) ? (float) $values[2] : 0;
            $series['out_gb'][] = isset($values[3]) ? (float) $values[3] : 0;
        }

        return $series;
    }

    /**
     * Assembles the data for the client DDoS tab, mirroring the Paymenter page:
     *  - derives the server's datacenter code(s) by CIDR-matching its IP blocks
     *    against the reseller's available IPs (which carry the datacenter code),
     *  - filters profiles to those datacenters,
     *  - lists the IPs already protected that fall within this server's subnets.
     *
     * @param ReliablesiteApi $api
     * @param mixed $server_id
     * @return array
     */
    private function buildDdosData($api, $server_id)
    {
        $details = $this->apiData($api->getServerDetails($server_id));
        $profileResp = $this->apiData($api->ddosProfiles());

        $server = isset($details['server']) ? $details['server'] : [];
        $ipBlocks = isset($details['ipAddressList']) ? $details['ipAddressList'] : [];
        $allProfiles = isset($profileResp['profiles']) ? $profileResp['profiles'] : [];
        $myAvailableIps = isset($profileResp['myAvailableIps']) ? $profileResp['myAvailableIps'] : [];

        // Derive datacenter codes for this server by matching its blocks to the
        // reseller's available IPs (which are tagged with the datacenter code).
        $serverBlocks = [];
        $dcCodes = [];
        foreach ($ipBlocks as $block) {
            $desc = isset($block['ipDescription']) ? $block['ipDescription'] : '';
            if ($desc === '') {
                continue;
            }
            $dc = null;
            foreach ($myAvailableIps as $entry) {
                $availIp = isset($entry['ipAddress']) ? $entry['ipAddress'] : '';
                $entryDc = isset($entry['dataCenterName']) ? $entry['dataCenterName'] : '';
                if ($availIp !== '' && $entryDc !== '' && $this->cidrInCidr($desc, $availIp)) {
                    $dc = $entryDc;
                    break;
                }
            }
            if ($dc) {
                $dcCodes[$dc] = true;
            }
            $serverBlocks[] = [
                'ipAddressId' => isset($block['ipAddressId']) ? $block['ipAddressId'] : '',
                'description' => $desc,
                'dataCenterName' => $dc,
            ];
        }
        $dcCodes = array_keys($dcCodes);

        // Keep only profiles in the server's datacenter(s); if we couldn't map a
        // datacenter (no myAvailableIps), fall back to showing all profiles.
        $filtered = [];
        foreach ($allProfiles as $p) {
            $pdc = isset($p['dataCenterName']) ? $p['dataCenterName'] : '';
            if (empty($dcCodes) || in_array($pdc, $dcCodes, true)) {
                $filtered[] = $p;
            }
        }

        // Build the "currently protected" list: profile IPs within this server's subnets.
        $protected = [];
        foreach ($filtered as $p) {
            $profile = isset($p['profile']) && is_array($p['profile']) ? $p['profile'] : [];
            $profileId = isset($profile['id']) ? $profile['id'] : (isset($p['profileId']) ? $p['profileId'] : '');
            $profileName = isset($profile['name']) ? $profile['name'] : (isset($p['name']) ? $p['name'] : '');
            $dsId = isset($p['dataSourceId']) ? $p['dataSourceId'] : '';
            $ips = (isset($p['ips']) && is_array($p['ips'])) ? $p['ips'] : [];
            foreach ($ips as $ip) {
                $ipVal = isset($ip['ip']) ? $ip['ip'] : (isset($ip['ipAddress']) ? $ip['ipAddress'] : '');
                $ipId = isset($ip['ipId']) ? $ip['ipId'] : (isset($ip['id']) ? $ip['id'] : '');
                if ($ipVal === '') {
                    continue;
                }
                $within = false;
                foreach ($serverBlocks as $b) {
                    if ($b['description'] !== '' && $this->cidrInCidr($ipVal, $b['description'])) {
                        $within = true;
                        break;
                    }
                }
                if (!$within) {
                    continue;
                }
                $protected[] = [
                    'data_source_id' => $dsId,
                    'profile_id' => $profileId,
                    'profile_name' => $profileName,
                    'ip_id' => $ipId,
                    'ip' => $ipVal,
                ];
            }
        }

        return [
            'server_blocks' => $serverBlocks,
            'profiles' => $filtered,
            'protected' => $protected,
            'datacenter_label' => isset($server['dataCenterLabel']) ? $server['dataCenterLabel'] : '',
            'datacenter_codes' => $dcCodes,
            'all_profile_count' => count($allProfiles),
        ];
    }

    /**
     * Returns true when $child (IP or CIDR) is contained within $parent (IP or
     * CIDR). IPv4 only - ReliableSite reseller blocks are IPv4. Ported from the
     * Paymenter ServiceManagementController.
     *
     * @param string $child
     * @param string $parent
     * @return bool
     */
    private function cidrInCidr($child, $parent)
    {
        $split = function ($cidr) {
            $cidr = trim($cidr);
            if (strpos($cidr, '/') !== false) {
                $parts = explode('/', $cidr, 2);

                return [$parts[0], (int) $parts[1]];
            }

            return [$cidr, 32];
        };

        list($childIp, $childMask) = $split($child);
        list($parentIp, $parentMask) = $split($parent);

        if ($childMask < $parentMask) {
            return false;
        }
        $childLong = ip2long($childIp);
        $parentLong = ip2long($parentIp);
        if ($childLong === false || $parentLong === false) {
            return false;
        }
        $maskLong = $parentMask === 0 ? 0 : ((-1 << (32 - $parentMask)) & 0xFFFFFFFF);

        return ($childLong & $maskLong) === ($parentLong & $maskLong);
    }

    /**
     * Converts a meta field list to a flat vars array for ModuleManager.
     *
     * @param array $meta
     * @return array
     */
    private function metaToVars(array $meta)
    {
        $vars = [];
        foreach ($meta as $field) {
            $vars[$field['key']] = $field['value'];
        }

        return $vars;
    }

    /**
     * "Ask Brian" screen: an embedded chat with the Sales Engineer bot.
     *
     * Serves both the rendered chat UI (plain GET) and each per-message proxy
     * call (GET ?ajax=1). The proxy keeps the reseller API key server-side - the
     * browser only ever talks to Blesta, never directly to the Brian API.
     *
     * @param array $vars
     * @return string
     */
    private function askBrianScreen(array &$vars)
    {
        // AJAX turn: proxy a single message to the Brian API and return JSON.
        // askBrianRespond() terminates the request, so nothing after it runs.
        if (isset($_GET['ajax']) && $_GET['ajax'] === '1') {
            $this->askBrianRespond();

            return '';
        }

        $api_key = $this->getSetting('api_key');

        return $this->renderManageScreen('askbrian', 'askbrian', [
            'ajax_url' => $this->base_uri . 'settings/company/modules/addrow/'
                . $this->getModuleId() . '/?scr=askbrian&ajax=1',
            'session_id' => $this->askBrianSessionId(),
            'has_key' => ($api_key !== null && $api_key !== ''),
            'home_link' => $this->base_uri . 'settings/company/modules/manage/' . $this->getModuleId(),
        ]);
    }

    /**
     * Proxies one chat turn to the reseller web-chat endpoint and emits JSON,
     * then terminates the request so only the JSON body is returned.
     */
    private function askBrianRespond()
    {
        $message = isset($_GET['message']) ? (string) $_GET['message'] : '';
        $session_id = (isset($_GET['sessionId']) && $_GET['sessionId'] !== '')
            ? (string) $_GET['sessionId']
            : $this->askBrianSessionId();
        $assignment_id = isset($_GET['assignmentId']) ? (string) $_GET['assignmentId'] : '';

        $this->emitJson($this->askBrianSend($message, $session_id, $assignment_id));
    }

    /**
     * Builds the reseller-module session id in the owner-specified format:
     *   web-res-blesta-admin-{uid}-{host}
     * where {uid} is the logged-in staff id and {host} is the Blesta host, so
     * the bot knows the request is from the Blesta reseller module admin area.
     *
     * @return string
     */
    private function askBrianSessionId()
    {
        Loader::loadComponents($this, ['Session']);
        $uid = $this->Session->read('blesta_staff_id');
        if (empty($uid)) {
            $uid = $this->Session->read('blesta_id');
        }
        $uid = preg_replace('/[^A-Za-z0-9]/', '', (string) $uid);
        if ($uid === '') {
            $uid = '0';
        }

        $host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : '';
        if ($host === '' && class_exists('Configure')) {
            $host = (string) parse_url((string) Configure::get('Blesta.root_web_dir'), PHP_URL_HOST);
        }
        // Drop any port and keep only host-safe characters.
        $host = preg_replace('/:\d+$/', '', (string) $host);
        $host = strtolower(preg_replace('/[^A-Za-z0-9.\-]/', '', $host));
        if ($host === '') {
            $host = 'unknown';
        }

        return 'web-res-blesta-admin-' . $uid . '-' . $host;
    }

    /**
     * Sends one message to the AskBrian reseller web-chat endpoint using the
     * reseller's configured API key. Returns a normalized array the chat UI
     * consumes: { success, response, sessionId, assignmentId } or { success:false, error }.
     *
     * @param string $message
     * @param string $session_id
     * @param string $assignment_id
     * @return array
     */
    private function askBrianSend($message, $session_id, $assignment_id)
    {
        $message = trim($message);
        if ($message === '') {
            return ['success' => false, 'error' => 'Please enter a message.'];
        }
        if (strlen($message) > 2000) {
            return ['success' => false, 'error' => 'Message must be 2000 characters or fewer.'];
        }

        $api_key = $this->getSetting('api_key');
        if (empty($api_key)) {
            return ['success' => false, 'error' => Language::_('Reliablesite.!error.api_key.missing', true)];
        }
        if (!function_exists('curl_version')) {
            return ['success' => false, 'error' => 'cURL extension is not available.'];
        }

        $body = ['message' => $message];
        if ($session_id !== '') {
            $body['sessionId'] = $session_id;
        }
        if ($assignment_id !== '') {
            $body['assignmentId'] = $assignment_id;
        }

        $headers = [
            'Content-Type: application/json',
            'x-api-key: ' . $api_key,
        ];
        $client_ip = $this->clientIp();
        if (!empty($client_ip)) {
            $headers[] = 'x-client-ip: ' . $client_ip;
        }

        $ch = curl_init(self::BRIAN_API_URL);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60);
        $verify = class_exists('Configure') ? (bool) Configure::get('Blesta.curl_verify_ssl') : false;
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, $verify);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, $verify ? 2 : 0);

        $response = curl_exec($ch);
        $curl_error = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($curl_error) {
            $this->log(self::BRIAN_API_URL, $response, 'output', false);

            return ['success' => false, 'error' => 'Could not reach the assistant: ' . $curl_error];
        }

        $parsed = json_decode($response, true);
        if (!is_array($parsed)) {
            $this->log(self::BRIAN_API_URL, $response, 'output', false);

            return ['success' => false, 'error' => 'Unexpected response from the assistant.'];
        }

        if ($status >= 200 && $status < 300 && !empty($parsed['success'])) {
            $this->log(self::BRIAN_API_URL, $response, 'output', true);

            return [
                'success' => true,
                'response' => isset($parsed['response']) ? $parsed['response'] : '',
                'sessionId' => isset($parsed['sessionId']) ? $parsed['sessionId'] : $session_id,
                'assignmentId' => isset($parsed['assignmentId']) ? $parsed['assignmentId'] : $assignment_id,
            ];
        }

        $this->log(self::BRIAN_API_URL, $response, 'output', false);
        $msg = isset($parsed['message']) ? $parsed['message'] : 'The assistant returned an error.';
        if ($status === 401) {
            $msg = 'The assistant rejected the reseller API key (401 Unauthorized).';
        } elseif ($status === 404) {
            $msg = 'The assistant channel is not enabled for this key (404 Not Found).';
        }

        return ['success' => false, 'error' => $msg];
    }

    /**
     * Emits a JSON payload and terminates the request, discarding any buffered
     * layout output so the caller receives only the JSON body.
     *
     * @param array $payload
     */
    private function emitJson(array $payload)
    {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        if (!headers_sent()) {
            header('Content-Type: application/json');
        }
        echo json_encode($payload);
        exit;
    }

    /**
     * Builds the management tab bar shared by every admin screen.
     *
     * Uses Paradigm's own .nav-tabs-custom, which supplies the colours, hover
     * state, active underline and horizontal overflow scrolling for both light
     * and dark (app/views/admin/paradigm/css/application.css:20485).
     *
     * Deliberately not routed through Widget::setTabs(): that renders into
     * .card-filter-bar, which Paradigm pins to `flex-wrap: nowrap !important`
     * (application.css:19572), so eleven tabs would clip rather than scroll.
     * setTabs() is also for switching panels inside one card, whereas this is
     * cross-page navigation sitting above a different card on every screen.
     *
     * Icons are Bootstrap Icons: Paradigm ships no Font Awesome CSS and no FA
     * webfont, so `fas fa-*` renders as an empty box.
     *
     * @param string $active The active tab key
     * @return string HTML
     */
    private function manageNav($active)
    {
        $module_id = $this->getModuleId();
        $row_id = $this->getModuleRowId();
        $addrow = $this->base_uri . 'settings/company/modules/addrow/' . $module_id . '/?scr=';

        $tabs = [
            'home' => ['label' => 'Overview', 'icon' => 'bi-speedometer2',
                'url' => $this->base_uri . 'settings/company/modules/manage/' . $module_id],
            'askbrian' => ['label' => 'Ask Brian', 'icon' => 'bi-robot', 'url' => $addrow . 'askbrian'],
            'settings' => ['label' => 'Settings', 'icon' => 'bi-gear',
                'url' => $this->base_uri . 'settings/company/modules/editrow/' . $module_id . '/' . $row_id],
            'pendingorders' => ['label' => 'Pending Orders', 'icon' => 'bi-hourglass-split', 'url' => $addrow . 'pendingorders'],
            'servers' => ['label' => 'Servers', 'icon' => 'bi-hdd-rack', 'url' => $addrow . 'servers'],
            'catalog' => ['label' => 'Catalog', 'icon' => 'bi-box-seam', 'url' => $addrow . 'catalog'],
            'customers' => ['label' => 'Customers', 'icon' => 'bi-people', 'url' => $addrow . 'customers'],
            'ddosprofiles' => ['label' => 'DDoS Profiles', 'icon' => 'bi-shield-check', 'url' => $addrow . 'ddosprofiles'],
            'ddoshistory' => ['label' => 'DDoS History', 'icon' => 'bi-clock-history', 'url' => $addrow . 'ddoshistory'],
            'nullroutes' => ['label' => 'Null Routes', 'icon' => 'bi-slash-circle', 'url' => $addrow . 'nullroutes'],
            'synclog' => ['label' => 'Sync Log', 'icon' => 'bi-list-ul', 'url' => $addrow . 'synclog'],
        ];

        $html = '<div class="rs-nav"><ul class="nav nav-tabs-custom border-0 mb-0" role="tablist">';
        foreach ($tabs as $key => $tab) {
            $html .= '<li class="nav-item">'
                . '<a class="nav-link' . ($key === $active ? ' active' : '') . '"'
                . ' href="' . htmlspecialchars($tab['url'], ENT_QUOTES, 'UTF-8') . '">'
                . '<i class="bi ' . $tab['icon'] . '"></i> '
                . htmlspecialchars($tab['label'], ENT_QUOTES, 'UTF-8')
                . '</a></li>';
        }
        $html .= '</ul></div>';

        return $html;
    }

    /**
     * Renders an admin management screen: scoped styles, the tab bar, and the
     * view, all inside the .rs-module wrapper.
     *
     * @param string $view View name
     * @param string $active Active tab key
     * @param array $data View variables
     * @return string
     */
    private function renderManageScreen($view, $active, array $data)
    {
        return $this->wrap($this->manageNav($active) . $this->renderViewRaw($view, $data));
    }

    /**
     * Renders a module view wrapped in the scoped .rs-module shell.
     *
     * @param string $name View name (without extension)
     * @param array $data Variables to expose
     * @param bool $client Whether this is a client-facing view
     * @return string
     */
    private function renderView($name, array $data, $client = false)
    {
        return $this->wrap($this->renderViewRaw($name, $data, $client), !$client);
    }

    /**
     * Fetches a view's HTML without the wrapper (used when composing several
     * pieces into one wrapped page).
     *
     * @param string $name
     * @param array $data
     * @param bool $client
     * @return string
     */
    private function renderViewRaw($name, array $data, $client = false)
    {
        $this->view = new View($name, 'default');
        $this->view->base_uri = $this->base_uri;
        $this->view->setDefaultView('components' . DS . 'modules' . DS . 'reliablesite' . DS);
        Loader::loadHelpers($this, ['Form', 'Html', 'Widget']);

        foreach ($data as $key => $value) {
            $this->view->set($key, $value);
        }

        return $this->view->fetch();
    }

    /**
     * Builds a web path to one of this module's view assets. WEBDIR-relative so
     * it resolves correctly under subdirectory installs and index.php routing.
     *
     * @param string $file Path relative to views/default/
     * @return string
     */
    private function assetUri($file)
    {
        return WEBDIR . 'components/modules/reliablesite/views/default/' . $file;
    }

    /**
     * Emits an idempotent <link> for a module stylesheet.
     *
     * Modules have no controller, so they cannot use the Css helper. Blesta's
     * own Widget::setStyleSheet() writes an unguarded <link>, which would be
     * duplicated because wrap() can run more than once per page - so this
     * appends via JS with a data-rs-style guard instead.
     *
     * @param string $file Path relative to views/default/
     * @return string
     */
    private function styleTag($file)
    {
        $href = $this->assetUri($file);

        return '<script type="text/javascript">(function(){'
            . 'var h=' . json_encode($href) . ';'
            . 'if(document.querySelector(\'link[data-rs-style="\'+h+\'"]\'))return;'
            . 'var l=document.createElement("link");'
            . 'l.rel="stylesheet";l.type="text/css";l.media="screen";'
            . 'l.href=h;l.setAttribute("data-rs-style",h);'
            . 'document.head.appendChild(l);})();</script>';
    }

    /**
     * Emits an idempotent <script> for a module script, using the same
     * duplicate guard as styleTag().
     *
     * @param string $file Path relative to views/default/
     * @return string
     */
    private function scriptTag($file)
    {
        $src = $this->assetUri($file);

        return '<script type="text/javascript">(function(){'
            . 'var s=' . json_encode($src) . ';'
            . 'if(document.querySelector(\'script[data-rs-script="\'+s+\'"]\'))return;'
            . 'var e=document.createElement("script");'
            . 'e.type="text/javascript";e.src=s;e.setAttribute("data-rs-script",s);'
            . 'document.head.appendChild(e);})();</script>';
    }

    /**
     * Wraps content in the scoped module shell.
     *
     * Admin gets the Paradigm sheet (Bootstrap 5, driven entirely by Paradigm's
     * CSS custom properties, so light/dark follows <html data-theme> with no
     * JS). Client gets the Allure sheet (Bootstrap 4, data-theme-mode).
     *
     * Neither emits colours inline any more, and neither restyles core
     * components - the surrounding theme owns .card, .table, .btn and friends.
     *
     * @param string $html
     * @param bool $admin Whether this is an admin-facing screen
     * @return string
     */
    private function wrap($html, $admin = true)
    {
        return $admin
            ? $this->styleTag('css/rs-admin.css')
                . $this->scriptTag('js/rs-admin.js')
                . '<div class="rs-module rs-admin">' . $html . '</div>'
            : $this->styleTag('css/rs-client.css')
                . '<div class="rs-module rs-client">' . $html . '</div>';
    }
}
