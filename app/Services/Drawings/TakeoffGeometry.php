<?php

namespace App\Services\Drawings;

use App\Support\Dec;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * Exact geometry for takeoff. Points are [x, y] pairs in sheet units, as strings with three decimals.
 * Nothing here passes through a float, so the saved quantity does not depend on the browser.
 */
final class TakeoffGeometry
{
    private const ROOT_SCALE = 12;

    /** @param array{0:string,1:string} $a @param array{0:string,1:string} $b */
    public static function distance(array $a, array $b): BigDecimal
    {
        $dx = Dec::of($b[0])->minus(Dec::of($a[0]));
        $dy = Dec::of($b[1])->minus(Dec::of($a[1]));

        return $dx->multipliedBy($dx)->plus($dy->multipliedBy($dy))->sqrt(self::ROOT_SCALE, RoundingMode::HalfUp);
    }

    /** Total length along the points in order. */
    public static function polylineLength(array $points): BigDecimal
    {
        $total = BigDecimal::zero();

        for ($i = 1, $n = count($points); $i < $n; $i++) {
            $total = $total->plus(self::distance($points[$i - 1], $points[$i]));
        }

        return $total;
    }

    /** Area enclosed by the points, by the shoelace formula. */
    public static function polygonArea(array $points): BigDecimal
    {
        $sum = BigDecimal::zero();
        $n = count($points);

        for ($i = 0; $i < $n; $i++) {
            $next = $points[($i + 1) % $n];
            $sum = $sum
                ->plus(Dec::of($points[$i][0])->multipliedBy(Dec::of($next[1])))
                ->minus(Dec::of($next[0])->multipliedBy(Dec::of($points[$i][1])));
        }

        return $sum->abs()->multipliedBy('0.5');
    }

    /**
     * Whether a closed outline crosses itself, which would make the shoelace area wrong.
     * Coordinates are scaled to whole thousandths, so the test is exact integer arithmetic.
     */
    public static function selfIntersects(array $points): bool
    {
        $scaled = array_map(fn (array $point) => [
            Dec::of($point[0])->multipliedBy(1000)->toScale(0, RoundingMode::HalfUp)->toInt(),
            Dec::of($point[1])->multipliedBy(1000)->toScale(0, RoundingMode::HalfUp)->toInt(),
        ], $points);

        $n = count($scaled);

        for ($i = 0; $i < $n; $i++) {
            for ($j = $i + 2; $j < $n; $j++) {
                // The first and last edges share a corner, as do neighbours.
                if ($i === 0 && $j === $n - 1) {
                    continue;
                }

                if (self::segmentsTouch($scaled[$i], $scaled[($i + 1) % $n], $scaled[$j], $scaled[($j + 1) % $n])) {
                    return true;
                }
            }
        }

        return false;
    }

    /** @param array{0:int,1:int} $a @param array{0:int,1:int} $b @param array{0:int,1:int} $c @param array{0:int,1:int} $d */
    private static function segmentsTouch(array $a, array $b, array $c, array $d): bool
    {
        $d1 = self::side($c, $d, $a);
        $d2 = self::side($c, $d, $b);
        $d3 = self::side($a, $b, $c);
        $d4 = self::side($a, $b, $d);

        if ($d1 * $d2 < 0 && $d3 * $d4 < 0) {
            return true;
        }

        return ($d1 === 0 && self::within($c, $d, $a))
            || ($d2 === 0 && self::within($c, $d, $b))
            || ($d3 === 0 && self::within($a, $b, $c))
            || ($d4 === 0 && self::within($a, $b, $d));
    }

    /** Which side of the line a-b the point p lies on: -1, 0 or 1. */
    private static function side(array $a, array $b, array $p): int
    {
        return ($b[0] - $a[0]) * ($p[1] - $a[1]) <=> ($b[1] - $a[1]) * ($p[0] - $a[0]);
    }

    private static function within(array $a, array $b, array $p): bool
    {
        return $p[0] >= min($a[0], $b[0]) && $p[0] <= max($a[0], $b[0])
            && $p[1] >= min($a[1], $b[1]) && $p[1] <= max($a[1], $b[1]);
    }
}
