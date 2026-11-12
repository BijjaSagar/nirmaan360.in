<?php

namespace App\Services\Wording;

/** What was kept and what was refused of a model's answer for the free slots of one document. */
final readonly class SlotDraft
{
    /**
     * @param  array<string, string>  $accepted  Slot id => text that passed Guard A.
     * @param  array<string, list<Violation>>  $rejected  Slot id => why its text was refused.
     * @param  list<string>  $dropped  Ids the model sent that are not free slots of this document, or that it sent twice.
     * @param  list<string>  $missing  Free slots the model gave no usable text for. They are written from their code text.
     * @param  bool  $malformed  The answer did not have the agreed shape at all.
     */
    public function __construct(
        public array $accepted,
        public array $rejected,
        public array $dropped,
        public array $missing,
        public bool $malformed = false,
    ) {}

    /** A refused slot is what calls for one corrective request, and what makes a call free of charge. */
    public function hasRejections(): bool
    {
        return $this->rejected !== [];
    }

    /**
     * The texts to keep. A refused or missing slot is absent, so the renderer writes it from its code text.
     *
     * @return array<string, SlotText>
     */
    public function texts(): array
    {
        return array_map(fn (string $text) => SlotText::ai($text), $this->accepted);
    }

    /** @return array<string, list<string>> Slot id => the rules it broke, without the text. Safe to log and to send back in a corrective request. */
    public function rejectedRules(): array
    {
        return array_map(
            fn (array $violations) => array_values(array_unique(array_map(fn (Violation $violation) => $violation->rule, $violations))),
            $this->rejected,
        );
    }
}
