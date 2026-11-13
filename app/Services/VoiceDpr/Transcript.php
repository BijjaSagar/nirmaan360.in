<?php

namespace App\Services\VoiceDpr;

use App\Services\DocumentReading\ReaderRequest;
use Normalizer;

/**
 * The words the phone heard, as the checks see them: in compatibility form (NFKC), with "<" and ">"
 * replaced as they were in the text sent to the model, and every run of spaces as one space. A
 * phrase the model says it copied is looked for in the same form, as whole words.
 *
 * Positions are characters, and "spans" name the words a phrase covers, so two fields can be kept
 * from leaning on the same spoken words.
 */
final class Transcript
{
    /** Marks that end a clause. A number and the word it belongs to are never on two sides of one. */
    private const BREAKS = [',', ';', '।', '॥', '.', '?', '!'];

    /** @var list<string> */
    private array $chars;

    private string $lower;

    /** @var list<array{start:int, end:int}> Words and runs of digits, in order. */
    private array $tokens = [];

    /** @var array<int, int> Character position => the token it is part of. */
    private array $tokenAt = [];

    /** @var array<string, list<array{start:int, end:int, first:int, last:int, value:string, plain:bool}>> */
    private array $mentions = [];

    public function __construct(string $raw)
    {
        $text = self::normalise($raw);
        $this->chars = mb_str_split($text);
        $this->lower = SpokenNumbers::lower($text);
        $this->tokenize();
    }

    /** NFKC, "<" and ">" made harmless as in the request, and spaces collapsed. */
    public static function normalise(string $raw): string
    {
        $text = mb_check_encoding($raw, 'UTF-8') ? Normalizer::normalize($raw, Normalizer::FORM_KC) : false;

        if (! is_string($text)) {
            return '';
        }

        return trim((string) preg_replace('/[\s\p{Z}]+/u', ' ', ReaderRequest::safe($text)));
    }

    /** A phrase as it is looked for: normalised, without marks before its first or after its last letter or digit. */
    public static function phrase(string $raw): string
    {
        return (string) preg_replace('/^[^\p{L}\p{M}\p{N}]+|[^\p{L}\p{M}\p{N}]+$/u', '', self::normalise($raw));
    }

    public function text(): string
    {
        return implode('', $this->chars);
    }

    /** @return list<array{start:int, end:int}> */
    public function tokens(): array
    {
        return $this->tokens;
    }

    /** The token's text in lower case. */
    public function word(int $index): string
    {
        $token = $this->tokens[$index];

        return mb_substr($this->lower, $token['start'], $token['end'] - $token['start']);
    }

    /**
     * Every place the phrase stands as whole words, in order.
     *
     * @return list<array{start:int, end:int, first:int, last:int}>
     */
    public function occurrences(string $phrase): array
    {
        $needle = SpokenNumbers::lower(self::phrase($phrase));

        if ($needle === '') {
            return [];
        }

        $found = [];
        $length = mb_strlen($needle);
        $from = 0;

        while (($at = mb_strpos($this->lower, $needle, $from)) !== false) {
            $end = $at + $length;
            $from = $at + 1;

            if (! $this->boundaryBefore($at) || ! $this->boundaryAfter($end)) {
                continue;
            }

            $first = $this->tokenAt[$at] ?? null;
            $last = $this->tokenAt[$end - 1] ?? null;

            if ($first !== null && $last !== null) {
                $found[] = ['start' => $at, 'end' => $end, 'first' => $first, 'last' => $last];
            }
        }

        return $found;
    }

    /**
     * Every number said, as whole words, read with the longest phrase that is one number: "दोन हजार
     * पाचशे" is one, "सहा बारा" two. "plain" is false for a size, a grade or a time of day.
     *
     * @return list<array{start:int, end:int, first:int, last:int, value:string, plain:bool}>
     */
    public function mentions(SpokenNumbers $numbers): array
    {
        if (isset($this->mentions[$numbers->locale])) {
            return $this->mentions[$numbers->locale];
        }

        $found = [];
        $count = count($this->tokens);
        $i = 0;

        while ($i < $count) {
            if (! $this->boundaryBefore($this->tokens[$i]['start']) || ! $numbers->couldBePart($this->word($i))) {
                $i++;

                continue;
            }

            // The longest run of words that could each be part of a number, at most eight.
            $reach = $i;

            while ($reach + 1 < $count && $reach - $i < 7 && $numbers->couldBePart($this->word($reach + 1))) {
                $reach++;
            }

            $taken = null;

            for ($j = $reach; $j >= $i; $j--) {
                $start = $this->tokens[$i]['start'];
                $end = $this->tokens[$j]['end'];

                if (! $this->boundaryAfter($end)) {
                    continue;
                }

                $value = $numbers->read(implode('', array_slice($this->chars, $start, $end - $start)));

                if ($value !== null) {
                    $span = ['start' => $start, 'end' => $end, 'first' => $i, 'last' => $j];
                    $taken = [...$span, 'value' => $value, 'plain' => ! $this->notAQuantity($span)];
                    break;
                }
            }

            if ($taken === null) {
                $i++;

                continue;
            }

            $found[] = $taken;
            $i = $taken['last'] + 1;
        }

        return $this->mentions[$numbers->locale] = $found;
    }

    /**
     * Whether the number at this span is a size, a grade or a time of day: followed by "mm" or a clock
     * word, after "M", "Fe" or "grade", or written as "9:30".
     *
     * @param  array{start:int, end:int}  $span
     */
    public function notAQuantity(array $span): bool
    {
        $config = config('modules.voice_dpr');

        return $this->followedBy($span, [...(array) $config['size_words'], ...(array) $config['clock_words']])
            || $this->precededBy($span, (array) $config['grade_words'])
            || (($this->chars[$span['end']] ?? null) === ':' && preg_match('/^\p{N}$/u', $this->chars[$span['end'] + 1] ?? '') === 1);
    }

    /**
     * Words between two spans; -1 when they overlap.
     *
     * @param  array{first:int, last:int}  $a
     * @param  array{first:int, last:int}  $b
     */
    public function gap(array $a, array $b): int
    {
        return match (true) {
            $a['last'] < $b['first'] => $b['first'] - $a['last'] - 1,
            $b['last'] < $a['first'] => $a['first'] - $b['last'] - 1,
            default => -1,
        };
    }

    /**
     * Whether a comma, a full stop or the like stands between two spans, outside any number such as "12.5".
     *
     * @param  array{start:int, end:int}  $a
     * @param  array{start:int, end:int}  $b
     */
    public function clauseBreakBetween(array $a, array $b): bool
    {
        [$from, $to] = $a['end'] <= $b['start'] ? [$a['end'], $b['start']] : [$b['end'], $a['start']];

        for ($at = $from; $at < $to; $at++) {
            if (! isset($this->tokenAt[$at]) && in_array($this->chars[$at], self::BREAKS, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the next word after the span is one of these.
     *
     * @param  array{end:int}  $span
     * @param  list<string>  $words
     */
    public function followedBy(array $span, array $words): bool
    {
        $at = $span['end'];

        while (($this->chars[$at] ?? null) === ' ') {
            $at++;
        }

        foreach ($words as $word) {
            $word = SpokenNumbers::lower((string) $word);

            if ($word !== '' && mb_substr($this->lower, $at, mb_strlen($word)) === $word && $this->boundaryAfter($at + mb_strlen($word))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the word just before the span is one of these. A space or a hyphen may stand between: "M 25", "M-25".
     *
     * @param  array{start:int}  $span
     * @param  list<string>  $words
     */
    public function precededBy(array $span, array $words): bool
    {
        $at = $span['start'];

        while ($at > 0 && in_array($this->chars[$at - 1], [' ', '-'], true)) {
            $at--;
        }

        foreach ($words as $word) {
            $word = SpokenNumbers::lower((string) $word);
            $from = $at - mb_strlen($word);

            if ($word !== '' && $from >= 0 && mb_substr($this->lower, $from, mb_strlen($word)) === $word && $this->boundaryBefore($from)) {
                return true;
            }
        }

        return false;
    }

    private function tokenize(): void
    {
        $count = count($this->chars);
        $at = 0;

        while ($at < $count) {
            $start = $at;

            if (self::isLetter($this->chars[$at])) {
                while ($at < $count && (self::isLetter($this->chars[$at])
                    || (in_array($this->chars[$at], ["'", '’'], true) && $at > $start && self::isLetter($this->chars[$at + 1] ?? '')))) {
                    $at++;
                }
            } elseif (self::isDigit($this->chars[$at])) {
                while ($at < $count && (self::isDigit($this->chars[$at])
                    || (in_array($this->chars[$at], ['.', ','], true) && self::isDigit($this->chars[$at + 1] ?? '')))) {
                    $at++;
                }
            } else {
                $at++;

                continue;
            }

            $index = count($this->tokens);
            $this->tokens[] = ['start' => $start, 'end' => $at];

            for ($char = $start; $char < $at; $char++) {
                $this->tokenAt[$char] = $index;
            }
        }
    }

    private function boundaryBefore(int $at): bool
    {
        return $at === 0 || ! self::isWordChar($this->chars[$at - 1]);
    }

    private function boundaryAfter(int $at): bool
    {
        return ! isset($this->chars[$at]) || ! self::isWordChar($this->chars[$at]);
    }

    private static function isLetter(string $char): bool
    {
        return $char !== '' && preg_match('/^[\p{L}\p{M}]$/u', $char) === 1;
    }

    private static function isDigit(string $char): bool
    {
        return $char !== '' && preg_match('/^\p{N}$/u', $char) === 1;
    }

    private static function isWordChar(string $char): bool
    {
        return preg_match('/^[\p{L}\p{M}\p{N}]$/u', $char) === 1;
    }
}
