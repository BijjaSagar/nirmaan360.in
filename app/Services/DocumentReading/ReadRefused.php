<?php

namespace App\Services\DocumentReading;

use App\Models\DocumentRead;
use RuntimeException;

/**
 * A step on a read that cannot be taken as things stand: the read is in the wrong state, pages are
 * missing, it does not fit the limits. The reason is a code for the screen or the phone, the status
 * is the HTTP status the answer carries, and getMessage() is a plain sentence in the reader's
 * language. Nothing was changed when this is thrown.
 */
class ReadRefused extends RuntimeException
{
    /** The read, or the page, is not in a state that allows this step. The answer says which state it is in. */
    public const WRONG_STATE = 'wrong_state';

    /** Pages of the range to read have nothing stored. */
    public const PAGES_MISSING = 'pages_missing';

    /** The read does not fit a limit as it is. The answer says how many pages from the front would. */
    public const OVER_LIMIT = 'over_limit';

    /** The read already has as many pages as its kind takes. */
    public const TOO_MANY_PAGES = 'too_many_pages';

    /** This page has been read again as often as is offered. */
    public const RETRIES_USED = 'retries_used';

    /** The answer for this page was cut off. Reading it again the same way would be cut off again. */
    public const CUT_OFF = 'cut_off';

    /** The stronger model is not offered for this page or to this company. */
    public const NOT_OFFERED = 'not_offered';

    /** The file is not a PDF, or is larger than a source file may be. */
    public const NOT_A_PDF = 'not_a_pdf';

    /** The file could not be kept on the disk. Nothing points at it; sending it again is safe. */
    public const NOT_STORED = 'not_stored';

    /** @param  array<string, mixed>  $details  Sent with the code. */
    public function __construct(
        public readonly string $reason,
        string $message,
        public readonly array $details = [],
        public readonly int $status = 409,
    ) {
        parent::__construct($message);
    }

    /** A repeated request finds the step already taken; the state in the answer lets the caller see that. */
    public static function wrongState(DocumentRead $read, ?string $pageStatus = null): self
    {
        return new self(self::WRONG_STATE, __('This cannot be done with the document as it is now. Reload the page and try again.'), array_filter([
            'status' => $read->status,
            'page_status' => $pageStatus,
        ]));
    }

    /** @return array<string, mixed> The body of the answer: the code first, then its details. */
    public function toArray(): array
    {
        return ['code' => $this->reason, ...$this->details];
    }
}
