<?php

declare(strict_types=1);

namespace GeoTz\GeoBuf;

final class PbfReader
{
    public const VARINT = 0;
    public const FIXED64 = 1;
    public const BYTES = 2;
    public const FIXED32 = 5;

    public int $pos = 0;
    public int $type = 0;

    private string $buf;
    private int $length;

    public function __construct(string $buf)
    {
        $this->buf = $buf;
        $this->length = strlen($buf);
    }

    /**
     * @param callable(int, array<string, mixed>, PbfReader): void $readField
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    public function readFields(callable $readField, array $result, ?int $end = null): array
    {
        $end = $end ?? $this->length;

        while ($this->pos < $end) {
            $val = $this->readVarint();
            $tag = $val >> 3;
            $startPos = $this->pos;

            $this->type = $val & 0x7;
            $readField($tag, $result, $this);

            if ($this->pos === $startPos) {
                $this->skip($val);
            }
        }

        return $result;
    }

    /**
     * @param callable(int, array<string, mixed>, PbfReader): void $readField
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    public function readMessage(callable $readField, array $result): array
    {
        return $this->readFields($readField, $result, $this->readVarint() + $this->pos);
    }

    public function readVarint(): int
    {
        $result = 0;
        $shift = 0;

        while ($this->pos < $this->length) {
            $byte = ord($this->buf[$this->pos++]);
            $result |= (($byte & 0x7f) << $shift);

            if (($byte & 0x80) === 0) {
                return $result;
            }

            $shift += 7;
            if ($shift > 63) {
                throw new \RuntimeException('Varint is too long');
            }
        }

        throw new \RuntimeException('Unexpected end of buffer while reading varint');
    }

    public function readSVarint(): int
    {
        $num = $this->readVarint();
        if (($num & 1) === 1) {
            return -intdiv($num + 1, 2);
        }

        return intdiv($num, 2);
    }

    public function readBoolean(): bool
    {
        return (bool) $this->readVarint();
    }

    public function readDouble(): float
    {
        $bytes = substr($this->buf, $this->pos, 8);
        $this->pos += 8;
        $data = unpack('g', $bytes);

        return $data[1];
    }

    public function readString(): string
    {
        $len = $this->readVarint();
        $str = substr($this->buf, $this->pos, $len);
        $this->pos += $len;

        return $str;
    }

    /**
     * @return array<int, int>
     */
    public function readPackedVarint(): array
    {
        if ($this->type !== self::BYTES) {
            return [$this->readVarint()];
        }

        $end = $this->readVarint() + $this->pos;
        $values = [];
        while ($this->pos < $end) {
            $values[] = $this->readVarint();
        }

        return $values;
    }

    private function skip(int $val): void
    {
        $type = $val & 0x7;
        switch ($type) {
            case self::VARINT:
                $this->readVarint();
                break;
            case self::FIXED64:
                $this->pos += 8;
                break;
            case self::BYTES:
                $len = $this->readVarint();
                $this->pos += $len;
                break;
            case self::FIXED32:
                $this->pos += 4;
                break;
            default:
                throw new \RuntimeException('Unsupported wire type: ' . $type);
        }
    }
}
