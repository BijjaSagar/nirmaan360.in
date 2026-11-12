<?php

namespace App\Services\Wording;

/** One slot of a document after its text was put together. */
final readonly class RenderedSlot
{
    /** The source of a fixed slot: written by the code from its template and the facts. */
    public const CODE = 'code';

    /**
     * @param  string  $source  RenderedSlot::CODE for a fixed slot, otherwise one of SlotText::SOURCES.
     * @param  list<Span>  $spans  Where the code put a fact, in order.
     * @param  list<TypedNumber>  $typed  Numbers a person typed that are not from the records.
     * @param  bool  $replaced  A model's text was kept out because it broke Guard A, and the code text stands instead.
     */
    public function __construct(
        public string $id,
        public string $type,
        public string $source,
        public string $text,
        public array $spans = [],
        public array $typed = [],
        public bool $replaced = false,
    ) {}

    /**
     * The text cut into plain pieces, inserted facts and typed numbers, so a view can mark each
     * figure without working with offsets. Pieces are raw text: escape them when printing.
     *
     * @param  array<string, string>  $labels  Fact key => label.
     * @return list<array{text: string, fact: ?string, label: ?string, typed: ?string}>
     */
    public function parts(array $labels = []): array
    {
        $marks = [];

        foreach ($this->spans as $span) {
            $marks[] = [$span->offset, $span->length, $span->fact, null];
        }

        foreach ($this->typed as $number) {
            $marks[] = [$number->offset, $number->length, null, $number->id];
        }

        usort($marks, fn (array $a, array $b) => $a[0] <=> $b[0]);

        $parts = [];
        $cursor = 0;

        foreach ($marks as [$offset, $length, $fact, $typed]) {
            if ($offset > $cursor) {
                $parts[] = ['text' => mb_substr($this->text, $cursor, $offset - $cursor), 'fact' => null, 'label' => null, 'typed' => null];
            }

            $parts[] = [
                'text' => mb_substr($this->text, $offset, $length),
                'fact' => $fact,
                'label' => $fact === null ? null : ($labels[$fact] ?? null),
                'typed' => $typed,
            ];
            $cursor = $offset + $length;
        }

        if ($cursor < mb_strlen($this->text)) {
            $parts[] = ['text' => mb_substr($this->text, $cursor), 'fact' => null, 'label' => null, 'typed' => null];
        }

        return $parts;
    }
}
