<?php

declare(strict_types=1);

namespace GeoTz\Index;

/**
 * The quadtree as a seekable binary, read a node at a time with fseek.
 *
 * Why this exists: json_decode'ing the index costs ~20MB per PHP process, and a
 * lookup touches 6.3 nodes on average out of 56,221 - 0.011% of the tree. Under
 * FrankenPHP each worker thread has its own PHP context and so its own copy,
 * which made memory scale with thread count and blocked autoscaling. Reading the
 * few nodes actually on the path leaves the file in the OS page cache, where one
 * copy is shared by every thread and every process on the node.
 *
 * The layout is deliberately fixed-width so a child can be reached by offset
 * without parsing anything before it - the property JSON cannot have.
 *
 *   header (20 bytes)
 *     char[4]  magic "GTZI"
 *     uint8    format version
 *     uint8[3] reserved, zero
 *     uint32   root node offset
 *     uint32   names table offset
 *     uint32   names table length
 *
 *   nodes, in post-order so a parent's children always precede it
 *     0x00 branch : uint32 x4 child offsets (a, b, c, d; 0 means absent)
 *     0x01 feature: uint32 pos, uint32 len   - a byte range of geo.dat
 *     0x02 zones  : uint8 count, uint16 x count - indices into the names table
 *
 *   names: JSON array of timezone ids (a few hundred, ~10KB, kept resident)
 *
 * Offset 0 can never be a node because the header occupies it, which is what
 * lets 0 stand for "no child" - open sea.
 */
final class PackedIndex implements Index
{
    public const MAGIC = 'GTZI';
    public const VERSION = 1;
    public const HEADER_LENGTH = 20;

    /**
     * A branch node - the largest fixed-size node - is 17 bytes. Reading that
     * much up front means one syscall per node instead of one per field; only a
     * zones node with eight or more entries needs a second read, and the
     * published datasets top out at two.
     */
    private const READ_AHEAD = 17;

    /** @var resource */
    private $handle;
    private string $path;
    private int $rootOffset;
    private int $size;

    /** @var array<int, string> */
    private array $names;

    public function __construct(string $path)
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            throw new \RuntimeException('Failed to open packed timezone index: ' . $path);
        }

        $this->handle = $handle;
        $this->path = $path;

        $stat = fstat($handle);
        $this->size = is_array($stat) ? (int) $stat['size'] : 0;

        $header = (string) fread($handle, self::HEADER_LENGTH);
        if (strlen($header) !== self::HEADER_LENGTH) {
            throw new \RuntimeException('Packed timezone index is truncated: ' . $path);
        }

        if (substr($header, 0, 4) !== self::MAGIC) {
            throw new \RuntimeException('Not a packed timezone index: ' . $path);
        }

        $version = ord($header[4]);
        if ($version !== self::VERSION) {
            // Refuse rather than guess. A mismatched index that is descended
            // anyway returns confident nonsense - a wrong timezone, not an error.
            throw new \RuntimeException(sprintf(
                'Packed timezone index %s is format version %d, this build reads version %d. Re-run scripts/pack-index.php.',
                $path,
                $version,
                self::VERSION
            ));
        }

        /** @var array{root: int, names: int, namesLen: int} $fields */
        $fields = unpack('Nroot/Nnames/NnamesLen', substr($header, 8));

        $this->rootOffset = $fields['root'];
        $namesOffset = $fields['names'];
        $namesLength = $fields['namesLen'];

        if ($this->rootOffset < self::HEADER_LENGTH || $this->rootOffset >= $this->size) {
            throw new \RuntimeException('Packed timezone index has an out-of-range root offset: ' . $path);
        }

        if ($namesOffset + $namesLength > $this->size) {
            throw new \RuntimeException('Packed timezone index has a truncated names table: ' . $path);
        }

        if (fseek($handle, $namesOffset) !== 0) {
            throw new \RuntimeException('Failed to seek packed timezone index: ' . $path);
        }

        $names = json_decode((string) fread($handle, $namesLength), true);
        if (!is_array($names)) {
            throw new \RuntimeException('Packed timezone index has an unreadable names table: ' . $path);
        }

        /** @var array<int, string> $names */
        $this->names = $names;
    }

    public function __destruct()
    {
        if (is_resource($this->handle)) {
            fclose($this->handle);
        }
    }

    public function rootNode(): array
    {
        return $this->node($this->rootOffset);
    }

    public function node(mixed $ref): array
    {
        if (!is_int($ref) || $ref < self::HEADER_LENGTH || $ref >= $this->size) {
            throw new \RuntimeException('Packed timezone index node reference out of range: ' . var_export($ref, true));
        }

        $buffer = $this->read($ref, self::READ_AHEAD);
        $type = ord($buffer[0]);

        if ($type === self::BRANCH) {
            if (strlen($buffer) < 17) {
                throw new \RuntimeException('Packed timezone index branch node is truncated at offset ' . $ref);
            }

            /** @var array<int, int> $offsets */
            $offsets = array_values((array) unpack('N4', substr($buffer, 1, 16)));

            return [
                'kind' => self::BRANCH,
                'children' => [
                    'a' => $offsets[0] !== 0 ? $offsets[0] : null,
                    'b' => $offsets[1] !== 0 ? $offsets[1] : null,
                    'c' => $offsets[2] !== 0 ? $offsets[2] : null,
                    'd' => $offsets[3] !== 0 ? $offsets[3] : null,
                ],
            ];
        }

        if ($type === self::FEATURE) {
            if (strlen($buffer) < 9) {
                throw new \RuntimeException('Packed timezone index feature node is truncated at offset ' . $ref);
            }

            /** @var array{pos: int, len: int} $fields */
            $fields = unpack('Npos/Nlen', substr($buffer, 1, 8));

            return ['kind' => self::FEATURE, 'pos' => $fields['pos'], 'len' => $fields['len']];
        }

        if ($type === self::ZONES) {
            $count = ord($buffer[1]);
            $needed = 2 + ($count * 2);
            if (strlen($buffer) < $needed) {
                $buffer = $this->read($ref, $needed);
            }

            $zones = [];
            if ($count > 0) {
                /** @var array<int, int> $indices */
                $indices = (array) unpack('n' . $count, substr($buffer, 2, $count * 2));
                foreach ($indices as $index) {
                    if (isset($this->names[$index])) {
                        $zones[] = $this->names[$index];
                    }
                }
            }

            return ['kind' => self::ZONES, 'zones' => $zones];
        }

        throw new \RuntimeException('Unknown packed timezone index node type ' . $type . ' at offset ' . $ref);
    }

    public function eachFeatureNode(callable $visit): void
    {
        $this->walk($this->rootOffset, '', $visit);
    }

    private function walk(int $offset, string $quadPos, callable $visit): void
    {
        $node = $this->node($offset);

        if ($node['kind'] === self::FEATURE) {
            $visit($quadPos, $node['pos'], $node['len']);

            return;
        }

        if ($node['kind'] !== self::BRANCH) {
            return;
        }

        foreach ($node['children'] as $quad => $child) {
            if ($child !== null) {
                $this->walk($child, $quadPos . $quad, $visit);
            }
        }
    }

    private function read(int $offset, int $length): string
    {
        if (fseek($this->handle, $offset) !== 0) {
            throw new \RuntimeException('Failed to seek packed timezone index: ' . $this->path);
        }

        $buffer = fread($this->handle, $length);
        if ($buffer === false || $buffer === '') {
            throw new \RuntimeException('Failed to read packed timezone index: ' . $this->path);
        }

        return $buffer;
    }
}
