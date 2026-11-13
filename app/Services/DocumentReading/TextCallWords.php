<?php

namespace App\Services\DocumentReading;

/**
 * The sentence a person is shown when a text call ended without an answer that could be used. A
 * code is stored on the call, never a sentence, so each person reads it in their own language.
 * The feature shows it beside what it put in place instead: its own wording, its own order.
 */
final class TextCallWords
{
    /** Why a call gave nothing, for ai_text_calls.fail_reason. Empty when there is nothing to say. */
    public static function failure(?string $reason, ?string $locale = null): string
    {
        return match ($reason) {
            null, '' => '',
            TextCallRunner::REJECTED => __('What the AI wrote did not pass the app\'s checks, so it was not used. Nothing was charged.', [], $locale),
            TextOutcome::TIMEOUT, TextOutcome::UNAVAILABLE, TextCallRunner::NOT_CONNECTED, TextCallRunner::WORKER_LOST, TextCallRunner::NOT_STARTED => __('The AI service did not answer. Nothing was charged. Try again in a few minutes.', [], $locale),
            TextCallRunner::COMPANY_LIMIT, TextCallRunner::PLATFORM_CEILING, TextCallRunner::NOT_RELEASED => __('The AI features are paused for now. Nothing was charged. Try again later.', [], $locale),
            TextCallRunner::CONSENT_OFF => __('Your company has not agreed to the current notice for sending documents to the AI service. An owner or admin can do that in Settings.', [], $locale),
            TextCallRunner::PLAN => __('Your plan no longer includes the AI features.', [], $locale),
            TextCallRunner::STARTER_GONE => __('The person who asked for this can no longer use the AI features. Ask for it again yourself.', [], $locale),
            default => __('The AI could not prepare this. Nothing was charged.', [], $locale),
        };
    }
}
