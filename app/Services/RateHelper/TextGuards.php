<?php

namespace App\Services\RateHelper;

/**
 * Reads the figures in a work description that change what the work costs: the concrete grade, the
 * mix ratio, sizes in millimetres, the steel grade, and lead and lift distances. When one of them is
 * in one description and different or missing in the other, the person is told and has to tick that
 * they checked before the rate can be accepted.
 *
 * It reads English wording and both kinds of digits. A description written wholly in Marathi or
 * Hindi words gives no figures here, so it raises no warning either way.
 */
final class TextGuards
{
    /** Kind => English label, passed through __() where shown. */
    public const KINDS = [
        'grade' => 'Concrete grade',
        'mix' => 'Mix ratio',
        'size' => 'Thickness or size in mm',
        'steel' => 'Steel grade',
        'lead' => 'Lead',
        'lift' => 'Lift',
    ];

    /** What "all leads" or "all lifts" is read as. It is a word, not a figure, so it is shown in the person's language. */
    public const ALL = 'all';

    /** Kind => English words for ALL on the screen, passed through __() where shown. */
    public const ALL_WORDS = [
        'lead' => 'all leads',
        'lift' => 'all lifts',
    ];

    private const NUMBER = '\d+(?:\.\d+)?';

    private const DISTANCE = '(km|kms|kilometres?|kilometers?|m|mtrs?|metres?|meters?|rmt)';

    /** Words that may stand between "lead" or "lift" and its distance. */
    private const FILLER = '(?:\s|:|=|-|of|upto|up|to|within|not|exceeding|beyond|above|below|initial|average|an|a)*';

    /**
     * @return array<string, list<string>> Kind => the figures of that kind found in the text, sorted.
     */
    public static function read(?string $text): array
    {
        $text = TextMatch::plainDigits((string) $text);

        $found = [
            'grade' => self::grades($text),
            'mix' => self::mixes($text),
            'size' => self::sizes($text),
            'steel' => self::steels($text),
            'lead' => self::distances($text, 'lead'),
            'lift' => self::distances($text, 'lift'),
        ];

        return array_map(function (array $values) {
            $values = array_values(array_unique($values));
            sort($values, SORT_STRING);

            return $values;
        }, $found);
    }

    /** One value of a warning as the screen shows it: a figure as read (M25, 1:4, 50 m), or ALL in the person's language. */
    public static function shown(string $kind, string $value): string
    {
        return $value === self::ALL && isset(self::ALL_WORDS[$kind]) ? __(self::ALL_WORDS[$kind]) : $value;
    }

    /**
     * What differs between the BOQ line's description and a candidate's.
     *
     * @return list<array{kind:string,label:string,line:list<string>,candidate:list<string>}>
     */
    public static function compare(?string $line, ?string $candidate): array
    {
        $ours = self::read($line);
        $theirs = self::read($candidate);
        $warnings = [];

        foreach (self::KINDS as $kind => $label) {
            if ($ours[$kind] !== $theirs[$kind]) {
                $warnings[] = ['kind' => $kind, 'label' => $label, 'line' => $ours[$kind], 'candidate' => $theirs[$kind]];
            }
        }

        return $warnings;
    }

    /** @return list<string> M25, written M25, M-25, M 25 or m25. */
    private static function grades(string $text): array
    {
        preg_match_all('/(?<![\p{L}\p{N}])(?:M[\s\-]{0,2}|m-?|एम[\s\-]?)(\d{2,3})(?![\p{L}\p{N}])(?!\.\d)(?!\s*(?:mm|cm|m)(?![\p{L}]))/u', $text, $matches);

        return array_map(fn (string $digits) => 'M'.$digits, $matches[1]);
    }

    /** @return list<string> 1:4 or 1:2:4, with spaces dropped. */
    private static function mixes(string $text): array
    {
        preg_match_all('/(?<![\d:.])1\s*:\s*('.self::NUMBER.')(?:\s*:\s*('.self::NUMBER.'))?(?![\d:])/u', $text, $matches, PREG_SET_ORDER);

        return array_map(fn (array $match) => implode(':', array_map(self::plain(...), ['1', $match[1], ...(isset($match[2]) && $match[2] !== '' ? [$match[2]] : [])])), $matches);
    }

    /** @return list<string> Every figure given in millimetres: "150 mm", "10-12 mm", "230 x 115 mm". */
    private static function sizes(string $text): array
    {
        $joined = '(?:\s*(?:to|x|×|\/|,|and|&)\s*|-|–)';
        preg_match_all('/(?<![\p{L}\p{N}.:])((?:'.self::NUMBER.$joined.')*'.self::NUMBER.')\s*(?:mm|m\.m\.?|मिमी|मि\.\s?मी\.?)(?![\p{L}])/iu', $text, $matches);
        $sizes = [];

        foreach ($matches[1] as $run) {
            preg_match_all('/'.self::NUMBER.'/', $run, $numbers);

            foreach ($numbers[0] as $number) {
                $sizes[] = self::plain($number);
            }
        }

        return $sizes;
    }

    /** @return list<string> Fe500 or Fe500D. */
    private static function steels(string $text): array
    {
        preg_match_all('/(?<![\p{L}\p{N}])Fe[\s\-]?(\d{3})\s?(D)?(?![\p{L}\p{N}])/iu', $text, $matches, PREG_SET_ORDER);

        return array_map(fn (array $match) => 'Fe'.$match[1].(isset($match[2]) && $match[2] !== '' ? 'D' : ''), $matches);
    }

    /**
     * A lead or lift distance, or "all". The word alone gives nothing, because "lead" is also a
     * metal and "lift" is also a machine.
     *
     * @return list<string>
     */
    private static function distances(string $text, string $word): array
    {
        $found = [];
        $other = $word === 'lead' ? 'lift' : 'lead';

        if (preg_match('/(?<![\p{L}])all\s+'.$word.'s?(?![\p{L}])/iu', $text)
            || preg_match('/(?<![\p{L}])all\s+'.$other.'s?\s*(?:and|&|,)\s*'.$word.'s?(?![\p{L}])/iu', $text)) {
            $found[] = self::ALL;
        }

        // "lead upto 50 m"
        preg_match_all('/(?<![\p{L}])'.$word.'s?(?![\p{L}])'.self::FILLER.'('.self::NUMBER.')\s*'.self::DISTANCE.'(?![\p{L}])/iu', $text, $after, PREG_SET_ORDER);
        // "50 m lead"
        preg_match_all('/(?<![\p{L}\p{N}.])('.self::NUMBER.')\s*'.self::DISTANCE.'\s+(?:initial\s+|average\s+)?'.$word.'s?(?![\p{L}])/iu', $text, $before, PREG_SET_ORDER);

        foreach ([...$after, ...$before] as $match) {
            $found[] = self::plain($match[1]).' '.(str_starts_with(strtolower($match[2]), 'k') ? 'km' : 'm');
        }

        return $found;
    }

    /** 150.0 and 150 are the same figure. */
    private static function plain(string $number): string
    {
        return str_contains($number, '.') ? rtrim(rtrim($number, '0'), '.') : $number;
    }
}
