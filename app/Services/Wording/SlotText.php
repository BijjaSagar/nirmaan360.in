<?php

namespace App\Services\Wording;

use InvalidArgumentException;

/** The text of one free slot and who wrote it. This is what a document keeps in its slots column. */
final readonly class SlotText
{
    /** Written by a model and passed by Guard A. */
    public const AI = 'ai';

    /** The slot's own code text. */
    public const TEMPLATE = 'template';

    /** Typed or changed by a person. Numbers in it are listed for a tick (Guard E). */
    public const EDITED = 'edited';

    public const SOURCES = [self::AI, self::TEMPLATE, self::EDITED];

    public function __construct(public string $text, public string $source)
    {
        if (! in_array($source, self::SOURCES, true)) {
            throw new InvalidArgumentException("'{$source}' is not a source of slot text.");
        }
    }

    public static function ai(string $text): self
    {
        return new self($text, self::AI);
    }

    public static function edited(string $text): self
    {
        return new self($text, self::EDITED);
    }

    /** @return array{text: string, source: string} */
    public function toArray(): array
    {
        return ['text' => $this->text, 'source' => $this->source];
    }

    /**
     * Reads what a slots column holds. Text whose source is missing or unknown is treated as a model's,
     * the source that is trusted least: with a figure in it, it is not used at all.
     *
     * @param  array{text?: mixed, source?: mixed}  $stored
     */
    public static function fromArray(array $stored): self
    {
        $source = $stored['source'] ?? null;

        return new self(
            is_string($stored['text'] ?? null) ? $stored['text'] : '',
            is_string($source) && in_array($source, self::SOURCES, true) ? $source : self::AI,
        );
    }
}
