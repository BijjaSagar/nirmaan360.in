<?php

namespace App\Services\OwnerPack;

use App\Services\Wording\SlotText;

/**
 * Sentences the AI wrote are in the language they were asked for. When a draft's language changes
 * they go, and the code's own sentence in the new language stands. The form still shows the old
 * text in its boxes, and sending it back unchanged is not taken as a sentence the person typed.
 */
final class AiSentences
{
    /**
     * @param  list<string>  $free  The document's free slot ids.
     * @param  array<string, mixed>|null  $stored  The slots column as stored.
     * @param  array<string, ?string>  $typed  Slot id => what the form sent.
     * @param  bool  $languageChanged  Whether the draft is being given another language than the one its sentences were written for.
     * @return array{0: array<string, mixed>|null, 1: array<string, ?string>} The stored slots and the typed text to go on with.
     */
    public static function forLanguage(array $free, ?array $stored, array $typed, bool $languageChanged): array
    {
        if (! $languageChanged || $stored === null) {
            return [$stored, $typed];
        }

        foreach ($free as $id) {
            $slot = $stored[$id] ?? null;

            if (! is_array($slot) || ($slot['source'] ?? null) !== SlotText::AI) {
                continue;
            }

            if (array_key_exists($id, $typed) && self::cleaned($typed[$id]) === self::cleaned($slot['text'] ?? null)) {
                $typed[$id] = '';
            }

            unset($stored[$id]);
        }

        return [$stored, $typed];
    }

    private static function cleaned(mixed $text): string
    {
        return is_string($text) ? trim(str_replace(["\r\n", "\r"], "\n", $text)) : '';
    }
}
