<?php

namespace App\Http\Presenters;

use App\Services\Imports\BoqImportLayouts;
use App\Services\Notify\MoneyMention;
use App\Services\Reports\BoqImportResult;
use App\Support\Dec;
use Carbon\CarbonInterface;

/**
 * The BOQ column check for the field app, in a shape of its own: the website's page drawn as data. Every key is
 * always there. Quantities and money are decimal strings, as everywhere in the API.
 *
 * Without money.view the figures the website's page leaves out are null here: every rate and amount, the totals,
 * the amounts that are not quantity times rate, and what an adjustment comes to; and a note or detail that quotes
 * one is left out (mayRead). What the file itself holds is shown as the website shows it, the values under each
 * column among them: a person cannot say what a column is without seeing it. See docs/API.md, "BOQ file import".
 */
final class BoqImportPresenter
{
    /** Problems listed, as on the website's column check. The rest are counted. */
    public const SHOWN_ERRORS = 200;

    /** Notes listed. The rest are counted. */
    public const SHOWN_NOTES = 200;

    /** Rate-only lines listed, as on the website. */
    public const SHOWN_RATE_ONLY = 100;

    /** Lines given a new code, and lines that change an item's wording, listed, as on the website. */
    public const SHOWN_CHANGES = 200;

    /** @param  array<string, mixed>  $preview  From AppBoqImport::preview(). */
    public static function preview(array $preview, bool $money): array
    {
        $existing = array_flip($preview['existing_codes']);
        $renumbered = array_column($preview['renumbered'], 'from', 'row');
        $amount = fn (mixed $value) => $money && $value !== null ? Dec::money($value) : null;
        $notes = array_values(array_filter($preview['notes'], fn (array $note) => self::mayRead($note['message'], $money)));
        $notesByRow = [];

        foreach ($notes as $note) {
            if ($note['row'] > 0) {
                $notesByRow[$note['row']][] = $note['message'];
            }
        }

        return [
            'token' => $preview['token'],
            'kind' => 'sheet',
            'file' => $preview['file'],
            'format' => $preview['format'],
            'version' => (int) $preview['version'],
            'expires_at' => $preview['expires_at'],
            'money' => $money,
            'can_confirm' => $preview['found'] && $preview['errors'] === [] && $preview['rows'] > 0,
            'found' => (bool) $preview['found'],
            'template' => (bool) $preview['template'],
            'picked' => $preview['picked'],
            'saved_layout' => self::savedLayout($preview['saved']),
            'sheets' => array_map(fn (array $sheet) => [
                'index' => $sheet['index'],
                'name' => (string) $sheet['name'],
                'rows' => $sheet['rows'],
                'priced_rows' => $sheet['priced'],
                'hidden' => (bool) $sheet['hidden'],
            ], $preview['sheets']),
            'sheet' => $preview['sheet'],
            'heading_row' => $preview['heading_row'],
            'span' => $preview['span'],
            'heading_candidates' => array_map(fn (int $row, string $text) => ['row' => $row, 'text' => $text], array_keys($preview['candidates']), array_values($preview['candidates'])),
            'columns' => array_map(fn (array $column) => [
                'column' => $column['column'],
                'letter' => $column['letter'],
                'heading' => (string) $column['heading'],
                'field' => $column['field'],
                'source' => $column['how'],
                'unit' => $column['unit'],
                'samples' => array_values($column['samples']),
            ], $preview['columns']),
            'fields' => [['key' => null, 'label' => __('Leave out')], ...self::labels(BoqImportLayouts::fieldLabels())],
            'kinds' => self::labels(self::kindLabels()),
            'sources' => self::labels(self::sourceLabels()),
            'unit' => $preview['unit'],
            'suggested_unit' => $preview['suggested_unit'],
            'heading_unit' => $preview['heading_unit'],
            'needs_unit' => (bool) $preview['needs_unit'],
            'counts' => [
                'lines' => $preview['line_count'],
                'rows' => $preview['rows'],
                'create' => $preview['plan']['add'],
                'update' => $preview['plan']['update'],
                'rate_only' => count($preview['rate_only']),
                'adjustments' => count($preview['adjustments']),
                'renumbered' => count($preview['renumbered']),
                'wording_changes' => count($preview['updates']),
                'left_out' => $preview['left_out'],
                'other_rows' => $preview['other_count'],
                'notes' => count($notes),
                'errors' => count($preview['errors']),
            ],
            'lines' => array_map(fn (array $line) => [
                'row' => $line['row'],
                'kind' => $line['kind'],
                'section' => $line['section'],
                'code' => (string) $line['code'],
                'renumbered_from' => isset($renumbered[$line['row']]) ? (string) $renumbered[$line['row']] : null,
                'description' => (string) $line['description'],
                'unit' => (string) $line['unit'],
                'qty' => Dec::qty($line['qty']),
                'rate' => $amount($line['rate']),
                'amount' => $amount($line['amount']),
                'per' => $line['per'],
                'file_rate' => $amount($line['file_rate']),
                'category' => $line['category'],
                'details' => array_values(array_filter((array) $line['details'], fn (string $detail) => self::mayRead($detail, $money, $line['file_rate']))),
                'notes' => $notesByRow[$line['row']] ?? [],
                'action' => isset($existing[(string) $line['code']]) ? 'update' : 'create',
            ], $preview['lines']),
            'rate_only' => array_map(fn (array $line) => [
                'row' => $line['row'],
                'code' => (string) $line['code'],
                'description' => (string) $line['description'],
                'unit' => (string) $line['unit'],
                'rate' => $amount($line['rate']),
            ], array_slice($preview['rate_only'], 0, self::SHOWN_RATE_ONLY)),
            'renumbered' => array_slice($preview['renumbered'], 0, self::SHOWN_CHANGES),
            'wording_changes' => array_slice($preview['updates'], 0, self::SHOWN_CHANGES),
            'adjustments' => array_map(fn (array $adjustment) => [
                'row' => $adjustment['row'],
                'label' => (string) $adjustment['label'],
                'percent' => $adjustment['percent'],
                'effect' => $amount($adjustment['effect']),
                'amount' => $amount($adjustment['amount']),
                'shows' => $money ? $adjustment['shows'] : null,
            ], $preview['adjustments']),
            'other_rows' => array_map(fn (array $other) => [
                'row' => $other['row'],
                'kind' => $other['kind'],
                'text' => (string) $other['text'],
                'reason' => $other['reason'],
                'can_put_back' => $other['kind'] === 'excluded',
            ], $preview['others']),
            'excluded_rows' => array_values(array_map('intval', $preview['skip'])),
            'notes' => array_slice($notes, 0, self::SHOWN_NOTES),
            'errors' => array_map(fn (array $error) => ['row' => $error['row'], 'field' => $error['field'], 'message' => $error['message']], array_slice($preview['errors'], 0, self::SHOWN_ERRORS)),
            'totals' => $money ? self::totals($preview) : null,
            'mismatches' => $money ? array_map(fn (array $mismatch) => [
                'row' => $mismatch['row'],
                'description' => (string) $mismatch['description'],
                'qty' => Dec::qty($mismatch['qty']),
                'rate' => Dec::money($mismatch['rate']),
                'computed' => Dec::money($mismatch['computed']),
                'amount' => Dec::money($mismatch['amount']),
            ], $preview['mismatches']) : null,
        ];
    }

    /**
     * What a confirm imported.
     *
     * @param  array{created: int, updated: int, unchanged: int, rows: int, notes: list<array{row: int, message: string}>}  $result
     */
    public static function result(array $result, bool $repeated, bool $money): array
    {
        $notes = array_values(array_filter($result['notes'], fn (array $note) => self::mayRead($note['message'], $money)));

        return [
            'created' => $result['created'],
            'updated' => $result['updated'],
            'unchanged' => $result['unchanged'],
            'rows' => $result['rows'],
            'notes' => array_map(fn (array $note) => ['row' => $note['row'], 'message' => $note['message']], array_slice($notes, 0, self::SHOWN_NOTES)),
            'notes_count' => count($notes),
            'repeated' => $repeated,
        ];
    }

    /** @return list<string> A refused import's problems as lines of text, row first, for the "errors" of a 422. */
    public static function problems(BoqImportResult $result): array
    {
        return array_map(
            fn (array $error) => $error['row'] > 0 ? __('Row :row', ['row' => $error['row']]).': '.$error['message'] : $error['message'],
            array_slice($result->errors, 0, self::SHOWN_ERRORS),
        );
    }

    /** @return array<string, string> What a row can be read as, named as on the website's page. */
    public static function kindLabels(): array
    {
        return [
            'line' => __('Line'), 'rate_only' => __('Rate only'), 'section' => __('Section'), 'subheading' => __('Sub-heading'),
            'spec' => __('Specification'), 'part' => __('Break-up'), 'subtotal' => __('Subtotal'), 'total' => __('Total'),
            'carried' => __('Carried forward'), 'say' => __('Say'), 'words' => __('In words'), 'adjustment' => __('Adjustment'),
            'note' => __('Note'), 'skipped' => __('Left out'), 'after_total' => __('After the total'),
            'column_numbers' => __('Column numbers'), 'heading_repeat' => __('Heading again'),
            'error' => __('Problem'), 'excluded' => __('Left out by you'), 'furniture' => __('Page or signature line'),
        ];
    }

    /** @return array<string, string> How a column's meaning was decided, named as on the website's page. */
    public static function sourceLabels(): array
    {
        return ['heading' => __('From the heading'), 'values' => __('From the values'), 'saved' => __('Saved layout'), 'chosen' => __('Your choice')];
    }

    /**
     * Whether a note or a line's detail may go to this person. The reader's notes are about the file, and a few quote a
     * figure it worked out or read: the lines' total against the sheet's, a rate it divided, a rate per 10 units. Without
     * money.view such a text is left out: one MoneyMention finds a rupee figure or a rate in, or one that quotes the rate
     * the file gave for more than one unit.
     */
    private static function mayRead(string $text, bool $money, ?string $fileRate = null): bool
    {
        return $money || (! MoneyMention::in($text) && ($fileRate === null || $fileRate === '' || ! str_contains($text, $fileRate)));
    }

    /**
     * @param  array<string, string>  $labels
     * @return list<array{key: string, label: string}>
     */
    private static function labels(array $labels): array
    {
        return array_map(fn (string $key, string $label) => ['key' => $key, 'label' => $label], array_keys($labels), array_values($labels));
    }

    private static function savedLayout(?array $saved): ?array
    {
        if ($saved === null) {
            return null;
        }

        return [
            'id' => $saved['id'],
            'file' => $saved['file'],
            'uses' => (int) $saved['uses'],
            'last_used_at' => $saved['at'] instanceof CarbonInterface ? $saved['at']->toIso8601String() : null,
        ];
    }

    private static function totals(array $preview): array
    {
        $optional = fn (mixed $value) => $value === null ? null : Dec::money($value);

        return [
            'lines' => Dec::money($preview['lines_total']),
            'adjustments' => Dec::money($preview['adjustments_total']),
            'with_adjustments' => Dec::money($preview['with_adjustments']),
            'file_subtotal' => $optional($preview['file_subtotal']),
            'subtotal_matches' => $preview['subtotal_matches'],
            'file_total' => $optional($preview['file_total']),
            'total_matches' => $preview['total_matches'],
            'rows' => array_map(fn (array $total) => [
                'row' => $total['row'],
                'label' => (string) $total['label'],
                'kind' => $total['kind'],
                'amount' => $optional($total['amount']),
                'comes_to' => Dec::money($total['adjusted'] ? $total['with_adjustments'] : $total['lines']),
                'matches' => $total['matches'],
            ], $preview['totals']),
        ];
    }
}
