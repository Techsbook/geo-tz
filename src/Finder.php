<?php

declare(strict_types=1);

namespace GeoTz;

use GeoTz\GeoBuf\Decoder as GeoBufDecoder;
use GeoTz\Index\ArrayIndex;
use GeoTz\Index\Index;
use GeoTz\Index\PackedIndex;
use Psr\Cache\CacheItemPoolInterface;
use Psr\SimpleCache\CacheInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Psr16Cache;

final class Finder
{
    /**
     * Decoded features are cached per quad. Left unbounded that cache grows until
     * every quad in the dataset is resident, which for the full dataset is close
     * to 2GB - a cost paid by every long-lived process, since nothing ever
     * evicts. Bounding it keeps the win (repeat lookups of the same place stay
     * fast) while capping the worst case at the size of the cap.
     *
     * With the index itself no longer resident (see {@see PackedIndex}) this cap
     * is what sets a worker's steady-state footprint, and under FrankenPHP it is
     * paid per thread - so the right value depends on how many threads the
     * deployment runs, which the library cannot know. Set `GEO_TZ_CACHE_ITEMS`
     * to override it without a code change; 64 is the fallback.
     */
    public const DEFAULT_CACHE_ITEMS = 64;

    /**
     * Environment override for {@see DEFAULT_CACHE_ITEMS}. `0` means unbounded.
     */
    public const CACHE_ITEMS_ENV = 'GEO_TZ_CACHE_ITEMS';

    private Index $index;
    private string $featureFilePath;
    private CacheInterface $featureCache;

    /**
     * @param array<string, mixed>|string|Index $index a decoded index.json, the
     *        path to a packed .index.bin, or a ready-made index
     */
    public function __construct(array|string|Index $index, string $featureFilePath)
    {
        $this->index = match (true) {
            $index instanceof Index => $index,
            is_string($index) => new PackedIndex($index),
            default => new ArrayIndex($index),
        };

        $this->featureFilePath = $featureFilePath;
        $this->featureCache = new Psr16Cache(self::boundedStore(self::defaultMaxItems()));
    }

    /**
     * The configured cache bound: `GEO_TZ_CACHE_ITEMS` if it is a sane value,
     * otherwise {@see DEFAULT_CACHE_ITEMS}.
     *
     * A malformed value falls back rather than throwing. This is read while a
     * worker boots, and taking the service down over a typo in an env var is a
     * worse failure than quietly using the default.
     */
    public static function defaultMaxItems(): int
    {
        $configured = getenv(self::CACHE_ITEMS_ENV);

        if (!is_string($configured) || !preg_match('/^\d+$/', trim($configured))) {
            return self::DEFAULT_CACHE_ITEMS;
        }

        return (int) trim($configured);
    }

    /**
     * @param array{preload?: bool, store?: object, maxItems?: int} $options
     *        maxItems caps the in-memory feature cache; 0 means unbounded. An
     *        explicit value wins over `GEO_TZ_CACHE_ITEMS`; otherwise the
     *        environment decides, falling back to DEFAULT_CACHE_ITEMS. Preload
     *        is unbounded unless capped explicitly, since preloading into a
     *        bounded cache would just evict as it goes.
     */
    public function setCache(?array $options = null): void
    {
        $preload = !empty($options['preload']);
        $maxItems = $options['maxItems'] ?? ($preload ? 0 : self::defaultMaxItems());

        $store = $this->resolveCacheStore($options['store'] ?? null, (int) $maxItems);
        $this->featureCache = $store;

        if ($preload) {
            $this->preCache();
        }
    }

    private static function boundedStore(int $maxItems): ArrayAdapter
    {
        // Positional, deliberately. Symfony renamed the second constructor
        // parameter between 8.0 ($storeSerialized) and 8.1 ($deepClone), so a
        // named argument blows up at runtime on whichever version the consumer
        // happens to resolve. The order and meaning are stable across ^8:
        // (defaultLifetime, storeSerialized|deepClone, maxLifetime, maxItems).
        return new ArrayAdapter(0, true, 0, $maxItems);
    }

    /**
     * Decode every quad's features into the cache up front.
     *
     * This is the opposite trade to the packed index: it buys lookup latency
     * with several hundred MB of resident memory. Only worth it for a
     * single-process, lookup-heavy job.
     */
    public function preCache(): void
    {
        $this->index->eachFeatureNode(function (string $quadPos, int $pos, int $len): void {
            $this->featureCache->set($quadPos, $this->loadFeatures($pos, $len));
        });
    }

    /**
     * @return array<int, string>
     */
    public function find(float $lat, float $lon): array
    {
        return $this->findUsingDataset($lat, $lon);
    }

    private function resolveCacheStore(mixed $store, int $maxItems): CacheInterface
    {
        if ($store instanceof CacheInterface) {
            return $store;
        }

        if ($store instanceof CacheItemPoolInterface) {
            return new Psr16Cache($store);
        }

        if ($store === null) {
            return new Psr16Cache(self::boundedStore($maxItems));
        }

        throw new \InvalidArgumentException('Cache store must implement Psr\\SimpleCache\\CacheInterface or Psr\\Cache\\CacheItemPoolInterface');
    }

    /**
     * @return array<int, string>
     */
    private function findUsingDataset(float $lat, float $lon): array
    {
        $originalLon = $lon;

        if (is_nan($lat) || $lat > 90 || $lat < -90) {
            throw new \InvalidArgumentException('Invalid latitude: ' . $lat);
        }

        if (is_nan($lon) || $lon > 180 || $lon < -180) {
            throw new \InvalidArgumentException('Invalid longitude: ' . $lon);
        }

        if ($lat === 90.0) {
            return array_map(static fn (array $zone): string => $zone['tzid'], OceanUtils::getOceanZones());
        }

        if ($lat >= 89.9999) {
            $lat = 89.9999;
        } elseif ($lat <= -89.9999) {
            $lat = -89.9999;
        }

        if ($lon >= 179.9999) {
            $lon = 179.9999;
        } elseif ($lon <= -179.9999) {
            $lon = -179.9999;
        }

        $quadData = [
            'top' => 89.9999,
            'bottom' => -89.9999,
            'left' => -179.9999,
            'right' => 179.9999,
            'midLat' => 0.0,
            'midLon' => 0.0,
        ];
        $quadPos = '';
        $node = $this->index->rootNode();

        while (true) {
            if ($lat >= $quadData['midLat'] && $lon >= $quadData['midLon']) {
                $nextQuad = 'a';
                $quadData['bottom'] = $quadData['midLat'];
                $quadData['left'] = $quadData['midLon'];
            } elseif ($lat >= $quadData['midLat'] && $lon < $quadData['midLon']) {
                $nextQuad = 'b';
                $quadData['bottom'] = $quadData['midLat'];
                $quadData['right'] = $quadData['midLon'];
            } elseif ($lat < $quadData['midLat'] && $lon < $quadData['midLon']) {
                $nextQuad = 'c';
                $quadData['top'] = $quadData['midLat'];
                $quadData['right'] = $quadData['midLon'];
            } else {
                $nextQuad = 'd';
                $quadData['top'] = $quadData['midLat'];
                $quadData['left'] = $quadData['midLon'];
            }

            $child = $node['kind'] === Index::BRANCH ? ($node['children'][$nextQuad] ?? null) : null;
            $quadPos .= $nextQuad;

            // No child means the quad holds no land at all.
            if ($child === null) {
                return OceanUtils::getTimezoneAtSea($originalLon);
            }

            $node = $this->index->node($child);

            if ($node['kind'] === Index::FEATURE) {
                $geoJson = $this->featureCache->get($quadPos);
                if ($geoJson === null) {
                    $geoJson = $this->loadFeatures($node['pos'], $node['len']);
                    $this->featureCache->set($quadPos, $geoJson);
                }

                $timezonesContainingPoint = [];
                $features = $geoJson['features'] ?? [];
                foreach ($features as $feature) {
                    $geometry = $feature['geometry'] ?? null;
                    if ($geometry && GeometryUtils::pointInGeometry([$lon, $lat], $geometry)) {
                        $timezonesContainingPoint[] = $feature['properties']['tzid'] ?? null;
                    }
                }

                $timezonesContainingPoint = array_values(array_filter($timezonesContainingPoint, static fn ($tzid) => $tzid !== null));

                // The quad has land in it, but not under this point.
                return count($timezonesContainingPoint) > 0
                    ? $timezonesContainingPoint
                    : OceanUtils::getTimezoneAtSea($originalLon);
            }

            // The quad is wholly inside its timezone(s), so there is nothing to
            // test the point against.
            if ($node['kind'] === Index::ZONES) {
                return $node['zones'];
            }

            $quadData['midLat'] = ($quadData['top'] + $quadData['bottom']) / 2;
            $quadData['midLon'] = ($quadData['left'] + $quadData['right']) / 2;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function loadFeatures(int $pos, int $len): array
    {
        $handle = fopen($this->featureFilePath, 'rb');
        if ($handle === false) {
            throw new \RuntimeException('Failed to open geo.dat file');
        }

        if (fseek($handle, $pos) !== 0) {
            fclose($handle);
            throw new \RuntimeException('Failed to seek geo.dat file');
        }

        $data = fread($handle, $len);
        fclose($handle);

        if ($data === false || strlen($data) < $len) {
            throw new \RuntimeException('Failed to read geo.dat file');
        }

        return GeoBufDecoder::decode($data);
    }
}
