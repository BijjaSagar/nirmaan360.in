<?php

namespace App\Services\Wording;

/**
 * The word lists of config/wording.php, ready for matching against the words of a text.
 * An entry is a whole word, a stem ending in "*", or several words in a row.
 */
final class WordLists
{
    /** A word: letters with their marks. The two joiners stay inside a Devanagari word. */
    private const WORD_PATTERN = '/[\p{L}\p{M}\x{200C}\x{200D}]+/u';

    /** Devanagari letters that carry a nukta as one character, and the letter without it. */
    private const WITHOUT_NUKTA = [
        "\u{0958}" => "\u{0915}", "\u{0959}" => "\u{0916}", "\u{095A}" => "\u{0917}", "\u{095B}" => "\u{091C}",
        "\u{095C}" => "\u{0921}", "\u{095D}" => "\u{0922}", "\u{095E}" => "\u{092B}", "\u{095F}" => "\u{092F}",
        "\u{0929}" => "\u{0928}", "\u{0931}" => "\u{0930}", "\u{0934}" => "\u{0933}",
        // The nukta itself and the two joiners are dropped; chandrabindu is read as anusvara.
        "\u{093C}" => '', "\u{200C}" => '', "\u{200D}" => '', "\u{0901}" => "\u{0902}",
    ];

    /** @var array<string, array{exact: array<string, true>, stems: list<string>, phrases: list<list<string>>}> */
    private array $compiled = [];

    /** @param  ?array<string, mixed>  $config  The lists, or null for config('wording'). */
    public function __construct(private ?array $config = null) {}

    /** One spelling for comparison: lower case, no nukta, no joiners. */
    public static function fold(string $word): string
    {
        return mb_strtolower(strtr($word, self::WITHOUT_NUKTA));
    }

    /**
     * The words of a text in order.
     *
     * @return list<array{text: string, folded: string, offset: int}> The offset counts characters, not bytes.
     */
    public static function words(string $text): array
    {
        // Reporting "no words" for text that could not be searched would let it past every list.
        if (preg_match_all(self::WORD_PATTERN, $text, $matches, PREG_OFFSET_CAPTURE) === false) {
            throw new WordingException('The text could not be read as words.');
        }

        $words = [];

        foreach ($matches[0] as [$word, $byte]) {
            $words[] = ['text' => $word, 'folded' => self::fold($word), 'offset' => mb_strlen(substr($text, 0, $byte))];
        }

        return $words;
    }

    /**
     * How many words, starting at $at, match an entry of the list. 0 when none does.
     *
     * @param  list<array{text: string, folded: string, offset: int}>  $words
     * @param  list<string>  $without  Entries left out for this text.
     */
    public function matchAt(string $list, array $words, int $at, array $without = []): int
    {
        $compiled = $this->compiled($list);
        $word = $words[$at]['folded'];
        $skip = array_map(self::fold(...), $without);

        foreach ($compiled['phrases'] as $phrase) {
            foreach ($phrase as $step => $part) {
                if (($words[$at + $step]['folded'] ?? null) !== $part) {
                    continue 2;
                }
            }

            return count($phrase);
        }

        if (in_array($word, $skip, true)) {
            return 0;
        }

        if (isset($compiled['exact'][$word])) {
            return 1;
        }

        foreach ($compiled['stems'] as $stem) {
            if (str_starts_with($word, $stem)) {
                return 1;
            }
        }

        return 0;
    }

    /** @return list<string> A plain list from config, such as the capital-only month names. */
    public function plain(string $key, ?string $locale = null): array
    {
        $value = $this->config()[$key] ?? [];

        if ($locale !== null) {
            $value = is_array($value) ? ($value[$locale] ?? []) : [];
        }

        return is_array($value) ? array_values(array_filter($value, 'is_string')) : [];
    }

    public function maxFreeChars(): int
    {
        return max(1, (int) ($this->config()['free_slot_max_chars'] ?? 1200));
    }

    /** @return array<string, mixed> */
    private function config(): array
    {
        return $this->config ??= (array) config('wording', []);
    }

    /** @return array{exact: array<string, true>, stems: list<string>, phrases: list<list<string>>} */
    private function compiled(string $list): array
    {
        if (isset($this->compiled[$list])) {
            return $this->compiled[$list];
        }

        $compiled = ['exact' => [], 'stems' => [], 'phrases' => []];
        $source = $this->config()[$list] ?? [];
        $source = is_array($source) ? $source : [];
        $entries = [];

        // A list is either flat or split by language. Either way every entry is used for every text.
        array_walk_recursive($source, function ($entry) use (&$entries) {
            if (is_string($entry) && trim($entry, " \t\n\r*") !== '') {
                $entries[] = trim($entry);
            }
        });

        // A guard with an empty list would let every sentence through. Better to stop.
        if ($entries === []) {
            throw new WordingException("The word list wording.{$list} is missing or empty.");
        }

        foreach ($entries as $entry) {
            // Cut into words the same way a text is, so "per-cent" and "per cent" are the same two words.
            $parts = array_column(self::words($entry), 'folded');

            if (count($parts) > 1) {
                $compiled['phrases'][] = $parts;
            } elseif (count($parts) === 1 && str_ends_with($entry, '*')) {
                $compiled['stems'][] = $parts[0];
            } elseif (count($parts) === 1) {
                $compiled['exact'][$parts[0]] = true;
            }
        }

        return $this->compiled[$list] = $compiled;
    }
}
