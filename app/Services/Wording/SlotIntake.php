<?php

namespace App\Services\Wording;

/**
 * Guard B, with Guard A applied to what is left. Takes a model's answer in the shape
 * {"slots":[{"id": string, "text": string}]} and keeps only text for the free slots this
 * document declares. Anything else is dropped; a free slot with no usable text stays with its code text.
 */
final class SlotIntake
{
    public function __construct(private SlotGuard $guard, private WordLists $lists) {}

    public function accept(DocumentType $type, mixed $output, string $locale): SlotDraft
    {
        FactFormat::locale($locale);

        $entries = is_array($output) ? ($output['slots'] ?? null) : null;

        if (! is_array($entries)) {
            return new SlotDraft([], [], [], $type->freeIds(), malformed: true);
        }

        $accepted = [];
        $rejected = [];
        $dropped = [];

        foreach ($entries as $entry) {
            $id = is_array($entry) ? ($entry['id'] ?? null) : null;
            $text = is_array($entry) ? ($entry['text'] ?? null) : null;

            if (! is_string($id) || ! is_string($text)) {
                if (is_string($id)) {
                    $dropped[] = $id;
                }

                continue;
            }

            $slot = $type->freeSlot($id);

            // Not a free slot of this document, or a second text for a slot already answered.
            if ($slot === null || isset($accepted[$id]) || isset($rejected[$id])) {
                $dropped[] = $id;

                continue;
            }

            $text = $this->tidy($text, $slot->maxChars ?? $this->lists->maxFreeChars());

            if ($text === '') {
                continue;
            }

            $violations = $this->guard->check($text, $locale);

            if ($violations === []) {
                $accepted[$id] = $text;
            } else {
                $rejected[$id] = $violations;
            }
        }

        $missing = array_values(array_diff($type->freeIds(), array_keys($accepted), array_keys($rejected)));

        return new SlotDraft($accepted, $rejected, $dropped, $missing);
    }

    /** One kind of line break, no space at the ends, and never longer than the slot allows. */
    private function tidy(string $text, int $maxChars): string
    {
        if (! mb_check_encoding($text, 'UTF-8')) {
            return $text;
        }

        $text = trim(str_replace(["\r\n", "\r"], "\n", $text));

        if (mb_strlen($text) <= $maxChars) {
            return $text;
        }

        $cut = mb_substr($text, 0, $maxChars);
        $lastSpace = mb_strrpos($cut, ' ');

        return rtrim($lastSpace !== false && $lastSpace > $maxChars / 2 ? mb_substr($cut, 0, $lastSpace) : $cut);
    }
}
