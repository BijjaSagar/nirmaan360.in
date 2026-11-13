<?php

namespace App\Services\DocumentReading;

use InvalidArgumentException;

/**
 * What a page picture costs the model to look at, and the largest size of a shape it takes as sent.
 *
 * The arithmetic is the provider's published reference for the high-resolution models: one token for
 * each 28 x 28 patch, no side longer than MAX_EDGE once padded to whole patches, and no more than
 * MAX_TOKENS patches. A picture inside these limits is read at the size it was sent, which is what
 * lets a row position the model returns be laid over the stored page.
 */
final class ImageTokens
{
    public const PATCH = 28;

    public const MAX_EDGE = 2576;

    public const MAX_TOKENS = 4784;

    public static function count(int $w, int $h): int
    {
        return intdiv($w + 27, 28) * intdiv($h + 27, 28);
    }

    /** Whether a picture of this size is read as sent. */
    public static function fits(int $w, int $h, int $maxEdge = self::MAX_EDGE, int $maxTokens = self::MAX_TOKENS): bool
    {
        return $w >= 1 && $h >= 1
            && intdiv($w + 27, 28) * 28 <= $maxEdge
            && intdiv($h + 27, 28) * 28 <= $maxEdge
            && self::count($w, $h) <= $maxTokens;
    }

    /**
     * The size the provider would shrink a picture to, as [width, height]. A picture that already
     * fits comes back unchanged. This is the reference function line for line, short side rounded
     * half to even as the live service does.
     *
     * @return array{0:int,1:int}
     */
    public static function resized(int $w, int $h, int $maxEdge = self::MAX_EDGE, int $maxTokens = self::MAX_TOKENS): array
    {
        if ($w < 1 || $h < 1) {
            throw new InvalidArgumentException('A picture has at least one pixel each way.');
        }

        if (self::fits($w, $h, $maxEdge, $maxTokens)) {
            return [$w, $h];
        }

        if ($h > $w) {
            [$resizedH, $resizedW] = self::resized($h, $w, $maxEdge, $maxTokens);

            return [$resizedW, $resizedH];
        }

        $aspect = $w / $h;
        $short = fn (int $long): int => max((int) round($long / $aspect, 0, PHP_ROUND_HALF_EVEN), 1);
        $lo = 1;
        $hi = $w;

        while ($lo + 1 < $hi) {
            $mid = intdiv($lo + $hi, 2);

            if (self::fits($mid, $short($mid), $maxEdge, $maxTokens)) {
                $lo = $mid;
            } else {
                $hi = $mid;
            }
        }

        return [$lo, $short($lo)];
    }

    /**
     * The size to draw or scale a page of this shape to, as [width, height]: the reference function
     * applied to eight times the size, because that function only ever shrinks. An A4 page of
     * 595 x 842 points gives 1622 x 2296. A shape so small that eight times its size still fits
     * (the longer side under about 320) comes back at eight times, not larger.
     *
     * @return array{0:int,1:int}
     */
    public static function largestFit(int $w, int $h): array
    {
        return self::resized($w * 8, $h * 8);
    }
}
