<?php

declare(strict_types=1);

namespace GeoTz;

final class FinderFactory
{
    public const DATASET_1970 = 'timezones-1970';
    public const DATASET_ALL = 'timezones';
    public const DATASET_NOW = 'timezones-now';

    /** @var array<string, Finder> */
    private static array $instances = [];

    public static function forDataset(string $dataset): Finder
    {
        if (!isset(self::$instances[$dataset])) {
            $indexFile = self::dataRoot() . '/' . $dataset . '.geojson.index.json';
            $geoDatFile = self::dataPath() . '/' . $dataset . '.geojson.geo.dat';

            $tzData = json_decode((string) file_get_contents($indexFile), true);
            if (!is_array($tzData)) {
                throw new \RuntimeException('Failed to load timezone index: ' . $indexFile);
            }

            self::$instances[$dataset] = new Finder($tzData, $geoDatFile);
        }

        return self::$instances[$dataset];
    }

    private static function dataRoot(): string
    {
        return dirname(__DIR__) . '/data';
    }

    private static function dataPath(): string
    {
        $envPath = getenv('GEO_TZ_DATA_PATH');
        if (is_string($envPath) && $envPath !== '') {
            return rtrim($envPath, '/');
        }

        return self::dataRoot();
    }
}
