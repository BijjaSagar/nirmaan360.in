<?php

namespace App\Services\Wording;

/**
 * One document a model is asked to write the free sentences of: its slots, the facts its fixed
 * sentences state, and its language. A feature builds it from its records, the same way every
 * time, both when a person asks for the draft and again when the request is sent.
 */
final readonly class WordingDocument
{
    /** Notes longer than this are cut. A note describes the situation in a line, it is not the document. */
    public const NOTE_CHARS = 300;

    public const MOST_NOTES = 12;

    /** @var list<string> */
    public array $notes;

    /**
     * @param  string  $locale  'en', 'hi' or 'mr'.
     * @param  list<string>  $notes  Optional lines the code prepared that describe the document in general words,
     *                               for example a finding's own sentence with its figures taken out. Never a figure,
     *                               a name or anything a person typed that the reader of the document should not see.
     *                               A line with a numeral in it is left out, so the model has no figure to copy.
     */
    public function __construct(public DocumentType $type, public FactSheet $sheet, public string $locale, array $notes = [])
    {
        FactFormat::locale($locale);

        $kept = [];

        foreach ($notes as $note) {
            $note = is_string($note) ? trim((string) preg_replace('/\s+/u', ' ', $note)) : '';

            if ($note !== '' && mb_check_encoding($note, 'UTF-8') && preg_match('/\p{N}/u', $note) !== 1) {
                $kept[] = mb_substr($note, 0, self::NOTE_CHARS);
            }
        }

        $this->notes = array_slice(array_values(array_unique($kept)), 0, self::MOST_NOTES);
    }

    /** Of the facts the document was built from. A feature stores it with the draft and compares it before the document goes out. */
    public function fingerprint(): string
    {
        return $this->sheet->fingerprint();
    }

    /** Whether another document asks for the same sentences: the same slots to write, in the same language. */
    public function sameSlotsAs(self $other): bool
    {
        return $this->type->key === $other->type->key
            && $this->type->freeIds() === $other->type->freeIds()
            && $this->locale === $other->locale;
    }

    /** Whether another document is the same one: the same slots to write, in the same language, from the same facts. */
    public function sameAs(self $other): bool
    {
        return $this->sameSlotsAs($other) && $this->fingerprint() === $other->fingerprint();
    }
}
