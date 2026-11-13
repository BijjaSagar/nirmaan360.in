<?php

namespace App\Services\DocumentReading;

use InvalidArgumentException;

/**
 * The band of a page a row was read from, as the model reports it: approximate, by the provider's
 * own statement. It is kept in parts per thousand of the page height so it does not depend on the
 * size of the stored picture.
 */
final class PageStrip
{
    /**
     * @param  int  $yTop  Pixel row of the picture that was sent
     * @param  int  $yBottom  Pixel row of the picture that was sent
     * @param  int  $imageHeight  Height of that picture as stored
     * @return array{top: int, bottom: int} Both from 0 to 1000, top never below bottom. Out-of-range rows are pulled in, and the band is rounded outwards.
     */
    public static function perThousand(int $yTop, int $yBottom, int $imageHeight): array
    {
        if ($imageHeight < 1) {
            throw new InvalidArgumentException('A stored page has a height.');
        }

        $top = max(0, min($imageHeight, min($yTop, $yBottom)));
        $bottom = max(0, min($imageHeight, max($yTop, $yBottom)));

        return [
            'top' => intdiv($top * 1000, $imageHeight),
            'bottom' => intdiv($bottom * 1000 + $imageHeight - 1, $imageHeight),
        ];
    }
}
