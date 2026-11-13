<?php

namespace App\Services\DocumentReading;

/** A page picture as the server stores it and sends it: an upright JPEG with no metadata. */
final readonly class NormalisedImage
{
    public function __construct(
        public string $bytes,
        public int $width,
        public int $height,
        public int $tokens,
        public string $sha256,
    ) {}
}
