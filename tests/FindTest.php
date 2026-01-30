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

    /**
     * @dataProvider provideUsBorderTimezoneCoordinates
     */
    public function testFindsTimezonesForUsBorderRegions(float $lat, float $lon, string $expectedTimezone): void
    {
        $result = GeoTz::find($lat, $lon);
        $this->assertSame([$expectedTimezone], $result);
    }

    /**
     * @return array<string, array{float, float, string}>
     */
    public static function provideUsBorderTimezoneCoordinates(): array
    {
        return [
            'detroit-mi' => [42.3314, -83.0458, 'America/Detroit'],
            'chicago-il' => [41.8832, -87.6324, 'America/Chicago'],
            'menominee-mi' => [45.1078, -87.6143, 'America/Menominee'],
            'new-york-ny' => [40.7128, -74.0060, 'America/New_York'],
            'indianapolis-in' => [39.7684, -86.1581, 'America/Indiana/Indianapolis'],
            'knox-in' => [41.2959, -86.6250, 'America/Indiana/Knox'],
            'marengo-in' => [38.3698, -86.3433, 'America/Indiana/Marengo'],
            'petersburg-in' => [38.4920, -87.2786, 'America/Indiana/Petersburg'],
            'tell-city-in' => [37.9514, -86.7678, 'America/Indiana/Tell_City'],
            'vevay-in' => [38.7478, -85.0672, 'America/Indiana/Vevay'],
            'vincennes-in' => [38.6773, -87.5286, 'America/Indiana/Vincennes'],
            'winamac-in' => [41.0514, -86.6030, 'America/Indiana/Winamac'],
            'louisville-ky' => [38.2527, -85.7585, 'America/Kentucky/Louisville'],
            'monticello-ky' => [36.8290, -84.8508, 'America/Kentucky/Monticello'],
            'beulah-nd' => [47.2633, -101.7771, 'America/North_Dakota/Beulah'],
            'center-nd' => [47.1150, -101.2996, 'America/North_Dakota/Center'],
            'new-salem-nd' => [46.8450, -101.4100, 'America/North_Dakota/New_Salem'],
            'boise-id' => [43.6150, -116.2023, 'America/Boise'],
        ];
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
