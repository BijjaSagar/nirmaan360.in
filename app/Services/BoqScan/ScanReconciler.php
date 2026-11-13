<?php

namespace App\Services\BoqScan;

use App\Models\BoqItem;
use App\Models\BoqScanRow;
use App\Models\DocumentRead;
use App\Models\DocumentReadPage;
use App\Models\MeasurementEntry;
use App\Models\RaBillItem;
use App\Services\DocumentReading\PrintedDigits;
use App\Services\DocumentReading\PrintedNumber;
use App\Services\DocumentReading\ReadIntake;
use App\Services\DocumentReading\UnitText;
use App\Support\Dec;
use Brick\Math\BigDecimal;
use Illuminate\Support\Collection;

/**
 * Every exact check on a scanned BOQ, worked out again from the stored rows each time it is asked,
 * so nothing the browser sends is trusted. Two figures agree when they differ by no more than the
 * tolerance (one rupee), the rule of the estimate import.
 *
 * The answer lists, for each row, each page and the read as a whole, the flags that stand and
 * whether each tick has been given; and the totals the screen shows beside the printed ones.
 *
 * It reads; it never writes. Nothing here runs with a signed-in person in mind: every query names the company.
 */
class ScanReconciler
{
    /** Cells whose remark "unclear" stops a row, by kind of row, as cell => the field typing clears it. */
    private const UNCLEAR_CELLS = [
        BoqScanRow::ITEM => ['item_no' => 'code', 'unit' => 'unit', 'qty' => 'qty', 'rate' => 'rate', 'amount' => 'amount'],
        BoqScanRow::ADDON => ['item_no' => 'code', 'amount' => 'amount'],
        BoqScanRow::SUBTOTAL => ['amount' => 'amount'],
        BoqScanRow::TOTAL => ['amount' => 'amount'],
    ];

    /** The figures of a row that are used, by kind of row. */
    private const NUMBERS = [
        BoqScanRow::ITEM => ['qty', 'rate', 'amount'],
        BoqScanRow::ADDON => ['amount'],
        BoqScanRow::SUBTOTAL => ['amount'],
        BoqScanRow::TOTAL => ['amount'],
    ];

    /** Rows that carry no line of the BOQ. */
    private const NOT_LINES = [BoqScanRow::HEADING, BoqScanRow::NOTE, BoqScanRow::SUBTOTAL, BoqScanRow::TOTAL];

    /**
     * @param  ?Collection<int, BoqScanRow>  $rows  The read's rows when the caller has them already, in order.
     */
    public function run(DocumentRead $read, ?Collection $rows = null): ScanState
    {
        $rows ??= BoqScanRow::withoutGlobalScopes()->where('organization_id', $read->organization_id)
            ->where('document_read_id', $read->id)->orderBy('sort')->orderBy('id')->get();

        $live = $rows->filter(fn (BoqScanRow $row) => $row->kind !== BoqScanRow::EXCLUDED)->values();
        $review = (array) ($read->review ?? []);
        $tolerance = Dec::of(config('modules.boq_scan.tolerance', '1.00'));
        $existing = $this->existing($read, $live);

        $codes = array_count_values($live->filter(fn (BoqScanRow $row) => $row->isImported() && filled($row->code))->map(fn (BoqScanRow $row) => (string) $row->code)->all());
        $duplicates = $this->duplicates($live);

        $rowFlags = [];
        $values = [];
        $itemsAbove = BigDecimal::zero();
        $since = BigDecimal::zero();
        $running = BigDecimal::zero();
        $candidates = [];

        foreach ($live as $row) {
            $flags = $this->rowFlags($row, $tolerance, $existing, $codes, $duplicates);
            $value = $this->value($row);

            // Sums the code makes down the document: the lines since the last subtotal, and all lines above.
            if ($row->kind === BoqScanRow::HEADING) {
                $since = BigDecimal::zero();
            } elseif ($row->kind === BoqScanRow::SUBTOTAL && $row->amount !== null) {
                $difference = $since->minus(Dec::of($row->amount))->abs();

                if ($difference->isGreaterThan($tolerance)) {
                    $flags[] = ScanFlag::make(ScanFlag::SUBTOTAL_MISMATCH, ScanFlag::TICK, ['sum' => Dec::money($since), 'printed' => Dec::money($row->amount), 'difference' => Dec::money($difference)]);
                }
            }

            if ($row->kind === BoqScanRow::ADDON && $row->percent !== null && $row->amount !== null && ! $this->addonNeedsAmount($row)) {
                $expected = Dec::percentOf($itemsAbove, $row->percent);
                $difference = Dec::of($expected)->minus(Dec::of($row->amount))->abs();

                if ($difference->isGreaterThan($tolerance)) {
                    $flags[] = ScanFlag::make(ScanFlag::ADDON_MISMATCH, ScanFlag::TICK, [
                        'percent' => self::plain((string) $row->percent), 'base' => Dec::money($itemsAbove),
                        'expected' => $expected, 'printed' => Dec::money($row->amount), 'difference' => Dec::money($difference),
                    ]);
                }
            }

            // A row that is not a line but prints at least what the lines above come to is a total, whatever the reader called it.
            if (in_array($row->kind, self::NOT_LINES, true) && $row->amount !== null
                && ! ($row->kind_set_by !== null && in_array($row->kind, [BoqScanRow::NOTE, BoqScanRow::SUBTOTAL], true))
                && $running->isPositive() && Dec::of($row->amount)->isGreaterThanOrEqualTo($running->minus($tolerance))) {
                $difference = $running->minus(Dec::of($row->amount))->abs();
                $candidates[] = ['row' => $row, 'sum' => Dec::money($running), 'printed' => Dec::money($row->amount), 'difference' => Dec::money($difference), 'differs' => $difference->isGreaterThan($tolerance)];
            }

            if ($row->kind === BoqScanRow::SUBTOTAL) {
                $since = BigDecimal::zero();
            }

            if ($row->isImported()) {
                $since = $since->plus($value);
                $running = $running->plus($value);

                if ($row->kind === BoqScanRow::ITEM) {
                    $itemsAbove = $itemsAbove->plus($value);
                }
            }

            $values[$row->id] = $value;
            $rowFlags[$row->id] = $this->withTicks($flags, (array) ($row->acks ?? []));
        }

        $readFlags = $this->readFlags($review, $candidates, $live);
        $pageFlags = $this->pageFlags($read, $review, $rows, $live);

        return new ScanState($live, $rowFlags, $pageFlags['flags'], $pageFlags['clean'], $pageFlags['suspect'], $readFlags, $this->summary($live, $candidates, $rowFlags, $pageFlags['flags'], $readFlags, $tolerance));
    }

    /** A decimal without trailing zeros: "6.0000" is 6, "7.5000" is 7.5. */
    private static function plain(string $value): string
    {
        return str_contains($value, '.') ? rtrim(rtrim($value, '0'), '.') : $value;
    }

    /**
     * @param  array<string, array{item: BoqItem, approved: string, records: bool}>  $existing
     * @param  array<string, int>  $codes
     * @param  array<int, list<int>>  $duplicates
     * @return list<array<string, mixed>>
     */
    private function rowFlags(BoqScanRow $row, BigDecimal $tolerance, array $existing, array $codes, array $duplicates): array
    {
        $flags = [];
        $kind = $row->kind;
        $unreadable = [];

        foreach (self::NUMBERS[$kind] ?? [] as $field) {
            $raw = trim((string) $row->getAttribute($field.'_raw'));

            if ($raw === '' || $row->typedField($field)) {
                continue;
            }

            $parsed = PrintedNumber::parse($raw, $field);

            if ($parsed->ok) {
                continue;
            }

            $unreadable[$field] = true;
            $flags[] = $parsed->reason === PrintedNumber::MIXED_SCRIPT
                ? ScanFlag::make(ScanFlag::MIXED_SCRIPT, ScanFlag::BLOCK, ['field' => $field])
                : ScanFlag::make(ScanFlag::NUMBER_UNREADABLE, ScanFlag::BLOCK, ['field' => $field, 'reason' => (string) $parsed->reason]);
        }

        foreach (self::UNCLEAR_CELLS[$kind] ?? [] as $cell => $field) {
            if ($row->stateOf($cell) === 'unclear' && ! $row->typedField($field) && ! isset($unreadable[$field])) {
                $flags[] = ScanFlag::make(ScanFlag::UNCLEAR_VALUE, ScanFlag::BLOCK, ['field' => $field]);
            }
        }

        if ($kind === BoqScanRow::ITEM) {
            $missing = array_values(array_filter(['description', 'unit', 'qty', 'rate'], fn (string $field) => blank($row->getAttribute($field)) && ! isset($unreadable[$field])));

            if ($missing !== []) {
                $flags[] = ScanFlag::make(ScanFlag::MISSING_REQUIRED, ScanFlag::BLOCK, ['fields' => $missing]);
            }

            if (($mismatch = $this->amountMismatch($row, $tolerance)) !== null) {
                $flags[] = $mismatch;
            }
        }

        if ($kind === BoqScanRow::ADDON) {
            if (blank($row->description)) {
                $flags[] = ScanFlag::make(ScanFlag::MISSING_REQUIRED, ScanFlag::BLOCK, ['fields' => ['description']]);
            }

            if ($this->addonNeedsAmount($row)) {
                $flags[] = ScanFlag::make(ScanFlag::ADDON_MISMATCH, ScanFlag::BLOCK, ['need' => 'amount']);
            }
        }

        if ($row->isImported()) {
            $code = (string) $row->code;

            if ($code === '') {
                $long = ! $row->typedField('code') && mb_strlen(ItemNumber::plain((string) $row->item_no_raw)) > 32;
                $flags[] = ScanFlag::make(ScanFlag::CODE_PROBLEM, ScanFlag::BLOCK, ['reason' => $long ? 'too_long' : 'blank']);
            } elseif (($codes[$code] ?? 0) > 1) {
                $flags[] = ScanFlag::make(ScanFlag::CODE_PROBLEM, ScanFlag::BLOCK, ['reason' => 'repeated', 'code' => $code]);
            }
        }

        foreach (['description' => 2000, 'unit' => 16, 'section' => 120] as $field => $max) {
            if (mb_strlen((string) $row->getAttribute($field)) > $max && ($field !== 'section' || $row->isImported())) {
                $flags[] = ScanFlag::make(ScanFlag::TOO_LONG, ScanFlag::BLOCK, ['field' => $field, 'max' => $max]);
            }
        }

        if ($row->isImported() && filled($row->code) && isset($existing[(string) $row->code])) {
            $line = $existing[(string) $row->code];
            $qty = $kind === BoqScanRow::ADDON ? '1' : $row->qty;
            $unit = $kind === BoqScanRow::ADDON ? 'LS' : $row->unit;

            if ($qty !== null && Dec::of($line['approved'])->isGreaterThan(Dec::of($qty))) {
                $flags[] = ScanFlag::make(ScanFlag::QTY_BELOW_APPROVED, ScanFlag::BLOCK, ['code' => (string) $row->code, 'approved' => Dec::qty($line['approved'])]);
            }

            if ($line['records'] && filled($unit) && $unit !== $line['item']->unit) {
                $flags[] = ScanFlag::make(ScanFlag::UNIT_LOCKED, ScanFlag::BLOCK, ['code' => (string) $row->code, 'unit' => (string) $line['item']->unit]);
            }

            $flags[] = ScanFlag::make(ScanFlag::UPDATES_EXISTING, ScanFlag::TICK, [
                'code' => (string) $row->code,
                'old' => [
                    'description' => (string) $line['item']->description,
                    'unit' => (string) $line['item']->unit,
                    'qty' => Dec::qty($line['item']->qty),
                    'rate' => Dec::money($line['item']->rate),
                ],
            ]);
        }

        if (in_array($kind, self::NOT_LINES, true) && $row->kind_set_by === null) {
            $figures = array_values(array_filter(['qty', 'rate'], fn (string $field) => filled($row->getAttribute($field.'_raw')) || $row->getAttribute($field) !== null));

            if (in_array($kind, [BoqScanRow::HEADING, BoqScanRow::NOTE], true) && (filled($row->amount_raw) || $row->amount !== null)) {
                $figures[] = 'amount';
            }

            if ($figures !== []) {
                $flags[] = ScanFlag::make(ScanFlag::FIGURES_ON_NON_ITEM_ROW, ScanFlag::BLOCK, ['fields' => $figures]);
            }
        }

        if (isset($duplicates[$row->id])) {
            $flag = ScanFlag::make(ScanFlag::POSSIBLE_DUPLICATE_ROW, ScanFlag::BLOCK, ['with' => $duplicates[$row->id]]);

            // A person who answered that both rows are real lines cleared it, for as long as the same rows stand.
            if (! $this->answered((array) ($row->acks ?? []), $flag)) {
                $flags[] = $flag;
            }
        }

        if ($kind === BoqScanRow::ITEM && blank($row->amount_raw)) {
            $flags[] = ScanFlag::make(ScanFlag::AMOUNT_UNVERIFIED, ScanFlag::TICK);
        }

        if ($kind === BoqScanRow::ITEM && ! $row->typedField('unit') && filled($row->unit_raw) && ! UnitText::normalise((string) $row->unit_raw)['known']) {
            $flags[] = ScanFlag::make(ScanFlag::UNIT_UNKNOWN, ScanFlag::TICK, ['unit' => (string) $row->unit_raw]);
        }

        if (($row->initial['instruction_like'] ?? []) !== []) {
            $flags[] = ScanFlag::make(ScanFlag::INSTRUCTION_LIKE_TEXT, ScanFlag::TICK);
        }

        if ($row->isImported() && $row->confirmed_at === null) {
            $flags[] = ScanFlag::make(ScanFlag::UNCONFIRMED_ROW, ScanFlag::BLOCK);
        }

        return $flags;
    }

    /**
     * Quantity times rate against the amount. Once the readings disagreed, only a person typing both
     * quantity and rate clears it: either so that the line reconciles, or with an override and a
     * reason. Typing the amount alone to match never does.
     */
    private function amountMismatch(BoqScanRow $row, BigDecimal $tolerance): ?array
    {
        if ($row->qty === null || $row->rate === null) {
            return null;
        }

        $product = Dec::of($row->qty)->multipliedBy(Dec::of($row->rate));
        $differs = fn ($amount) => $amount !== null && $product->minus(Dec::of($amount))->abs()->isGreaterThan($tolerance);
        $initial = (array) ($row->initial ?? []);

        $readDiffered = isset($initial['qty'], $initial['rate'], $initial['amount'])
            && Dec::of($initial['qty'])->multipliedBy(Dec::of($initial['rate']))->minus(Dec::of($initial['amount']))->abs()->isGreaterThan($tolerance);
        $bothTyped = $row->typedField('qty') && $row->typedField('rate');

        $open = ($differs($row->amount) && ! $this->overridden($row)) || ($readDiffered && ! $bothTyped);

        if (! $open) {
            return null;
        }

        $amount = $row->amount ?? ($initial['amount'] ?? null);

        return ScanFlag::make(ScanFlag::AMOUNT_MISMATCH, ScanFlag::BLOCK, [
            'qty' => Dec::qty($row->qty),
            'rate' => Dec::money($row->rate),
            'amount' => $amount === null ? null : Dec::money($amount),
            'product' => Dec::money($product),
            'difference' => $amount === null ? null : Dec::money($product->minus(Dec::of($amount))->abs()),
        ]);
    }

    /** An override counts only for the quantity and rate a person typed when giving it. */
    public function overridden(BoqScanRow $row): bool
    {
        $override = ($row->overrides ?? [])[ScanFlag::AMOUNT_MISMATCH] ?? null;

        return is_array($override) && $row->typedField('qty') && $row->typedField('rate')
            && $row->qty !== null && $row->rate !== null
            && Dec::of($override['qty'] ?? 0)->isEqualTo(Dec::of($row->qty))
            && Dec::of($override['rate'] ?? 0)->isEqualTo(Dec::of($row->rate));
    }

    /** An add-on with no percentage, or with no amount from the second reading, needs its amount typed by a person. */
    private function addonNeedsAmount(BoqScanRow $row): bool
    {
        if ($row->amount === null) {
            return true;
        }

        return ($row->percent === null || blank($row->amount_raw)) && ! $row->typedField('amount');
    }

    /** What a line comes to: its amount, or quantity times rate where no amount is known. */
    private function value(BoqScanRow $row): BigDecimal
    {
        if (! $row->isImported()) {
            return BigDecimal::zero();
        }

        if ($row->amount !== null) {
            return Dec::of($row->amount);
        }

        return $row->kind === BoqScanRow::ITEM && $row->qty !== null && $row->rate !== null
            ? Dec::of($row->qty)->multipliedBy(Dec::of($row->rate))
            : BigDecimal::zero();
    }

    /**
     * @param  list<array<string, mixed>>  $candidates
     * @param  Collection<int, BoqScanRow>  $live
     * @return list<array<string, mixed>>
     */
    private function readFlags(array $review, array $candidates, Collection $live): array
    {
        $acks = (array) ($review['acks'] ?? []);
        $flags = [];

        foreach ($candidates as $candidate) {
            if (! $candidate['differs']) {
                continue;
            }

            /** @var BoqScanRow $row */
            $row = $candidate['row'];
            $flag = ScanFlag::make(ScanFlag::TOTAL_MISMATCH, ScanFlag::BLOCK, [
                'row_id' => $row->id, 'page_no' => (int) $row->page_no,
                'printed' => $candidate['printed'], 'sum' => $candidate['sum'], 'difference' => $candidate['difference'],
            ]);

            if (! $this->answered((array) ($acks[ScanFlag::TOTAL_MISMATCH][(string) $row->id] ?? []), $flag, single: true)) {
                $flags[] = $flag;
            }
        }

        if ($candidates === [] && $live->contains(fn (BoqScanRow $row) => $row->isImported())) {
            $flags[] = ScanFlag::make(ScanFlag::NO_GRAND_TOTAL, ScanFlag::TICK);
        }

        return $this->withTicks($flags, $acks);
    }

    /**
     * @param  Collection<int, BoqScanRow>  $rows  Every row, the excluded ones too.
     * @param  Collection<int, BoqScanRow>  $live
     * @return array{flags: array<int, list<array<string, mixed>>>, clean: array<int, bool>, suspect: array<int, bool>}
     */
    private function pageFlags(DocumentRead $read, array $review, Collection $rows, Collection $live): array
    {
        $pages = DocumentReadPage::withoutGlobalScopes()
            ->where('organization_id', $read->organization_id)->where('document_read_id', $read->id)->where('pass', BoqReadKind::ROWS)
            ->get(['id', 'page_no', 'part', 'status', 'fail_reason', 'injection_flags']);

        $numbers = collect(range(1, max(1, (int) $read->pages_total)))
            ->merge($pages->pluck('page_no'))->merge($rows->pluck('page_no'))
            ->map(fn ($number) => (int) $number)->uniqueStrict()->sort()->values();

        $orders = [];

        foreach ((array) ($review['pages'] ?? []) as $number => $info) {
            foreach ((array) ($info['parts'] ?? []) as $part) {
                $order = (array) ($part['column_order'] ?? []);

                if (in_array('qty', $order, true) && in_array('rate', $order, true)) {
                    $orders[(int) $number] = array_search('qty', $order, true) < array_search('rate', $order, true);
                }
            }
        }

        $flags = [];
        $clean = [];
        $suspect = [];

        foreach ($numbers as $number) {
            $info = (array) ($review['pages'][(string) $number] ?? []);
            $onPage = $live->where('page_no', $number);
            $readings = $pages->where('page_no', $number);
            $extras = (array) ($info['extra_amount_rows'] ?? []);
            $acks = (array) ($info['acks'] ?? []);
            $suspect[$number] = $this->columnsSuspect($number, $onPage, $orders);
            $clean[$number] = ! $suspect[$number] && $extras === [] && ! isset($info['no_lines']) && $onPage->isNotEmpty();
            $list = [];

            if (isset($info['no_lines'])) {
                $flags[$number] = [];

                continue;
            }

            foreach ($extras as $extra) {
                if (! isset($extra['resolved'])) {
                    $list[] = ScanFlag::make(ScanFlag::SECOND_READING_EXTRA_ROW, ScanFlag::BLOCK, [
                        'extra' => (string) ($extra['id'] ?? ''), 'item_no' => (string) ($extra['item_no'] ?? ''), 'amount' => (string) ($extra['raw'] ?? ''),
                        'y_top' => (int) ($extra['y_top'] ?? 0), 'y_bottom' => (int) ($extra['y_bottom'] ?? 0),
                    ]);
                }
            }

            if ($suspect[$number]) {
                $flag = ScanFlag::make(ScanFlag::COLUMNS_SUSPECT, ScanFlag::BLOCK);

                if (! $this->answered((array) ($acks[ScanFlag::COLUMNS_SUSPECT] ?? []), $flag, single: true)) {
                    $list[] = $flag;
                }
            }

            $anyRead = $readings->contains(fn ($page) => $page->status === DocumentReadPage::READ);
            $failed = $readings->where('status', DocumentReadPage::FAILED)->sortByDesc('id')->first();

            if (! $anyRead && $failed !== null && ! $onPage->contains(fn (BoqScanRow $row) => ! $row->fromReader())) {
                $list[] = ScanFlag::make(ScanFlag::PAGE_UNRESOLVED, ScanFlag::BLOCK, ['reason' => (string) $failed->fail_reason]);
            }

            $scripts = $onPage->flatMap(fn (BoqScanRow $row) => array_values(array_filter((array) ($row->scripts ?? []))))->uniqueStrict()->values()->all();

            if (in_array(PrintedDigits::ASCII, $scripts, true) && in_array(PrintedDigits::DEVANAGARI, $scripts, true)) {
                $list[] = ScanFlag::make(ScanFlag::SCRIPT_DIFFERS_ON_PAGE, ScanFlag::TICK);
            }

            $kinds = collect((array) ($info['parts'] ?? []))->pluck('page_kind')->filter()->values();

            if ($kinds->isNotEmpty() && ! $kinds->contains(BoqReadKind::PAGE_TABLE)) {
                $list[] = ScanFlag::make(ScanFlag::NO_TABLE_ON_PAGE, ScanFlag::TICK, ['page_kind' => (string) $kinds->first()]);
            }

            $injected = collect((array) ($info['parts'] ?? []))->flatMap(fn ($part) => (array) ($part['instruction_like'] ?? []))->filter()->isNotEmpty();

            if ($injected) {
                $list[] = ScanFlag::make(ScanFlag::INSTRUCTION_LIKE_TEXT, ScanFlag::TICK);
            }

            $flags[$number] = $this->withTicks($list, $acks);
        }

        return ['flags' => $flags, 'clean' => $clean, 'suspect' => $suspect];
    }

    /**
     * Quantity and rate may be the wrong way round on a page: most of its lines print two decimals
     * in the quantity and three in the rate, or the page lists rate before quantity while most pages
     * of the document do the opposite.
     *
     * @param  Collection<int, BoqScanRow>  $onPage
     * @param  array<int, bool>  $orders  Page => whether quantity comes before rate.
     */
    private function columnsSuspect(int $number, Collection $onPage, array $orders): bool
    {
        $decimals = fn (?string $raw, string $kind) => ($parsed = PrintedNumber::parse((string) $raw, $kind))->ok && str_contains((string) $parsed->value, '.')
            ? strlen(explode('.', (string) $parsed->value, 2)[1])
            : null;

        $both = 0;
        $swapped = 0;

        foreach ($onPage->where('kind', BoqScanRow::ITEM) as $row) {
            $qty = $decimals($row->qty_raw, 'qty');
            $rate = $decimals($row->rate_raw, 'rate');

            if ($qty === null || $rate === null) {
                // A rate printed with three decimals does not parse as a rate at all; it still counts as one.
                $rateText = PrintedNumber::parse((string) $row->rate_raw, 'qty');
                $rate ??= $rateText->ok && str_contains((string) $rateText->value, '.') ? strlen(explode('.', (string) $rateText->value, 2)[1]) : null;
            }

            if ($qty === null || $rate === null) {
                continue;
            }

            $both++;
            $swapped += $qty === 2 && $rate === 3 ? 1 : 0;
        }

        $rule = (array) config('modules.boq_scan.columns_suspect', ['rows' => 3, 'share' => '0.60']);

        if ($both >= (int) ($rule['rows'] ?? 3) && Dec::of($swapped)->isGreaterThanOrEqualTo(Dec::of($both)->multipliedBy(Dec::of($rule['share'] ?? '0.60')))) {
            return true;
        }

        if (isset($orders[$number]) && $orders[$number] === false) {
            $others = array_filter($orders, fn ($first, $page) => $page !== $number, ARRAY_FILTER_USE_BOTH);
            $qtyFirst = count(array_filter($others));

            return $others !== [] && $qtyFirst * 2 > count($others);
        }

        return false;
    }

    /**
     * Rows of a page read as two halves that may be the same printed row seen twice: a row of the
     * bottom half lying in the band both halves cover, with the same item number and the same start
     * of description as a row of the top half.
     *
     * @param  Collection<int, BoqScanRow>  $live
     * @return array<int, list<int>> Row id => the ids it may repeat.
     */
    private function duplicates(Collection $live): array
    {
        $band = ReadIntake::halfOf(ReadIntake::BOTTOM);
        [$from, $to] = [$band['top'], 1000 - $band['top']];
        $same = fn (BoqScanRow $row) => ItemNumber::key((string) $row->item_no_raw).'|'.mb_strtolower(mb_substr(trim((string) preg_replace('/\s+/u', ' ', (string) $row->description_raw)), 0, 40));
        $found = [];

        foreach ($live->where('part', ReadIntake::BOTTOM)->filter(fn (BoqScanRow $row) => $row->fromReader()) as $bottom) {
            $middle = intdiv((int) $bottom->y_top + (int) $bottom->y_bottom, 2);

            if ($middle < $from || $middle > $to) {
                continue;
            }

            foreach ($live->where('page_no', $bottom->page_no)->where('part', ReadIntake::TOP)->filter(fn (BoqScanRow $row) => $row->fromReader()) as $top) {
                if ($same($top) === $same($bottom)) {
                    $found[$bottom->id][] = $top->id;
                    $found[$top->id][] = $bottom->id;
                }
            }
        }

        return $found;
    }

    /**
     * The project's BOQ lines that rows of this scan would change, by code: the line itself (the
     * lowest id, the one the import updates), its approved quantity and whether it has records.
     *
     * @param  Collection<int, BoqScanRow>  $live
     * @return array<string, array{item: BoqItem, approved: string, records: bool}>
     */
    private function existing(DocumentRead $read, Collection $live): array
    {
        $codes = $live->filter(fn (BoqScanRow $row) => $row->isImported() && filled($row->code))->pluck('code')->uniqueStrict()->values()->all();

        if ($codes === [] || $read->project_id === null) {
            return [];
        }

        $items = BoqItem::withoutGlobalScopes()->where('organization_id', $read->organization_id)->where('project_id', $read->project_id)
            ->whereNull('deleted_at')->whereIn('code', $codes)->orderBy('id')->get()->uniqueStrict('code')->keyBy('code');

        if ($items->isEmpty()) {
            return [];
        }

        $ids = $items->pluck('id')->all();
        $approved = MeasurementEntry::withoutGlobalScopes()->where('organization_id', $read->organization_id)->where('project_id', $read->project_id)
            ->where('status', 'APPROVED')->whereIn('boq_item_id', $ids)->selectRaw('boq_item_id, sum(qty) as total')->groupBy('boq_item_id')->pluck('total', 'boq_item_id');
        $measured = MeasurementEntry::withoutGlobalScopes()->where('organization_id', $read->organization_id)->whereIn('boq_item_id', $ids)->distinct()->pluck('boq_item_id')->flip();
        $billed = RaBillItem::withoutGlobalScopes()->whereIn('boq_item_id', $ids)->distinct()->pluck('boq_item_id')->flip();

        return $items->map(fn (BoqItem $item) => [
            'item' => $item,
            'approved' => (string) ($approved->get($item->id) ?? '0'),
            'records' => $measured->has($item->id) || $billed->has($item->id),
        ])->all();
    }

    /**
     * @param  list<array<string, mixed>>  $flags
     * @param  array<string, mixed>  $acks  Code => the tick, or for read-level totals code => row id => tick.
     * @return list<array<string, mixed>>
     */
    private function withTicks(array $flags, array $acks): array
    {
        return array_map(function (array $flag) use ($acks) {
            if ($flag['level'] === ScanFlag::TICK) {
                $flag['ticked'] = $this->answered((array) ($acks[$flag['code']] ?? []), $flag, single: true);
            }

            return $flag;
        }, $flags);
    }

    /**
     * Whether a person answered this flag, for the figures it stands on now.
     *
     * @param  array<string, mixed>  $answer  One answer {by, at, hash, reason?}, or for a row's flags code => answer.
     */
    private function answered(array $answer, array $flag, bool $single = false): bool
    {
        $answer = $single ? $answer : (array) ($answer[$flag['code']] ?? []);

        return ($answer['hash'] ?? null) === ScanFlag::hash($flag);
    }

    /**
     * @param  Collection<int, BoqScanRow>  $live
     * @param  list<array<string, mixed>>  $candidates
     * @param  array<int, list<array<string, mixed>>>  $rowFlags
     * @param  array<int, list<array<string, mixed>>>  $pageFlags
     * @param  list<array<string, mixed>>  $readFlags
     * @return array<string, mixed>
     */
    private function summary(Collection $live, array $candidates, array $rowFlags, array $pageFlags, array $readFlags, BigDecimal $tolerance): array
    {
        $lines = $live->filter(fn (BoqScanRow $row) => $row->isImported());
        $printed = BigDecimal::zero();
        $value = BigDecimal::zero();

        foreach ($lines as $row) {
            if ($row->amount !== null) {
                $printed = $printed->plus(Dec::of($row->amount));
            }

            if ($row->kind === BoqScanRow::ADDON) {
                $value = $value->plus(Dec::of($row->amount ?? 0));
            } elseif ($row->qty !== null && $row->rate !== null) {
                $value = $value->plus(Dec::of(Dec::money(Dec::of($row->qty)->multipliedBy(Dec::of($row->rate)))));
            }
        }

        $total = $candidates === [] ? null : end($candidates)['printed'];
        $count = ['blocking' => 0, 'unticked' => 0, 'unconfirmed' => 0, 'pages_unresolved' => 0];

        foreach ([...array_merge(...array_values($rowFlags ?: [[]])), ...array_merge(...array_values($pageFlags ?: [[]])), ...$readFlags] as $flag) {
            match (true) {
                $flag['code'] === ScanFlag::UNCONFIRMED_ROW => $count['unconfirmed']++,
                $flag['level'] === ScanFlag::BLOCK => $count['blocking']++,
                ! ($flag['ticked'] ?? false) => $count['unticked']++,
                default => null,
            };

            if ($flag['code'] === ScanFlag::PAGE_UNRESOLVED) {
                $count['pages_unresolved']++;
            }
        }

        $valueDiffers = $total !== null && Dec::of($total)->minus($value)->abs()->isGreaterThan($tolerance);

        return [
            'rows' => $live->count(),
            'lines' => $lines->count(),
            'printed_amounts' => Dec::money($printed),
            'value_after_import' => Dec::money($value),
            'printed_total' => $total,
            'difference_printed' => $total === null ? null : Dec::money(Dec::of($total)->minus($printed)),
            'difference_value' => $total === null ? null : Dec::money(Dec::of($total)->minus($value)),
            ...$count,
            'needs_value_reason' => $valueDiffers,
            'can_confirm' => $count['blocking'] === 0 && $count['unticked'] === 0 && $count['unconfirmed'] === 0 && $lines->isNotEmpty(),
        ];
    }
}
