<?php

declare(strict_types=1);

namespace GeoTz;

final class OceanUtils
{
    /** @var array<int, array{left: float, right: float, tzid: string}> */
    private const OCEAN_ZONES = [
        ['tzid' => 'Etc/GMT-12', 'left' => 172.5, 'right' => 180],
        ['tzid' => 'Etc/GMT-11', 'left' => 157.5, 'right' => 172.5],
        ['tzid' => 'Etc/GMT-10', 'left' => 142.5, 'right' => 157.5],
        ['tzid' => 'Etc/GMT-9', 'left' => 127.5, 'right' => 142.5],
        ['tzid' => 'Etc/GMT-8', 'left' => 112.5, 'right' => 127.5],
        ['tzid' => 'Etc/GMT-7', 'left' => 97.5, 'right' => 112.5],
        ['tzid' => 'Etc/GMT-6', 'left' => 82.5, 'right' => 97.5],
        ['tzid' => 'Etc/GMT-5', 'left' => 67.5, 'right' => 82.5],
        ['tzid' => 'Etc/GMT-4', 'left' => 52.5, 'right' => 67.5],
        ['tzid' => 'Etc/GMT-3', 'left' => 37.5, 'right' => 52.5],
        ['tzid' => 'Etc/GMT-2', 'left' => 22.5, 'right' => 37.5],
        ['tzid' => 'Etc/GMT-1', 'left' => 7.5, 'right' => 22.5],
        ['tzid' => 'Etc/GMT', 'left' => -7.5, 'right' => 7.5],
        ['tzid' => 'Etc/GMT+1', 'left' => -22.5, 'right' => -7.5],
        ['tzid' => 'Etc/GMT+2', 'left' => -37.5, 'right' => -22.5],
        ['tzid' => 'Etc/GMT+3', 'left' => -52.5, 'right' => -37.5],
        ['tzid' => 'Etc/GMT+4', 'left' => -67.5, 'right' => -52.5],
        ['tzid' => 'Etc/GMT+5', 'left' => -82.5, 'right' => -67.5],
        ['tzid' => 'Etc/GMT+6', 'left' => -97.5, 'right' => -82.5],
        ['tzid' => 'Etc/GMT+7', 'left' => -112.5, 'right' => -97.5],
        ['tzid' => 'Etc/GMT+8', 'left' => -127.5, 'right' => -112.5],
        ['tzid' => 'Etc/GMT+9', 'left' => -142.5, 'right' => -127.5],
        ['tzid' => 'Etc/GMT+10', 'left' => -157.5, 'right' => -142.5],
        ['tzid' => 'Etc/GMT+11', 'left' => -172.5, 'right' => -157.5],
        ['tzid' => 'Etc/GMT+12', 'left' => -180, 'right' => -172.5],
    ];

    /**
     * @return array<int, string>
     */
    public static function getTimezoneAtSea(float $lon): array
    {
        if ($lon === -180.0 || $lon === 180.0) {
            return ['Etc/GMT+12', 'Etc/GMT-12'];
        }

        $tzs = [];
        foreach (self::OCEAN_ZONES as $zone) {
            if ($zone['left'] <= $lon && $zone['right'] >= $lon) {
                $tzs[] = $zone['tzid'];
            } elseif ($zone['right'] < $lon) {
                break;
            }
        }

        return $tzs;
    }

    /**
     * @return array<int, array{left: float, right: float, tzid: string}>
     */
    public static function getOceanZones(): array
    {
        return self::OCEAN_ZONES;
    }
}
