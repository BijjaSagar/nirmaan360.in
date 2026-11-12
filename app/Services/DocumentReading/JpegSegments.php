<?php

namespace App\Services\DocumentReading;

/**
 * The marker segments of a JPEG file that come before the picture data: where metadata lives.
 * Pure PHP, so nothing here depends on ext-exif or GD.
 */
final class JpegSegments
{
    public const APP0 = 0xE0;

    public const APP1 = 0xE1;

    public const APP15 = 0xEF;

    public const COMMENT = 0xFE;

    private const START_OF_SCAN = 0xDA;

    private const END_OF_IMAGE = 0xD9;

    /**
     * Each segment with the offset of its 0xFF byte and its whole length. "scan" is where the picture
     * data starts, or null when the file is not a JPEG or breaks off before it.
     *
     * @return array{segments: list<array{marker:int, offset:int, length:int}>, scan: ?int}
     */
    public static function read(string $bytes): array
    {
        $segments = [];
        $size = strlen($bytes);

        if ($size < 4 || $bytes[0] !== "\xFF" || $bytes[1] !== "\xD8") {
            return ['segments' => [], 'scan' => null];
        }

        $at = 2;

        while ($at + 1 < $size) {
            if ($bytes[$at] !== "\xFF") {
                break;
            }

            // Any number of 0xFF bytes may pad the gap before a marker.
            while ($at + 1 < $size && $bytes[$at + 1] === "\xFF") {
                $at++;
            }

            if ($at + 1 >= $size) {
                break;
            }

            $marker = ord($bytes[$at + 1]);

            if ($marker === self::START_OF_SCAN) {
                return ['segments' => $segments, 'scan' => $at];
            }

            if ($marker === self::END_OF_IMAGE) {
                break;
            }

            // Restart markers and TEM stand alone, with no length.
            if (($marker >= 0xD0 && $marker <= 0xD7) || $marker === 0x01) {
                $at += 2;

                continue;
            }

            if ($at + 4 > $size) {
                break;
            }

            $length = (ord($bytes[$at + 2]) << 8) | ord($bytes[$at + 3]);

            if ($length < 2 || $at + 2 + $length > $size) {
                break;
            }

            $segments[] = ['marker' => $marker, 'offset' => $at, 'length' => $length + 2];
            $at += $length + 2;
        }

        return ['segments' => $segments, 'scan' => null];
    }

    /** What a segment carries, without its marker and length bytes. */
    public static function payload(string $bytes, array $segment): string
    {
        return substr($bytes, $segment['offset'] + 4, $segment['length'] - 4);
    }
}
