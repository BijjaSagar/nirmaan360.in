<?php

namespace App\Services\DocumentReading;

use RuntimeException;

/**
 * The server itself cannot do a step of reading, whatever was uploaded. Nothing is stored, the
 * person is told the server cannot process the file, and the platform admin is told why.
 */
final class ReaderUnavailable extends RuntimeException
{
    public const IMAGE_TOOLS_MISSING = 'image_tools_missing';

    public const IMAGE_TOOLS_FAILED = 'image_tools_failed';

    public function __construct(public readonly string $reason)
    {
        parent::__construct("The reader cannot run on this server: {$reason}.");
    }
}
