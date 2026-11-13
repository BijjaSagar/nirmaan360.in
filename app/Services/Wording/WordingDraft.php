<?php

namespace App\Services\Wording;

/**
 * What came of asking a model for the free sentences of a document, as it is handed to the feature
 * that asked.
 *
 * Either the model's sentences passed every guard (source "ai"), or none of them is used and the
 * document stands in its code text (source "template"), with the reason why. There is nothing in
 * between: an answer with one refused sentence is not used at all, because it is not charged.
 */
final readonly class WordingDraft
{
    public const AI = SlotText::AI;

    public const TEMPLATE = SlotText::TEMPLATE;

    /**
     * @param  array<string, SlotText>  $texts  Slot id => the model's text. Empty when the source is "template".
     * @param  list<string>  $unwritten  Free slots the model gave no usable text for. The renderer writes them from their code text.
     * @param  ?string  $reason  Why the code text is used: the call's fail reason. Null when the source is "ai".
     */
    private function __construct(
        public WordingDocument $document,
        public string $source,
        public array $texts,
        public array $unwritten,
        public ?string $reason,
    ) {}

    /** The model's sentences, each of which passed Guard A. */
    public static function ai(WordingDocument $document, SlotDraft $draft): self
    {
        return new self($document, self::AI, $draft->texts(), $draft->missing, null);
    }

    /** No model text is used. Every free slot is written from its code text. */
    public static function template(WordingDocument $document, string $reason): self
    {
        return new self($document, self::TEMPLATE, [], $document->type->freeIds(), $reason);
    }

    public function fromModel(): bool
    {
        return $this->source === self::AI;
    }

    /**
     * For the document's slots column, and for Renderer::render as its $free argument. A slot that
     * is absent is written from its code text.
     *
     * @return array<string, array{text: string, source: string}>
     */
    public function slots(): array
    {
        return array_map(fn (SlotText $text) => $text->toArray(), $this->texts);
    }

    /** Of the facts the draft was made for. Store it with the draft: Guard D compares it before the document goes out. */
    public function fingerprint(): string
    {
        return $this->document->fingerprint();
    }
}
