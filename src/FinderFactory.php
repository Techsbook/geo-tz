<?php

declare(strict_types=1);

namespace GeoTz;

use GeoTz\Index\PackedIndex;

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
            $geoDatFile = self::locate($dataset . '.geojson.geo.dat');

            // The packed index is read a node at a time and costs a few KB; the
            // JSON one is decoded whole and costs ~20MB per process. Prefer the
            // packed file, but keep reading JSON when it is absent so a data
            // directory built before the packed format still works.
            $packedFile = self::locate($dataset . '.geojson.index.bin', false);
            if ($packedFile !== null) {
                self::$instances[$dataset] = new Finder(new PackedIndex($packedFile), $geoDatFile);

                return self::$instances[$dataset];
            }

            $indexFile = self::locate($dataset . '.geojson.index.json');

            $tzData = json_decode((string) file_get_contents($indexFile), true);
            if (!is_array($tzData)) {
                throw new \RuntimeException('Failed to load timezone index: ' . $indexFile);
            }

            self::$instances[$dataset] = new Finder($tzData, $geoDatFile);
        }

        return self::$instances[$dataset];
    }

    /**
     * Find a data file, preferring an overridden data directory.
     *
     * An index and its geo.dat are a matched pair - the index holds byte offsets
     * into that exact file - so both are looked up the same way. Taking the
     * index from the package while GEO_TZ_DATA_PATH supplied a newer geo.dat
     * would read features from the wrong offsets.
     */
    private static function locate(string $file, bool $required = true): ?string
    {
        foreach (array_unique([self::dataPath(), self::dataRoot()]) as $dir) {
            if (is_file($dir . '/' . $file)) {
                return $dir . '/' . $file;
            }
        }

        if ($required) {
            throw new \RuntimeException('Timezone data file not found: ' . $file);
        }

        return null;
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
