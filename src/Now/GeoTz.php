<?php

declare(strict_types=1);

namespace GeoTz\Now;

use GeoTz\FinderFactory;

final class GeoTz
{
    /**
     * Find the timezone ID(s) at the given GPS coordinates using the "now" dataset.
     *
     * @return array<int, string>
     */
    public static function find(float $lat, float $lon): array
    {
        return FinderFactory::forDataset(FinderFactory::DATASET_NOW)->find($lat, $lon);
    }

    /**
     * Set caching behavior for the "now" dataset.
     *
     * @param array{preload?: bool, store?: object} $options
     */
    public static function setCache(?array $options = null): void
    {
        FinderFactory::forDataset(FinderFactory::DATASET_NOW)->setCache($options);
    }

    /**
     * Load all features into memory to speed up future lookups for the "now" dataset.
     */
    public static function preCache(): void
    {
        FinderFactory::forDataset(FinderFactory::DATASET_NOW)->preCache();
    }
}
