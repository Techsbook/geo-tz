<?php

declare(strict_types=1);

namespace GeoTz\Tests;

use GeoTz\Finder;
use GeoTz\FinderFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

/**
 * The decoded-feature cache used to grow without limit, so any long-lived
 * process eventually held every quad in the dataset - close to 2GB for the full
 * set. These tests pin the bound that prevents that.
 */
final class CacheBoundTest extends TestCase
{
    /**
     * @return array<int, array{float, float}>
     */
    private function spreadOfCoordinates(int $count): array
    {
        mt_srand(1234);
        $points = [];
        for ($i = 0; $i < $count; $i++) {
            $points[] = [mt_rand(-6000, 6000) / 100, mt_rand(-18000, 18000) / 100];
        }

        return $points;
    }

    public function testCacheHonoursTheConfiguredBound(): void
    {
        $adapter = new ArrayAdapter(defaultLifetime: 0, storeSerialized: true, maxLifetime: 0, maxItems: 8);
        $finder = FinderFactory::forDataset(FinderFactory::DATASET_ALL);
        $finder->setCache(['store' => $adapter]);

        foreach ($this->spreadOfCoordinates(400) as [$lat, $lon]) {
            try {
                $finder->find($lat, $lon);
            } catch (\InvalidArgumentException) {
                // Out-of-range coordinates are not what this test is about.
            }
        }

        $this->assertLessThanOrEqual(8, count($adapter->getValues()));
    }

    public function testDefaultCacheIsBoundedNotUnlimited(): void
    {
        $finder = FinderFactory::forDataset(FinderFactory::DATASET_NOW);
        $finder->setCache(null);

        foreach ($this->spreadOfCoordinates(600) as [$lat, $lon]) {
            try {
                $finder->find($lat, $lon);
            } catch (\InvalidArgumentException) {
            }
        }

        $pool = $this->poolBehind($finder);
        $this->assertLessThanOrEqual(Finder::DEFAULT_CACHE_ITEMS, count($pool->getValues()));
    }

    public function testPreloadIsStillAllowedToBeUnbounded(): void
    {
        // preCache() exists precisely to hold everything, so asking for it must
        // not be silently capped - it just has to be an explicit choice.
        $finder = FinderFactory::forDataset(FinderFactory::DATASET_NOW);
        $finder->setCache(['maxItems' => 0]);

        $pool = $this->poolBehind($finder);
        $reflection = new \ReflectionProperty($pool, 'maxItems');

        $this->assertSame(0, $reflection->getValue($pool));
    }

    private function poolBehind(Finder $finder): ArrayAdapter
    {
        $cache = (new \ReflectionProperty($finder, 'featureCache'))->getValue($finder);
        $pool = (new \ReflectionProperty($cache, 'pool'))->getValue($cache);

        $this->assertInstanceOf(ArrayAdapter::class, $pool);

        return $pool;
    }
}
