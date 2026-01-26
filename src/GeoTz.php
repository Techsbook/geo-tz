<?php

declare(strict_types=1);

namespace GeoTz;

final class GeoTz
{
    /**
     * Find the timezone ID(s) at the given GPS coordinates using the 1970 dataset.
     *
     * @return array<int, string>
     */
    public static function find(float $lat, float $lon): array
    {
        return FinderFactory::forDataset(FinderFactory::DATASET_1970)->find($lat, $lon);
    }

    /**
     * Set caching behavior for the 1970 dataset.
     *
     * @param array{preload?: bool, store?: object} $options
     */
    public static function setCache(?array $options = null): void
    {
        FinderFactory::forDataset(FinderFactory::DATASET_1970)->setCache($options);
    }

    /**
     * Load all features into memory to speed up future lookups for the 1970 dataset.
     */
    public static function preCache(): void
    {
        FinderFactory::forDataset(FinderFactory::DATASET_1970)->preCache();
    }
}
