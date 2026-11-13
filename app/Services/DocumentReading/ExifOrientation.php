<?php

namespace App\Services\DocumentReading;

/**
 * Which way up a phone held the camera, read from the photo's Exif block (tag 0x0112).
 *
 * 1 is upright and is also the answer when the tag, the block or the JPEG itself is missing or
 * broken. 3 is upside down, 6 and 8 are on their side, and 2, 4, 5 and 7 are mirror images.
 * Pure PHP: ext-exif is optional on the servers this runs on.
 */
final class ExifOrientation
{
    private const TAG = 0x0112;

    public static function of(string $jpegBytes): int
    {
        foreach (JpegSegments::read($jpegBytes)['segments'] as $segment) {
            if ($segment['marker'] !== JpegSegments::APP1) {
                continue;
            }

            $payload = JpegSegments::payload($jpegBytes, $segment);

            // XMP also travels in APP1. Only the block that starts "Exif\0\0" is a TIFF header.
            if (str_starts_with($payload, "Exif\0\0")) {
                return self::fromTiff(substr($payload, 6));
            }
        }

        return 1;
    }

    private static function fromTiff(string $tiff): int
    {
        $size = strlen($tiff);
        $order = substr($tiff, 0, 2);

        if ($size < 8 || ($order !== 'II' && $order !== 'MM')) {
            return 1;
        }

        [$short, $long] = $order === 'II' ? ['v', 'V'] : ['n', 'N'];

        if (unpack($short, $tiff, 2)[1] !== 42) {
            return 1;
        }

        $directory = unpack($long, $tiff, 4)[1];

        if ($directory + 2 > $size) {
            return 1;
        }

        $entries = unpack($short, $tiff, $directory)[1];

        for ($index = 0; $index < $entries; $index++) {
            $entry = $directory + 2 + 12 * $index;

            if ($entry + 12 > $size) {
                return 1;
            }

            if (unpack($short, $tiff, $entry)[1] !== self::TAG) {
                continue;
            }

            // Written as a SHORT. A BYTE or a LONG is read too, since some writers get the type wrong.
            $value = match (unpack($short, $tiff, $entry + 2)[1]) {
                1 => ord($tiff[$entry + 8]),
                3 => unpack($short, $tiff, $entry + 8)[1],
                4 => unpack($long, $tiff, $entry + 8)[1],
                default => 1,
            };

            return $value >= 1 && $value <= 8 ? $value : 1;
        }

        return 1;
    }
}
