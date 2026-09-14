<?php

declare(strict_types=1);

namespace GeoTz;

use GeoTz\GeoBuf\Decoder as GeoBufDecoder;
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
     * 256 entries comfortably covers the hot set of a typical workload, where
     * requests cluster on populated places.
     */
    public const DEFAULT_CACHE_ITEMS = 256;

    /** @var array<string, mixed> */
    private array $tzData;
    private string $featureFilePath;
    private CacheInterface $featureCache;

    /**
     * @param array<string, mixed> $tzData
     */
    public function __construct(array $tzData, string $featureFilePath)
    {
        $this->tzData = $tzData;
        $this->featureFilePath = $featureFilePath;
        $this->featureCache = new Psr16Cache(self::boundedStore(self::DEFAULT_CACHE_ITEMS));
    }

    /**
     * @param array{preload?: bool, store?: object, maxItems?: int} $options
     *        maxItems caps the in-memory feature cache; 0 means unbounded.
     *        Defaults to DEFAULT_CACHE_ITEMS, or unbounded when preload is set,
     *        since preloading into a bounded cache would just evict as it goes.
     */
    public function setCache(?array $options = null): void
    {
        $preload = !empty($options['preload']);
        $maxItems = $options['maxItems'] ?? ($preload ? 0 : self::DEFAULT_CACHE_ITEMS);

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

    public function preCache(): void
    {
        $this->preCacheRecursive($this->tzData['lookup'], '');
    }

    /**
     * @return array<int, string>
     */
    public function find(float $lat, float $lon): array
    {
        return $this->findUsingDataset($lat, $lon);
    }

    private function resolveCacheStore(mixed $store, int $maxItems = self::DEFAULT_CACHE_ITEMS): CacheInterface
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

    private function preCacheRecursive(mixed $curTzData, string $quadPos): void
    {
        if (!is_array($curTzData)) {
            return;
        }

        if (isset($curTzData['pos'], $curTzData['len']) && $curTzData['pos'] >= 0) {
            $geoJson = $this->loadFeatures((int) $curTzData['pos'], (int) $curTzData['len']);
            $this->featureCache->set($quadPos, $geoJson);
            return;
        }

        foreach ($curTzData as $key => $value) {
            if (is_string($key)) {
                $this->preCacheRecursive($value, $quadPos . $key);
            }
        }
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
        $curTzData = $this->tzData['lookup'];

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

            $curTzData = is_array($curTzData) ? ($curTzData[$nextQuad] ?? null) : null;
            $quadPos .= $nextQuad;

            if ($curTzData === null) {
                return OceanUtils::getTimezoneAtSea($originalLon);
            }

            if (is_array($curTzData) && isset($curTzData['pos'], $curTzData['len']) && $curTzData['pos'] >= 0) {
                $geoJson = $this->featureCache->get($quadPos);
                if ($geoJson === null) {
                    $geoJson = $this->loadFeatures((int) $curTzData['pos'], (int) $curTzData['len']);
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

                return count($timezonesContainingPoint) > 0
                    ? $timezonesContainingPoint
                    : OceanUtils::getTimezoneAtSea($originalLon);
            }

            if (is_array($curTzData) && array_is_list($curTzData) && count($curTzData) > 0) {
                $timezones = [];
                foreach ($curTzData as $idx) {
                    $timezones[] = $this->tzData['timezones'][$idx] ?? null;
                }
                return array_values(array_filter($timezones, static fn ($tzid) => $tzid !== null));
            }

            if (!is_array($curTzData)) {
                throw new \RuntimeException('Unexpected data type');
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
