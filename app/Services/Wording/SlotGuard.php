<?php

namespace App\Services\Wording;

/**
 * Guard A. Decides whether text a model wrote may stand in a document.
 *
 * A model writes only the sentences that state no figure. So its text is refused when it carries
 * a token, a numeral, a currency mark, a percent sign or word, a month or weekday name, a Roman
 * numeral, a word that is a figure by itself, or a number word beside a quantity word ("fifteen
 * days", "a week"). Number words alone pass: "one", "एक" and "दो" are everyday words.
 *
 * The guard only ever refuses. It never repairs a sentence.
 */
final class SlotGuard
{
    /** How far apart, in words, a number word and a quantity word may stand and still be read together. */
    private const REACH = 2;

    private const PATTERNS = [
        Violation::TOKEN => '/\{\{|\}\}/u',
        // Every numeral: digits of any script, fractions such as ½, superscripts, Roman numeral signs.
        Violation::DIGIT => '/\p{N}+/u',
        Violation::CURRENCY => '/\p{Sc}/u',
        Violation::PERCENT => '/[%\x{066A}\x{FE6A}\x{FF05}\x{2030}\x{2031}]/u',
        // No hidden characters, except line breaks, tabs and the two joiners Devanagari needs.
        Violation::CONTROL => '/(?![\n\r\t\x{200C}\x{200D}])[\p{Cc}\p{Cf}\p{Co}]/u',
    ];

    private const WORD_RULES = [
        Violation::CURRENCY => 'currency_words',
        Violation::PERCENT => 'percent_words',
        Violation::WEEKDAY => 'weekday_names',
        Violation::FIGURE_WORD => 'figure_words',
    ];

    /** Two or more of I, V and X that form a numeral up to thirty-nine. A single "I" is a pronoun. */
    private const ROMAN_PATTERN = '/^X{0,3}(?:IX|IV|V?I{0,3})$/';

    public function __construct(private WordLists $lists) {}

    public function passes(string $text, ?string $locale = null): bool
    {
        return $this->check($text, $locale) === [];
    }

    /**
     * @param  ?string  $locale  The language of the document. Null checks every list in full.
     * @return list<Violation> Empty when the text may be used.
     */
    public function check(string $text, ?string $locale = null): array
    {
        // A pattern cannot search bytes that are not text, and would report "nothing found".
        if (! mb_check_encoding($text, 'UTF-8')) {
            return [new Violation(Violation::ENCODING, '', 0)];
        }

        $violations = [];

        foreach (self::PATTERNS as $rule => $pattern) {
            if (preg_match_all($pattern, $text, $matches, PREG_OFFSET_CAPTURE) === false) {
                return [new Violation(Violation::ENCODING, '', 0)];
            }

            foreach ($matches[0] as [$found, $byte]) {
                $violations[] = new Violation($rule, $found, mb_strlen(substr($text, 0, $byte)));
            }
        }

        $words = WordLists::words($text);
        $numbers = [];
        $quantities = [];

        foreach ($words as $at => $word) {
            foreach (self::WORD_RULES as $rule => $list) {
                if (($length = $this->lists->matchAt($list, $words, $at)) > 0) {
                    $violations[] = new Violation($rule, self::span($text, $words, $at, $at + $length - 1), $word['offset']);
                }
            }

            if ($this->isMonth($text, $words, $at, $locale)) {
                $violations[] = new Violation(Violation::MONTH, $word['text'], $word['offset']);
            }

            if (strlen($word['text']) >= 2 && preg_match(self::ROMAN_PATTERN, $word['text']) === 1) {
                $violations[] = new Violation(Violation::ROMAN, $word['text'], $word['offset']);
            }

            if ($this->lists->matchAt('number_words', $words, $at) > 0) {
                $numbers[] = $at;
            }

            // "a week": the article counts one of the unit that follows it.
            if (isset($words[$at + 1]) && $this->lists->matchAt('unit_articles', $words, $at) > 0 && $this->lists->matchAt('article_units', $words, $at + 1) > 0) {
                $violations[] = new Violation(Violation::NUMBER_WORD, self::span($text, $words, $at, $at + 1), $word['offset']);
            }

            if ($this->lists->matchAt('quantity_words', $words, $at) > 0) {
                $quantities[] = $at;
            }
        }

        foreach ($numbers as $number) {
            foreach ($quantities as $quantity) {
                if ($number !== $quantity && abs($number - $quantity) <= self::REACH) {
                    $violations[] = new Violation(
                        Violation::NUMBER_WORD,
                        self::span($text, $words, min($number, $quantity), max($number, $quantity)),
                        $words[min($number, $quantity)]['offset'],
                    );

                    break;
                }
            }
        }

        return $violations;
    }

    /** @param  list<array{text: string, folded: string, offset: int}>  $words */
    private function isMonth(string $text, array $words, int $at, ?string $locale): bool
    {
        $skipped = $locale === null ? [] : $this->lists->plain('month_names_not_in', $locale);

        if ($this->lists->matchAt('month_names', $words, $at, $skipped) > 0) {
            return true;
        }

        $word = $words[$at]['text'];

        foreach ($this->lists->plain('month_names_capital_only') as $name) {
            if (mb_strtolower($word) !== mb_strtolower($name) || mb_substr($word, 0, 1) !== mb_strtoupper(mb_substr($word, 0, 1))) {
                continue;
            }

            $opensSentence = in_array($name, $this->lists->plain('month_names_not_first_in_sentence'), true)
                && preg_match('/(?:^|[.!?।:,\n])[\s"\'“‘(\[]*$/u', mb_substr($text, 0, $words[$at]['offset'])) === 1;

            return ! $opensSentence;
        }

        return false;
    }

    /** @param  list<array{text: string, folded: string, offset: int}>  $words */
    private static function span(string $text, array $words, int $from, int $to): string
    {
        $start = $words[$from]['offset'];

        return mb_substr($text, $start, $words[$to]['offset'] + mb_strlen($words[$to]['text']) - $start);
    }
}
