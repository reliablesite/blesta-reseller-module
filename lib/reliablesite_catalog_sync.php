<?php
/**
 * Catalog import + sync runner.
 *
 * Blesta port of the Paymenter module's Support\CatalogSync +
 * Jobs\SyncProductFromCatalog. Fetches the JSON inventory-widget catalog and:
 *
 *   - imports a catalog row as a Blesta package (with per-cycle pricing + markup),
 *   - keeps imported packages in sync (always stock; name/description/price when
 *     not frozen),
 *   - records per-run stats and (optionally) emails a sync report.
 *
 * Tracking state lives in mod_reliablesite_packages.
 *
 * @package reliablesite.lib
 */
class ReliablesiteCatalogSync
{
    /**
     * Catalog cycle key => Blesta (term, period) + month count for flat markup.
     */
    private static $cycleMap = [
        'monthly' => ['term' => 1, 'period' => 'month', 'months' => 1],
        'quarterly' => ['term' => 3, 'period' => 'month', 'months' => 3],
        'semi_annual' => ['term' => 6, 'period' => 'month', 'months' => 6],
        'annual' => ['term' => 1, 'period' => 'year', 'months' => 12],
        'biennial' => ['term' => 2, 'period' => 'year', 'months' => 24],
        'triennial' => ['term' => 3, 'period' => 'year', 'months' => 36],
    ];

    /** @var Reliablesite The owning module */
    private $module;

    /** @var array|null Per-request catalog cache */
    private $catalogCache = null;

    /** @var ReliablesiteOptionSync|null Per-request option-sync helper */
    private $optionSync = null;

    /** @var bool Whether option groups have been synced this request */
    private $optionsEnsured = false;

    /**
     * @param Reliablesite $module
     */
    public function __construct($module)
    {
        $this->module = $module;
        Loader::loadModels($this->module, ['Packages', 'PackageGroups', 'Record']);
    }

    /**
     * Fetches the inventory catalog once per request.
     *
     * @param bool $forceRefresh
     * @return array { success, products, hash, errors }
     */
    public function fetchCatalog($forceRefresh = false)
    {
        if ($this->catalogCache !== null && !$forceRefresh) {
            return $this->catalogCache;
        }

        $resp = $this->module->getApi()->catalogProducts();
        if (empty($resp['success'])) {
            $this->catalogCache = [
                'success' => false,
                'products' => [],
                'hash' => null,
                'errors' => !empty($resp['errors']) ? $resp['errors'] : ['Failed to fetch catalog'],
            ];

            return $this->catalogCache;
        }

        $this->catalogCache = [
            'success' => true,
            'products' => isset($resp['data']['products']) ? $resp['data']['products'] : [],
            'hash' => isset($resp['data']['hash']) ? $resp['data']['hash'] : null,
            'errors' => [],
        ];

        return $this->catalogCache;
    }

    /**
     * Imports a catalog row as a new Blesta package, or returns the existing
     * mapping if the inventory id is already tracked.
     *
     * @param array $row A catalog row
     * @return array { success, package_id, errors }
     */
    public function importProduct(array $row)
    {
        $result = ['success' => false, 'package_id' => null, 'errors' => []];

        $inventoryId = $this->rowInventoryId($row);
        if ($inventoryId === '') {
            $result['errors'][] = 'Catalog row is missing a product id.';

            return $result;
        }

        // Already imported?
        $existing = $this->getMapping($inventoryId);
        if ($existing) {
            $result['success'] = true;
            $result['package_id'] = (int) $existing->package_id;

            return $result;
        }

        $groupId = (int) $this->module->getSetting('import_package_group');
        if ($groupId <= 0) {
            $result['errors'][] = 'No import package group configured.';

            return $result;
        }

        $currency = $this->module->getSetting('import_currency_code');
        if (empty($currency)) {
            $currency = 'USD';
        }

        $pricing = $this->buildPricing($row, $currency);
        if (empty($pricing)) {
            $result['errors'][] = 'Catalog row has no usable pricing.';

            return $result;
        }

        $package = [
            'names' => [['lang' => 'en_us', 'name' => ReliablesiteProductFormatter::formatName($row)]],
            'descriptions' => [[
                'lang' => 'en_us',
                'text' => '',
                'html' => ReliablesiteProductFormatter::formatDescription($row),
            ]],
            'status' => 'active',
            'qty' => $this->rowStock($row),
            'module_id' => $this->module->getModuleId(),
            'module_row' => $this->module->getModuleRowId(),
            'meta' => ['reliablesite_product_id' => $inventoryId],
            'pricing' => $pricing,
            'email_content' => [['lang' => 'en_us', 'html' => '']],
            'select_group_type' => 'existing',
            'groups' => [$groupId],
            'taxable' => 0,
            'single_term' => 0,
        ];

        $packageId = $this->module->Packages->add($package);
        $errors = $this->module->Packages->errors();
        if (!empty($errors)) {
            $result['errors'][] = $this->flattenErrors($errors);

            return $result;
        }

        $this->module->Record->insert('mod_reliablesite_packages', [
            'rs_inventory_id' => $inventoryId,
            'package_id' => $packageId,
            'tracked' => 1,
            'frozen' => 0,
            'last_synced_price' => $this->monthlyBase($row),
            'last_sync' => date('Y-m-d H:i:s'),
        ]);

        // Attach the applicable ReliableSite configurable options to the new
        // package (best effort; never blocks the import).
        if ($this->module->getSetting('options_sync_enabled') == '1') {
            try {
                $optionSync = $this->ensureOptionGroups();
                if ($optionSync) {
                    $optionSync->attachPackage((int) $packageId, $row);
                }
            } catch (\Throwable $e) {
                // Options are non-fatal for an import; recover the shared Record
                // so the caught error can't break the rest of the page render.
                $this->module->recoverRecord();
            }
        }

        $result['success'] = true;
        $result['package_id'] = (int) $packageId;

        return $result;
    }

    /**
     * Runs a full sync pass across all tracked packages.
     *
     * @param string $trigger Free-form label for the report (scheduler/manual/command)
     * @return array Stats { total, updated, skipped_frozen, not_found, failed, duration_seconds }
     */
    public function run($trigger = 'scheduler')
    {
        $start = microtime(true);
        $this->module->startSyncLog();
        $this->module->addSyncLog('Catalog sync started (' . $trigger . ')');

        $stats = [
            'total' => 0,
            'updated' => 0,
            'skipped_frozen' => 0,
            'not_found' => 0,
            'unchanged' => 0,
            'failed' => 0,
        ];

        // Self-heal first: drop mappings whose Blesta package was deleted, so a
        // single orphaned row can't make every later sync log a failure.
        $pruned = $this->pruneOrphanedMappings();
        if ($pruned > 0) {
            $this->module->addSyncLog($pruned . ' orphaned product mapping(s) removed (Blesta package deleted).');
        }

        $catalog = $this->fetchCatalog(true);
        if (empty($catalog['success'])) {
            $this->module->addSyncLog('ERROR: ' . implode('; ', $catalog['errors']));
            $stats['duration_seconds'] = round(microtime(true) - $start, 2);
            $logId = $this->module->saveSyncLog();
            $this->sendReport($stats, $trigger, $logId);

            return $stats;
        }

        $mappings = $this->module->Record->select()
            ->from('mod_reliablesite_packages')
            ->where('tracked', '=', 1)
            ->fetchAll();

        $stats['total'] = count($mappings);

        foreach ($mappings as $mapping) {
            // Catch Throwable (not just Exception) so one bad package - a deleted
            // record, a type error, etc. - never aborts the whole sync run.
            try {
                $status = $this->syncPackage($mapping, $catalog['products']);
                if (isset($stats[$status])) {
                    $stats[$status]++;
                }
            } catch (\Throwable $e) {
                $stats['failed']++;
                $this->module->addSyncLog('Package #' . $mapping->package_id . ' failed: ' . $e->getMessage());
            }
        }

        // Sync configurable options + recompute package membership.
        $this->syncOptions();

        $stats['duration_seconds'] = round(microtime(true) - $start, 2);
        $this->module->addSyncLog(sprintf(
            'Sync finished in %ss. Updated: %d, frozen: %d, not found: %d, unchanged: %d, failed: %d.',
            $stats['duration_seconds'],
            $stats['updated'],
            $stats['skipped_frozen'],
            $stats['not_found'],
            $stats['unchanged'],
            $stats['failed']
        ));

        $logId = $this->module->saveSyncLog();
        $this->sendReport($stats, $trigger, $logId);

        return $stats;
    }

    /**
     * Syncs a single tracked package against the catalog.
     *
     * @param object $mapping A mod_reliablesite_packages row
     * @param array $products The catalog products list
     * @return string updated|unchanged|skipped_frozen|not_found|noop
     */
    public function syncPackage($mapping, array $products)
    {
        $package = $this->module->Packages->get($mapping->package_id);
        if (!$package) {
            // Mapping points at a deleted package - drop it.
            $this->module->Record->from('mod_reliablesite_packages')->where('id', '=', $mapping->id)->delete();

            return 'noop';
        }

        $row = $this->findCatalogRow($products, $mapping->rs_inventory_id);
        if (!$row) {
            $this->module->addSyncLog('Inventory #' . $mapping->rs_inventory_id . ' no longer in catalog.');

            return 'not_found';
        }

        $frozen = (int) $mapping->frozen === 1;
        $didChange = false;

        // Always refresh stock - the most operationally important field.
        $newStock = $this->rowStock($row);
        $edit = ['qty' => $newStock, 'meta' => ['reliablesite_product_id' => (string) $mapping->rs_inventory_id]];

        if (!$frozen) {
            // Refresh name + description.
            $edit['names'] = [['lang' => 'en_us', 'name' => ReliablesiteProductFormatter::formatName($row)]];
            $edit['descriptions'] = [[
                'lang' => 'en_us',
                'text' => '',
                'html' => ReliablesiteProductFormatter::formatDescription($row),
            ]];

            // Refresh prices on the cycles the package already has.
            $monthlyBase = $this->monthlyBase($row);
            $lastMonthly = (float) $mapping->last_synced_price;
            $priceChanged = $monthlyBase > 0 && abs($monthlyBase - $lastMonthly) >= 0.005;

            if ($priceChanged) {
                $pricing = $this->rebuildExistingPricing($package, $row);
                if (!empty($pricing)) {
                    $edit['pricing'] = $pricing;
                }
            }
        }

        $this->module->Packages->edit($mapping->package_id, $edit);
        $errors = $this->module->Packages->errors();
        if (!empty($errors)) {
            throw new Exception($this->flattenErrors($errors));
        }
        $didChange = true;

        $this->module->Record->where('id', '=', $mapping->id)->update('mod_reliablesite_packages', [
            'last_synced_price' => $this->monthlyBase($row),
            'last_sync' => date('Y-m-d H:i:s'),
        ]);

        if ($frozen) {
            return 'skipped_frozen';
        }

        return $didChange ? 'updated' : 'unchanged';
    }

    /**
     * Sets the frozen flag on a tracked package (freeze keeps stock syncing but
     * stops name/description/price updates).
     *
     * @param int $packageId
     * @param bool $frozen
     * @return bool
     */
    public function setFrozen($packageId, $frozen)
    {
        $this->module->Record->where('package_id', '=', $packageId)
            ->update('mod_reliablesite_packages', ['frozen' => $frozen ? 1 : 0]);

        return true;
    }

    /**
     * Stops tracking a package for sync (does not delete the Blesta package).
     *
     * @param int $packageId
     * @return bool
     */
    public function untrack($packageId)
    {
        $this->module->Record->from('mod_reliablesite_packages')
            ->where('package_id', '=', $packageId)
            ->delete();

        return true;
    }

    /**
     * Returns the tracking mapping for an inventory id, or null.
     *
     * @param string $inventoryId
     * @return object|null
     */
    public function getMapping($inventoryId)
    {
        return $this->module->Record->select()
            ->from('mod_reliablesite_packages')
            ->where('rs_inventory_id', '=', (string) $inventoryId)
            ->fetch();
    }

    /**
     * Returns a map of inventory_id => mapping row for all tracked packages.
     *
     * @return array
     */
    public function getMappings()
    {
        $rows = $this->module->Record->select()
            ->from('mod_reliablesite_packages')
            ->fetchAll();
        $map = [];
        foreach ($rows as $r) {
            $map[(string) $r->rs_inventory_id] = $r;
        }

        return $map;
    }

    /**
     * Removes tracking mappings whose Blesta package no longer exists (e.g. the
     * package was deleted in Blesta). Uses a set-difference so it doesn't depend
     * on NULL-join semantics.
     *
     * @return int The number of orphaned mappings removed
     */
    public function pruneOrphanedMappings()
    {
        $mappings = $this->module->Record->select()
            ->from('mod_reliablesite_packages')
            ->fetchAll();
        if (empty($mappings)) {
            return 0;
        }

        $package_ids = [];
        foreach ($mappings as $m) {
            $package_ids[] = (int) $m->package_id;
        }

        $existing = [];
        $rows = $this->module->Record->select(['id'])
            ->from('packages')
            ->where('id', 'in', $package_ids)
            ->fetchAll();
        foreach ($rows as $r) {
            $existing[(int) $r->id] = true;
        }

        $removed = 0;
        foreach ($mappings as $m) {
            if (!isset($existing[(int) $m->package_id])) {
                $this->module->Record->from('mod_reliablesite_packages')->where('id', '=', $m->id)->delete();
                $removed++;
            }
        }

        return $removed;
    }

    /**
     * Syncs the ReliableSite configurable-options catalog into Blesta and
     * recomputes which option groups apply to each tracked package. Gated by the
     * options_sync_enabled setting; failures are logged, never fatal.
     *
     * @return void
     */
    public function syncOptions()
    {
        if ($this->module->getSetting('options_sync_enabled') != '1') {
            return;
        }

        try {
            $sync = $this->ensureOptionGroups();
            if (!$sync) {
                return;
            }
            $membership = $sync->syncPackageMembership();
            $this->module->addSyncLog(sprintf(
                'Options: %d group(s) mapped to packages (%d assignment(s)).',
                $membership['groups'],
                $membership['packages']
            ));
        } catch (\Throwable $e) {
            $this->module->recoverRecord();
            $this->module->addSyncLog('Option sync failed: ' . $e->getMessage());
        }
    }

    /**
     * Fetches the options feed and upserts the Blesta configurable options once
     * per request, returning the option-sync helper (or null on fetch failure).
     *
     * @return ReliablesiteOptionSync|null
     */
    private function ensureOptionGroups()
    {
        if ($this->optionSync === null) {
            $this->optionSync = $this->module->loadOptionSync();
        }
        if ($this->optionsEnsured) {
            return $this->optionSync;
        }

        $feed = $this->module->getApi()->inventoryOptions();
        if (empty($feed['success'])) {
            $this->module->addSyncLog('Options feed unavailable: ' . implode('; ', (array) $feed['errors']));

            return null;
        }

        $stats = $this->optionSync->syncFromFeed($feed['data']);
        $this->optionsEnsured = true;
        $this->module->addSyncLog(sprintf(
            'Options: %d group(s) - created %d, updated %d, unchanged %d, failed %d.',
            $stats['groups'],
            $stats['created'],
            $stats['updated'],
            $stats['unchanged'],
            $stats['failed']
        ));

        return $this->optionSync;
    }

    /**
     * Imports every catalog product that is not already tracked.
     *
     * @param array $products The catalog products list
     * @return array { created, skipped, failed }
     */
    public function importAll(array $products)
    {
        $stats = ['created' => 0, 'skipped' => 0, 'failed' => 0];
        foreach ($products as $row) {
            $invId = $this->rowInventoryId($row);
            if ($invId === '') {
                $stats['failed']++;
                continue;
            }
            if ($this->getMapping($invId)) {
                $stats['skipped']++;
                continue;
            }
            $res = $this->importProduct($row);
            if (!empty($res['success'])) {
                $stats['created']++;
            } else {
                $stats['failed']++;
            }
        }

        return $stats;
    }

    /**
     * Rewrites every tracked (non-frozen) package's name + description from the
     * latest formatter output.
     *
     * @param array $products The catalog products list
     * @return array { updated, frozen, not_found }
     */
    public function reformatNames(array $products)
    {
        $stats = ['updated' => 0, 'frozen' => 0, 'not_found' => 0];
        $byId = [];
        foreach ($products as $row) {
            $byId[$this->rowInventoryId($row)] = $row;
        }

        $mappings = $this->module->Record->select()
            ->from('mod_reliablesite_packages')
            ->where('tracked', '=', 1)
            ->fetchAll();

        foreach ($mappings as $m) {
            if ((int) $m->frozen === 1) {
                $stats['frozen']++;
                continue;
            }
            $row = isset($byId[(string) $m->rs_inventory_id]) ? $byId[(string) $m->rs_inventory_id] : null;
            if (!$row) {
                $stats['not_found']++;
                continue;
            }
            $this->module->Packages->edit($m->package_id, [
                'names' => [['lang' => 'en_us', 'name' => ReliablesiteProductFormatter::formatName($row)]],
                'descriptions' => [[
                    'lang' => 'en_us',
                    'text' => '',
                    'html' => ReliablesiteProductFormatter::formatDescription($row),
                ]],
                'meta' => ['reliablesite_product_id' => (string) $m->rs_inventory_id],
            ]);
            $stats['updated']++;
        }

        return $stats;
    }

    /**
     * Derives a hardware profile for a catalog row (standard / efi / storage).
     *
     * @param array $row
     * @return string
     */
    public function productProfile(array $row)
    {
        if (!empty($row['option_profile'])) {
            return $row['option_profile'];
        }
        $storage = (isset($row['storage']) && is_array($row['storage'])) ? $row['storage'] : [];
        if (empty($storage)) {
            return 'standard';
        }
        foreach ($storage as $disk) {
            if (strcasecmp(isset($disk['type']) ? $disk['type'] : '', 'NVMe') === 0) {
                return 'efi';
            }
        }
        $allHdd = true;
        foreach ($storage as $disk) {
            if (strcasecmp(isset($disk['type']) ? $disk['type'] : '', 'HDD') !== 0) {
                $allHdd = false;
                break;
            }
        }

        return $allHdd ? 'storage' : 'standard';
    }

    // -----------------------------------------------------------------
    // Internal helpers
    // -----------------------------------------------------------------

    private function findCatalogRow(array $products, $inventoryId)
    {
        foreach ($products as $r) {
            $candidate = $this->rowInventoryId($r);
            if ($candidate !== '' && $candidate === (string) $inventoryId) {
                return $r;
            }
        }

        return null;
    }

    private function rowInventoryId(array $row)
    {
        if (isset($row['product_id']) && $row['product_id'] !== '') {
            return (string) $row['product_id'];
        }
        if (isset($row['id']) && $row['id'] !== '') {
            return (string) $row['id'];
        }

        return '';
    }

    private function rowStock(array $row)
    {
        return (int) (isset($row['stock']) ? $row['stock'] : 0);
    }

    private function monthlyBase(array $row)
    {
        $pricing = (isset($row['pricing']) && is_array($row['pricing'])) ? $row['pricing'] : [];
        if (isset($pricing['monthly'])) {
            return (float) $pricing['monthly'];
        }
        if (isset($row['monthly_price'])) {
            return (float) $row['monthly_price'];
        }

        return 0.0;
    }

    /**
     * Builds a full Blesta pricing array from a catalog row, honoring cycle_mode.
     *
     * @param array $row
     * @param string $currency
     * @return array
     */
    private function buildPricing(array $row, $currency)
    {
        $pricing = (isset($row['pricing']) && is_array($row['pricing'])) ? $row['pricing'] : [];
        if (!isset($pricing['monthly']) && isset($row['monthly_price'])) {
            $pricing['monthly'] = (float) $row['monthly_price'];
        }

        $cycleMode = $this->module->getSetting('cycle_mode');
        $monthlyOnly = ($cycleMode !== 'all');

        $out = [];
        foreach (self::$cycleMap as $cycleKey => $meta) {
            if ($monthlyOnly && $cycleKey !== 'monthly') {
                continue;
            }
            if (!isset($pricing[$cycleKey])) {
                continue;
            }
            $base = (float) $pricing[$cycleKey];
            if ($base <= 0) {
                continue;
            }
            $price = $this->applyMarkup($base, $meta['months']);
            $out[] = [
                'term' => $meta['term'],
                'period' => $meta['period'],
                'currency' => $currency,
                'price' => $price,
                // Renewal price must match the recurring price; a null/empty
                // price_renews is stored as 0 by Blesta (free renewals).
                'price_renews' => $price,
                'setup_fee' => '',
                'cancel_fee' => '',
            ];
        }

        return $out;
    }

    /**
     * Rebuilds the pricing array for an existing package, preserving each
     * existing price row's id/currency and updating the amount from the catalog.
     *
     * @param object $package
     * @param array $row
     * @return array
     */
    private function rebuildExistingPricing($package, array $row)
    {
        $pricing = (isset($row['pricing']) && is_array($row['pricing'])) ? $row['pricing'] : [];
        if (!isset($pricing['monthly']) && isset($row['monthly_price'])) {
            $pricing['monthly'] = (float) $row['monthly_price'];
        }
        if (empty($package->pricing)) {
            return [];
        }

        $out = [];
        foreach ($package->pricing as $existing) {
            $cycleKey = $this->cycleKeyFor($existing->term, $existing->period);
            $entry = [
                'id' => $existing->id,
                'term' => $existing->term,
                'period' => $existing->period,
                'currency' => $existing->currency,
                'price' => $existing->price,
                'price_renews' => $existing->price,
                'setup_fee' => ($existing->setup_fee !== null ? $existing->setup_fee : ''),
                'cancel_fee' => ($existing->cancel_fee !== null ? $existing->cancel_fee : ''),
            ];
            if ($cycleKey && isset($pricing[$cycleKey]) && (float) $pricing[$cycleKey] > 0) {
                $months = self::$cycleMap[$cycleKey]['months'];
                $entry['price'] = $this->applyMarkup((float) $pricing[$cycleKey], $months);
                $entry['price_renews'] = $entry['price'];
            }
            $out[] = $entry;
        }

        return $out;
    }

    private function cycleKeyFor($term, $period)
    {
        foreach (self::$cycleMap as $key => $meta) {
            if ((int) $meta['term'] === (int) $term && $meta['period'] === $period) {
                return $key;
            }
        }

        return null;
    }

    /**
     * Applies the configured markup to a base price.
     *
     * @param float $base
     * @param int $cycleMonths
     * @return string Price rounded to 2 decimals
     */
    private function applyMarkup($base, $cycleMonths)
    {
        $type = $this->module->getSetting('markup_type');
        if (empty($type)) {
            $type = 'percent';
        }
        $value = (float) $this->module->getSetting('markup_value');

        if ($type === 'flat') {
            $price = $base + ($value * $cycleMonths);
        } else {
            $price = $base * (1 + ($value / 100));
        }

        return number_format(round($price, 2), 2, '.', '');
    }

    private function sendReport(array $stats, $trigger, $logId)
    {
        if ($this->module->getSetting('sync_report_enabled') != '1') {
            return;
        }
        $this->module->sendSyncReport($stats, $trigger, $logId);
    }

    private function flattenErrors($errors)
    {
        $out = [];
        foreach ((array) $errors as $field) {
            foreach ((array) $field as $message) {
                $out[] = is_scalar($message) ? $message : json_encode($message);
            }
        }

        return implode('; ', $out);
    }
}
