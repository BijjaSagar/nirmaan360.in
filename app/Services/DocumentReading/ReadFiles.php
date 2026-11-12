<?php

namespace App\Services\DocumentReading;

use App\Models\DocumentRead;
use App\Models\DocumentReadPage;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Where a read keeps its files on the private disk, and the only way they are opened for sending.
 * Everything of one read is in one folder, so deleting the folder deletes everything:
 *
 *   org-{company}/document-reads/{read}/p{page}-{pass}-{part}.jpg   a page picture
 *   org-{company}/document-reads/{read}/source.pdf                  the original, kept for the record
 *   org-{company}/document-reads/{read}/text.json                   the text taken out of a PDF, by page
 *
 * The disk is a bucket on production, so nothing here assumes a local path.
 */
final class ReadFiles
{
    public static function directory(DocumentRead $read): string
    {
        return "org-{$read->organization_id}/document-reads/{$read->id}";
    }

    /** The name is fixed for a page, pass and part, so uploading the same page again replaces the file. */
    public static function page(DocumentRead $read, int $pageNo, string $pass = 'main', string $part = 'full'): string
    {
        $clean = fn (string $name) => preg_replace('/[^a-z0-9_]/', '', strtolower($name)) ?: 'x';

        return self::directory($read)."/p{$pageNo}-{$clean($pass)}-{$clean($part)}.jpg";
    }

    public static function source(DocumentRead $read): string
    {
        return self::directory($read).'/source.pdf';
    }

    public static function text(DocumentRead $read): string
    {
        return self::directory($read).'/text.json';
    }

    /** Whether a stored path lies inside this read's own folder. A row can never point a reader at another company's file. */
    public static function owns(DocumentRead $read, ?string $path): bool
    {
        return is_string($path)
            && str_starts_with($path, self::directory($read).'/')
            && ! str_contains($path, '..');
    }

    /**
     * The bytes of a page picture, exactly as they were stored at upload.
     *
     * @throws RuntimeException When the file is gone, lies outside the read's folder or is not the file that was uploaded.
     */
    public static function picture(DocumentRead $read, DocumentReadPage $page): string
    {
        if (! self::owns($read, $page->image_path)) {
            throw new RuntimeException('The page picture is not in its read\'s folder.');
        }

        $bytes = Storage::get($page->image_path);

        if (! is_string($bytes) || $bytes === '') {
            throw new RuntimeException('The page picture is missing.');
        }

        if (filled($page->image_sha256) && ! hash_equals((string) $page->image_sha256, hash('sha256', $bytes))) {
            throw new RuntimeException('The page picture has changed since it was uploaded.');
        }

        return $bytes;
    }

    /**
     * Stores the text taken out of a PDF, one string per page number, and records the path on the read.
     *
     * @param  array<int, string>  $texts
     */
    public static function putTexts(DocumentRead $read, array $texts): void
    {
        $path = self::text($read);
        $pages = [];

        foreach ($texts as $number => $text) {
            $pages[(string) (int) $number] = (string) $text;
        }

        // The row first, so no file exists that no row points at. If the file cannot be written the path is taken back.
        $read->forceFill(['text_path' => $path])->save();

        $json = json_encode(['pages' => $pages], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);

        if ($json === false || ! Storage::put($path, $json)) {
            $read->forceFill(['text_path' => null])->save();

            throw new RuntimeException('The text of the document could not be stored.');
        }
    }

    /**
     * The stored text of the pages from $from to $to, by page number.
     *
     * @return array<int, string>
     *
     * @throws RuntimeException When the file is gone or a page of the range has no text in it.
     */
    public static function texts(DocumentRead $read, int $from, int $to): array
    {
        if (! self::owns($read, $read->text_path)) {
            throw new RuntimeException('The document text is not in its read\'s folder.');
        }

        $stored = json_decode((string) Storage::get($read->text_path), true);
        $pages = is_array($stored) && is_array($stored['pages'] ?? null) ? $stored['pages'] : null;

        if ($pages === null) {
            throw new RuntimeException('The document text is missing.');
        }

        $texts = [];

        for ($number = $from; $number <= max($from, $to); $number++) {
            if (! is_string($pages[(string) $number] ?? null)) {
                throw new RuntimeException("The document text has no page {$number}.");
            }

            $texts[$number] = $pages[(string) $number];
        }

        return $texts;
    }

    /** Deletes the read's whole folder. True when nothing of it is left, also when there was nothing. */
    public static function delete(DocumentRead $read): bool
    {
        $directory = self::directory($read);

        Storage::deleteDirectory($directory);

        return Storage::allFiles($directory) === [];
    }
}
