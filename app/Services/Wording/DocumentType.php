<?php

namespace App\Services\Wording;

use InvalidArgumentException;

/**
 * A document as an ordered list of slots, declared in code by the feature that owns it.
 * A letter with three unpaid bills is a type with three bill sentences: the list is built per document.
 */
final readonly class DocumentType
{
    /**
     * What a model returns for the free slots. One fixed shape for every document, so the provider
     * compiles it once. Which ids are allowed is checked by SlotIntake, not by the shape.
     */
    public const OUTPUT_SCHEMA = [
        'type' => 'object',
        'properties' => [
            'slots' => [
                'type' => 'array',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'id' => ['type' => 'string'],
                        'text' => ['type' => 'string'],
                    ],
                    'required' => ['id', 'text'],
                    'additionalProperties' => false,
                ],
            ],
        ],
        'required' => ['slots'],
        'additionalProperties' => false,
    ];

    /** @var list<Slot> */
    public array $slots;

    /**
     * @param  string  $key  Names the type, for example eot_request.
     * @param  iterable<Slot>  $slots
     */
    public function __construct(public string $key, iterable $slots)
    {
        if (preg_match(Slot::ID_PATTERN, $key) !== 1) {
            throw new InvalidArgumentException("'{$key}' cannot be used as a document type.");
        }

        $list = [];

        foreach ($slots as $slot) {
            if (! $slot instanceof Slot) {
                throw new InvalidArgumentException("Document type '{$key}' holds slots only.");
            }

            if (isset($list[$slot->id])) {
                throw new InvalidArgumentException("Document type '{$key}' has the slot '{$slot->id}' twice.");
            }

            $list[$slot->id] = $slot;
        }

        if ($list === []) {
            throw new InvalidArgumentException("Document type '{$key}' has no slots.");
        }

        $this->slots = array_values($list);
    }

    public function slot(string $id): ?Slot
    {
        foreach ($this->slots as $slot) {
            if ($slot->id === $id) {
                return $slot;
            }
        }

        return null;
    }

    /** The slot when it is one a model or a person may write, otherwise null. */
    public function freeSlot(string $id): ?Slot
    {
        $slot = $this->slot($id);

        return $slot?->isFree() ? $slot : null;
    }

    /** @return list<Slot> */
    public function freeSlots(): array
    {
        return array_values(array_filter($this->slots, fn (Slot $slot) => $slot->isFree()));
    }

    /** @return list<string> */
    public function freeIds(): array
    {
        return array_map(fn (Slot $slot) => $slot->id, $this->freeSlots());
    }

    /** @return list<string> Every fact key a fixed slot states, in document order. */
    public function factKeys(): array
    {
        $keys = [];

        foreach ($this->slots as $slot) {
            array_push($keys, ...$slot->factKeys());
        }

        return array_values(array_unique($keys));
    }

    /**
     * What a model is told about each free slot: its purpose, and what the fixed slots beside it
     * state, by label. Never a value. A label that carries a numeral or repeats a value of the
     * sheet is left out, so the model has no figure to copy.
     *
     * @return list<array{id: string, purpose: string, nearby_facts: list<string>}>
     */
    public function brief(FactSheet $sheet): array
    {
        $brief = [];

        foreach ($this->slots as $index => $slot) {
            if (! $slot->isFree()) {
                continue;
            }

            $labels = [];

            foreach ([$this->fixedNeighbour($index, -1), $this->fixedNeighbour($index, 1)] as $neighbour) {
                foreach ($neighbour?->factKeys() ?? [] as $key) {
                    $label = $sheet->find($key)?->label;

                    if ($label !== null && self::safeLabel($label, $sheet)) {
                        $labels[] = $label;
                    }
                }
            }

            $brief[] = ['id' => $slot->id, 'purpose' => (string) $slot->purpose, 'nearby_facts' => array_values(array_unique($labels))];
        }

        return $brief;
    }

    private function fixedNeighbour(int $index, int $step): ?Slot
    {
        for ($at = $index + $step; isset($this->slots[$at]); $at += $step) {
            if ($this->slots[$at]->isFixed()) {
                return $this->slots[$at];
            }
        }

        return null;
    }

    private static function safeLabel(string $label, FactSheet $sheet): bool
    {
        if (preg_match('/\p{N}/u', $label) !== 0) {
            return false;
        }

        $folded = mb_strtolower($label);

        foreach ($sheet as $fact) {
            if (mb_strlen($fact->value) >= 3 && str_contains($folded, mb_strtolower($fact->value))) {
                return false;
            }
        }

        return true;
    }
}
