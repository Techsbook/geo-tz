<?php

declare(strict_types=1);

namespace GeoTz\Tests;

use GeoTz\OceanUtils;
use PHPUnit\Framework\TestCase;

use GeoTz\GeoTz;
use GeoTz\All\GeoTz as AllGeoTz;
use GeoTz\Now\GeoTz as NowGeoTz;

final class FindTest extends TestCase
{
    public function testFindsTimezoneForValidCoordinate(): void
    {
        $result = GeoTz::find(47.650499, -122.35007);
        $this->assertSame(['America/Los_Angeles'], $result);
    }

    public function testFindsTimezoneInOcean(): void
    {
        $result = GeoTz::find(0, 0);
        $this->assertSame(['Etc/GMT'], $result);
    }

    public function testFindsBothTimezonesOnDateline(): void
    {
        $result = GeoTz::find(40, 180);
        $this->assertSame(['Etc/GMT+12', 'Etc/GMT-12'], $result);
    }

    public function testFindsAllOceanTimezonesAtNorthPole(): void
    {
        $expected = array_map(static fn (array $zone): string => $zone['tzid'], OceanUtils::getOceanZones());
        $result = GeoTz::find(90, 0);
        $this->assertSame($expected, $result);
    }

    public function testDatasetDifferences(): void
    {
        $all = AllGeoTz::find(12.826174, 45.036933);
        $this->assertSame(['Asia/Aden'], $all);

        $default = GeoTz::find(12.826174, 45.036933);
        $this->assertSame(['Asia/Riyadh'], $default);

        $now = NowGeoTz::find(12.826174, 45.036933);
        $this->assertSame(['Europe/Moscow'], $now);
    }
}
