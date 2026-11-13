<?php

namespace App\Services\OwnerPack;

use App\Services\DocumentReading\ExifOrientation;
use GdImage;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * A site photo as it is handed to a client through a shared link: drawn again by the server as an
 * upright JPEG of a modest size. Nothing of the stored file leaves but its pixels, so where and
 * with which phone a photo was taken does not travel with it.
 *
 * A photo that cannot be drawn again is not sent at all. The stored file is never sent as it is.
 *
 * Not final only so a test can stand in for a server without the image functions.
 */
class SharedPhoto
{
    /** What the server decodes, and the GD constant that says it can. Named, not used, so the class loads without GD. */
    private const TYPES = ['image/jpeg' => 'IMG_JPG', 'image/png' => 'IMG_PNG', 'image/webp' => 'IMG_WEBP'];

    private const QUALITY = 82;

    /**
     * Whether a stored photo can be sent: the file is there, its header says it is a picture this
     * server decodes, and it is not larger than the server opens. Read from the header alone, so a
     * person is told when they pick the photo, not after the update has gone out.
     */
    public function sendable(string $path): bool
    {
        return $this->header($path) !== null;
    }

    /** The JPEG to send, or null when the file is missing, is not a picture, is too large to open, or cannot be redrawn here. */
    public function jpeg(string $path): ?string
    {
        try {
            $header = $this->header($path);

            if ($header === null) {
                return null;
            }

            [$bytes, $type] = $header;
            $source = @imagecreatefromstring($bytes);

            if (! $source instanceof GdImage) {
                return null;
            }

            $orientation = $type === 'image/jpeg' ? ExifOrientation::of($bytes) : 1;
            unset($bytes);

            return $this->encode($this->upright($this->fitted($source), $orientation));
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }

    /** Whether this server has the image functions. */
    protected static function canRedraw(): bool
    {
        foreach (['imagecreatefromstring', 'imagecreatetruecolor', 'imagecopyresampled', 'imagerotate', 'imageflip', 'imagejpeg', 'imagetypes'] as $function) {
            if (! function_exists($function)) {
                return false;
            }
        }

        return true;
    }

    /**
     * The bytes of the file and its kind, when it may be opened. The size is checked from the header,
     * before any pixel is decoded into memory.
     *
     * @return array{0: string, 1: string}|null
     */
    private function header(string $path): ?array
    {
        try {
            if (! static::canRedraw() || ! Storage::exists($path)) {
                return null;
            }

            $bytes = (string) Storage::get($path);
            $size = @getimagesizefromstring($bytes);
            $type = is_array($size) ? (string) ($size['mime'] ?? '') : '';
            $decodes = self::TYPES[$type] ?? null;

            if ($decodes === null || ! defined($decodes) || (imagetypes() & constant($decodes)) === 0) {
                return null;
            }

            [$width, $height] = [(int) $size[0], (int) $size[1]];
            $pixels = $width * $height;

            if ($width < 1 || $height < 1 || $pixels > (int) config('modules.owner_pack.photo_max_pixels')) {
                return null;
            }

            return [$bytes, $type];
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }

    /** Drawn onto a white canvas no longer than the configured edge. JPEG has no transparency, so a clear background becomes white. */
    private function fitted(GdImage $source): GdImage
    {
        [$width, $height] = [imagesx($source), imagesy($source)];
        $edge = max(320, (int) config('modules.owner_pack.photo_edge'));
        $scale = min(1, $edge / max($width, $height));
        $canvas = imagecreatetruecolor(max(1, (int) round($width * $scale)), max(1, (int) round($height * $scale)));

        imagefill($canvas, 0, 0, (int) imagecolorallocate($canvas, 255, 255, 255));
        imagecopyresampled($canvas, $source, 0, 0, 0, 0, imagesx($canvas), imagesy($canvas), $width, $height);

        return $canvas;
    }

    /**
     * Turned the way the phone was held. imagerotate turns anticlockwise: orientation 6 needs a quarter
     * turn clockwise and 8 the other way. The mirrored kinds (2, 4, 5, 7) are flipped first.
     */
    private function upright(GdImage $photo, int $orientation): GdImage
    {
        if (in_array($orientation, [2, 4, 5, 7], true)) {
            imageflip($photo, $orientation === 4 ? IMG_FLIP_VERTICAL : IMG_FLIP_HORIZONTAL);
        }

        $angle = match ($orientation) {
            3 => 180,
            6, 7 => -90,
            5, 8 => 90,
            default => 0,
        };

        if ($angle === 0) {
            return $photo;
        }

        $turned = imagerotate($photo, $angle, 0);

        return $turned instanceof GdImage ? $turned : $photo;
    }

    private function encode(GdImage $photo): ?string
    {
        ob_start();
        $written = imagejpeg($photo, null, self::QUALITY);
        $jpeg = (string) ob_get_clean();

        return $written && $jpeg !== '' ? $jpeg : null;
    }
}
