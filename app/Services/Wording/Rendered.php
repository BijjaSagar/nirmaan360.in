<?php

namespace App\Services\Wording;

/**
 * A document put together from its slots and one fact sheet. It knows where every figure in it
 * came from: a fact the code inserted, or a number a person typed.
 */
final readonly class Rendered
{
    /**
     * @param  string  $fingerprint  Of the fact sheet the figures were taken from.
     * @param  list<RenderedSlot>  $slots
     * @param  array<string, string>  $labels  Fact key => label, for the facts the document shows.
     */
    public function __construct(
        public string $type,
        public string $locale,
        public string $fingerprint,
        public array $slots,
        public array $labels,
    ) {}

    /** The whole text, slots separated by an empty line. */
    public function text(string $between = "\n\n"): string
    {
        return implode($between, array_map(fn (RenderedSlot $slot) => $slot->text, $this->slots));
    }

    public function slot(string $id): ?RenderedSlot
    {
        foreach ($this->slots as $slot) {
            if ($slot->id === $id) {
                return $slot;
            }
        }

        return null;
    }

    /** @return list<string> Every fact the document shows, once, in the order of first appearance. */
    public function factKeys(): array
    {
        $keys = [];

        foreach ($this->slots as $slot) {
            foreach ($slot->spans as $span) {
                $keys[$span->fact] = true;
            }
        }

        return array_keys($keys);
    }

    /** @return list<TypedNumber> */
    public function typedNumbers(): array
    {
        return array_merge(...array_map(fn (RenderedSlot $slot) => $slot->typed, $this->slots));
    }

    /**
     * What a person must tick before a letter or a client document goes out: every fact shown,
     * then every number typed by hand.
     *
     * @return list<string>
     */
    public function ticksNeeded(): array
    {
        return [...$this->factKeys(), ...array_map(fn (TypedNumber $number) => $number->id, $this->typedNumbers())];
    }

    /** @return list<string> Free slots where a model's text was kept out and the code text stands instead. */
    public function replaced(): array
    {
        return array_values(array_map(
            fn (RenderedSlot $slot) => $slot->id,
            array_filter($this->slots, fn (RenderedSlot $slot) => $slot->replaced),
        ));
    }

    /**
     * The free slots as they now stand, for the document's slots column.
     *
     * @return array<string, array{text: string, source: string}>
     */
    public function freeTexts(): array
    {
        $texts = [];

        foreach ($this->slots as $slot) {
            if ($slot->type === Slot::FREE) {
                $texts[$slot->id] = ['text' => $slot->text, 'source' => $slot->source];
            }
        }

        return $texts;
    }

    /**
     * For the review screen: each slot cut into plain text, inserted facts with their label, and typed numbers.
     *
     * @return list<array{id: string, type: string, source: string, parts: list<array{text: string, fact: ?string, label: ?string, typed: ?string}>}>
     */
    public function parts(): array
    {
        return array_map(fn (RenderedSlot $slot) => [
            'id' => $slot->id,
            'type' => $slot->type,
            'source' => $slot->source,
            'parts' => $slot->parts($this->labels),
        ], $this->slots);
    }
}
