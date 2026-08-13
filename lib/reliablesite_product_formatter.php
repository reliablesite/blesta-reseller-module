<?php
/**
 * Synthesizes clean product names and rich descriptions from the structured
 * fields the ReliableSite inventory widget returns. The raw "description" field
 * on each inventory row is marketing-flavored and verbose; this class produces
 * scannable replacements built from cpu / ram / storage / data_center and the
 * profile resolved by {@see ReliablesiteInventoryOptions::getProductProfile()}
 * instead.
 *
 * Plain-PHP port of the Paymenter module's Support\ProductFormatter so the
 * Blesta catalog import and the per-package sync share one source of truth.
 *
 * @package reliablesite.lib
 */
class ReliablesiteProductFormatter
{
    /**
     * Build a short scannable product name. Three families, three formats:
     *
     *   Preconfigured  -> "AMD Epyc 4545P - 128GB DDR5 - 2TB NVMe (Los Angeles)"
     *   Storage server -> "32TB HDD Storage - AMD Epyc 4545P - Miami"
     *   Build-to-order -> "AMD Ryzen 3900X (12C/24T) - Build-to-order - Los Angeles"
     *
     * @param array $row A single inventory catalog row
     * @return string
     */
    public static function formatName(array $row)
    {
        $cpu = self::formatCpu($row);
        $ram = self::formatRam($row);
        $storage = self::formatStorageSummary($row);
        $city = self::cleanCity((string) (isset($row['data_center']) ? $row['data_center'] : ''));
        $profile = ReliablesiteInventoryOptions::getProductProfile($row);
        $isBuildToOrder = trim((string) (isset($row['ram']['capacity']) ? $row['ram']['capacity'] : '')) === '';

        if ($profile === 'storage' && $storage !== '') {
            return self::joinParts([$storage . ' Storage', $cpu, $city], ' - ');
        }

        if ($isBuildToOrder) {
            $cores = isset($row['cpu']['cores']) ? $row['cpu']['cores'] : null;
            $threads = isset($row['cpu']['threads']) ? $row['cpu']['threads'] : null;
            $cpuSpec = $cpu;
            if ($cores && $threads) {
                $cpuSpec .= ' (' . $cores . 'C/' . $threads . 'T)';
            }

            return self::joinParts([$cpuSpec, 'Build-to-order', $city], ' - ');
        }

        $name = self::joinParts(array_filter([$cpu, $ram, $storage]), ' - ');
        if ($city !== '') {
            $name .= ' (' . $city . ')';
        }

        return $name !== '' ? $name : ('ReliableSite ' . self::extractId($row));
    }

    /**
     * Multi-section HTML description suitable for a Blesta package description.
     *
     * @param array $row A single inventory catalog row
     * @return string
     */
    public static function formatDescription(array $row)
    {
        $city = self::cleanCity((string) (isset($row['data_center']) ? $row['data_center'] : 'unknown location'));
        $cpuLine = self::formatCpu($row);
        if ($cpuLine === '') {
            $cpuLine = '-';
        }
        $cores = isset($row['cpu']['cores']) ? $row['cpu']['cores'] : null;
        $threads = isset($row['cpu']['threads']) ? $row['cpu']['threads'] : null;
        if ($cores && $threads) {
            $cpuLine .= ' (' . $cores . ' cores / ' . $threads . ' threads)';
        }

        $ramCap = trim((string) (isset($row['ram']['capacity']) ? $row['ram']['capacity'] : ''));
        $ram = $ramCap !== '' ? self::formatRam($row) : 'Configurable at order time';
        $storage = self::formatStorageSummary($row);
        if ($storage === '') {
            $storage = 'Configurable at order time';
        }
        $network = self::formatNetworkSpeed($row);
        $profile = ReliablesiteInventoryOptions::getProductProfile($row);
        $isBuildToOrder = $ramCap === '';

        $items = [
            'CPU: ' . $cpuLine,
            'RAM: ' . $ram,
            'Storage: ' . $storage,
        ];
        if ($network !== null) {
            $items[] = 'Network: ' . $network;
        }
        if ($profile === 'efi') {
            $items[] = 'NVMe-optimized server with EFI boot.';
        } elseif ($profile === 'storage') {
            $items[] = 'High-capacity storage-optimized configuration.';
        }

        $html = '<p>Dedicated server in <strong>' . htmlspecialchars($city) . '</strong>.</p>';
        $html .= '<p><strong>Specifications:</strong></p><ul>';
        foreach ($items as $item) {
            $html .= '<li>' . htmlspecialchars($item) . '</li>';
        }
        $html .= '</ul>';
        if ($isBuildToOrder) {
            $html .= '<p><em>This server is build-to-order - RAM, storage, and other options are '
                . 'configurable at order time.</em></p>';
        }

        return $html;
    }

    /**
     * "{count}x {size} {type}" per disk, joined by " + ". Empty if no disks.
     *
     * @param array $row A single inventory catalog row
     * @return string
     */
    public static function formatStorageSummary(array $row)
    {
        $storage = isset($row['storage']) ? $row['storage'] : [];
        if (!is_array($storage) || empty($storage)) {
            return '';
        }

        $disks = [];
        foreach ($storage as $disk) {
            $count = (int) (isset($disk['count']) ? $disk['count'] : 1);
            $size = trim((string) (isset($disk['size']) ? $disk['size'] : (isset($disk['capacity']) ? $disk['capacity'] : '')));
            $type = trim((string) (isset($disk['type']) ? $disk['type'] : ''));
            if ($size === '') {
                continue;
            }
            $label = ($count > 1 ? ($count . 'x ' . $size) : $size);
            if ($type !== '') {
                $label .= ' ' . $type;
            }
            $disks[] = $label;
        }

        return implode(' + ', $disks);
    }

    /**
     * "Los Angeles (LAX)" -> "Los Angeles". Strips a trailing parenthesized code.
     *
     * @param string $dataCenter
     * @return string
     */
    public static function cleanCity($dataCenter)
    {
        $cleaned = preg_replace('/\s*\([^)]*\)\s*$/', '', $dataCenter);

        return trim((string) $cleaned);
    }

    private static function formatCpu(array $row)
    {
        $raw = trim((string) (isset($row['cpu']['raw_string']) ? $row['cpu']['raw_string'] : ''));
        if ($raw !== '') {
            return $raw;
        }
        $brand = isset($row['cpu']['brand']) ? $row['cpu']['brand'] : '';
        $model = isset($row['cpu']['model']) ? $row['cpu']['model'] : '';
        $combo = trim($brand . ' ' . $model);

        return $combo !== '' ? $combo : 'Dedicated server';
    }

    private static function formatRam(array $row)
    {
        $capacity = trim((string) (isset($row['ram']['capacity']) ? $row['ram']['capacity'] : ''));
        $type = trim((string) (isset($row['ram']['type']) ? $row['ram']['type'] : ''));
        if ($capacity === '' && $type === '') {
            return '';
        }

        return trim($capacity . ' ' . $type);
    }

    /**
     * Network speed is unreliable in the catalog - some rows contain garbage
     * like "2 TB NVMe" (storage info misfiled). Only emit if it looks like a
     * real link speed.
     */
    private static function formatNetworkSpeed(array $row)
    {
        $speed = trim((string) (isset($row['network']['speed'])
            ? $row['network']['speed']
            : (isset($row['nic']['speed']) ? $row['nic']['speed'] : '')));
        if ($speed === '') {
            return null;
        }
        if (!preg_match('/(Gbps|Mbps|Gbit|Mbit)/i', $speed)) {
            return null;
        }

        return $speed;
    }

    private static function joinParts(array $parts, $glue)
    {
        $cleaned = array_values(array_filter($parts, function ($p) {
            return trim((string) $p) !== '';
        }));

        return implode($glue, $cleaned);
    }

    private static function extractId(array $row)
    {
        return (string) (isset($row['product_id']) ? $row['product_id'] : (isset($row['id']) ? $row['id'] : ''));
    }
}
