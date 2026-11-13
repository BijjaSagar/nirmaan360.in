<?php

namespace App\Services\DocumentReading;

use RuntimeException;

/**
 * An uploaded page picture that will not be stored. The reason is a code for the screen or the
 * phone to put into words; the details carry the numbers those words need.
 */
final class ImageRefused extends RuntimeException
{
    public const NOT_AN_IMAGE = 'not_an_image';

    public const IMAGE_TOO_BIG = 'image_too_big';

    public const IMAGE_TOO_SMALL = 'image_too_small';

    public const IMAGE_MIRRORED = 'image_mirrored';

    public const FILE_TOO_BIG = 'file_too_big';

    /** @param  array<string, mixed>  $details */
    public function __construct(public readonly string $reason, public readonly array $details = [])
    {
        parent::__construct("Page picture refused: {$reason}.");
    }

    /** @return array<string, mixed> The body of the answer: the code first, then its numbers. */
    public function toArray(): array
    {
        return ['code' => $this->reason, ...$this->details];
    }
}
