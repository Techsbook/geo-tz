<?php

declare(strict_types=1);

namespace GeoTz;

final class GeometryUtils
{
    /**
     * @param array{0: float, 1: float} $point
     * @param array<string, mixed> $geometry
     */
    public static function pointInGeometry(array $point, array $geometry): bool
    {
        $type = $geometry['type'] ?? null;
        if ($type === 'Polygon') {
            return self::pointInPolygon($point, $geometry['coordinates'] ?? []);
        }

        if ($type === 'MultiPolygon') {
            foreach ($geometry['coordinates'] ?? [] as $polygon) {
                if (self::pointInPolygon($point, $polygon)) {
                    return true;
                }
            }
            return false;
        }

        if ($type === 'GeometryCollection') {
            foreach ($geometry['geometries'] ?? [] as $geom) {
                if (self::pointInGeometry($point, $geom)) {
                    return true;
                }
            }
            return false;
        }

        return false;
    }

    /**
     * @param array{0: float, 1: float} $point
     * @param array<int, array<int, array<int, float>>> $polygon
     */
    private static function pointInPolygon(array $point, array $polygon): bool
    {
        if (count($polygon) === 0) {
            return false;
        }

        if (!self::pointInRing($point, $polygon[0])) {
            return false;
        }

        $holeCount = count($polygon);
        for ($i = 1; $i < $holeCount; $i++) {
            if (self::pointInRing($point, $polygon[$i])) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array{0: float, 1: float} $point
     * @param array<int, array<int, float>> $ring
     */
    private static function pointInRing(array $point, array $ring): bool
    {
        $x = $point[0];
        $y = $point[1];
        $inside = false;
        $count = count($ring);

        if ($count < 2) {
            return false;
        }

        for ($i = 0, $j = $count - 1; $i < $count; $j = $i++) {
            $xi = $ring[$i][0];
            $yi = $ring[$i][1];
            $xj = $ring[$j][0];
            $yj = $ring[$j][1];

            if (self::pointOnSegment($x, $y, $xi, $yi, $xj, $yj)) {
                return true;
            }

            $intersects = (($yi > $y) !== ($yj > $y))
                && ($x < ($xj - $xi) * ($y - $yi) / ($yj - $yi) + $xi);

            if ($intersects) {
                $inside = !$inside;
            }
        }

        return $inside;
    }

    private static function pointOnSegment(float $x, float $y, float $x1, float $y1, float $x2, float $y2): bool
    {
        $lenSq = ($x2 - $x1) ** 2 + ($y2 - $y1) ** 2;
        if ($lenSq === 0.0) {
            return abs($x - $x1) <= 1e-12 && abs($y - $y1) <= 1e-12;
        }

        $cross = ($x - $x1) * ($y2 - $y1) - ($y - $y1) * ($x2 - $x1);
        if (abs($cross) > 1e-12) {
            return false;
        }

        $dot = ($x - $x1) * ($x2 - $x1) + ($y - $y1) * ($y2 - $y1);
        if ($dot < 0) {
            return false;
        }

        if ($dot > $lenSq) {
            return false;
        }

        return true;
    }
}
