<?php

namespace App\Services\BoqScan;

use App\Support\Dec;

/**
 * The checks the code makes on a scanned BOQ, by code name, and what each says to a person.
 *
 * A "block" stops the import until a person has dealt with it: typed a value, chosen the kind of a
 * row, given a reason, or (for the few that say so) answered it. A "tick" needs a person's own tick,
 * with who and when kept. A flag is a code and its figures; the words are made here, in the
 * person's language, when the screen asks.
 */
final class ScanFlag
{
    public const BLOCK = 'block';

    public const TICK = 'tick';

    // Stop the import until dealt with.
    public const NUMBER_UNREADABLE = 'number_unreadable';

    public const MIXED_SCRIPT = 'mixed_script';

    public const AMOUNT_MISMATCH = 'amount_mismatch';

    public const UNCLEAR_VALUE = 'unclear_value';

    public const MISSING_REQUIRED = 'missing_required';

    public const CODE_PROBLEM = 'code_problem';

    public const TOO_LONG = 'too_long';

    public const QTY_BELOW_APPROVED = 'qty_below_approved';

    public const UNIT_LOCKED = 'unit_locked';

    public const FIGURES_ON_NON_ITEM_ROW = 'figures_on_non_item_row';

    public const POSSIBLE_DUPLICATE_ROW = 'possible_duplicate_row';

    public const SECOND_READING_EXTRA_ROW = 'second_reading_extra_row';

    public const COLUMNS_SUSPECT = 'columns_suspect';

    public const TOTAL_MISMATCH = 'total_mismatch';

    public const PAGE_UNRESOLVED = 'page_unresolved';

    public const UNCONFIRMED_ROW = 'unconfirmed_row';

    // Need a person's tick.
    public const AMOUNT_UNVERIFIED = 'amount_unverified';

    public const SUBTOTAL_MISMATCH = 'subtotal_mismatch';

    public const ADDON_MISMATCH = 'addon_mismatch';

    public const NO_GRAND_TOTAL = 'no_grand_total';

    public const UPDATES_EXISTING = 'updates_existing';

    public const SCRIPT_DIFFERS_ON_PAGE = 'script_differs_on_page';

    public const UNIT_UNKNOWN = 'unit_unknown';

    public const INSTRUCTION_LIKE_TEXT = 'instruction_like_text';

    public const NO_TABLE_ON_PAGE = 'no_table_on_page';

    /** Answered on a row through the acknowledgements route. Everything else on a row is cleared by typing or choosing a kind. */
    public const ROW_ACKS = [
        self::AMOUNT_UNVERIFIED, self::SUBTOTAL_MISMATCH, self::ADDON_MISMATCH, self::UPDATES_EXISTING,
        self::UNIT_UNKNOWN, self::INSTRUCTION_LIKE_TEXT, self::POSSIBLE_DUPLICATE_ROW,
    ];

    /** Answered for a page. */
    public const PAGE_ACKS = [self::SCRIPT_DIFFERS_ON_PAGE, self::NO_TABLE_ON_PAGE, self::COLUMNS_SUSPECT, self::SECOND_READING_EXTRA_ROW, self::INSTRUCTION_LIKE_TEXT];

    /** Answered for the whole read. A total that does not add up needs a reason. */
    public const READ_ACKS = [self::NO_GRAND_TOTAL, self::TOTAL_MISMATCH];

    /** Answers that need a reason of their own. */
    public const NEEDS_REASON = [self::TOTAL_MISMATCH];

    /**
     * @param  array<string, mixed>  $params
     * @return array{code: string, level: string, params: array<string, mixed>}
     */
    public static function make(string $code, string $level, array $params = []): array
    {
        return ['code' => $code, 'level' => $level, 'params' => $params];
    }

    /** A fingerprint of a flag's figures. A tick counts only while the figures it was given for still stand. */
    public static function hash(array $flag): string
    {
        return sha1($flag['code'].'|'.json_encode($flag['params'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /** What the flag says to a person, in their language. */
    public static function message(array $flag): string
    {
        $p = $flag['params'] ?? [];
        $money = fn ($value) => $value === null ? '' : Dec::indian($value, 2);

        return match ($flag['code']) {
            self::NUMBER_UNREADABLE => __(':field: the printed text cannot be read as a number. Type it.', ['field' => self::field($p['field'] ?? '')]),
            self::MIXED_SCRIPT => __(':field: the printed figure mixes two kinds of digits. Type it.', ['field' => self::field($p['field'] ?? '')]),
            self::UNCLEAR_VALUE => __(':field: the reader was not sure what is printed. Check it against the page and type it.', ['field' => self::field($p['field'] ?? '')]),
            self::AMOUNT_MISMATCH => __('Quantity times rate is :product, but the amount reads :amount, a difference of :difference. Type the quantity and the rate as printed.', [
                'product' => $money($p['product'] ?? null), 'amount' => $money($p['amount'] ?? null), 'difference' => $money($p['difference'] ?? null),
            ]),
            self::MISSING_REQUIRED => __('Missing: :fields. Type them, or change the kind of this row.', ['fields' => implode(', ', array_map(self::field(...), (array) ($p['fields'] ?? [])))]),
            self::CODE_PROBLEM => match ($p['reason'] ?? null) {
                'too_long' => __('The item code is longer than 32 characters. Type a shorter one.'),
                'repeated' => __('Item code :code is used on another line of this scan. Give each line its own code.', ['code' => (string) ($p['code'] ?? '')]),
                default => __('This line has no item code. Type one.'),
            },
            self::TOO_LONG => __(':field is longer than :max characters.', ['field' => self::field($p['field'] ?? ''), 'max' => (int) ($p['max'] ?? 0)]),
            self::QTY_BELOW_APPROVED => __('Quantity cannot be lower than the approved quantity (:qty) for item :code.', ['qty' => fmt_qty($p['approved'] ?? 0), 'code' => (string) ($p['code'] ?? '')]),
            self::UNIT_LOCKED => __('Item :code already has measurements or bills in :unit. The unit cannot change.', ['code' => (string) ($p['code'] ?? ''), 'unit' => (string) ($p['unit'] ?? '')]),
            self::FIGURES_ON_NON_ITEM_ROW => __('This row prints figures, but it is not marked as a line. Choose what kind of row it is.'),
            self::POSSIBLE_DUPLICATE_ROW => __('This row may have been read twice, once in each half of the page. Leave one out, or confirm that both are real lines.'),
            self::SECOND_READING_EXTRA_ROW => __('The second reading found the amount :amount on a row the first reading did not return. Add the row by hand, or mark it as not a line.', ['amount' => (string) ($p['amount'] ?? '')]),
            self::COLUMNS_SUSPECT => __('Quantity and rate may have been read the wrong way round on this page. Check which column is which.'),
            self::TOTAL_MISMATCH => __('This row prints :printed, but the lines above it add up to :sum, a difference of :difference. Fix the lines, change the kind of this row, or give a reason.', [
                'printed' => $money($p['printed'] ?? null), 'sum' => $money($p['sum'] ?? null), 'difference' => $money($p['difference'] ?? null),
            ]),
            self::PAGE_UNRESOLVED => __('This page could not be read. Read it again, read it as two halves, add its lines by hand, or mark that it has no BOQ lines.'),
            self::UNCONFIRMED_ROW => __('Not confirmed yet.'),
            self::AMOUNT_UNVERIFIED => __('No amount was read for this line, so its quantity and rate could not be checked against one.'),
            self::SUBTOTAL_MISMATCH => __('The lines since the last subtotal or heading add up to :sum, but this row prints :printed.', ['sum' => $money($p['sum'] ?? null), 'printed' => $money($p['printed'] ?? null)]),
            self::ADDON_MISMATCH => ($p['need'] ?? null) === 'amount'
                ? __('Type the amount of this add-on as it is printed.')
                : __(':percent percent of the lines above is :expected, but this row prints :printed.', ['percent' => (string) ($p['percent'] ?? ''), 'expected' => $money($p['expected'] ?? null), 'printed' => $money($p['printed'] ?? null)]),
            self::NO_GRAND_TOTAL => __('This document prints no total that the lines could be checked against.'),
            self::UPDATES_EXISTING => __('Item :code is already in the BOQ. Importing this line changes it.', ['code' => (string) ($p['code'] ?? '')]),
            self::SCRIPT_DIFFERS_ON_PAGE => __('The figures on this page are printed in two kinds of digits. Look-alike digits are easy to misread: check them.'),
            self::UNIT_UNKNOWN => __('The unit ":unit" is not one the app knows. Check it, or type the unit.', ['unit' => (string) ($p['unit'] ?? '')]),
            self::INSTRUCTION_LIKE_TEXT => __('Some text here reads like an instruction to the reader. It was not followed. Check this against the page.'),
            self::NO_TABLE_ON_PAGE => __('The reader found no BOQ table on this page.'),
            default => (string) $flag['code'],
        };
    }

    /** A field's name as the screen shows it. */
    public static function field(string $field): string
    {
        return match ($field) {
            'section' => __('Section'),
            'code' => __('Item code'),
            'description' => __('Description'),
            'unit' => __('Unit'),
            'qty' => __('Quantity'),
            'rate' => __('Rate'),
            'amount' => __('Amount'),
            'percent' => __('Percentage'),
            default => $field,
        };
    }
}
