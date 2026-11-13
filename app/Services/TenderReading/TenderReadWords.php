<?php

namespace App\Services\TenderReading;

use App\Models\CrmTenderFact;
use App\Models\DocumentRead;
use App\Services\DocumentReading\ReadWords;

/** The sentences the tender reader's screens show for the codes it stores. */
final class TenderReadWords
{
    /** Why taking the text out of a tender file ended, for a read that failed while it was prepared. */
    public static function error(DocumentRead $read): string
    {
        $details = (array) ($read->options['refused'] ?? []);

        return match ($read->error) {
            TenderTextRefused::DOCUMENT_GONE => __('This file is no longer on the tender. Nothing was read.'),
            TenderTextRefused::NOT_A_PDF => __('This file is not a PDF, so its text cannot be taken out. Nothing was read.'),
            TenderTextRefused::TOO_LARGE => __('Only a PDF of up to :mb MB can be read as text.', ['mb' => (int) ($details['mb'] ?? 10)]),
            TenderTextRefused::UNREADABLE => __('This PDF could not be opened. It may be damaged or have a password. Save a fresh copy and upload it again.'),
            TenderTextRefused::TOO_MANY_PAGES => __('This PDF has :pages pages. Up to :max pages can be read as text.', ['pages' => (int) ($details['pages'] ?? 0), 'max' => (int) ($details['max'] ?? TenderFields::setting('max_pages', 50))]),
            TenderTextRefused::TOO_SLOW => __('This PDF is too complex to read in the time allowed. Nothing was read.'),
            default => ReadWords::error($read->error),
        };
    }

    /** Where a fact's line was checked, in the one sentence per source that may be said (spec D2). */
    public static function source(CrmTenderFact $fact): string
    {
        return match (true) {
            $fact->source === CrmTenderFact::FROM_IMAGE => __('Read from the page picture. Check it against the page.'),
            $fact->quote_found === true => __("Matches the PDF's text layer on page :page.", ['page' => $fact->page]),
            default => __('Not matched to the text of page :page. Check it against the page.', ['page' => $fact->page]),
        };
    }

    /** Why a page was not read, said of one page or of several. */
    public static function pageFlag(string $flag, int $pages = 1): string
    {
        if ($pages > 1) {
            return match ($flag) {
                TenderPdfText::MACHINE_TEXT => __('their text was laid over a scan by a machine'),
                TenderPdfText::OLD_FONT => __('their text is in an old Marathi or Hindi font'),
                TenderPdfText::NO_TEXT => __('they have no text'),
                'unreadable' => __('the reader could not make sense of their text'),
                'failed' => __('the reader could not read them'),
                'not_sent' => __('they were not sent to be read'),
                default => __('their text does not match the document'),
            };
        }

        return match ($flag) {
            TenderPdfText::MACHINE_TEXT => __('its text was laid over a scan by a machine'),
            TenderPdfText::OLD_FONT => __('its text is in an old Marathi or Hindi font'),
            TenderPdfText::NO_TEXT => __('it has no text'),
            'unreadable' => __('the reader could not make sense of its text'),
            'failed' => __('the reader could not read it'),
            'not_sent' => __('it was not sent to be read'),
            default => __('its text does not match the document'),
        };
    }
}
