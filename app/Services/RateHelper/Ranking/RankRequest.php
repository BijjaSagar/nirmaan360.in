<?php

namespace App\Services\RateHelper\Ranking;

use App\Models\AiTextCall;
use App\Services\DocumentReading\TextRequest;

/**
 * What a model is asked when it ranks the candidates of some BOQ lines.
 *
 * It is given, per line, the line's description and unit, and per candidate its ref, description,
 * unit and the kind of record it came from. Never a rate, an amount, a quantity, a date, or the
 * name of a project, a tender, the company or a person: the rate is read from the record by the
 * app, at display and again at accept, as the person who is looking.
 */
final class RankRequest
{
    /** The purpose of the text call, with its model and limits in config/modules/document_reading.php. */
    public const PURPOSE = 'rate_match';

    /** The same for every company, so the provider can keep it ready. */
    public const INSTRUCTIONS = <<<'TEXT'
    Your task: for each line of a building contractor's bill of quantities (BOQ), judge which of the items the software found in the company's own records describe the same work.

    What you are given
    - <lines> lists BOQ lines. Each has a "ref" such as L1, a "description", a "unit" and "candidates".
    - Each candidate is an item from the company's own records, with a "ref" such as C1, a "description", a "unit" and a "source": rate_library (the company's rate library), own_boq (a BOQ line of another of its projects) or own_tender (a BOQ line of one of its past tenders).
    - You are never given a rate or an amount, and you never write one. The software reads every rate from the records itself.

    What to answer
    - One entry in "matches" for every line, with the line's ref as "line".
    - In its "picks", one entry for every candidate of that line, most alike first, with the candidate's ref as "candidate".
    - "same_work" is "yes" when the candidate describes the same work as the line; "similar" when it is the same kind of work but differs in something that changes the rate, such as the material, the grade or mix of concrete, the thickness, the finish, the height or depth, the lead or lift, or what is included; "no" when it is other work.
    - "note" is one short plain sentence about that candidate and the line only, never about another candidate. It names the main difference, or why it is the same work. Write it in the language the request names, in that language's own script. Name a difference in words, such as "a thicker wall" or "a different concrete grade". Leave it empty when there is nothing useful to say.

    No figures in a note. The software discards a note that carries one: a digit in any script, a fraction sign or a Roman numeral; a number written in words next to a unit; the name of a month or of a day of the week; a currency sign or its short form (Rs, INR); a percent sign or a word for percent; the characters {{ or }}.

    Use only the refs you were given, and for each line only the refs of its own candidates. Answer with the JSON object only.
    TEXT;

    /** The answer: per line, its candidates in order with a verdict and a note. Nothing else. */
    public const SCHEMA = [
        'type' => 'object',
        'properties' => [
            'matches' => [
                'type' => 'array',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'line' => ['type' => 'string'],
                        'picks' => [
                            'type' => 'array',
                            'items' => [
                                'type' => 'object',
                                'properties' => [
                                    'candidate' => ['type' => 'string'],
                                    'same_work' => ['type' => 'string', 'enum' => RankAnswer::VERDICTS],
                                    'note' => ['type' => 'string'],
                                ],
                                'required' => ['candidate', 'same_work', 'note'],
                                'additionalProperties' => false,
                            ],
                        ],
                    ],
                    'required' => ['line', 'picks'],
                    'additionalProperties' => false,
                ],
            ],
        ],
        'required' => ['matches'],
        'additionalProperties' => false,
    ];

    /** The rule code for an answer that named no line and candidate that were sent. */
    public const NOTHING_KNOWN = 'nothing_known';

    private const LANGUAGES = ['en' => 'English', 'hi' => 'Hindi', 'mr' => 'Marathi'];

    /**
     * The Messages body for one part.
     *
     * @param  AiTextCall  $call  Its correction, when it has one, is said to the model in plain words.
     * @param  list<array<string, mixed>>  $lines  The part's material, as RankingParts builds it.
     * @return array<string, mixed>
     */
    public static function body(AiTextCall $call, array $lines, string $locale): array
    {
        $blocks = [
            TextRequest::data('lines', self::sendable($lines)),
            'Language of the notes: '.(self::LANGUAGES[$locale] ?? 'English').'. Rank the candidates of every line in <lines>.',
        ];

        if (is_array($call->correction) && $call->correction !== []) {
            $blocks[] = 'An earlier answer to this request was discarded: it named no line and candidate from <lines>. Answer again for every line, using only the refs given.';
        }

        return TextRequest::body($call, self::INSTRUCTIONS, self::SCHEMA, $blocks);
    }

    /**
     * What of the material leaves the server: refs, descriptions, units and the kind of source. The
     * ids of the app's own rows stay here.
     *
     * @param  list<array<string, mixed>>  $lines
     * @return list<array<string, mixed>>
     */
    private static function sendable(array $lines): array
    {
        return array_map(fn (array $line) => [
            'ref' => (string) $line['ref'],
            'description' => (string) $line['description'],
            'unit' => (string) $line['unit'],
            'candidates' => array_map(fn (array $candidate) => [
                'ref' => (string) $candidate['ref'],
                'source' => (string) $candidate['source'],
                'description' => (string) $candidate['description'],
                'unit' => (string) $candidate['unit'],
            ], array_values((array) $line['candidates'])),
        ], array_values($lines));
    }
}
