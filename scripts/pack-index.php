#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Re-encode <dataset>.geojson.index.json as <dataset>.geojson.index.bin.
 *
 * The JSON index has to be parsed from byte zero to reach any node, so the whole
 * tree ends up resident - ~20MB per PHP process, paid per FrankenPHP worker
 * thread. The binary is fixed-width and addressed by offset, so the Finder reads
 * only the handful of nodes on the path and the file stays in the OS page cache,
 * shared by every thread on the machine.
 *
 * Nodes are written post-order: a node's children are always at lower offsets
 * than the node itself, so every child offset is known by the time its parent is
 * encoded and no back-patching pass is needed. Emission order is fixed, so
 * packing the same JSON twice produces byte-identical output.
 *
 * Anything this does not recognise is a hard failure. A packer that guesses at
 * an unfamiliar node shape produces an index that looks fine and silently
 * answers with the wrong timezone; a build that stops is cheap by comparison.
 *
 * Usage: php scripts/pack-index.php [dataset ...]      (default: all three)
 */

use GeoTz\Index\PackedIndex;

$autoload = dirname(__DIR__) . '/vendor/autoload.php';
if (is_file($autoload)) {
    require $autoload;
} else {
    require dirname(__DIR__) . '/src/Index/Index.php';
    require dirname(__DIR__) . '/src/Index/PackedIndex.php';
}

const QUADS = ['a', 'b', 'c', 'd'];
const MAX_UINT32 = 4294967295;

/**
 * Decoding the full index needs a few hundred MB. This is a build step run by
 * hand or by update-data.php, not something the library does at runtime.
 */
if (($limit = ini_get('memory_limit')) !== false && $limit !== '-1') {
    $bytes = (int) $limit * match (strtoupper(substr((string) $limit, -1))) {
        'G' => 1073741824,
        'M' => 1048576,
        'K' => 1024,
        default => 1,
    };
    if ($bytes < 768 * 1048576) {
        ini_set('memory_limit', '768M');
    }
}

/**
 * @param array<string, mixed> $index decoded index.json
 * @return array{0: string, 1: array<string, int>} the binary and a node census
 */
function packIndex(array $index, string $label): array
{
    if (!isset($index['lookup']) || !is_array($index['lookup'])) {
        throw new RuntimeException("{$label}: index has no lookup tree");
    }

    $names = $index['timezones'] ?? null;
    if (!is_array($names) || !array_is_list($names)) {
        throw new RuntimeException("{$label}: index has no timezones list");
    }

    if (count($names) > 65536) {
        throw new RuntimeException(sprintf(
            '%s: %d timezones exceeds the 65536 addressable by a uint16 index. The node encoding needs widening.',
            $label,
            count($names)
        ));
    }

    $chunks = [];
    $offset = PackedIndex::HEADER_LENGTH;
    $census = ['branch' => 0, 'feature' => 0, 'zones' => 0, 'empty' => 0];

    /**
     * @return int the node's offset, or 0 for "no node here" - open sea
     */
    $emit = static function (mixed $node, string $path) use (&$emit, &$chunks, &$offset, &$census, $names, $label): int {
        if (!is_array($node)) {
            throw new RuntimeException(sprintf(
                '%s: node at /%s is %s, expected an object or list',
                $label,
                $path,
                get_debug_type($node)
            ));
        }

        if (isset($node['pos'], $node['len'])) {
            $pos = (int) $node['pos'];
            $len = (int) $node['len'];

            // Upstream marks a quad with no features as pos -1. The JSON lookup
            // fell through such a node and ended up at sea; an absent child
            // means exactly that here, so encode it as one.
            if ($pos < 0) {
                $census['empty']++;

                return 0;
            }

            if ($len < 0 || $pos > MAX_UINT32 || $len > MAX_UINT32) {
                throw new RuntimeException("{$label}: node at /{$path} has an unencodable pos/len ({$pos}/{$len})");
            }

            $census['feature']++;
            $buffer = pack('CNN', PackedIndex::FEATURE, $pos, $len);
        } elseif (array_is_list($node)) {
            $count = count($node);
            if ($count === 0) {
                throw new RuntimeException("{$label}: node at /{$path} is an empty zone list");
            }

            if ($count > 255) {
                throw new RuntimeException("{$label}: node at /{$path} lists {$count} zones, more than a uint8 count holds");
            }

            $buffer = pack('CC', PackedIndex::ZONES, $count);
            foreach ($node as $index) {
                if (!is_int($index) || $index < 0 || !isset($names[$index])) {
                    throw new RuntimeException(sprintf(
                        '%s: node at /%s references timezone %s, which is not in the names table',
                        $label,
                        $path,
                        var_export($index, true)
                    ));
                }

                $buffer .= pack('n', $index);
            }

            $census['zones']++;
        } else {
            foreach (array_keys($node) as $key) {
                if (!in_array($key, QUADS, true)) {
                    throw new RuntimeException(sprintf(
                        '%s: node at /%s has unrecognised key "%s". The index format has changed; the packer must be updated before this data can be shipped.',
                        $label,
                        $path,
                        (string) $key
                    ));
                }
            }

            // Children first, so their offsets exist by the time the parent is
            // encoded. This is what makes the single pass sufficient.
            $children = [];
            foreach (QUADS as $quad) {
                $children[$quad] = array_key_exists($quad, $node)
                    ? $emit($node[$quad], $path . $quad)
                    : 0;
            }

            if ($children === ['a' => 0, 'b' => 0, 'c' => 0, 'd' => 0]) {
                throw new RuntimeException("{$label}: node at /{$path} is a branch with no children");
            }

            $census['branch']++;
            $buffer = pack('CNNNN', PackedIndex::BRANCH, $children['a'], $children['b'], $children['c'], $children['d']);
        }

        $here = $offset;
        $chunks[] = $buffer;
        $offset += strlen($buffer);

        if ($offset > MAX_UINT32) {
            throw new RuntimeException("{$label}: packed index exceeds the 4GB addressable by uint32 offsets");
        }

        return $here;
    };

    $root = $emit($index['lookup'], '');
    if ($root === 0) {
        throw new RuntimeException("{$label}: the root node is empty");
    }

    $namesJson = (string) json_encode(array_values($names), JSON_UNESCAPED_SLASHES);
    $namesOffset = $offset;

    $header = PackedIndex::MAGIC
        . chr(PackedIndex::VERSION)
        . "\0\0\0"
        . pack('NNN', $root, $namesOffset, strlen($namesJson));

    return [$header . implode('', $chunks) . $namesJson, $census];
}

// Same override the library reads, so a data directory kept outside the package
// is packed where it lives rather than where the package happens to be.
$envDataDir = getenv('GEO_TZ_DATA_PATH');
$dataDir = is_string($envDataDir) && $envDataDir !== ''
    ? rtrim($envDataDir, '/')
    : dirname(__DIR__) . '/data';

$datasets = array_slice($argv, 1);
if ($datasets === []) {
    $datasets = ['timezones', 'timezones-1970', 'timezones-now'];
}

$status = 0;

foreach ($datasets as $dataset) {
    $source = "{$dataDir}/{$dataset}.geojson.index.json";
    $target = "{$dataDir}/{$dataset}.geojson.index.bin";

    try {
        if (!is_file($source)) {
            throw new RuntimeException("missing source index {$source}");
        }

        $decoded = json_decode((string) file_get_contents($source), true);
        if (!is_array($decoded)) {
            throw new RuntimeException("{$dataset}: could not decode {$source}");
        }

        [$binary, $census] = packIndex($decoded, $dataset);
        unset($decoded);

        // Write beside the target and rename, so a reader never sees a partial
        // file and a failed pack leaves the previous index in place.
        $temp = $target . '.tmp';
        if (file_put_contents($temp, $binary) !== strlen($binary)) {
            throw new RuntimeException("{$dataset}: failed to write {$temp}");
        }

        if (!rename($temp, $target)) {
            @unlink($temp);
            throw new RuntimeException("{$dataset}: failed to move {$temp} into place");
        }

        printf(
            "%-16s %6.0f KB JSON -> %6.0f KB packed  (%d branch, %d feature, %d zone%s)\n",
            $dataset,
            filesize($source) / 1024,
            strlen($binary) / 1024,
            $census['branch'],
            $census['feature'],
            $census['zones'],
            $census['empty'] > 0 ? sprintf(', %d empty', $census['empty']) : ''
        );
    } catch (Throwable $e) {
        fwrite(STDERR, "Pack failed: {$e->getMessage()}\n");
        $status = 1;
    }
}

exit($status);
