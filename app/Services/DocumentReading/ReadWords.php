<?php

namespace App\Services\DocumentReading;

use App\Models\DocumentRead;

/**
 * The sentences a person is shown for the codes a read stores: why it paused, and why it ended
 * without being read. A code is stored, never a sentence, so each person reads it in their own language.
 */
final class ReadWords
{
    /** A read was left in "preparing": taking the text out of the PDF did not finish in time. */
    public const TOO_LARGE_FOR_TEXT = 'too_large_for_text';

    public const FINISH_FAILED = 'finish_failed';

    public const NOT_STARTED = 'not_started';

    public const PAUSED_TOO_LONG = 'paused_too_long';

    /** An owner or admin switched the readers off for the company while the read was still open. */
    public const CONSENT_WITHDRAWN = 'consent_withdrawn';

    /** Why a read is paused, for document_reads.pause_reason. */
    public static function pause(?string $reason, ?string $locale = null): string
    {
        return match ($reason) {
            DocumentRead::PAUSE_CREDIT_CAP => __('It reached the credit limit set for this document. Continue it with a new limit to read the remaining pages.', [], $locale),
            // Shown in the field app and sent to phones too, so it never says buy: see PlanWording.
            DocumentRead::PAUSE_WALLET => __('Your company has no AI credits left. Your company\'s owner can check the credits.', [], $locale),
            DocumentRead::PAUSE_STARTER_GONE => __('The person who started it can no longer use the reader. Someone else must start it again.', [], $locale),
            DocumentRead::PAUSE_CONSENT_OFF => __('Your company has not agreed to the current notice for sending documents to the AI service. An owner or admin can do that in Settings.', [], $locale),
            DocumentRead::PAUSE_PLAN => __('Your plan no longer includes the AI readers.', [], $locale),
            DocumentRead::PAUSE_MANY_FAILURES => __('Too many pages could not be read. Check that the pages are clear and the right way up before you continue.', [], $locale),
            DocumentRead::PAUSE_PROVIDER_LIMIT => __('The AI service is busy. Continue in a few minutes.', [], $locale),
            DocumentRead::PAUSE_COMPANY_LIMIT => __('Your company has reached a limit for the readers.', [], $locale),
            DocumentRead::PAUSE_PLATFORM_CEILING => __('The readers are paused for now. Try again later.', [], $locale),
            default => __('The AI service cannot be reached right now. Nothing more is sent until someone continues it.', [], $locale),
        };
    }

    /** Why a read ended without being read, for document_reads.error. Empty when there is nothing to say. */
    public static function error(?string $code, ?string $locale = null): string
    {
        return match ($code) {
            self::TOO_LARGE_FOR_TEXT => __('This file is too large to read as text. Read it as page pictures.', [], $locale),
            self::FINISH_FAILED => __('Something went wrong while finishing this document.', [], $locale),
            self::NOT_STARTED => __('It was not started in time, so it was cancelled.', [], $locale),
            self::PAUSED_TOO_LONG => __('It stayed paused for too long, so it was cancelled.', [], $locale),
            self::CONSENT_WITHDRAWN => __('Your company switched off the AI readers, so it was cancelled.', [], $locale),
            default => '',
        };
    }
}
