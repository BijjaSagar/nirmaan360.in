<?php

namespace App\Services\Wording;

/**
 * Guard E. After a person has edited a free slot, finds every number in it that is not the printed
 * form of a fact on the sheet. A number counts as coming from the records only when a fact's whole
 * printed form stands there: "₹1,50,000.00" or "5 November 2026", never a part of one.
 */
final class TypedNumbers
{
    /** Numerals as a person types a number: 15, 1,50,000.00, 15/11/2026, 10:30, 10-15. */
    public const NUMBER_PATTERN = '/\p{N}+(?:[.,\/:\-]\p{N}+)*/u';

    private const CONTEXT_CHARS = 24;

    /** @return list<TypedNumber> In the order they stand in the text. */
    public static function find(string $slotId, string $text, FactSheet $sheet, string $locale): array
    {
        if (! mb_check_encoding($text, 'UTF-8') || preg_match_all(self::NUMBER_PATTERN, $text, $matches, PREG_OFFSET_CAPTURE) === false) {
            throw new GuardViolation(Violation::ENCODING, $slotId);
        }

        $numbers = [];

        foreach ($matches[0] as [$number, $byte]) {
            $start = mb_strlen(substr($text, 0, $byte));
            $numbers[] = [$number, $start, $start + mb_strlen($number)];
        }

        if ($numbers === []) {
            return [];
        }

        $fromRecords = self::printedFacts($text, $numbers, $sheet, $locale);
        $typed = [];

        foreach ($numbers as [$number, $start, $end]) {
            foreach ($fromRecords as [$from, $to]) {
                if ($start >= $from && $end <= $to) {
                    continue 2;
                }
            }

            $contextStart = max(0, $start - self::CONTEXT_CHARS);
            $typed[] = new TypedNumber(
                $slotId,
                count($typed) + 1,
                $number,
                $start,
                $end - $start,
                trim(mb_substr($text, $contextStart, $end - $contextStart + self::CONTEXT_CHARS)),
            );
        }

        return $typed;
    }

    /**
     * The stretches of the text where a fact's whole printed form stands.
     *
     * @param  list<array{0: string, 1: int, 2: int}>  $numbers
     * @return list<array{0: int, 1: int}>
     */
    private static function printedFacts(string $text, array $numbers, FactSheet $sheet, string $locale): array
    {
        $stretches = [];

        foreach ($sheet as $fact) {
            $printed = $fact->display($locale);

            if ($printed === '' || preg_match('/\p{N}/u', $printed) !== 1) {
                continue;
            }

            $length = mb_strlen($printed);

            for ($from = 0; ($at = mb_strpos($text, $printed, $from)) !== false; $from = $at + 1) {
                // "14" inside "2014" or "14.5" is not the fact 14: a match may not cut a typed number in two.
                foreach ($numbers as [, $start, $end]) {
                    if (($start < $at && $at < $end) || ($start < $at + $length && $at + $length < $end)) {
                        continue 2;
                    }
                }

                $stretches[] = [$at, $at + $length];
            }
        }

        return $stretches;
    }
}
