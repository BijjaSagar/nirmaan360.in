<?php

namespace App\Services\Wording;

/** Where the code put one fact into the text of a slot. Offset and length count characters, not bytes. */
final readonly class Span
{
    public function __construct(public string $fact, public int $offset, public int $length) {}

    /** @return array{fact: string, offset: int, length: int} */
    public function toArray(): array
    {
        return ['fact' => $this->fact, 'offset' => $this->offset, 'length' => $this->length];
    }
}
