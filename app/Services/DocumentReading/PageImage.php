<?php

namespace App\Services\DocumentReading;

use finfo;
use GdImage;

/**
 * Turns an uploaded page picture into the only form that is ever stored or sent to the model:
 * an upright JPEG redrawn by the server, small enough to be read at the size it is sent.
 *
 * Nothing of the uploaded file survives except its pixels. Camera details, location, comments and
 * colour profiles are not copied, because the picture is drawn onto a new canvas and encoded again,
 * and the encoder's own comment line is taken out afterwards. A picture that cannot be redrawn is
 * refused; the bytes as received are never kept.
 *
 * Not final only so a test can stand in for a server without the image functions.
 */
class PageImage
{
    /** What may be uploaded, and the GD constant that says this server can decode it. Named, not used, so the class loads without GD. */
    public const TYPES = ['image/jpeg' => 'IMG_JPG', 'image/png' => 'IMG_PNG', 'image/webp' => 'IMG_WEBP'];

    /** Checked from the file header, before any pixel is decoded into memory. */
    public const MAX_PIXELS = 12_000_000;

    public const MAX_BYTES = 4 * 1024 * 1024;

    public const QUALITY = 90;

    /**
     * @throws ImageRefused When this picture cannot be used. The reason says what to tell the person.
     * @throws ReaderUnavailable When the server lacks the image functions, for any picture.
     */
    public static function normalise(string $bytes, int $minShortSide): NormalisedImage
    {
        $type = (string) (new finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
        $header = isset(self::TYPES[$type]) ? @getimagesizefromstring($bytes) : false;

        if (! $header || ($header['mime'] ?? '') !== $type || $header[0] < 1 || $header[1] < 1) {
            throw new ImageRefused(ImageRefused::NOT_AN_IMAGE);
        }

        // The decoder fills the missing part of a cut-off JPEG with grey and says nothing, and a reader
        // would then report half a page as the whole of it. The other two kinds fail to decode instead.
        if ($type === 'image/jpeg' && ! self::isWhole($bytes)) {
            throw new ImageRefused(ImageRefused::NOT_AN_IMAGE);
        }

        $orientation = $type === 'image/jpeg' ? ExifOrientation::of($bytes) : 1;

        if ($header[0] * $header[1] > self::MAX_PIXELS) {
            throw self::tooBig(...self::uprightSize($header[0], $header[1], $orientation));
        }

        if (! static::canRedraw($type)) {
            throw new ReaderUnavailable(ReaderUnavailable::IMAGE_TOOLS_MISSING);
        }

        // Mirrored orientations are refused rather than flipped back: a page of figures is not the
        // place to guess which way round it was.
        if (in_array($orientation, [2, 4, 5, 7], true)) {
            throw new ImageRefused(ImageRefused::IMAGE_MIRRORED);
        }

        self::assertSize($header[0], $header[1], $orientation, $minShortSide);

        $source = @imagecreatefromstring($bytes);

        if (! $source instanceof GdImage) {
            throw new ImageRefused(ImageRefused::NOT_AN_IMAGE);
        }

        // The decoder has the last word on the size. The header was only a way to refuse early.
        self::assertSize(imagesx($source), imagesy($source), $orientation, $minShortSide);

        $page = self::upright(self::onWhite($source), $orientation);
        unset($source);

        ob_start();
        $encoded = imagejpeg($page, null, self::QUALITY);
        $jpeg = self::withoutMetadata((string) ob_get_clean());

        if (! $encoded || $jpeg === null) {
            throw new ReaderUnavailable(ReaderUnavailable::IMAGE_TOOLS_FAILED);
        }

        if (strlen($jpeg) > self::MAX_BYTES) {
            throw new ImageRefused(ImageRefused::FILE_TOO_BIG, ['max_bytes' => self::MAX_BYTES]);
        }

        [$width, $height] = [imagesx($page), imagesy($page)];

        return new NormalisedImage($jpeg, $width, $height, ImageTokens::count($width, $height), hash('sha256', $jpeg));
    }

    /** A JPEG that reaches its picture data and its end marker. Bytes after the end marker do not matter. */
    private static function isWhole(string $jpeg): bool
    {
        $scan = JpegSegments::read($jpeg)['scan'];

        return $scan !== null && strpos($jpeg, "\xFF\xD9", $scan) !== false;
    }

    /** Whether this server can decode the given kind of picture and write a JPEG. */
    protected static function canRedraw(string $type): bool
    {
        foreach (['imagecreatefromstring', 'imagecreatetruecolor', 'imagecopy', 'imagerotate', 'imagejpeg', 'imagetypes'] as $function) {
            if (! function_exists($function)) {
                return false;
            }
        }

        $decodes = self::TYPES[$type];

        return defined($decodes) && (imagetypes() & constant($decodes)) !== 0 && (imagetypes() & IMG_JPG) !== 0;
    }

    /**
     * Width and height once the picture stands upright.
     *
     * @return array{0:int,1:int}
     */
    private static function uprightSize(int $storedWidth, int $storedHeight, int $orientation): array
    {
        return in_array($orientation, [5, 6, 7, 8], true) ? [$storedHeight, $storedWidth] : [$storedWidth, $storedHeight];
    }

    /** The limits apply to the picture as it will stand once turned upright. */
    private static function assertSize(int $storedWidth, int $storedHeight, int $orientation, int $minShortSide): void
    {
        [$width, $height] = self::uprightSize($storedWidth, $storedHeight, $orientation);

        if ($width * $height > self::MAX_PIXELS || ! ImageTokens::fits($width, $height)) {
            throw self::tooBig($width, $height);
        }

        if (min($width, $height) < $minShortSide) {
            throw new ImageRefused(ImageRefused::IMAGE_TOO_SMALL, [
                'min_short_side' => $minShortSide, 'width' => $width, 'height' => $height,
            ]);
        }
    }

    private static function tooBig(int $width, int $height): ImageRefused
    {
        [$fitWidth, $fitHeight] = ImageTokens::largestFit($width, $height);

        return new ImageRefused(ImageRefused::IMAGE_TOO_BIG, [
            'max_tokens' => ImageTokens::MAX_TOKENS,
            'max_edge' => ImageTokens::MAX_EDGE,
            'width' => $width,
            'height' => $height,
            'fits' => ['width' => $fitWidth, 'height' => $fitHeight],
        ]);
    }

    /** JPEG has no transparency, so a clear background becomes white rather than black. */
    private static function onWhite(GdImage $source): GdImage
    {
        [$width, $height] = [imagesx($source), imagesy($source)];
        $canvas = imagecreatetruecolor($width, $height);

        if (! $canvas instanceof GdImage) {
            throw new ReaderUnavailable(ReaderUnavailable::IMAGE_TOOLS_FAILED);
        }

        imagealphablending($canvas, true);
        imagefilledrectangle($canvas, 0, 0, $width - 1, $height - 1, (int) imagecolorallocate($canvas, 255, 255, 255));
        imagecopy($canvas, $source, 0, 0, 0, 0, $width, $height);

        return $canvas;
    }

    /** imagerotate turns anticlockwise. Orientation 6 needs a quarter turn clockwise, 8 the other way. */
    private static function upright(GdImage $page, int $orientation): GdImage
    {
        $angle = match ($orientation) {
            3 => 180,
            6 => -90,
            8 => 90,
            default => 0,
        };

        if ($angle === 0) {
            return $page;
        }

        $turned = imagerotate($page, $angle, 0);

        if (! $turned instanceof GdImage) {
            throw new ReaderUnavailable(ReaderUnavailable::IMAGE_TOOLS_FAILED);
        }

        return $turned;
    }

    /**
     * The encoder writes one comment naming itself and nothing else of that sort. Every comment and
     * every application block other than the plain JFIF header is dropped here, so the claim that the
     * stored file carries no metadata does not rest on what an encoder version happens to write.
     * Null when the encoder's output is not a JPEG that can be walked.
     */
    private static function withoutMetadata(string $jpeg): ?string
    {
        $parts = JpegSegments::read($jpeg);

        if ($parts['scan'] === null) {
            return null;
        }

        $kept = "\xFF\xD8";

        foreach ($parts['segments'] as $segment) {
            $marker = $segment['marker'];
            $jfif = $marker === JpegSegments::APP0 && str_starts_with(JpegSegments::payload($jpeg, $segment), "JFIF\0");
            $extra = $marker === JpegSegments::COMMENT || ($marker >= JpegSegments::APP0 && $marker <= JpegSegments::APP15);

            if ($jfif || ! $extra) {
                $kept .= substr($jpeg, $segment['offset'], $segment['length']);
            }
        }

        return $kept.substr($jpeg, $parts['scan']);
    }
}
