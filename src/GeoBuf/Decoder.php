<?php

declare(strict_types=1);

namespace GeoTz\GeoBuf;

final class Decoder
{
    private const GEOMETRY_TYPES = [
        'Point',
        'MultiPoint',
        'LineString',
        'MultiLineString',
        'Polygon',
        'MultiPolygon',
        'GeometryCollection',
    ];

    /** @var array<int, string> */
    private array $keys = [];
    /** @var array<int, mixed> */
    private array $values = [];
    /** @var array<int, int>|null */
    private ?array $lengths = null;
    private int $dim = 2;
    private float $e = 1_000_000.0;

    /**
     * @return array<string, mixed>
     */
    public static function decode(string $buf): array
    {
        $decoder = new self();
        return $decoder->decodeBuffer($buf);
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeBuffer(string $buf): array
    {
        $this->dim = 2;
        $this->e = 1_000_000.0;
        $this->lengths = null;
        $this->keys = [];
        $this->values = [];

        $pbf = new PbfReader($buf);
        $obj = $pbf->readFields(function (int $tag, array &$obj, PbfReader $pbf): void {
            $this->readDataField($tag, $obj, $pbf);
        }, []);
        $this->keys = [];

        return $obj;
    }

    /** @param array<string, mixed> $obj */
    private function readDataField(int $tag, array &$obj, PbfReader $pbf): void
    {
        if ($tag === 1) {
            $this->keys[] = $pbf->readString();
        } elseif ($tag === 2) {
            $this->dim = $pbf->readVarint();
        } elseif ($tag === 3) {
            $this->e = pow(10, $pbf->readVarint());
        } elseif ($tag === 4) {
            $this->readFeatureCollection($pbf, $obj);
        } elseif ($tag === 5) {
            $this->readFeature($pbf, $obj);
        } elseif ($tag === 6) {
            $this->readGeometry($pbf, $obj);
        }
    }

    /** @param array<string, mixed> $obj */
    private function readFeatureCollection(PbfReader $pbf, array &$obj): void
    {
        $obj['type'] = 'FeatureCollection';
        $obj['features'] = [];
        $obj = $pbf->readMessage(function (int $tag, array &$obj, PbfReader $pbf): void {
            $this->readFeatureCollectionField($tag, $obj, $pbf);
        }, $obj);
    }

    /** @param array<string, mixed> $feature */
    private function readFeature(PbfReader $pbf, array $feature): array
    {
        $feature['type'] = 'Feature';
        $feature = $pbf->readMessage(function (int $tag, array &$feature, PbfReader $pbf): void {
            $this->readFeatureField($tag, $feature, $pbf);
        }, $feature);
        if (!array_key_exists('geometry', $feature)) {
            $feature['geometry'] = null;
        }

        return $feature;
    }

    /** @param array<string, mixed> $geom */
    private function readGeometry(PbfReader $pbf, array $geom): array
    {
        $geom['type'] = 'Point';
        return $pbf->readMessage(function (int $tag, array &$geom, PbfReader $pbf): void {
            $this->readGeometryField($tag, $geom, $pbf);
        }, $geom);
    }

    /** @param array<string, mixed> $obj */
    private function readFeatureCollectionField(int $tag, array &$obj, PbfReader $pbf): void
    {
        if ($tag === 1) {
            $obj['features'][] = $this->readFeature($pbf, []);
        } elseif ($tag === 13) {
            $this->values[] = $this->readValue($pbf);
        } elseif ($tag === 15) {
            $this->readProps($pbf, $obj);
        }
    }

    /** @param array<string, mixed> $feature */
    private function readFeatureField(int $tag, array &$feature, PbfReader $pbf): void
    {
        if ($tag === 1) {
            $feature['geometry'] = $this->readGeometry($pbf, []);
        } elseif ($tag === 11) {
            $feature['id'] = $pbf->readString();
        } elseif ($tag === 12) {
            $feature['id'] = $pbf->readSVarint();
        } elseif ($tag === 13) {
            $this->values[] = $this->readValue($pbf);
        } elseif ($tag === 14) {
            $feature['properties'] = $this->readProps($pbf, []);
        } elseif ($tag === 15) {
            $this->readProps($pbf, $feature);
        }
    }

    /** @param array<string, mixed> $geom */
    private function readGeometryField(int $tag, array &$geom, PbfReader $pbf): void
    {
        if ($tag === 1) {
            $geom['type'] = self::GEOMETRY_TYPES[$pbf->readVarint()];
        } elseif ($tag === 2) {
            $this->lengths = $pbf->readPackedVarint();
        } elseif ($tag === 3) {
            $this->readCoords($geom, $pbf, $geom['type']);
        } elseif ($tag === 4) {
            $geom['geometries'] = $geom['geometries'] ?? [];
            $geom['geometries'][] = $this->readGeometry($pbf, []);
        } elseif ($tag === 13) {
            $this->values[] = $this->readValue($pbf);
        } elseif ($tag === 15) {
            $this->readProps($pbf, $geom);
        }
    }

    /** @param array<string, mixed> $geom */
    private function readCoords(array &$geom, PbfReader $pbf, string $type): void
    {
        if ($type === 'Point') {
            $geom['coordinates'] = $this->readPoint($pbf);
        } elseif ($type === 'MultiPoint') {
            $geom['coordinates'] = $this->readLine($pbf);
        } elseif ($type === 'LineString') {
            $geom['coordinates'] = $this->readLine($pbf);
        } elseif ($type === 'MultiLineString') {
            $geom['coordinates'] = $this->readMultiLine($pbf);
        } elseif ($type === 'Polygon') {
            $geom['coordinates'] = $this->readMultiLine($pbf, true);
        } elseif ($type === 'MultiPolygon') {
            $geom['coordinates'] = $this->readMultiPolygon($pbf);
        }
    }

    private function readValue(PbfReader $pbf): mixed
    {
        $end = $pbf->readVarint() + $pbf->pos;
        $value = null;

        while ($pbf->pos < $end) {
            $val = $pbf->readVarint();
            $tag = $val >> 3;

            if ($tag === 1) {
                $value = $pbf->readString();
            } elseif ($tag === 2) {
                $value = $pbf->readDouble();
            } elseif ($tag === 3) {
                $value = $pbf->readVarint();
            } elseif ($tag === 4) {
                $value = -$pbf->readVarint();
            } elseif ($tag === 5) {
                $value = $pbf->readBoolean();
            } elseif ($tag === 6) {
                $value = json_decode($pbf->readString(), true);
            }
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $props
     * @return array<string, mixed>
     */
    private function readProps(PbfReader $pbf, array $props): array
    {
        $end = $pbf->readVarint() + $pbf->pos;
        while ($pbf->pos < $end) {
            $key = $this->keys[$pbf->readVarint()] ?? null;
            $value = $this->values[$pbf->readVarint()] ?? null;
            if ($key !== null) {
                $props[$key] = $value;
            }
        }
        $this->values = [];

        return $props;
    }

    /**
     * @return array<int, float>
     */
    private function readPoint(PbfReader $pbf): array
    {
        $end = $pbf->readVarint() + $pbf->pos;
        $coords = [];
        while ($pbf->pos < $end) {
            $coords[] = $pbf->readSVarint() / $this->e;
        }

        return $coords;
    }

    /**
     * @return array<int, array<int, float>>
     */
    private function readLinePart(PbfReader $pbf, int $end, ?int $len, bool $closed): array
    {
        $coords = [];
        $prev = array_fill(0, $this->dim, 0.0);
        $i = 0;

        while ($len !== null ? $i < $len : $pbf->pos < $end) {
            $point = [];
            for ($d = 0; $d < $this->dim; $d++) {
                $prev[$d] += $pbf->readSVarint();
                $point[$d] = $prev[$d] / $this->e;
            }
            $coords[] = $point;
            $i++;
        }

        if ($closed && count($coords) > 0) {
            $coords[] = $coords[0];
        }

        return $coords;
    }

    /**
     * @return array<int, array<int, float>>
     */
    private function readLine(PbfReader $pbf): array
    {
        return $this->readLinePart($pbf, $pbf->readVarint() + $pbf->pos, null, false);
    }

    /**
     * @return array<int, array<int, array<int, float>>>
     */
    private function readMultiLine(PbfReader $pbf, bool $closed = false): array
    {
        $end = $pbf->readVarint() + $pbf->pos;
        if ($this->lengths === null) {
            return [$this->readLinePart($pbf, $end, null, $closed)];
        }

        $coords = [];
        foreach ($this->lengths as $length) {
            $coords[] = $this->readLinePart($pbf, $end, $length, $closed);
        }
        $this->lengths = null;

        return $coords;
    }

    /**
     * @return array<int, array<int, array<int, array<int, float>>>>
     */
    private function readMultiPolygon(PbfReader $pbf): array
    {
        $end = $pbf->readVarint() + $pbf->pos;
        if ($this->lengths === null) {
            return [[$this->readLinePart($pbf, $end, null, true)]];
        }

        $coords = [];
        $j = 1;
        $polygonCount = $this->lengths[0] ?? 0;
        for ($i = 0; $i < $polygonCount; $i++) {
            $rings = [];
            $ringCount = $this->lengths[$j] ?? 0;
            for ($k = 0; $k < $ringCount; $k++) {
                $ringLen = $this->lengths[$j + 1 + $k] ?? 0;
                $rings[] = $this->readLinePart($pbf, $end, $ringLen, true);
            }
            $j += $ringCount + 1;
            $coords[] = $rings;
        }
        $this->lengths = null;

        return $coords;
    }
}
