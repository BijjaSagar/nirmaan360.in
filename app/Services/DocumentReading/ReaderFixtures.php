<?php

namespace App\Services\DocumentReading;

/**
 * The small PDFs the platform admin's browser self-check draws: one scanned page in each of the
 * four ways scanners and phones store a page picture inside a PDF. Their content is invented.
 * A browser that draws all four as a page with something on it can draw customers' scans.
 *
 * They are kept outside public/, so only a route that checks for the platform admin serves them.
 */
final class ReaderFixtures
{
    /** The name the self-check asks for => the file in resources/reader-fixtures, and the PDF filter its picture is stored with. */
    public const FILES = [
        'ccitt-g4' => ['file' => 'ccitt-g4.pdf', 'filter' => 'CCITTFaxDecode'],
        'jbig2' => ['file' => 'jbig2.pdf', 'filter' => 'JBIG2Decode'],
        'jpeg2000' => ['file' => 'jpeg2000.pdf', 'filter' => 'JPXDecode'],
        'dct' => ['file' => 'dct.pdf', 'filter' => 'DCTDecode'],
    ];

    /** @return list<string> */
    public static function names(): array
    {
        return array_keys(self::FILES);
    }

    /** The full path of a fixture, or null when there is none of that name or its file is missing. */
    public static function path(string $name): ?string
    {
        $file = self::FILES[$name]['file'] ?? null;
        $path = $file === null ? null : resource_path('reader-fixtures/'.$file);

        return $path !== null && is_file($path) ? $path : null;
    }
}
