<?php

namespace Tests\Feature\Website;

/**
 * Wording the product must never use about what its AI does. Nothing here has been measured or promised by
 * anyone, so none of it may be said on a website page, a screen, a store listing or in an app string.
 *
 * These are phrases, matched as whole words. A screen may rightly say "approved measurements" or
 * "Bank guarantee", so a test should hand over only the files that describe an AI feature.
 */
final class BannedWording
{
    /** Phrase as a person would name it => how to find it. */
    private const PATTERNS = [
        '100%' => '/(?<![\d.,])100\s?%/u',
        'guaranteed' => '/\bguaranteed\b/i',
        'error-free' => '/\berror[- ]free\b/i',
        'always correct' => '/\balways correct\b/i',
        'perfect' => '/\bperfect(ly)?\b/i',
        'an accuracy percentage' => '/\d\s?%\s+(accura\w+|correct)\b|\baccura\w+\s+(of|is|above|over)\s+\d/i',
        'only we' => '/\bonly we\b/i',
        'the only' => '/\bthe only\b/i',
        'first in India' => '/\bfirst in india\b/i',
        'no one else' => '/\bno one else\b/i',
        'unique' => '/\bunique\b/i',
        'approved by the department' => '/\bapproved by the department\b/i',
        'department-approved' => '/\bdepartment[- ]approved\b/i',
        'legally compliant' => '/\blegally compliant\b/i',
        'legal advice' => '/\blegal advice\b/i',
        'the department will pass it' => '/\bthe department will pass\b/i',
        'no engineer needed' => '/\bno engineer needed\b/i',
        'proof of' => '/\bproof of\b/i',
        'verified on the page' => '/\bverified on the page\b/i',
        'found on the page' => '/\bfound on the page\b/i',
    ];

    /** @return list<string> The phrases found in the text, as named above. */
    public static function found(string $text): array
    {
        return array_keys(array_filter(self::PATTERNS, fn (string $pattern) => preg_match($pattern, $text) === 1));
    }

    /** @return list<string> */
    public static function phrases(): array
    {
        return array_keys(self::PATTERNS);
    }
}
