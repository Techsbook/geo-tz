<?php

declare(strict_types=1);

namespace GeoTz\Index;

/**
 * The original index backend: the whole quadtree json_decode'd into PHP arrays.
 *
 * Costs roughly 20MB resident per PHP process for the full dataset, which under
 * FrankenPHP is paid per worker thread. {@see PackedIndex} exists to avoid that;
 * this class is kept so a consumer whose data directory predates the packed
 * format still works, and so the two can be compared in tests.
 */
final class ArrayIndex implements Index
{
    /** @var array<string, mixed> */
    private array $tzData;

    /** @param array<string, mixed> $tzData decoded <dataset>.geojson.index.json */
    public function __construct(array $tzData)
    {
        if (!isset($tzData['lookup']) || !is_array($tzData['lookup'])) {
            throw new \InvalidArgumentException('Timezone index has no lookup tree');
        }

        $this->tzData = $tzData;
    }

    public function rootNode(): array
    {
        return $this->node($this->tzData['lookup']);
    }

    public function node(mixed $ref): array
    {
        if (!is_array($ref)) {
            throw new \RuntimeException('Unexpected data type');
        }

        // A negative pos is upstream's way of marking a quad with no features.
        // The original lookup fell through such nodes and ended up at sea; the
        // packed format encodes the same thing as an absent child.
        if (isset($ref['pos'], $ref['len']) && $ref['pos'] >= 0) {
            return ['kind' => self::FEATURE, 'pos' => (int) $ref['pos'], 'len' => (int) $ref['len']];
        }

        if (array_is_list($ref) && count($ref) > 0) {
            $zones = [];
            foreach ($ref as $idx) {
                $zones[] = $this->tzData['timezones'][$idx] ?? null;
            }

            return [
                'kind' => self::ZONES,
                'zones' => array_values(array_filter($zones, static fn ($tzid) => $tzid !== null)),
            ];
        }

        $children = [];
        foreach (['a', 'b', 'c', 'd'] as $quad) {
            $child = $ref[$quad] ?? null;
            $children[$quad] = is_array($child) ? $child : null;
        }

        return ['kind' => self::BRANCH, 'children' => $children];
    }

    public function eachFeatureNode(callable $visit): void
    {
        $this->walk($this->tzData['lookup'], '', $visit);
    }

    private function walk(mixed $node, string $quadPos, callable $visit): void
    {
        if (!is_array($node)) {
            return;
        }

        if (isset($node['pos'], $node['len']) && $node['pos'] >= 0) {
            $visit($quadPos, (int) $node['pos'], (int) $node['len']);

            return;
        }

        foreach (['a', 'b', 'c', 'd'] as $quad) {
            if (isset($node[$quad]) && is_array($node[$quad])) {
                $this->walk($node[$quad], $quadPos . $quad, $visit);
            }
        }
    }
}
