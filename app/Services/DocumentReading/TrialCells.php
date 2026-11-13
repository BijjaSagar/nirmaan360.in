<?php

namespace App\Services\DocumentReading;

/**
 * The value cells of an answer, found wherever they are in it, for the platform admin's dry-run
 * page: what was printed, how the code reads it, and the script of its digits.
 *
 * A cell is named by its field and its row. The field is its place in the answer without
 * positions, so the "qty" cell of every entry of "rows" is the field "rows.qty". The row is the
 * positions on the way to it, joined by dots: "3" for the fourth row, "" for a cell outside any list.
 * Field and row together name one cell of one answer.
 */
final class TrialCells
{
    /** A cell with no digit in it has no script to score. */
    public const NO_DIGITS = 'none';

    public const NUMBER_KINDS = ['qty', 'rate', 'amount', 'percent', 'count'];

    private const MOST = 5000;

    /**
     * @param  array<string, mixed>|null  $answer  The accepted answer of one call.
     * @param  array<string, string>  $kinds  Field => how the code reads it (DescribesTrialResult::cellKinds).
     * @return list<array{field: string, row: string, raw: string, state: string, script: string, read_as: ?string, value: ?string, problem: ?string, had_comma: bool}>
     */
    public static function of(?array $answer, array $kinds = []): array
    {
        $cells = [];

        if ($answer !== null) {
            self::walk($answer, [], [], $kinds, $cells);
        }

        return $cells;
    }

    /** The name a mark is stored under. A field never holds "|", because a row is only digits and dots. */
    public static function key(string $field, string $row): string
    {
        return $field.'|'.$row;
    }

    /** The script of the digits in a printed value: ascii, devanagari, other, mixed, or none when it has no digit. */
    public static function script(string $raw): string
    {
        $digits = PrintedDigits::plain($raw);

        if ($digits === null || preg_match('/\d/', $digits['text']) !== 1) {
            return self::NO_DIGITS;
        }

        return $digits['script'];
    }

    /**
     * @param  list<string>  $names
     * @param  list<int>  $positions
     * @param  array<string, string>  $kinds
     * @param  list<array<string, mixed>>  $cells
     */
    private static function walk(mixed $node, array $names, array $positions, array $kinds, array &$cells): void
    {
        if (! is_array($node) || count($cells) >= self::MOST) {
            return;
        }

        if (self::isCell($node)) {
            $cells[] = self::cell($node, implode('.', $names), implode('.', $positions), $kinds);

            return;
        }

        foreach ($node as $key => $child) {
            is_int($key)
                ? self::walk($child, $names, [...$positions, $key], $kinds, $cells)
                : self::walk($child, [...$names, (string) $key], $positions, $kinds, $cells);
        }
    }

    private static function isCell(array $node): bool
    {
        return count($node) === 2 && is_string($node['raw'] ?? null) && is_string($node['state'] ?? null);
    }

    /**
     * @param  array{raw: string, state: string}  $node
     * @param  array<string, string>  $kinds
     * @return array<string, mixed>
     */
    private static function cell(array $node, string $field, string $row, array $kinds): array
    {
        $raw = Cell::raw($node);
        $readAs = $kinds[$field] ?? null;
        $cell = [
            'field' => $field, 'row' => $row, 'raw' => $raw, 'state' => Cell::state($node), 'script' => self::script($raw),
            'read_as' => $readAs, 'value' => null, 'problem' => null, 'had_comma' => false,
        ];

        if ($raw === '' || $readAs === null) {
            return $cell;
        }

        if (in_array($readAs, self::NUMBER_KINDS, true)) {
            $number = PrintedNumber::parse($raw, $readAs);

            return [...$cell, 'value' => $number->value, 'problem' => $number->reason, 'had_comma' => $number->hadComma];
        }

        if ($readAs === 'date') {
            $date = PrintedDate::parse($raw);

            return [...$cell, 'value' => $date, 'problem' => $date === null ? 'not_a_date' : null];
        }

        if ($readAs === 'unit') {
            $unit = UnitText::normalise($raw);

            return [...$cell, 'value' => $unit['unit'], 'problem' => $unit['known'] ? null : 'unknown_unit'];
        }

        return $cell;
    }
}
