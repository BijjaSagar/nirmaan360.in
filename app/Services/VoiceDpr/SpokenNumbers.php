<?php

namespace App\Services\VoiceDpr;

use App\Services\DocumentReading\PrintedDigits;
use App\Services\DocumentReading\PrintedNumber;
use App\Support\Dec;
use Brick\Math\BigDecimal;
use Normalizer;

/**
 * Turns a spoken or written number phrase into a decimal, or refuses.
 *
 * Understood: digits of any script ("12.5", "१२.५", "1,200"), number words to the thousands in the
 * language the phone listened in and in English ("सहा", "दोनशे पन्नास", "two hundred and fifty"),
 * and the fraction words people use for quantities ("साडे बारा", "दीडशे", "ढाई सौ", "two and a half",
 * "बारा पूर्णांक पाच"). The words are lists in config('modules.voice_dpr'), not rules, so a native
 * reader can check every one.
 *
 * It never guesses. The whole phrase must be one number: "सहा गवंडी", "दो तीन" or "6 12" are
 * refused, and so is anything a list does not know. Nothing here uses a float.
 */
final class SpokenNumbers
{
    /** @var array<string, array<string, mixed>> The kind of each known word, by the word in lower case. */
    private array $words = [];

    /** @var list<string> Prefix words, longest first, for words written together such as "साडेबारा". */
    private array $prefixes = [];

    /** @var list<string> Hundreds that may be written on to the number before them, such as the "शे" of "दोनशे". */
    private array $attached = [];

    public function __construct(public readonly string $locale)
    {
        $languages = array_values(array_unique([self::language($locale), 'en']));
        $config = config('modules.voice_dpr');

        foreach ($languages as $language) {
            foreach ((array) ($config['number_words'][$language] ?? []) as $word => $value) {
                $value = Dec::of((string) $value);
                $this->add((string) $word, [
                    't' => 'num',
                    'v' => $value,
                    'single' => self::isWhole($value) && $value->isLessThanOrEqualTo(9),
                    'tens' => $language === 'en' && self::isWhole($value) && $value->isGreaterThanOrEqualTo(20) && $value->remainder(10)->isZero(),
                    'unit' => $language === 'en' && self::isWhole($value) && $value->isGreaterThanOrEqualTo(1) && $value->isLessThanOrEqualTo(9),
                ]);
            }

            foreach ((array) ($config['scale_words'][$language] ?? []) as $word => $value) {
                $this->add((string) $word, ['t' => 'scale', 'v' => Dec::of((string) $value)]);

                if ($language === 'mr' && Dec::of((string) $value)->isEqualTo(100)) {
                    $this->attached[] = self::lower((string) $word);
                }
            }

            foreach ((array) ($config['fraction_words'][$language] ?? []) as $word => $value) {
                $this->add((string) $word, ['t' => 'frac', 'v' => Dec::of((string) $value)]);
            }

            foreach ((array) ($config['fraction_prefixes'][$language] ?? []) as $word => $figures) {
                $this->add((string) $word, [
                    't' => 'prefix',
                    'add' => Dec::of((string) $figures['add']),
                    'single' => isset($figures['single']) ? Dec::of((string) $figures['single']) : null,
                ]);
                $this->prefixes[] = self::lower((string) $word);
            }

            foreach ((array) ($config['decimal_words'][$language] ?? []) as $word) {
                $this->add((string) $word, ['t' => 'point']);
            }
        }

        // English joins its numbers with these two: "two hundred and fifty", "a hundred", "one and a half".
        $this->add('and', ['t' => 'and']);
        $this->add('a', ['t' => 'a']);

        usort($this->prefixes, fn (string $a, string $b) => mb_strlen($b) <=> mb_strlen($a));
    }

    /**
     * The number a phrase says, as a plain decimal such as "12.5" or "250", or null when the phrase is
     * not exactly one number.
     */
    public static function parse(string $phrase, string $locale): ?string
    {
        return (new self($locale))->read($phrase);
    }

    public function read(string $phrase): ?string
    {
        $plain = PrintedDigits::plain($phrase);

        if ($plain === null || $plain['made_digits'] || $plain['script'] === PrintedDigits::MIXED) {
            return null;
        }

        $text = self::lower($plain['text']);
        // A hyphen joins words ("twenty-five"); it never makes a number negative here.
        $text = (string) preg_replace('/[\-\x{2010}-\x{2014}\x{2212}]+/u', ' ', $text);
        $text = trim((string) preg_replace('/[\s\p{Z}]+/u', ' ', $text));
        $text = (string) preg_replace('/^[^\p{L}\p{M}\p{N}]+|[^\p{L}\p{M}\p{N}]+$/u', '', $text);

        if ($text === '') {
            return null;
        }

        if (preg_match('/^[\d.,]+$/', $text) === 1) {
            return $this->digits($text);
        }

        $atoms = [];

        foreach (explode(' ', $text) as $token) {
            $expanded = $this->expand($token);

            if ($expanded === null) {
                return null;
            }

            array_push($atoms, ...$expanded);
        }

        $value = $this->number($atoms);

        return $value === null ? null : (string) $value->strippedOfTrailingZeros();
    }

    /** Whether a single word could be part of a number, so a scan can skip every other word at once. */
    public function couldBePart(string $word): bool
    {
        return $this->expand(self::lower((string) Normalizer::normalize($word, Normalizer::FORM_KC))) !== null
            || preg_match('/^\p{N}+([.,]\p{N}+)*$/u', $word) === 1;
    }

    /** 'mr' for "mr-IN", and so on. Anything unknown is read as English. */
    public static function language(string $locale): string
    {
        $language = strtolower(substr($locale, 0, 2));

        return in_array($language, ['mr', 'hi', 'en'], true) ? $language : 'en';
    }

    /** NFKC, then lower case one character at a time, so offsets into the text stay where they were. */
    public static function lower(string $text): string
    {
        $text = Normalizer::normalize($text, Normalizer::FORM_KC);

        if (! is_string($text)) {
            return '';
        }

        return implode('', array_map(function (string $char): string {
            $lower = mb_strtolower($char);

            return mb_strlen($lower) === 1 ? $lower : $char;
        }, mb_str_split($text)));
    }

    /** @param  array<string, mixed>  $atom */
    private function add(string $word, array $atom): void
    {
        $this->words[self::lower($word)] = $atom;
    }

    private function digits(string $text): ?string
    {
        $read = PrintedNumber::parse($text, 'qty');

        return $read->ok ? (string) Dec::of((string) $read->value)->strippedOfTrailingZeros() : null;
    }

    /**
     * The parts one word stands for. A Marathi word may join a fraction to a number and a hundred to
     * either: "साडेबारा" is साडे + बारा, "दीडशे" दीड + शे, "पावणेदोनशे" पावणे + दोन + शे.
     *
     * @return list<array<string, mixed>>|null
     */
    private function expand(string $token, int $depth = 0): ?array
    {
        if (isset($this->words[$token])) {
            return [$this->words[$token]];
        }

        if (preg_match('/^\d+(?:[.,]\d+)*$/', $token) === 1) {
            $value = $this->digits($token);

            return $value === null ? null : [[
                't' => 'num',
                'v' => Dec::of($value),
                'single' => strlen($token) === 1,
                'tens' => false,
                'unit' => false,
            ]];
        }

        if ($depth > 2) {
            return null;
        }

        foreach ($this->prefixes as $prefix) {
            if (str_starts_with($token, $prefix) && $token !== $prefix) {
                $rest = $this->expand(substr($token, strlen($prefix)), $depth + 1);

                if ($rest !== null && in_array($rest[0]['t'], ['num', 'scale'], true)) {
                    return [$this->words[$prefix], ...$rest];
                }
            }
        }

        foreach ($this->attached as $hundred) {
            if (str_ends_with($token, $hundred) && $token !== $hundred) {
                $head = $this->expand(substr($token, 0, -strlen($hundred)), $depth + 1);

                if ($head !== null && in_array(end($head)['t'], ['num', 'frac', 'prefix'], true)) {
                    return [...$head, $this->words[$hundred]];
                }
            }
        }

        return null;
    }

    /**
     * Groups such as "दोन हजार", "पाचशे" and "पन्नास", each with its hundred or thousand, largest first.
     * Each group must be smaller than the one before it, so "पचास दो सौ" and "दो हज़ार बारह सौ" are refused.
     *
     * @param  list<array<string, mixed>>  $atoms
     */
    private function number(array $atoms): ?BigDecimal
    {
        $total = BigDecimal::zero();
        $lastScale = null;
        $count = count($atoms);
        $i = 0;

        while ($i < $count) {
            if ($atoms[$i]['t'] === 'and') {
                // Only between a hundred or a thousand and what follows: "two hundred and fifty".
                if ($lastScale === null || $lastScale->isEqualTo(1) || $i + 1 >= $count) {
                    return null;
                }

                $i++;
            }

            $group = $this->group($atoms, $i);

            if ($group === false) {
                return null;
            }

            [$value, $i] = $group;
            $scale = BigDecimal::one();

            if ($i < $count && $atoms[$i]['t'] === 'scale') {
                $scale = $atoms[$i]['v'];
                $i++;
            }

            if ($value === null) {
                if ($scale->isEqualTo(1)) {
                    return null;
                }

                $value = BigDecimal::one();
            }

            if ($scale->isGreaterThan(1) && $value->isGreaterThanOrEqualTo(100)) {
                return null;
            }

            $part = $value->multipliedBy($scale);

            if ($lastScale !== null && ($scale->isGreaterThanOrEqualTo($lastScale) || $part->isGreaterThanOrEqualTo($lastScale))) {
                return null;
            }

            $total = $total->plus($part);
            $lastScale = $scale;
        }

        return $lastScale === null ? null : $total;
    }

    /**
     * One group's number before its hundred or thousand. Null as the value means none was said, as in
     * "सौ" or "a hundred", which count as one.
     *
     * @param  list<array<string, mixed>>  $atoms
     * @return array{0: ?BigDecimal, 1: int}|false
     */
    private function group(array $atoms, int $i): array|false
    {
        $atom = $atoms[$i] ?? null;
        $next = $atoms[$i + 1] ?? null;

        if ($atom === null) {
            return false;
        }

        switch ($atom['t']) {
            case 'scale':
                return [null, $i];

            case 'a':
                if (($next['t'] ?? null) === 'scale') {
                    return [null, $i + 1];
                }

                return ($next['t'] ?? null) === 'frac' ? [$next['v'], $i + 2] : false;

            case 'prefix':
                if (($next['t'] ?? null) === 'num' && self::isWhole($next['v']) && $next['v']->isGreaterThanOrEqualTo(1)) {
                    return [$next['v']->plus($atom['add']), $i + 2];
                }

                return $atom['single'] !== null && ($next === null || $next['t'] === 'scale') ? [$atom['single'], $i + 1] : false;

            case 'frac':
                return [$atom['v'], $i + 1];

            case 'point':
                return $this->decimals(BigDecimal::zero(), $atoms, $i);

            case 'num':
                $value = $atom['v'];
                $i++;

                if ($atom['tens'] && ($atoms[$i]['unit'] ?? false)) {
                    $value = $value->plus($atoms[$i]['v']);
                    $i++;
                }

                if (($atoms[$i]['t'] ?? null) === 'point') {
                    return self::isWhole($value) ? $this->decimals($value, $atoms, $i) : false;
                }

                // "two and a half", "one and a quarter"
                if (($atoms[$i]['t'] ?? null) === 'and' && self::isWhole($value)) {
                    $j = ($atoms[$i + 1]['t'] ?? null) === 'a' ? $i + 2 : $i + 1;

                    if (($atoms[$j]['t'] ?? null) === 'frac' && $atoms[$j]['v']->isLessThan(1)) {
                        return [$value->plus($atoms[$j]['v']), $j + 1];
                    }
                }

                return [$value, $i];
        }

        return false;
    }

    /**
     * The digits after "point", each said on its own: "twelve point five", "point two five".
     *
     * @param  list<array<string, mixed>>  $atoms
     * @return array{0: BigDecimal, 1: int}|false
     */
    private function decimals(BigDecimal $whole, array $atoms, int $i): array|false
    {
        $i++;
        $digits = '';

        while (($atoms[$i]['t'] ?? null) === 'num' && $atoms[$i]['single']) {
            $digits .= (string) $atoms[$i]['v'];
            $i++;
        }

        return $digits === '' ? false : [$whole->plus(Dec::of('0.'.$digits)), $i];
    }

    private static function isWhole(BigDecimal $value): bool
    {
        return $value->strippedOfTrailingZeros()->getScale() === 0;
    }
}
