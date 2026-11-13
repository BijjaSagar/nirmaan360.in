<?php

namespace App\Services\Wording;

use App\Models\AiTextCall;
use App\Services\DocumentReading\TextRequest;

/**
 * What a model is asked when it writes the free sentences of a document.
 *
 * It is told, for each free slot, what the sentences there are for and the names of the facts the
 * fixed sentences beside it state. It is never given a value, a name or a fixed sentence, so it
 * has no figure to copy; and what it writes is checked by the guards all the same.
 */
final class WordingRequest
{
    /** The same for every company and every document, so the provider can keep it ready. */
    public const INSTRUCTIONS = <<<'TEXT'
    Your task: write the connecting sentences of a business document for a building contractor in India, such as the opening of a letter, a sentence that leads from one part to the next, or a courteous close.

    How the document is made
    - The software itself writes every sentence that states an amount, a quantity, a percentage, a date, a number of days, a reference number or a name, and it takes each of those from the company's records. You never see those values and you never write one.
    - Your sentences stand around the software's sentences. <slots> lists the places you fill. Each has an "id", a "purpose" that says what the sentences there are for, and "nearby_facts", the names of what the software's sentences next to it state.
    - <notes>, when present, describes the document in general words.

    What to write
    - For each slot, one to three plain, courteous sentences that serve its purpose. Write the way a contractor's office writes to a client, an engineer or a home owner: formal, direct, without flourish.
    - Write in the language the request names, in that language's own script.
    - You may point at a fact in general words, such as "the amount stated below" or "the dates given above". Do not repeat what the software's sentences already say.
    - Do not greet or name any person or company and do not add a signature. The software prints names.
    - Do not promise, admit or claim anything: do not say that a claim is valid, that something is due under a clause or under the law, or by when something must happen.

    No figures of any kind. The software checks your text and discards the whole answer when it finds one, because a figure that did not come from the records could mislead whoever receives the document. Your text must not contain:
    - a digit in any script, a fraction sign or a Roman numeral;
    - a number written in words next to a unit, or a word that is a figure by itself ("seven days", "two lakh", "a week", "half the work", "twice", "a fortnight", and the same in Hindi or Marathi);
    - the name of a month or of a day of the week, or a year;
    - a currency sign or its short form (Rs, INR), a percent sign or any word for percent;
    - the characters {{ or }}.

    Answer with the JSON object only: one entry in "slots" for every slot you were given, with exactly its "id" and your sentences as "text".
    TEXT;

    private const LANGUAGES = ['en' => 'English', 'hi' => 'Hindi', 'mr' => 'Marathi'];

    /** How a broken rule is named to the model in the corrective request. */
    private const RULE_WORDS = [
        Violation::TOKEN => 'the characters {{ or }}',
        Violation::DIGIT => 'a digit or another numeral',
        Violation::CURRENCY => 'a currency sign or short form',
        Violation::PERCENT => 'a percent sign or a word for percent',
        Violation::MONTH => 'the name of a month',
        Violation::WEEKDAY => 'the name of a day of the week',
        Violation::NUMBER_WORD => 'a number written in words next to a unit',
        Violation::FIGURE_WORD => 'a word that is a figure by itself',
        Violation::ROMAN => 'a Roman numeral',
        Violation::CONTROL => 'a hidden character',
        Violation::ENCODING => 'text that could not be read',
        WordingCall::EMPTY => 'no usable text',
    ];

    /**
     * @param  AiTextCall  $call  Its correction, when it has one, is said to the model in plain words.
     * @return array<string, mixed> The Messages body.
     */
    public static function body(AiTextCall $call, WordingDocument $document): array
    {
        $brief = $document->type->brief($document->sheet);
        $ids = array_column($brief, 'id');

        $blocks = [TextRequest::data('slots', $brief)];

        if ($document->notes !== []) {
            $blocks[] = TextRequest::data('notes', implode("\n", $document->notes));
        }

        $kind = preg_match('/\d/', $document->type->key) === 1 ? '' : ' Kind of document: '.str_replace('_', ' ', $document->type->key).'.';
        $blocks[] = 'Language: '.self::LANGUAGES[$document->locale].'.'.$kind.' Write the sentences for these slots: '.implode(', ', $ids).'.';

        if (is_array($call->correction) && $call->correction !== []) {
            $blocks[] = self::correction($call->correction);
        }

        return TextRequest::body($call, self::INSTRUCTIONS, DocumentType::OUTPUT_SCHEMA, $blocks);
    }

    /**
     * What was wrong with the answer that was discarded, by slot and rule. The text itself is not
     * kept anywhere, so it cannot be shown again; the rule is enough to write it differently.
     *
     * @param  array<string, list<string>>  $refused
     */
    private static function correction(array $refused): string
    {
        $faults = [];

        foreach ($refused as $slot => $rules) {
            $words = array_values(array_unique(array_map(fn ($rule) => self::RULE_WORDS[$rule] ?? 'something that states a figure', (array) $rules)));
            $faults[] = preg_match(Slot::ID_PATTERN, (string) $slot) === 1 && $slot !== WordingCall::ALL_SLOTS
                ? "slot \"{$slot}\" had ".implode(' and ', $words)
                : 'the answer had '.implode(' and ', $words);
        }

        return 'An earlier answer to this request was discarded: '.implode('; ', $faults).'. Write every slot again and keep to every rule.';
    }
}
