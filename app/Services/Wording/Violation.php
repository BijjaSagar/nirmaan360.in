<?php

namespace App\Services\Wording;

/** One reason a sentence from a model was refused. */
final readonly class Violation
{
    /** The model placed "{{" or "}}". Only the code places a token. */
    public const TOKEN = 'token';

    /** A digit or any other numeral, in any script. */
    public const DIGIT = 'digit';

    public const CURRENCY = 'currency';

    public const PERCENT = 'percent';

    public const MONTH = 'month';

    public const WEEKDAY = 'weekday';

    /** A number word within two words of a quantity word: "fifteen days", "पंधरा दिवसांत". */
    public const NUMBER_WORD = 'number_word';

    /** A word that is a figure by itself: "fortnight", "twice". */
    public const FIGURE_WORD = 'figure_word';

    /** II, IV, XII: a clause or a count written in Roman numerals. */
    public const ROMAN = 'roman_numeral';

    /** Hidden or control characters, which could split a word so that the lists miss it. */
    public const CONTROL = 'control_character';

    /** Bytes that are not text. Nothing can be checked in them, so nothing is let through. */
    public const ENCODING = 'encoding';

    /**
     * @param  string  $found  The piece of text that broke the rule.
     * @param  int  $offset  Where it starts, counted in characters.
     */
    public function __construct(public string $rule, public string $found, public int $offset) {}

    /** @return array{rule: string, found: string, offset: int} */
    public function toArray(): array
    {
        return ['rule' => $this->rule, 'found' => $this->found, 'offset' => $this->offset];
    }
}
