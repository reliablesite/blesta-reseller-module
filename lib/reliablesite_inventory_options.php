<?php
/**
 * Profile/group logic for ReliableSite configurable options.
 *
 * The inventory-options feed (fetched by ReliablesiteApi::inventoryOptions())
 * exposes every option group globally; which groups actually apply to a given
 * product depends on its storage "profile" and whether it is build-to-order.
 *
 * This class is the single source of truth for that mapping so the option sync
 * ({@see ReliablesiteOptionSync}) attaches the right groups to each package.
 *
 * Blesta port of the Paymenter module's Support\InventoryOptions.
 *
 * @package reliablesite.lib
 */
class ReliablesiteInventoryOptions
{
    /** Inventory option group for Operating System selection. */
    const OS_GROUP_ID = 577;

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
     * Determine the product profile from its storage types.
     *
     * @param array $product Inventory product row
     * @return string 'efi', 'storage', or 'standard'
     */
    public static function getProductProfile(array $product)
    {
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
     * The option group IDs applicable to a product.
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
     * Convenience: resolve the applicable group IDs straight from a product row.
     *
     * @param array $product Inventory product row
     * @return int[]
     */
    public static function groupsForProduct(array $product)
    {
        return self::getGroupsForProfile(self::getProductProfile($product), $product);
    }
}
