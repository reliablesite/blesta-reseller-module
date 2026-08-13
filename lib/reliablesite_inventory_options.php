<?php
/**
 * Profile/group logic for ReliableSite configurable options.
 *
 * The inventory catalog publishes, per product, exactly which option groups that
 * product should be offered:
 *
 *     "product_id": "262",
 *     "option_profile": "standard",
 *     "option_group_ids": ["565","578","579","580","618","634", ...]
 *
 * That assignment is authoritative and is *not* derivable from the spec sheet -
 * the feed carries six distinct "Bandwidth" groups and three distinct "Operating
 * System" groups, and which one a product gets is a merchandising decision made
 * upstream. Guessing it from disk types and RAM capacity (as this class used to)
 * silently mis-prices products: a 5 TB-capped server guessed onto the unmetered
 * ladder gives the 1 Gbps unmetered upgrade away for $0 instead of charging for
 * it, and force-attaching the legacy OS group on top of the current one leaves
 * the package with two "Operating System" dropdowns.
 *
 * So: {@see self::groupsForProduct()} reads option_group_ids straight from the
 * product row, and only falls back to the legacy hardcoded lists for feed rows
 * that predate the field. A group id the options feed does not carry can never
 * reach Blesta, because {@see ReliablesiteOptionSync} only attaches groups it
 * has a mapping row for, and mapping rows are created solely from the options
 * feed.
 *
 * Blesta port of the Paymenter module's Support\InventoryOptions.
 *
 * @package reliablesite.lib
 */
class ReliablesiteInventoryOptions
{
    /**
     * Every option group the feed uses for OS selection. A product carries
     * exactly one of these; anything matching on a single hardcoded id (577)
     * silently misses products on 651 or 655.
     */
    public static $osGroupIds = [577, 651, 655];

    // -----------------------------------------------------------------
    // Legacy fallback lists
    //
    // Used only for catalog rows with no option_group_ids. They cannot reach
    // most of the feed's groups and disagree with the published assignment on
    // essentially every current product - treat them as a last resort, never as
    // the source of truth.
    // -----------------------------------------------------------------

    /** Offered on every server product. */
    public static $universalGroups = [577, 578, 579, 580, 618, 642, 643, 645, 654];

    /** Hardware upgrade groups - only for build-to-order products (empty RAM capacity). */
    public static $hardwareGroups = [576, 621, 626, 627, 631, 632, 649, 652];

    /** Profile-specific groups keyed by storage profile. */
    public static $profileGroups = [
        'efi' => [565, 622, 653, 655],
        'standard' => [565, 622, 653, 651],
        'storage' => [647],
    ];

    /**
     * The option group IDs the feed assigns to a product.
     *
     * @param array $product Inventory product row
     * @return int[] De-duplicated integer group IDs, empty when the row carries
     *  no assignment
     */
    public static function assignedGroups(array $product)
    {
        $assigned = isset($product['option_group_ids']) ? $product['option_group_ids'] : null;
        if (!is_array($assigned)) {
            return [];
        }

        $groups = [];
        foreach ($assigned as $groupId) {
            $groupId = (int) $groupId;
            if ($groupId > 0) {
                $groups[] = $groupId;
            }
        }

        return array_values(array_unique($groups));
    }

    /**
     * Whether the feed told us which groups this product gets (as opposed to us
     * having to fall back to the legacy guess). Reported in the sync log so a
     * feed regression is visible rather than silent.
     *
     * @param array $product Inventory product row
     * @return bool
     */
    public static function hasAssignment(array $product)
    {
        return !empty(self::assignedGroups($product));
    }

    /**
     * The product's option profile.
     *
     * The feed publishes this directly; the disk-type derivation below is only
     * a fallback, and it disagrees with the published profile on well over half
     * the catalog (NVMe-plus-HDD mixes in particular).
     *
     * @param array $product Inventory product row
     * @return string 'efi', 'storage', or 'standard'
     */
    public static function getProductProfile(array $product)
    {
        $profile = trim((string) (isset($product['option_profile']) ? $product['option_profile'] : ''));
        if ($profile !== '') {
            return $profile;
        }

        $storage = isset($product['storage']) && is_array($product['storage']) ? $product['storage'] : [];
        if (empty($storage)) {
            return 'standard';
        }

        foreach ($storage as $disk) {
            $type = isset($disk['type']) ? $disk['type'] : '';
            if (strcasecmp($type, 'NVMe') === 0) {
                return 'efi';
            }
        }

        $allHdd = true;
        foreach ($storage as $disk) {
            $type = isset($disk['type']) ? $disk['type'] : '';
            if (strcasecmp($type, 'HDD') !== 0) {
                $allHdd = false;
                break;
            }
        }

        return $allHdd ? 'storage' : 'standard';
    }

    /**
     * Legacy group guess for a product with no published assignment.
     *
     * Build-to-order products (empty ram.capacity) additionally receive the
     * hardware upgrade groups; pre-configured products only get universal +
     * profile-specific groups.
     *
     * @param string $profile 'efi', 'standard', or 'storage'
     * @param array $product Inventory product row
     * @return int[] De-duplicated integer group IDs
     */
    public static function getGroupsForProfile($profile, array $product = [])
    {
        $profileSpecific = isset(self::$profileGroups[$profile])
            ? self::$profileGroups[$profile]
            : self::$profileGroups['standard'];
        $groups = array_merge(self::$universalGroups, $profileSpecific);

        $ramCapacity = trim((string) (isset($product['ram']['capacity']) ? $product['ram']['capacity'] : ''));
        if ($ramCapacity === '') {
            $groups = array_merge($groups, self::$hardwareGroups);
        }

        return array_values(array_unique(array_map('intval', $groups)));
    }

    /**
     * Resolves the applicable group IDs for a product: the feed's assignment
     * when present, the legacy guess otherwise.
     *
     * @param array $product Inventory product row
     * @return int[]
     */
    public static function groupsForProduct(array $product)
    {
        $assigned = self::assignedGroups($product);
        if (!empty($assigned)) {
            return $assigned;
        }

        return self::getGroupsForProfile(self::getProductProfile($product), $product);
    }

    /**
     * Whether a group id is one of the feed's OS selection groups.
     *
     * @param int $groupId
     * @return bool
     */
    public static function isOsGroup($groupId)
    {
        return in_array((int) $groupId, self::$osGroupIds, true);
    }
}
