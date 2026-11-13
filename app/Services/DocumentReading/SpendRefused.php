<?php

namespace App\Services\DocumentReading;

use RuntimeException;

/**
 * A read or a text call may not spend right now. The reason is a code for the screen or the phone,
 * the status is the HTTP status the answer carries, and getMessage() is a plain sentence in the
 * reader's language. Nothing was held or charged when this is thrown.
 */
class SpendRefused extends RuntimeException
{
    /** The wallet cannot cover it. */
    public const NO_CREDITS = 'no_credits';

    /** A daily budget of the person or the company is used up. */
    public const DAILY_BUDGET = 'daily_budget';

    /** A limit on this one company: its share of the day, uncharged cost, unusable answers, trial allowance or a block. */
    public const COMPANY_LIMIT = 'company_limit';

    /** A platform limit for the day or the month has been reached, for every company. */
    public const READER_PAUSED = 'reader_paused';

    /** The platform admin has not released the readers yet. */
    public const NOT_RELEASED = 'not_released';

    /** @param  array<string, scalar|null>  $details  Sent with the code. Never a balance or another company's figure. */
    public function __construct(
        public readonly string $reason,
        public readonly int $status,
        string $message,
        public readonly array $details = [],
    ) {
        parent::__construct($message);
    }

    /** @return array<string, scalar|null> The body of the answer: the code first, then its details. */
    public function toArray(): array
    {
        return ['code' => $this->reason, ...$this->details];
    }
}
