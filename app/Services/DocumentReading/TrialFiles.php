<?php

namespace App\Services\DocumentReading;

use App\Models\ReaderTrial;
use App\Models\ReaderTrialPage;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Where a dry run keeps what the platform admin uploaded for it, on the default private disk:
 *
 *     platform/reader-trials/{trial uuid}/p{page}.jpg
 *     platform/reader-trials/{trial uuid}/t{page}.txt
 *
 * The folder belongs to no company. It is not listed under any module's "purge_directories",
 * because erasing a company has nothing to delete here. The whole folder goes after 24 hours.
 */
final class TrialFiles
{
    public const ROOT = 'platform/reader-trials';

    public static function directory(ReaderTrial $trial): string
    {
        if (! Str::isUuid((string) $trial->uuid)) {
            throw new RuntimeException('A dry run without its own folder name has no files.');
        }

        return self::ROOT.'/'.$trial->uuid;
    }

    public static function picture(ReaderTrial $trial, int $pageNo): string
    {
        return self::directory($trial)."/p{$pageNo}.jpg";
    }

    public static function text(ReaderTrial $trial, int $pageNo): string
    {
        return self::directory($trial)."/t{$pageNo}.txt";
    }

    /** Whether a stored path lies inside this trial's own folder. A row can never point a dry run at a company's file. */
    public static function owns(ReaderTrial $trial, ?string $path): bool
    {
        return is_string($path)
            && str_starts_with($path, self::directory($trial).'/')
            && ! str_contains($path, '..');
    }

    /**
     * The bytes of a page picture, exactly as they were stored at upload.
     *
     * @throws RuntimeException When the file is gone, lies outside the trial's folder or is not the file that was uploaded.
     */
    public static function pictureBytes(ReaderTrial $trial, ReaderTrialPage $page): string
    {
        if (! self::owns($trial, $page->image_path)) {
            throw new RuntimeException('The page picture is not in its dry run\'s folder.');
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

    /** @throws RuntimeException When the pasted text is gone or lies outside the trial's folder. */
    public static function words(ReaderTrial $trial, ReaderTrialPage $page): string
    {
        if (! self::owns($trial, $page->text_path)) {
            throw new RuntimeException('The pasted text is not in its dry run\'s folder.');
        }

        $text = Storage::get($page->text_path);

        if (! is_string($text) || $text === '') {
            throw new RuntimeException('The pasted text is missing.');
        }

        return $text;
    }

    /** Deletes the trial's whole folder. True when nothing of it is left. */
    public static function delete(ReaderTrial $trial): bool
    {
        $directory = self::directory($trial);

        Storage::deleteDirectory($directory);

        return Storage::allFiles($directory) === [];
    }
}
