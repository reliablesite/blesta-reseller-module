<?php
/**
 * Syncs the ReliableSite configurable-options catalog into Blesta's native
 * package configurable options.
 *
 * Each ReliableSite option *group* (e.g. 577 "Operating System") maps 1:1 to a
 * Blesta PackageOptionGroup holding one PackageOption of type 'select'; each RS
 * *addon* maps to an option value. The RS ids are persisted so an order can be
 * rebuilt with no guesswork:
 *
 *   - PackageOption.name  = "rs_opt_{group_id}"   (internal marker)
 *   - PackageOptionValue.value = "{addon_id}"      (the value returned at order time)
 *
 * Because the addon id is stored as the value, {@see ReliablesiteOrderService}
 * can read the selection straight back from Services::getOptions() and build the
 * Order API configOptions map with no extra lookup.
 *
 * Which groups apply to which package is decided by
 * {@see ReliablesiteInventoryOptions}. Group<->package membership is recomputed
 * on every full sync run.
 *
 * Blesta port of the Paymenter module's Support\OptionSync.
 *
 * @package reliablesite.lib
 */
class ReliablesiteOptionSync
{
    /** Internal-name prefix marking a Blesta option as ReliableSite-managed. */
    const NAME_PREFIX = 'rs_opt_';

    /**
     * Catalog cycle key => Blesta (term, period).
     */
    private static $cycleMap = [
        'monthly' => ['term' => 1, 'period' => 'month'],
        'quarterly' => ['term' => 3, 'period' => 'month'],
        'semi_annual' => ['term' => 6, 'period' => 'month'],
        'annual' => ['term' => 1, 'period' => 'year'],
        'biennial' => ['term' => 2, 'period' => 'year'],
        'triennial' => ['term' => 3, 'period' => 'year'],
    ];

    /** @var Reliablesite The owning module */
    private $module;

    /** @var int The active company id */
    private $companyId;

    /**
     * @param Reliablesite $module
     */
    public function __construct($module)
    {
        $this->module = $module;
        Loader::loadModels($this->module, ['PackageOptions', 'PackageOptionGroups', 'Record']);
        $this->companyId = (int) Configure::get('Blesta.company_id');
        // Self-heal: guarantee the mapping table exists before any query touches
        // it, so the feature works even without a formal Blesta module upgrade.
        $this->module->ensureOptionGroupsTable();
    }

    /**
     * Upserts every option group in the feed into Blesta configurable options.
     *
     * @param array $feed The ReliablesiteApi::inventoryOptions() data { hash, groups }
     * @return array Stats { groups, options_created, options_updated, unchanged, failed }
     */
    public function syncFromFeed(array $feed)
    {
        $stats = ['groups' => 0, 'created' => 0, 'updated' => 0, 'unchanged' => 0, 'failed' => 0];

        $groups = isset($feed['groups']) && is_array($feed['groups']) ? $feed['groups'] : [];
        $hash = isset($feed['hash']) ? (string) $feed['hash'] : '';

        foreach ($groups as $key => $group) {
            if (!is_array($group)) {
                continue;
            }
            $rsGroupId = (int) (isset($group['group_id']) ? $group['group_id'] : $key);
            if ($rsGroupId <= 0) {
                continue;
            }
            $stats['groups']++;
            try {
                $result = $this->upsertGroup($rsGroupId, $group, $hash);
                if (isset($stats[$result])) {
                    $stats[$result]++;
                }
            } catch (\Throwable $e) {
                $stats['failed']++;
                $this->module->recoverRecord();
                $this->module->addSyncLog('Option group #' . $rsGroupId . ' failed: ' . $e->getMessage());
            }
        }

        return $stats;
    }

    /**
     * Creates or updates the Blesta option group + option for one RS group.
     *
     * @param int $rsGroupId
     * @param array $group The feed group
     * @param string $hash The current feed hash
     * @return string created|updated|unchanged
     */
    private function upsertGroup($rsGroupId, array $group, $hash)
    {
        $label = trim((string) (isset($group['description']) ? $group['description'] : ''));
        if ($label === '') {
            $label = 'Option group ' . $rsGroupId;
        }
        $groupName = 'ReliableSite: ' . $label;

        $mapping = $this->getGroupMapping($rsGroupId);

        // Nothing changed upstream since the last sync - leave values untouched.
        if ($mapping && $hash !== '' && (string) $mapping->last_hash === $hash) {
            return 'unchanged';
        }

        // A select option must have at least one value; skip empty groups.
        if (empty($group['options']) || !is_array($group['options'])) {
            return 'unchanged';
        }

        // Self-heal: if the mapping row was lost but the Blesta option still
        // exists (matched by its rs_opt_{id} name), adopt it instead of creating
        // a duplicate.
        if (!$mapping) {
            $found = $this->findExistingOption($rsGroupId);
            if ($found) {
                $this->saveGroupMapping($rsGroupId, (int) $found->option_group_id, (int) $found->option_id, '');
                $mapping = $this->getGroupMapping($rsGroupId);
            }
        }

        if (!$mapping) {
            // Create the option group (membership is set by syncPackageMembership()).
            $optionGroupId = $this->module->PackageOptionGroups->add([
                'company_id' => $this->companyId,
                'name' => $groupName,
                'description' => $label,
                'hidden' => 0,
            ]);
            if (!$optionGroupId) {
                throw new Exception($this->flattenErrors($this->module->PackageOptionGroups->errors()));
            }

            $optionId = $this->module->PackageOptions->add([
                'company_id' => $this->companyId,
                'label' => $label,
                'name' => self::NAME_PREFIX . $rsGroupId,
                'type' => 'select',
                'addable' => 1,
                'editable' => 1,
                'hidden' => 0,
                'values' => $this->buildValues($group),
                'groups' => [$optionGroupId],
            ]);
            if (!$optionId) {
                throw new Exception($this->flattenErrors($this->module->PackageOptions->errors()));
            }

            $this->saveGroupMapping($rsGroupId, $optionGroupId, $optionId, $hash);

            return 'created';
        }

        // Update the existing group + option in place (additive; never deletes
        // values that may already be in use by a live service).
        $this->module->PackageOptionGroups->edit((int) $mapping->option_group_id, [
            'name' => $groupName,
            'description' => $label,
        ]);

        $existing = $this->loadExistingValues((int) $mapping->option_id);
        $this->module->PackageOptions->edit((int) $mapping->option_id, [
            'label' => $label,
            'name' => self::NAME_PREFIX . $rsGroupId,
            'type' => 'select',
            'addable' => 1,
            'editable' => 1,
            'hidden' => 0,
            'values' => $this->buildValues($group, $existing),
            'groups' => [(int) $mapping->option_group_id],
        ]);

        $this->saveGroupMapping($rsGroupId, (int) $mapping->option_group_id, (int) $mapping->option_id, $hash);

        return 'updated';
    }

    /**
     * Builds the option-values array from a feed group's addons. When $existing
     * is supplied (edit path), matched addons carry their value/pricing ids so
     * the update happens in place rather than duplicating rows.
     *
     * @param array $group
     * @param array $existing value_string => { id, pricing: { "term|period|currency" => link_id } }
     * @return array
     */
    private function buildValues(array $group, array $existing = [])
    {
        $addons = isset($group['options']) && is_array($group['options']) ? $group['options'] : [];
        $values = [];
        // Only assign a default on first creation; on edit, leave stored default
        // flags untouched so we can't end up asserting two defaults at once.
        $markDefault = empty($existing);
        $first = true;

        foreach ($addons as $addon) {
            if (!is_array($addon)) {
                continue;
            }
            $addonId = (int) (isset($addon['addon_id']) ? $addon['addon_id'] : 0);
            if ($addonId <= 0) {
                continue;
            }
            $name = trim((string) (isset($addon['description']) ? $addon['description'] : ''));
            if ($name === '') {
                $name = 'Option ' . $addonId;
            }
            $valueString = (string) $addonId;
            $prior = isset($existing[$valueString]) ? $existing[$valueString] : null;

            $entry = [
                'name' => $name,
                'value' => $valueString,
                'status' => 'active',
                'pricing' => $this->buildValuePricing($addon, $prior ? $prior['pricing'] : []),
            ];
            if ($markDefault) {
                $entry['default'] = $first ? 1 : 0;
            }
            if ($prior) {
                $entry['id'] = $prior['id'];
            }
            $values[] = $entry;
            $first = false;
        }

        return $values;
    }

    /**
     * Builds the per-cycle pricing array for one addon, honoring cycle_mode and
     * (on edit) reusing existing pricing-link ids.
     *
     * @param array $addon
     * @param array $existingPricing "term|period|currency" => package_option_pricing.id
     * @return array
     */
    private function buildValuePricing(array $addon, array $existingPricing = [])
    {
        $prices = isset($addon['pricing']) && is_array($addon['pricing']) ? $addon['pricing'] : [];
        $setupFees = isset($addon['setup_fees']) && is_array($addon['setup_fees']) ? $addon['setup_fees'] : [];
        $currency = $this->currency();
        $monthlyOnly = ($this->module->getSetting('cycle_mode') !== 'all');

        $out = [];
        foreach (self::$cycleMap as $cycleKey => $meta) {
            if ($monthlyOnly && $cycleKey !== 'monthly') {
                continue;
            }
            if (!array_key_exists($cycleKey, $prices) || !is_numeric($prices[$cycleKey])) {
                continue;
            }
            $price = $this->optionMarkup((float) $prices[$cycleKey]);
            $setupRaw = isset($setupFees[$cycleKey]) && is_numeric($setupFees[$cycleKey]) ? (float) $setupFees[$cycleKey] : 0.0;
            $setup = $this->optionMarkup($setupRaw);

            $entry = [
                'term' => $meta['term'],
                'period' => $meta['period'],
                'currency' => $currency,
                'price' => $price,
                'price_renews' => $price,
                'setup_fee' => $setup,
            ];
            $mapKey = $meta['term'] . '|' . $meta['period'] . '|' . $currency;
            if (isset($existingPricing[$mapKey])) {
                $entry['id'] = $existingPricing[$mapKey];
            }
            $out[] = $entry;
        }

        return $out;
    }

    /**
     * Applies markup to an option price. Only the percentage markup applies -
     * a flat per-server markup must not be duplicated onto every option (it
     * would turn free OS choices into paid ones), so flat-mode options pass
     * through at wholesale.
     *
     * @param float $base
     * @return string Price rounded to 2 decimals
     */
    private function optionMarkup($base)
    {
        if ($base <= 0) {
            return '0.00';
        }
        $type = $this->module->getSetting('markup_type');
        if (empty($type)) {
            $type = 'percent';
        }
        if ($type !== 'percent') {
            return number_format(round($base, 2), 2, '.', '');
        }
        $value = (float) $this->module->getSetting('markup_value');

        return number_format(round($base * (1 + ($value / 100)), 2), 2, '.', '');
    }

    /**
     * Loads the existing option values + pricing-link ids for an option so an
     * edit can update rows in place.
     *
     * @param int $optionId
     * @return array value_string => { id, pricing: { "term|period|currency" => link_id } }
     */
    private function loadExistingValues($optionId)
    {
        $rows = $this->module->Record->select([
                'package_option_values.id' => 'value_id',
                'package_option_values.value' => 'value',
                'package_option_pricing.id' => 'link_id',
                'pricings.term' => 'term',
                'pricings.period' => 'period',
                'pricings.currency' => 'currency',
            ])
            ->from('package_option_values')
            ->leftJoin(
                'package_option_pricing',
                'package_option_pricing.option_value_id',
                '=',
                'package_option_values.id',
                false
            )
            ->leftJoin('pricings', 'pricings.id', '=', 'package_option_pricing.pricing_id', false)
            ->where('package_option_values.option_id', '=', (int) $optionId)
            ->fetchAll();

        $out = [];
        foreach ($rows as $r) {
            $value = (string) $r->value;
            if (!isset($out[$value])) {
                $out[$value] = ['id' => (int) $r->value_id, 'pricing' => []];
            }
            if ($r->link_id !== null && $r->term !== null) {
                $key = (int) $r->term . '|' . $r->period . '|' . $r->currency;
                $out[$value]['pricing'][$key] = (int) $r->link_id;
            }
        }

        return $out;
    }

    /**
     * Recomputes group<->package membership for every mapped option group,
     * based on each tracked package's inventory profile.
     *
     * @return array Stats { groups, packages }
     */
    public function syncPackageMembership()
    {
        $stats = ['groups' => 0, 'packages' => 0];

        $groupMappings = $this->module->Record->select()
            ->from('mod_reliablesite_option_groups')
            ->fetchAll();
        if (empty($groupMappings)) {
            return $stats;
        }

        // inventory_id => package_id for tracked packages.
        $packages = $this->module->Record->select(['rs_inventory_id', 'package_id'])
            ->from('mod_reliablesite_packages')
            ->where('tracked', '=', 1)
            ->fetchAll();
        if (empty($packages)) {
            return $stats;
        }

        $catalog = $this->module->getApi()->catalogProducts();
        $products = (!empty($catalog['success']) && isset($catalog['data']['products']))
            ? $catalog['data']['products']
            : [];

        // Build rs_group_id => [package_id, ...] from each package's profile.
        $membership = [];
        foreach ($packages as $pkg) {
            $row = $this->findProduct($products, (string) $pkg->rs_inventory_id);
            if (!$row) {
                continue;
            }
            foreach (ReliablesiteInventoryOptions::groupsForProduct($row) as $rsGroupId) {
                $membership[(int) $rsGroupId][] = (int) $pkg->package_id;
            }
        }

        foreach ($groupMappings as $gm) {
            $rsGroupId = (int) $gm->rs_group_id;
            $desired = isset($membership[$rsGroupId]) ? array_values(array_unique($membership[$rsGroupId])) : [];

            $group = $this->module->PackageOptionGroups->get((int) $gm->option_group_id);
            if (!$group) {
                continue;
            }
            $this->module->PackageOptionGroups->edit((int) $gm->option_group_id, [
                'name' => $group->name,
                'description' => isset($group->description) ? $group->description : '',
                'packages' => $desired,
            ]);
            $stats['groups']++;
            $stats['packages'] += count($desired);
        }

        return $stats;
    }

    /**
     * Attaches a single package to the option groups applicable to its inventory
     * row, without disturbing other packages' membership. Used at import time.
     *
     * @param int $packageId
     * @param array $product The catalog product row
     * @return int Number of groups attached
     */
    public function attachPackage($packageId, array $product)
    {
        $rsGroupIds = ReliablesiteInventoryOptions::groupsForProduct($product);
        if (empty($rsGroupIds)) {
            return 0;
        }

        $attached = 0;
        foreach ($rsGroupIds as $rsGroupId) {
            $mapping = $this->getGroupMapping((int) $rsGroupId);
            if (!$mapping) {
                continue;
            }
            $exists = $this->module->Record->select(['package_id'])
                ->from('package_option')
                ->where('option_group_id', '=', (int) $mapping->option_group_id)
                ->where('package_id', '=', (int) $packageId)
                ->fetch();
            if ($exists) {
                continue;
            }
            // package_option links an option group to a package (option_group_id,
            // package_id); it has no order column - mirror PackageOptionGroups::setPackages().
            $this->module->Record->insert('package_option', [
                'option_group_id' => (int) $mapping->option_group_id,
                'package_id' => (int) $packageId,
            ]);
            $attached++;
        }

        return $attached;
    }

    /**
     * Finds an existing Blesta option (and one of its groups) previously synced
     * for this RS group, by its rs_opt_{id} internal name. Used to self-heal a
     * lost mapping row without creating duplicates.
     *
     * @param int $rsGroupId
     * @return object|null { option_id, option_group_id }
     */
    private function findExistingOption($rsGroupId)
    {
        return $this->module->Record->select([
                'package_options.id' => 'option_id',
                'package_option_group.option_group_id' => 'option_group_id',
            ])
            ->from('package_options')
            ->leftJoin(
                'package_option_group',
                'package_option_group.option_id',
                '=',
                'package_options.id',
                false
            )
            ->where('package_options.company_id', '=', $this->companyId)
            ->where('package_options.name', '=', self::NAME_PREFIX . $rsGroupId)
            ->fetch();
    }

    /**
     * @param int $rsGroupId
     * @return object|null
     */
    private function getGroupMapping($rsGroupId)
    {
        return $this->module->Record->select()
            ->from('mod_reliablesite_option_groups')
            ->where('rs_group_id', '=', (string) $rsGroupId)
            ->fetch();
    }

    private function saveGroupMapping($rsGroupId, $optionGroupId, $optionId, $hash)
    {
        $existing = $this->getGroupMapping($rsGroupId);
        $data = [
            'rs_group_id' => (string) $rsGroupId,
            'option_group_id' => (int) $optionGroupId,
            'option_id' => (int) $optionId,
            'last_hash' => (string) $hash,
            'last_sync' => date('Y-m-d H:i:s'),
        ];
        if ($existing) {
            $this->module->Record->where('id', '=', $existing->id)->update('mod_reliablesite_option_groups', $data);
        } else {
            $this->module->Record->insert('mod_reliablesite_option_groups', $data);
        }
    }

    private function findProduct(array $products, $inventoryId)
    {
        foreach ($products as $row) {
            if (!is_array($row)) {
                continue;
            }
            $candidate = isset($row['product_id']) && $row['product_id'] !== '' ? (string) $row['product_id']
                : (isset($row['id']) && $row['id'] !== '' ? (string) $row['id'] : '');
            if ($candidate !== '' && $candidate === (string) $inventoryId) {
                return $row;
            }
        }

        return null;
    }

    private function currency()
    {
        $currency = $this->module->getSetting('import_currency_code');

        return !empty($currency) ? $currency : 'USD';
    }

    private function flattenErrors($errors)
    {
        $out = [];
        foreach ((array) $errors as $field) {
            foreach ((array) $field as $message) {
                $out[] = is_scalar($message) ? $message : json_encode($message);
            }
        }

        return $out ? implode('; ', $out) : 'Unknown error';
    }
}
