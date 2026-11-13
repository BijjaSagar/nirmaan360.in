<?php

namespace App\Services\BoqScan;

use App\Models\BoqScanRow;
use App\Models\DocumentRead;
use App\Models\DocumentReadPage;
use App\Services\DocumentReading\Cell;
use App\Services\DocumentReading\Contracts\DescribesTrialResult;
use App\Services\DocumentReading\Contracts\ReadKind;
use App\Services\DocumentReading\PrintedNumber;
use App\Services\DocumentReading\ReaderRequest;
use App\Support\Dec;
use LogicException;

/**
 * The scanned BOQ reader. Every page picture is read twice, by separate requests:
 *
 * - "rows": every row of the table with its item number, description, unit, quantity and rate, and
 *   what kind of row it is. It has no field for the amount and is told not to use the amount column.
 * - "amounts": only the item number and the amount column, run only for a page the first reading
 *   called a BOQ table. It never sees the first answer.
 *
 * The code then pairs the two, multiplies quantity by rate and compares it with the amount. The two
 * figures come from different requests (and models), so agreement makes an error less likely; it
 * does not prove the line, which is why every line is also confirmed by a person.
 */
class BoqReadKind implements DescribesTrialResult, ReadKind
{
    public const KEY = 'boq';

    public const ROWS = 'rows';

    public const AMOUNTS = 'amounts';

    public const PAGE_TABLE = 'boq_table';

    public const PAGE_OTHER = 'other';

    public const PAGE_BLANK = 'blank';

    public const COLUMNS = ['item_no', 'description', 'unit', 'qty', 'rate', 'amount', 'other'];

    /** Longest text kept of a printed cell. Anything longer is cut, and a description that is still too long for the BOQ is stopped later. */
    public const CUT = ['item_no' => 64, 'description' => 4000, 'unit' => 32, 'qty' => 64, 'rate' => 64, 'amount' => 64];

    public const ROWS_INSTRUCTIONS = <<<'TEXT'
    The picture is one page of a bill of quantities (BOQ) or an estimate for building work. Copy its table into the form.
    - page_kind: "boq_table" when the page holds a table of work items with quantities or rates, "blank" when nothing is printed on the page, otherwise "other".
    - column_order: the printed columns of the table from left to right, each named item_no, description, unit, qty, rate, amount or other. An empty list when there is no table.
    - rows: every row of the table from top to bottom, with row_no counting from 1. A description printed on several lines is one row, its lines joined with single spaces.
    - kind: "item" for a work item, "heading" for text with no figures, "subtotal" and "total" for rows that print a sum, "addon" for rows such as "add X %", with the percentage left inside the description as printed, "note" for anything else in the table.
    - y_top and y_bottom: the pixel rows of this picture where the row starts and where it ends.
    - Do not read the amount column. Do not use it to fill or check quantity or rate.
    TEXT;

    public const AMOUNTS_INSTRUCTIONS = <<<'TEXT'
    The picture is one page of a bill of quantities (BOQ) or an estimate for building work.
    - Read only the item number column and the amount column, for every row that prints something in the amount column, totals included. Do not read quantity or rate.
    - rows: from top to bottom, with row_no counting from 1, and y_top and y_bottom: the pixel rows of this picture where the row starts and where it ends.
    TEXT;

    public function __construct(private BoqScanIngest $ingest) {}

    /** @return array<string, mixed> The form of the first reading. It has no amount. */
    public static function rowsSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'page_kind' => ['type' => 'string', 'enum' => [self::PAGE_TABLE, self::PAGE_OTHER, self::PAGE_BLANK]],
                'column_order' => ['type' => 'array', 'items' => ['type' => 'string', 'enum' => self::COLUMNS]],
                'rows' => ['type' => 'array', 'items' => [
                    'type' => 'object',
                    'properties' => [
                        'row_no' => ['type' => 'integer'],
                        'kind' => ['type' => 'string', 'enum' => BoqScanRow::KINDS],
                        'y_top' => ['type' => 'integer'],
                        'y_bottom' => ['type' => 'integer'],
                        'item_no' => Cell::SCHEMA,
                        'description' => Cell::SCHEMA,
                        'unit' => Cell::SCHEMA,
                        'qty' => Cell::SCHEMA,
                        'rate' => Cell::SCHEMA,
                    ],
                    'required' => ['row_no', 'kind', 'y_top', 'y_bottom', 'item_no', 'description', 'unit', 'qty', 'rate'],
                    'additionalProperties' => false,
                ]],
            ],
            'required' => ['page_kind', 'column_order', 'rows'],
            'additionalProperties' => false,
        ];
    }

    /** @return array<string, mixed> The form of the second reading: item number and amount only. */
    public static function amountsSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'rows' => ['type' => 'array', 'items' => [
                    'type' => 'object',
                    'properties' => [
                        'row_no' => ['type' => 'integer'],
                        'y_top' => ['type' => 'integer'],
                        'y_bottom' => ['type' => 'integer'],
                        'item_no' => Cell::SCHEMA,
                        'amount' => Cell::SCHEMA,
                    ],
                    'required' => ['row_no', 'y_top', 'y_bottom', 'item_no', 'amount'],
                    'additionalProperties' => false,
                ]],
            ],
            'required' => ['rows'],
            'additionalProperties' => false,
        ];
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function passes(): array
    {
        return [self::ROWS, self::AMOUNTS];
    }

    public function limits(): array
    {
        return ['max_pages' => 60, 'min_short_side' => 600];
    }

    public function request(DocumentReadPage $page, ?string $imageBase64, ?string $text): array
    {
        if ($imageBase64 === null) {
            throw new LogicException('The BOQ reader reads page pictures only.');
        }

        $amounts = $page->pass === self::AMOUNTS;
        $language = $page->documentRead?->options['language'] ?? null;

        return ReaderRequest::image(
            self::KEY,
            $page,
            $amounts ? self::AMOUNTS_INSTRUCTIONS : self::ROWS_INSTRUCTIONS,
            $amounts ? self::amountsSchema() : self::rowsSchema(),
            $imageBase64,
            is_string($language) ? $language : null,
        );
    }

    public function accept(array $output, DocumentReadPage $page): ?array
    {
        $rows = $output['rows'] ?? [];

        if (! is_array($rows) || count($rows) > (int) config('modules.boq_scan.max_rows_per_page', 300)) {
            return null;
        }

        $height = (int) $page->image_height;
        $cells = $page->pass === self::AMOUNTS ? ['item_no', 'amount'] : ['item_no', 'description', 'unit', 'qty', 'rate'];
        $clean = [];

        foreach (array_values($rows) as $index => $row) {
            $kept = [
                'row_no' => max(0, min(9999, (int) ($row['row_no'] ?? $index + 1))),
                'y_top' => $this->clamp((int) ($row['y_top'] ?? 0), $height),
                'y_bottom' => $this->clamp((int) ($row['y_bottom'] ?? 0), $height),
            ];

            if ($page->pass !== self::AMOUNTS) {
                $kept['kind'] = in_array($row['kind'] ?? null, BoqScanRow::KINDS, true) ? $row['kind'] : BoqScanRow::NOTE;
            }

            foreach ($cells as $cell) {
                $value = is_array($row[$cell] ?? null) ? $row[$cell] : [];
                $raw = trim((string) preg_replace('/[\s\p{Z}]+/u', ' ', Cell::raw($value)));

                $kept[$cell] = ['raw' => mb_substr($raw, 0, self::CUT[$cell]), 'state' => Cell::state(['raw' => $raw, 'state' => $value['state'] ?? null])];
            }

            $clean[] = $kept;
        }

        if ($page->pass === self::AMOUNTS) {
            return ['rows' => $clean];
        }

        return [
            'page_kind' => in_array($output['page_kind'] ?? null, [self::PAGE_TABLE, self::PAGE_OTHER, self::PAGE_BLANK], true) ? $output['page_kind'] : self::PAGE_OTHER,
            'column_order' => array_values(array_filter((array) ($output['column_order'] ?? []), fn ($column) => in_array($column, self::COLUMNS, true))),
            'rows' => $clean,
        ];
    }

    /** A page the reader calls blank is not charged. The second reading always is. */
    public function chargeable(DocumentReadPage $page): bool
    {
        return ! ($page->pass === self::ROWS && ($page->result['page_kind'] ?? null) === self::PAGE_BLANK);
    }

    public function pageRead(DocumentReadPage $page): void
    {
        $this->ingest->pageRead($page);
    }

    public function nextPasses(DocumentReadPage $page): array
    {
        return $page->pass === self::ROWS && ($page->result['page_kind'] ?? null) === self::PAGE_TABLE ? [self::AMOUNTS] : [];
    }

    /** A cut-off table is offered as two halves; the amount column of a page is short, so a cut-off there just fails. */
    public function onCutOff(DocumentReadPage $page): string
    {
        return $page->pass === self::ROWS ? 'offer_halves' : 'fail';
    }

    public function finished(DocumentRead $read): void
    {
        $this->ingest->derive($read);
    }

    public function abilities(DocumentRead $read): array
    {
        return ['all' => ['boq.manage', 'money.view'], 'any' => []];
    }

    public function purge(DocumentRead $read): void
    {
        BoqScanRow::withoutGlobalScopes()
            ->where('organization_id', $read->organization_id)
            ->where('document_read_id', $read->id)
            ->delete();
    }

    public function cellKinds(string $pass): array
    {
        return $pass === self::AMOUNTS
            ? ['rows.amount' => 'amount']
            : ['rows.qty' => 'qty', 'rows.rate' => 'rate', 'rows.unit' => 'unit'];
    }

    /**
     * For the platform admin's dry run: how the two readings of a page pair up, and on how many item
     * rows quantity times rate equals the second reading's amount. Counts only.
     */
    public function pageChecks(array $answers): array
    {
        $rows = $answers[self::ROWS]['rows'] ?? null;
        $amounts = $answers[self::AMOUNTS]['rows'] ?? null;

        if (! is_array($rows) || ! is_array($amounts)) {
            return [];
        }

        // A dry-run answer is in pixels of a picture whose height is not at hand here; the lowest row stands in for it.
        $height = max([1, ...array_map(fn ($row) => (int) ($row['y_bottom'] ?? 0), [...$rows, ...$amounts])]);
        $place = fn (array $row) => ['key' => ItemNumber::key(Cell::raw((array) ($row['item_no'] ?? []))), 'y' => intdiv(((int) ($row['y_top'] ?? 0) + (int) ($row['y_bottom'] ?? 0)) * 500, $height)];
        $pairing = AmountPairing::pair(array_map($place, $rows), array_map($place, $amounts), (int) config('modules.boq_scan.pair_within', 15));

        $paired = $lines = $agree = 0;

        foreach ($pairing['pairs'] as $r => $a) {
            if (($rows[$r]['kind'] ?? null) !== BoqScanRow::ITEM) {
                continue;
            }

            $paired++;
            $qty = PrintedNumber::parse(Cell::raw((array) ($rows[$r]['qty'] ?? [])), 'qty');
            $rate = PrintedNumber::parse(Cell::raw((array) ($rows[$r]['rate'] ?? [])), 'rate');
            $amount = PrintedNumber::parse(Cell::raw((array) ($amounts[$a]['amount'] ?? [])), 'amount');

            if (! $qty->ok || ! $rate->ok || ! $amount->ok) {
                continue;
            }

            $lines++;
            $difference = Dec::of($qty->value)->multipliedBy(Dec::of($rate->value))->minus(Dec::of($amount->value))->abs();
            $agree += $difference->isGreaterThan(Dec::of(config('modules.boq_scan.tolerance', '1.00'))) ? 0 : 1;
        }

        $items = count(array_filter($rows, fn ($row) => ($row['kind'] ?? null) === BoqScanRow::ITEM));

        return [
            ['label' => __('Item rows with an amount from the second reading'), 'ok' => $paired === $items, 'note' => __(':count of :total', ['count' => $paired, 'total' => $items])],
            ['label' => __('Quantity times rate equals the amount'), 'ok' => $agree === $lines, 'note' => __(':count of :total', ['count' => $agree, 'total' => $lines])],
            ['label' => __('Amounts the first reading has no row for'), 'ok' => $pairing['extra'] === [], 'note' => (string) count($pairing['extra'])],
        ];
    }

    private function clamp(int $y, int $height): int
    {
        return $height > 0 ? max(0, min($height, $y)) : max(0, $y);
    }
}
