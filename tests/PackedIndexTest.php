<?php

declare(strict_types=1);

namespace GeoTz\Tests;

use GeoTz\Finder;
use GeoTz\Index\ArrayIndex;
use GeoTz\Index\Index;
use GeoTz\Index\PackedIndex;
use PHPUnit\Framework\TestCase;

/**
 * The packed index is a re-encoding of the JSON quadtree, so the only thing that
 * really matters is that it is indistinguishable from it. These tests establish
 * that by walking every node of both encodings for every dataset - not by
 * sampling lookups, which would only ever exercise the 0.011% of the tree that
 * the sample points happen to land on.
 */
final class PackedIndexTest extends TestCase
{
    private const DATASETS = ['timezones', 'timezones-1970', 'timezones-now'];

    private static function dataDir(): string
    {
        return dirname(__DIR__) . '/data';
    }

    private static function packedPath(string $dataset): string
    {
        return self::dataDir() . '/' . $dataset . '.geojson.index.bin';
    }

    private static function jsonPath(string $dataset): string
    {
        return self::dataDir() . '/' . $dataset . '.geojson.index.json';
    }

    private static function geoDatPath(string $dataset): string
    {
        return self::dataDir() . '/' . $dataset . '.geojson.geo.dat';
    }

    private function arrayIndex(string $dataset): ArrayIndex
    {
        $decoded = json_decode((string) file_get_contents(self::jsonPath($dataset)), true);
        self::assertIsArray($decoded);

        return new ArrayIndex($decoded);
    }

    /**
     * @return array<int, array{string}>
     */
    public static function provideDatasets(): array
    {
        return array_map(static fn (string $dataset): array => [$dataset], self::DATASETS);
    }

    /**
     * @dataProvider provideDatasets
     */
    public function testPackedTreeIsStructurallyIdenticalToTheJsonTree(string $dataset): void
    {
        $json = $this->arrayIndex($dataset);
        $packed = new PackedIndex(self::packedPath($dataset));

        $counts = ['branch' => 0, 'feature' => 0, 'zones' => 0];
        $mismatches = [];
        $this->compareNodes($json, $json->rootNode(), $packed, $packed->rootNode(), '', $counts, $mismatches);

        $this->assertSame([], array_slice($mismatches, 0, 10), sprintf(
            '%d of %d nodes differ between the JSON and packed encodings of %s',
            count($mismatches),
            array_sum($counts),
            $dataset
        ));

        // Guard against a walk that silently visits nothing and passes.
        $this->assertGreaterThan(10000, $counts['branch'], 'suspiciously few branch nodes walked');
        $this->assertGreaterThan(10000, $counts['feature'], 'suspiciously few feature nodes walked');
        $this->assertGreaterThan(10000, $counts['zones'], 'suspiciously few zone nodes walked');
    }

    /**
     * Collect differences rather than asserting per node - there are 56,000 of
     * them, and a failure is far more useful as "these ten nodes differ" than as
     * the first assertion that happened to trip.
     *
     * @param array{kind: int, children?: array<string, mixed>, pos?: int, len?: int, zones?: array<int, string>} $jsonNode
     * @param array{kind: int, children?: array<string, mixed>, pos?: int, len?: int, zones?: array<int, string>} $packedNode
     * @param array<string, int> $counts
     * @param array<int, string> $mismatches
     */
    private function compareNodes(
        ArrayIndex $json,
        array $jsonNode,
        PackedIndex $packed,
        array $packedNode,
        string $quadPos,
        array &$counts,
        array &$mismatches
    ): void {
        $where = '/' . $quadPos;

        if ($jsonNode['kind'] !== $packedNode['kind']) {
            $mismatches[] = "{$where}: kind {$jsonNode['kind']} vs {$packedNode['kind']}";

            return;
        }

        if ($jsonNode['kind'] === Index::FEATURE) {
            $counts['feature']++;
            if ($jsonNode['pos'] !== $packedNode['pos'] || $jsonNode['len'] !== $packedNode['len']) {
                $mismatches[] = sprintf(
                    '%s: feature %d/%d vs %d/%d',
                    $where,
                    $jsonNode['pos'],
                    $jsonNode['len'],
                    $packedNode['pos'],
                    $packedNode['len']
                );
            }

            return;
        }

        if ($jsonNode['kind'] === Index::ZONES) {
            $counts['zones']++;
            if ($jsonNode['zones'] !== $packedNode['zones']) {
                $mismatches[] = sprintf(
                    '%s: zones [%s] vs [%s]',
                    $where,
                    implode(',', $jsonNode['zones']),
                    implode(',', $packedNode['zones'])
                );
            }

            return;
        }

        $counts['branch']++;

        foreach (['a', 'b', 'c', 'd'] as $quad) {
            $jsonChild = $jsonNode['children'][$quad] ?? null;
            $packedChild = $packedNode['children'][$quad] ?? null;

            if (($jsonChild === null) !== ($packedChild === null)) {
                $mismatches[] = sprintf(
                    '%s: child %s is %s in JSON but %s when packed',
                    $where,
                    $quad,
                    $jsonChild === null ? 'absent' : 'present',
                    $packedChild === null ? 'absent' : 'present'
                );

                continue;
            }

            if ($jsonChild === null) {
                continue;
            }

            $this->compareNodes(
                $json,
                $json->node($jsonChild),
                $packed,
                $packed->node($packedChild),
                $quadPos . $quad,
                $counts,
                $mismatches
            );
        }
    }

    /**
     * @dataProvider provideDatasets
     */
    public function testPreCacheVisitsTheSameFeatureNodesInBothEncodings(string $dataset): void
    {
        $fromJson = [];
        $this->arrayIndex($dataset)->eachFeatureNode(
            static function (string $quadPos, int $pos, int $len) use (&$fromJson): void {
                $fromJson[$quadPos] = [$pos, $len];
            }
        );

        $fromPacked = [];
        (new PackedIndex(self::packedPath($dataset)))->eachFeatureNode(
            static function (string $quadPos, int $pos, int $len) use (&$fromPacked): void {
                $fromPacked[$quadPos] = [$pos, $len];
            }
        );

        $this->assertNotEmpty($fromPacked);

        // The quad position is the Finder's feature-cache key, so these having
        // the same keys is what makes a preloaded cache interchangeable.
        ksort($fromJson);
        ksort($fromPacked);
        $this->assertSame($fromJson, $fromPacked);
    }

    /**
     * @dataProvider provideDatasets
     */
    public function testLookupsAgreeAcrossTheWholeGlobe(string $dataset): void
    {
        $viaJson = new Finder($this->arrayIndex($dataset), self::geoDatPath($dataset));
        $viaPacked = new Finder(self::packedPath($dataset), self::geoDatPath($dataset));

        foreach ($this->coordinatesUnderTest() as $label => [$lat, $lon]) {
            $this->assertSame(
                $viaJson->find($lat, $lon),
                $viaPacked->find($lat, $lon),
                "{$dataset} disagrees at {$label} ({$lat}, {$lon})"
            );
        }
    }

    /**
     * A spread over the globe, plus the edges that have their own code paths:
     * the poles, the dateline, the clamped extremes and open ocean.
     *
     * @return array<string, array{float, float}>
     */
    private function coordinatesUnderTest(): array
    {
        $points = [
            'north-pole' => [90.0, 0.0],
            'north-pole-east' => [90.0, 179.0],
            'just-below-north-pole' => [89.99999, 10.0],
            'south-pole' => [-90.0, 0.0],
            'just-above-south-pole' => [-89.99999, -10.0],
            'dateline-east' => [40.0, 180.0],
            'dateline-west' => [40.0, -180.0],
            'null-island' => [0.0, 0.0],
            'mid-pacific' => [-20.0, -140.0],
            'mid-atlantic' => [30.0, -40.0],
            'equator-dateline' => [0.0, 179.9999],
            'london' => [51.5074, -0.1278],
            'dubai' => [25.2048, 55.2708],
            'new-york' => [40.7128, -74.0060],
            'sydney' => [-33.8688, 151.2093],
            'aden-disputed' => [12.826174, 45.036933],
            'menominee-border' => [45.1078, -87.6143],
            'kathmandu' => [27.7172, 85.3240],
            'antarctica' => [-77.8463, 166.6683],
            'svalbard' => [78.2232, 15.6267],
        ];

        // A deterministic scatter, so a regression anywhere in the tree shows up
        // rather than only where someone thought to look.
        mt_srand(20260915);
        for ($i = 0; $i < 400; $i++) {
            $points['scatter-' . $i] = [mt_rand(-9000, 9000) / 100, mt_rand(-18000, 18000) / 100];
        }

        return $points;
    }

    public function testFinderDoesNotHoldThePackedIndexInMemory(): void
    {
        // The whole point of the format. The JSON index for this dataset is
        // ~20MB decoded; the packed one should cost a few KB plus the names.
        $before = memory_get_usage();
        $finder = new Finder(self::packedPath('timezones'), self::geoDatPath('timezones'));
        $afterOpen = memory_get_usage() - $before;

        $this->assertLessThan(
            1048576,
            $afterOpen,
            sprintf('opening the packed index cost %.2f MB', $afterOpen / 1048576)
        );

        // Descending must not accumulate the tree either. Only the bounded
        // feature cache is allowed to grow.
        foreach ($this->coordinatesUnderTest() as [$lat, $lon]) {
            $finder->find($lat, $lon);
        }

        $this->assertLessThan(
            20971520,
            memory_get_usage() - $before,
            'descending the packed index grew memory like the JSON index would'
        );
    }

    public function testFactoryPrefersThePackedIndexWhenItIsPresent(): void
    {
        $dir = $this->dataDirectoryContaining('probe-packed', ['index.bin', 'geo.dat']);

        $this->assertInstanceOf(PackedIndex::class, $this->indexBehind('probe-packed', $dir));
    }

    public function testFactoryStillReadsJsonWhenNoPackedIndexHasBeenBuilt(): void
    {
        // A consumer upgrading the library without rebuilding their data
        // directory must keep working, just without the memory saving.
        $dir = $this->dataDirectoryContaining('probe-json', ['index.json', 'geo.dat']);

        $this->assertInstanceOf(ArrayIndex::class, $this->indexBehind('probe-json', $dir));
    }

    /**
     * @param array<int, string> $suffixes
     */
    private function dataDirectoryContaining(string $dataset, array $suffixes): string
    {
        $dir = sys_get_temp_dir() . '/geo-tz-' . $dataset;
        @mkdir($dir, 0777, true);

        $sources = [
            'index.bin' => self::packedPath('timezones-now'),
            'index.json' => self::jsonPath('timezones-now'),
            'geo.dat' => self::geoDatPath('timezones-now'),
        ];

        foreach ($suffixes as $suffix) {
            $target = $dir . '/' . $dataset . '.geojson.' . $suffix;
            if (!is_file($target)) {
                // Symlink: geo.dat is 15MB and this runs on every test run.
                symlink($sources[$suffix], $target);
            }
        }

        return $dir;
    }

    private function indexBehind(string $dataset, string $dataDir): Index
    {
        $previous = getenv('GEO_TZ_DATA_PATH');
        putenv('GEO_TZ_DATA_PATH=' . $dataDir);

        try {
            $finder = \GeoTz\FinderFactory::forDataset($dataset);
        } finally {
            putenv($previous === false ? 'GEO_TZ_DATA_PATH' : 'GEO_TZ_DATA_PATH=' . $previous);
        }

        $index = (new \ReflectionProperty($finder, 'index'))->getValue($finder);
        $this->assertInstanceOf(Index::class, $index);

        return $index;
    }

    public function testRejectsAFileThatIsNotAPackedIndex(): void
    {
        $path = $this->temporaryFile(str_repeat('n', 64));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Not a packed timezone index');
        new PackedIndex($path);
    }

    public function testRejectsAFutureFormatVersionRatherThanMisreadingIt(): void
    {
        $bytes = (string) file_get_contents(self::packedPath('timezones-now'));
        $bytes[4] = chr(PackedIndex::VERSION + 1);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('format version');
        new PackedIndex($this->temporaryFile($bytes));
    }

    public function testRejectsATruncatedFile(): void
    {
        $bytes = substr((string) file_get_contents(self::packedPath('timezones-now')), 0, 12);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('truncated');
        new PackedIndex($this->temporaryFile($bytes));
    }

    public function testRejectsAMissingFile(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Failed to open packed timezone index');
        new PackedIndex(self::dataDir() . '/does-not-exist.index.bin');
    }

    /**
     * @dataProvider provideDatasets
     */
    public function testPackingIsReproducible(string $dataset): void
    {
        $existing = (string) file_get_contents(self::packedPath($dataset));

        // Repacking the same JSON must give byte-identical output, otherwise
        // "the committed index matches the committed data" is unverifiable.
        $target = sys_get_temp_dir() . '/geo-tz-repack-' . $dataset;
        $rebuilt = $this->packInto(dirname(__DIR__) . '/scripts/pack-index.php', $dataset, $target);

        $this->assertSame(
            hash('sha256', $existing),
            hash('sha256', $rebuilt),
            "repacking {$dataset} did not reproduce the committed index"
        );
    }

    private function packInto(string $script, string $dataset, string $target): string
    {
        @mkdir($target, 0777, true);
        copy(self::jsonPath($dataset), $target . '/' . $dataset . '.geojson.index.json');

        exec(
            sprintf(
                'GEO_TZ_DATA_PATH=%s %s %s %s 2>&1',
                escapeshellarg($target),
                escapeshellarg(PHP_BINARY),
                escapeshellarg($script),
                escapeshellarg($dataset)
            ),
            $output,
            $status
        );

        $this->assertSame(0, $status, 'pack-index.php failed: ' . implode("\n", $output));

        $produced = $target . '/' . $dataset . '.geojson.index.bin';
        $this->assertFileExists($produced);

        return (string) file_get_contents($produced);
    }

    private function temporaryFile(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'geo-tz-index');
        file_put_contents($path, $contents);

        return $path;
    }
}
