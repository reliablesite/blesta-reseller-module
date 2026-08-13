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
 * Which groups apply to which package comes from the catalog feed itself (see
 * {@see ReliablesiteInventoryOptions}); membership is recomputed on every full
 * sync run.
 *
 * Two rules govern removal, because Blesta's PackageOptions::edit() deletes any
 * option value not present in the submitted set - along with its pricing rows,
 * which service_options points at:
 *
 *   1. A value the feed has dropped is deleted only if no service references it.
 *   2. A value a service does reference is kept and flipped to 'inactive', which
 *      is Blesta's own mechanism for "not orderable any more, but preserved for
 *      the services already on it". It is re-evaluated on the next run and goes
 *      away once the last service using it is gone.
 *
 * The same reasoning applies one level up: a package keeps its link to an option
 * group while a live service on that package still has a selection in it, so the
 * option cannot vanish out from under a service edit or renewal.
 *
 * Blesta port of the Paymenter module's Support\OptionSync.
 *
 * @package reliablesite.lib
 */
class ReliablesiteOptionSync
{
    /** Internal-name prefix marking a Blesta option as ReliableSite-managed. */
    const NAME_PREFIX = 'rs_opt_';

    /** How many individual retained memberships to name in the sync log. */
    const MAX_RETENTION_NOTES = 10;

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

    /** @var array Per-run notes about retained (in-use) values, for the sync log */
    private $retained = [];

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
     * The feed-wide hash is deliberately ignored: it rolls whenever any group
     * anywhere changes, so trusting it would rewrite all 50-odd groups over one
     * price edit. Each group carries its own fingerprint instead.
     *
     * @param array $feed The ReliablesiteApi::inventoryOptions() data { hash, groups }
     * @return array Stats { groups, created, updated, unchanged, failed, values_removed, values_retained }
     */
    public function syncFromFeed(array $feed)
    {
        $stats = [
            'groups' => 0,
            'created' => 0,
            'updated' => 0,
            'unchanged' => 0,
            'failed' => 0,
            'values_removed' => 0,
            'values_retained' => 0,
        ];

        $groups = isset($feed['groups']) && is_array($feed['groups']) ? $feed['groups'] : [];
        $this->retained = [];

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
                $result = $this->upsertGroup($rsGroupId, $group);
                if (isset($stats[$result['status']])) {
                    $stats[$result['status']]++;
                }
                $stats['values_removed'] += $result['removed'];
                $stats['values_retained'] += $result['retained'];
            } catch (\Throwable $e) {
                $stats['failed']++;
                $this->module->recoverRecord();
                $this->module->addSyncLog('Option group #' . $rsGroupId . ' failed: ' . $e->getMessage());
            }
        }

        foreach ($this->retained as $note) {
            $this->module->addSyncLog($note);
        }

        return $stats;
    }

    /**
     * Creates or updates the Blesta option group + option for one RS group.
     *
     * @param int $rsGroupId
     * @param array $group The feed group
     * @return array { status: created|updated|unchanged, removed: int, retained: int }
     */
    private function upsertGroup($rsGroupId, array $group)
    {
        $outcome = ['status' => 'unchanged', 'removed' => 0, 'retained' => 0];

        $label = trim((string) (isset($group['description']) ? $group['description'] : ''));
        if ($label === '') {
            $label = 'Option group ' . $rsGroupId;
        }
        $groupName = 'ReliableSite: ' . $label;

        $mapping = $this->getGroupMapping($rsGroupId);
        $fingerprint = $this->groupFingerprint($group);

        // Nothing about this group (or the pricing settings applied to it) has
        // changed since the last run - leave it alone. Fingerprinting per group
        // rather than trusting the feed-wide hash is what keeps a repeat sync
        // silent: the feed hash rolls whenever any group anywhere changes.
        if ($mapping && (string) $mapping->last_hash === $fingerprint) {
            return $outcome;
        }

        // A select option must have at least one value; skip empty groups.
        if (empty($group['options']) || !is_array($group['options'])) {
            return $outcome;
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

            $this->saveGroupMapping($rsGroupId, $optionGroupId, $optionId, $fingerprint);
            $outcome['status'] = 'created';

            return $outcome;
        }

        // Update the existing group + option in place. Blesta deletes any value
        // missing from the submitted set, so work out first which of the values
        // the feed has dropped may actually go.
        $optionId = (int) $mapping->option_id;
        $existing = $this->loadExistingValues($optionId);
        $keep = $this->retainDroppedValues($rsGroupId, $label, $group, $existing);

        $values = array_merge($this->buildValues($group, $existing), $keep['values']);
        if (empty($values)) {
            // Nothing orderable to write; never blank out a live option.
            return $outcome;
        }

        $this->module->PackageOptionGroups->edit((int) $mapping->option_group_id, [
            'name' => $groupName,
            'description' => $label,
        ]);

        $this->module->PackageOptions->edit($optionId, [
            'label' => $label,
            'name' => self::NAME_PREFIX . $rsGroupId,
            'type' => 'select',
            'addable' => 1,
            'editable' => 1,
            'hidden' => 0,
            'values' => $values,
            'groups' => [(int) $mapping->option_group_id],
        ]);
        $errors = $this->module->PackageOptions->errors();
        if (!empty($errors)) {
            throw new Exception($this->flattenErrors($errors));
        }

        $this->saveGroupMapping($rsGroupId, (int) $mapping->option_group_id, $optionId, $fingerprint);

        $outcome['status'] = 'updated';
        $outcome['removed'] = $keep['removed'];
        $outcome['retained'] = count($keep['values']);

        return $outcome;
    }

    /**
     * Works out what to do with the values the feed no longer lists.
     *
     * Ones no service references are simply left out of the submission, which is
     * how Blesta deletes them (value, pricing links and pricings together). Ones
     * a service does reference are resubmitted as 'inactive': hidden from new
     * orders, still resolvable for the services holding them, and re-tested on
     * every later run so they clear on their own.
     *
     * @param int $rsGroupId
     * @param string $label The group's feed label (for the log line)
     * @param array $group The feed group
     * @param array $existing The option's current values, from loadExistingValues()
     * @return array { values: array[], removed: int }
     */
    private function retainDroppedValues($rsGroupId, $label, array $group, array $existing)
    {
        $current = [];
        $addons = isset($group['options']) && is_array($group['options']) ? $group['options'] : [];
        foreach ($addons as $addon) {
            if (is_array($addon) && (int) (isset($addon['addon_id']) ? $addon['addon_id'] : 0) > 0) {
                $current[(string) (int) $addon['addon_id']] = true;
            }
        }

        $dropped = [];
        foreach ($existing as $valueString => $row) {
            if (!isset($current[$valueString])) {
                $dropped[$valueString] = $row;
            }
        }
        if (empty($dropped)) {
            return ['values' => [], 'removed' => 0];
        }

        $ids = [];
        foreach ($dropped as $row) {
            $ids[] = (int) $row['id'];
        }
        $inUse = $this->valuesInUse($ids);

        $values = [];
        $names = [];
        $removed = 0;
        foreach ($dropped as $valueString => $row) {
            if (!isset($inUse[(int) $row['id']])) {
                $removed++;
                continue;
            }
            $name = $row['name'] !== '' ? $row['name'] : ('Option ' . $valueString);
            $values[] = [
                'id' => (int) $row['id'],
                'name' => $name,
                'value' => $valueString,
                'status' => 'inactive',
                // An inactive value may not be the option's default.
                'default' => 0,
                // No pricing key: Blesta only touches prices it is given, so the
                // retained value keeps the price it was last sold at.
            ];
            $names[] = $name . ' (' . $inUse[(int) $row['id']] . ')';
        }

        if (!empty($names)) {
            $this->retained[] = sprintf(
                'Kept %d withdrawn option(s) in "%s" (group #%d) - still selected by existing services, '
                . 'hidden from new orders: %s',
                count($names),
                $label,
                $rsGroupId,
                implode(', ', $names)
            );
        }

        return ['values' => $values, 'removed' => $removed];
    }

    /**
     * Which of the given option values a service is holding.
     *
     * Deliberately counts services in every state, cancelled included: the row
     * being protected is a stored selection, and deleting the value would leave
     * that service_options row pointing at nothing.
     *
     * @param int[] $valueIds
     * @return array value_id => number of services referencing it
     */
    private function valuesInUse(array $valueIds)
    {
        $valueIds = array_values(array_filter(array_map('intval', $valueIds)));
        if (empty($valueIds)) {
            return [];
        }

        // One service holds at most one row per option value, so COUNT(*) is the
        // service count (and Record cannot escape COUNT(DISTINCT ...) anyway).
        $rows = $this->module->Record->select([
                'package_option_pricing.option_value_id' => 'value_id',
                'COUNT(*)' => 'services',
            ])
            ->from('service_options')
            ->innerJoin(
                'package_option_pricing',
                'package_option_pricing.id',
                '=',
                'service_options.option_pricing_id',
                false
            )
            ->where('package_option_pricing.option_value_id', 'in', $valueIds)
            ->group('package_option_pricing.option_value_id')
            ->fetchAll();

        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r->value_id] = (int) $r->services;
        }

        return $out;
    }

    /**
     * A content fingerprint for one feed group.
     *
     * Covers the group's own payload plus every module setting that feeds into a
     * price, so a markup or billing-cycle change re-syncs while an unrelated
     * change elsewhere in the feed does not.
     *
     * @param array $group
     * @return string
     */
    private function groupFingerprint(array $group)
    {
        $payload = [
            'group' => $group,
            'currency' => $this->currency(),
            'cycle_mode' => (string) $this->module->getSetting('cycle_mode'),
            'markup_type' => (string) $this->module->getSetting('markup_type'),
            'markup_value' => (string) $this->module->getSetting('markup_value'),
        ];

        return md5((string) json_encode($payload));
    }

    /**
     * Builds the option-values array from a feed group's addons. When $existing
     * is supplied (edit path), matched addons carry their value/pricing ids so
     * the update happens in place rather than duplicating rows.
     *
     * @param array $group
     * @param array $existing value_string => { id, name, pricing: { "term|period|currency" => link_id } }
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
                // A value the feed lists again is orderable again, which is how
                // a previously retained (inactive) value comes back.
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
     * edit can update rows in place (and so a value the feed has dropped can be
     * resubmitted verbatim when a service still needs it).
     *
     * @param int $optionId
     * @return array value_string => { id, name, pricing: { "term|period|currency" => link_id } }
     */
    private function loadExistingValues($optionId)
    {
        $rows = $this->module->Record->select([
                'package_option_values.id' => 'value_id',
                'package_option_values.value' => 'value',
                'package_option_values.name' => 'name',
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
                $out[$value] = [
                    'id' => (int) $r->value_id,
                    'name' => trim((string) $r->name),
                    'pricing' => [],
                ];
            }
            if ($r->link_id !== null && $r->term !== null) {
                $key = (int) $r->term . '|' . $r->period . '|' . $r->currency;
                $out[$value]['pricing'][$key] = (int) $r->link_id;
            }
        }

        return $out;
    }

    /**
     * Recomputes group<->package membership for every mapped option group from
     * the catalog's published assignment.
     *
     * A package is additionally kept on a group it no longer qualifies for while
     * a live service on that package still has a selection there - otherwise the
     * option disappears from the service's own edit form and the next save drops
     * the customer's configuration.
     *
     * @param array|null $products The catalog products (fetched when not supplied)
     * @return array Stats { groups, packages, changed, retained, legacy }
     */
    public function syncPackageMembership(array $products = null)
    {
        $stats = ['groups' => 0, 'packages' => 0, 'changed' => 0, 'retained' => 0, 'legacy' => 0];

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

        if ($products === null) {
            $catalog = $this->module->getApi()->catalogProducts();
            $products = (!empty($catalog['success']) && isset($catalog['data']['products']))
                ? $catalog['data']['products']
                : [];
        }

        $labels = $this->groupLabels($groupMappings);

        // Build rs_group_id => [package_id, ...] from the feed's assignment.
        $membership = [];
        $duplicates = [];
        $noOs = [];
        foreach ($packages as $pkg) {
            $row = $this->findProduct($products, (string) $pkg->rs_inventory_id);
            if (!$row) {
                continue;
            }
            if (!ReliablesiteInventoryOptions::hasAssignment($row)) {
                $stats['legacy']++;
            }
            $groupIds = ReliablesiteInventoryOptions::groupsForProduct($row);
            foreach ($groupIds as $rsGroupId) {
                $membership[(int) $rsGroupId][] = (int) $pkg->package_id;
            }
            $this->collectDuplicateLabels($groupIds, $labels, $duplicates);
            if (!$this->hasOsGroup($groupIds)) {
                $noOs[] = (int) $pkg->package_id;
            }
        }

        $this->logDuplicateLabels($duplicates);
        if (!empty($noOs)) {
            // Every OS group the feed uses counts here, not just the legacy 577:
            // a package with none has no operating system to order or provision.
            $this->module->addSyncLog(sprintf(
                'WARNING: %d package(s) are assigned no Operating System group by the catalog: #%s',
                count($noOs),
                implode(', #', array_slice($noOs, 0, 20)) . (count($noOs) > 20 ? ', ...' : '')
            ));
        }

        $inUse = $this->liveGroupSelections();

        foreach ($groupMappings as $gm) {
            $rsGroupId = (int) $gm->rs_group_id;
            $optionGroupId = (int) $gm->option_group_id;
            $desired = isset($membership[$rsGroupId]) ? array_values(array_unique($membership[$rsGroupId])) : [];

            $group = $this->module->PackageOptionGroups->get($optionGroupId);
            if (!$group) {
                continue;
            }

            $current = [];
            foreach ((array) (isset($group->packages) ? $group->packages : []) as $packageId) {
                $current[] = (int) $packageId;
            }

            // Hold on to packages whose live services are still using this group.
            foreach ($current as $packageId) {
                if (in_array($packageId, $desired, true)) {
                    continue;
                }
                if (!empty($inUse[$packageId . ':' . $rsGroupId])) {
                    $desired[] = $packageId;
                    $stats['retained']++;
                    // These are the rows worth a manual look, so name them - but
                    // it is a standing condition reported on every run, so cap
                    // the list and let the summary count carry the rest.
                    if ($stats['retained'] <= self::MAX_RETENTION_NOTES) {
                        $this->module->addSyncLog(sprintf(
                            'Kept package #%d on "%s" (group #%d) - %d live service(s) still configured with it.',
                            $packageId,
                            isset($labels[$rsGroupId]) ? $labels[$rsGroupId] : ('group ' . $rsGroupId),
                            $rsGroupId,
                            $inUse[$packageId . ':' . $rsGroupId]
                        ));
                    }
                }
            }

            $stats['groups']++;
            $stats['packages'] += count($desired);

            // Membership is a delete-and-reinsert in Blesta; skip it entirely
            // when nothing moved, so a repeat sync writes nothing.
            sort($desired);
            sort($current);
            if ($desired === $current) {
                continue;
            }

            $this->module->PackageOptionGroups->edit($optionGroupId, [
                'name' => $group->name,
                'description' => isset($group->description) ? $group->description : '',
                'packages' => $desired,
            ]);
            $stats['changed']++;
        }

        return $stats;
    }

    /**
     * Attaches a single package to the option groups the feed assigns to its
     * inventory row, without disturbing other packages' membership. Used at
     * import time.
     *
     * A group id the options feed does not carry has no mapping row, so it is
     * skipped here rather than being able to break the import.
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
     * Which (package, RS group) pairs a non-cancelled service is configured
     * with. Cancelled services are excluded here on purpose: they never renew or
     * get edited again, so holding a whole group onto a package for one would
     * keep offering withdrawn options to new customers.
     *
     * @return array "packageId:rsGroupId" => service count
     */
    private function liveGroupSelections()
    {
        $rows = $this->module->Record->select([
                'package_pricing.package_id' => 'package_id',
                'package_options.name' => 'option_name',
                'COUNT(*)' => 'services',
            ])
            ->from('service_options')
            ->innerJoin('services', 'services.id', '=', 'service_options.service_id', false)
            ->innerJoin('package_pricing', 'package_pricing.id', '=', 'services.pricing_id', false)
            ->innerJoin(
                'package_option_pricing',
                'package_option_pricing.id',
                '=',
                'service_options.option_pricing_id',
                false
            )
            ->innerJoin(
                'package_option_values',
                'package_option_values.id',
                '=',
                'package_option_pricing.option_value_id',
                false
            )
            ->innerJoin('package_options', 'package_options.id', '=', 'package_option_values.option_id', false)
            ->where('services.status', '!=', 'canceled')
            ->like('package_options.name', self::NAME_PREFIX . '%')
            ->group(['package_pricing.package_id', 'package_options.name'])
            ->fetchAll();

        $out = [];
        foreach ($rows as $r) {
            $rsGroupId = (int) substr((string) $r->option_name, strlen(self::NAME_PREFIX));
            if ($rsGroupId <= 0) {
                continue;
            }
            $out[(int) $r->package_id . ':' . $rsGroupId] = (int) $r->services;
        }

        return $out;
    }

    /**
     * rs_group_id => human label, read from the Blesta option groups we own.
     *
     * @param array $groupMappings Rows from mod_reliablesite_option_groups
     * @return array
     */
    private function groupLabels(array $groupMappings)
    {
        $ids = [];
        foreach ($groupMappings as $gm) {
            $ids[] = (int) $gm->option_group_id;
        }
        if (empty($ids)) {
            return [];
        }

        $rows = $this->module->Record->select(['id', 'description', 'name'])
            ->from('package_option_groups')
            ->where('id', 'in', $ids)
            ->fetchAll();
        $byId = [];
        foreach ($rows as $r) {
            $label = trim((string) $r->description);
            $byId[(int) $r->id] = ($label !== '' ? $label : trim((string) $r->name));
        }

        $labels = [];
        foreach ($groupMappings as $gm) {
            $optionGroupId = (int) $gm->option_group_id;
            if (isset($byId[$optionGroupId])) {
                $labels[(int) $gm->rs_group_id] = $byId[$optionGroupId];
            }
        }

        return $labels;
    }

    /**
     * Notes a product the feed assigns two identically-named groups to, which
     * renders as two dropdowns with the same title. That is upstream data to be
     * corrected, not something the module should silently paper over - but it is
     * a property of the assignment, not of any one package, so it is tallied
     * here and reported once by {@see self::logDuplicateLabels()}.
     *
     * @param int[] $groupIds
     * @param array $labels rs_group_id => label
     * @param array $duplicates Tally, keyed by the colliding pair
     */
    private function collectDuplicateLabels(array $groupIds, array $labels, array &$duplicates)
    {
        $seen = [];
        foreach ($groupIds as $rsGroupId) {
            $rsGroupId = (int) $rsGroupId;
            $label = isset($labels[$rsGroupId]) ? $labels[$rsGroupId] : '';
            if ($label === '') {
                continue;
            }
            $key = strtolower($label);
            if (!isset($seen[$key])) {
                $seen[$key] = $rsGroupId;
                continue;
            }

            $pair = [$seen[$key], $rsGroupId];
            sort($pair);
            $tallyKey = $key . '|' . implode(':', $pair);
            if (!isset($duplicates[$tallyKey])) {
                $duplicates[$tallyKey] = ['label' => $label, 'groups' => $pair, 'packages' => 0];
            }
            $duplicates[$tallyKey]['packages']++;
        }
    }

    /**
     * Whether an assignment includes one of the feed's OS selection groups.
     *
     * @param int[] $groupIds
     * @return bool
     */
    private function hasOsGroup(array $groupIds)
    {
        foreach ($groupIds as $rsGroupId) {
            if (ReliablesiteInventoryOptions::isOsGroup($rsGroupId)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array $duplicates The tally built by collectDuplicateLabels()
     */
    private function logDuplicateLabels(array $duplicates)
    {
        foreach ($duplicates as $dupe) {
            $this->module->addSyncLog(sprintf(
                '%d package(s) are assigned two "%s" groups (#%d and #%d) by the catalog and will show two '
                . 'identically named dropdowns until the feed is corrected.',
                $dupe['packages'],
                $dupe['label'],
                $dupe['groups'][0],
                $dupe['groups'][1]
            ));
        }
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
