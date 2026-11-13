<?php

namespace App\Services\TenderReading;

use RuntimeException;

/**
 * A tender file whose text cannot be taken out. The code is stored on the read (document_reads.error)
 * and turned into a sentence in the person's own language by TenderReadWords.
 */
class TenderTextRefused extends RuntimeException
{
    public const DOCUMENT_GONE = 'tender_document_gone';

    public const NOT_A_PDF = 'tender_not_a_pdf';

    public const TOO_LARGE = 'tender_too_large';

    public const UNREADABLE = 'tender_unreadable';

    public const TOO_MANY_PAGES = 'tender_too_many_pages';

    public const TOO_SLOW = 'tender_too_slow';

    /** @param  array<string, int>  $details */
    public function __construct(public readonly string $reason, public readonly array $details = [])
    {
        parent::__construct($reason);
    }
}
