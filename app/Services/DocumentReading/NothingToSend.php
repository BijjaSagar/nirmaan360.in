<?php

namespace App\Services\DocumentReading;

use RuntimeException;

/**
 * A text call handler found that there is nothing to ask the model: the record the call is about
 * is gone, or it no longer needs the answer. Thrown from request(). Nothing is sent and nothing is
 * charged; a call that already exists ends as failed with this reason.
 */
final class NothingToSend extends RuntimeException
{
    public const SUBJECT_GONE = 'subject_gone';

    /** @param  string  $reason  A short code for the call's fail_reason, at most 24 characters. */
    public function __construct(public readonly string $reason = self::SUBJECT_GONE)
    {
        parent::__construct("There is nothing to send for this text call: {$reason}.");
    }
}
