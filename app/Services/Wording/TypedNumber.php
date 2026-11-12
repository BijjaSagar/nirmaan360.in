<?php

namespace App\Services\Wording;

/**
 * A number a person typed into a free slot that is not the printed form of any fact:
 * "typed by you, not from the records". It needs its own tick before the document can go out.
 */
final readonly class TypedNumber
{
    /** Fact keys never contain a colon, so a tick for a typed number cannot be taken for a tick of a fact. */
    public const ID_PREFIX = 'typed:';

    public string $id;

    /**
     * @param  int  $ordinal  Its place among the typed numbers of the slot, from 1.
     * @param  int  $offset  Where it starts in the slot's text, counted in characters.
     */
    public function __construct(public string $slot, public int $ordinal, public string $text, public int $offset, public int $length, public string $context = '')
    {
        // The number itself is part of the id: a tick given for "15" does not cover a later "50" in the same place.
        $this->id = self::ID_PREFIX.$slot.':'.$ordinal.':'.$text;
    }

    /** @return array{id: string, slot: string, text: string, context: string} */
    public function toArray(): array
    {
        return ['id' => $this->id, 'slot' => $this->slot, 'text' => $this->text, 'context' => $this->context];
    }
}
