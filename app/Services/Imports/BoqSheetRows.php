<?php

namespace App\Services\Imports;

use App\Services\ProjectSetup\BoqCategories;
use App\Services\Reports\EstimateSheet;
use App\Support\Dec;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Str;

/**
 * Reads the rows of any contractor's BOQ, estimate or tender sheet into BOQ lines, once BoqHeadings
 * (or the person) has said what each column holds. It extends EstimateSheet, whose helpers it shares.
 *
 * Every row is given a kind, and only "line" and "rate_only" rows become BOQ lines:
 * - section: a row with no figures whose number is a letter or a roman numeral (A, B, II), or "Part A".
 * - spec: a specification with no figures whose lettered or dashed rows below carry them. Those child
 *   lines get the specification in their details. Without children it is left out as a note.
 * - subheading: a short row with no figures inside a section. It is added to the section name.
 * - part: a lettered row with no rate under a line, explaining how its quantity is made up.
 * - subtotal, total, carried (c/f, b/f), say, words (the amount in words): never lines. Totals are
 *   checked against the lines above them.
 * - adjustment: "Add 6% contingencies", "-5% below DSR", "Electrical 3%". Shown, never imported.
 * - rate_only: a line whose quantity is QRO, "rate only", or blank with a rate. Imported with
 *   quantity 0; no quantity is ever made up.
 * - note, skipped, after_total, column_numbers, heading_repeat: left out, each with a reason.
 *
 * Quantity times rate is checked against the sheet's amount and every mismatch is listed. The figures
 * are imported as the sheet gives them; nothing is quietly corrected.
 */
final class BoqSheetRows extends EstimateSheet
{
    /** Kinds that become BOQ lines. */
    public const LINE_KINDS = ['line', 'rate_only'];

    /** Text this long, with no figures, is a specification rather than a heading. */
    private const SPEC_LENGTH = 60;

    /** How much of the specification a child line's description repeats. All of it is in the details. */
    private const SPEC_IN_DESCRIPTION = 150;

    private const MAX_DESCRIPTION = 2000;

    private ?string $section = null;

    private ?string $marker = null;

    private ?string $subheading = null;

    /** @var array{row: int, text: string, code: string, serial: string, unit: string, amount: ?string, children: int, sum: BigDecimal, stage: ?string}|null */
    private ?array $spec = null;

    private ?int $lastLine = null;

    private int $running = 0;

    /** The code of the last line in this section, which a line with no number of its own follows, as 4 then 4.1. */
    private ?string $lastBase = null;

    private BigDecimal $linesSum;

    private BigDecimal $sinceTotal;

    private BigDecimal $effects;

    /** What the total rows since the last line add up to, for a summary total of totals. */
    private BigDecimal $totalsAbove;

    private bool $afterGrand = false;

    /** @var array<int, array<string, mixed>> Sheet row => BOQ line. */
    private array $made = [];

    /** @var array<int, array{kind: string, text: string, reason: ?string}> Sheet row => what it is. */
    private array $kinds = [];

    /** @var list<array<string, mixed>> */
    private array $adjustments = [];

    /** @var list<array<string, mixed>> */
    private array $totals = [];

    /** @var list<array<string, mixed>> */
    private array $mismatches = [];

    private int $unpriced = 0;

    /**
     * @param  array<int, array<int, mixed>>  $grid  Sheet row => column => value.
     * @param  ?string  $unit  The unit the person gave for lines with none.
     * @return array{grid: array<int, array<int, ?string>>, columns: array<string, int>, header_row: int, kinds: array<int, array{kind: string, text: string, reason: ?string}>, lines: array<int, array<string, mixed>>, adjustments: list<array<string, mixed>>, totals: list<array<string, mixed>>, mismatches: list<array<string, mixed>>, notes: list<array{row: int, message: string}>, lines_total: string, adjustments_total: string, with_adjustments: string, file_subtotal: ?string, file_total: ?string}
     */
    public function classify(array $grid, SheetLayout $layout, ?string $unit = null): array
    {
        $this->linesSum = $this->sinceTotal = $this->effects = $this->totalsAbove = BigDecimal::zero();
        $columns = $layout->columns();
        $fallback = $layout->headingUnit() ?? CellValues::unit($unit);
        $headings = array_filter(array_map(fn (string $text) => BoqHeadings::normalise($text), $layout->headings));
        $numbers = array_values(array_filter(array_keys($grid), fn (int $number) => $number > $layout->lastRow()));
        $detector = new BoqHeadings;

        foreach ($numbers as $index => $number) {
            $cells = $grid[$number];
            $row = $this->read($cells, $columns);

            if ($row === null) {
                continue;
            }

            if ($index < 2 && $detector->isColumnNumbers($cells)) {
                $this->kind($number, 'column_numbers', $row['all'], __('The column numbers printed under the heading.'));

                continue;
            }

            if ($this->repeatsHeading($cells, $headings)) {
                $this->kind($number, 'heading_repeat', $row['all'], __('The heading, printed again.'));

                continue;
            }

            $next = isset($numbers[$index + 1]) ? $this->read($grid[$numbers[$index + 1]], $columns) : null;
            $this->row($number, $row, $next, $columns, $fallback);
        }

        $this->closeSpec();
        ksort($this->made);
        $this->summarise();

        return [
            'grid' => $this->output(),
            'columns' => self::OUTPUT,
            'header_row' => $layout->lastRow(),
            'kinds' => $this->kinds,
            'lines' => $this->made,
            'adjustments' => $this->adjustments,
            'totals' => $this->totals,
            'mismatches' => $this->mismatches,
            'notes' => $this->notes,
            'lines_total' => Dec::money($this->linesSum),
            'adjustments_total' => Dec::money($this->effects),
            'with_adjustments' => Dec::money($this->linesSum->plus($this->effects)),
            'file_subtotal' => $this->fileSubtotal(),
            'file_total' => $this->fileTotal(),
        ];
    }

    /** @return array<string, mixed>|null The row's values by field, or null when the columns read are all empty. */
    private function read(array $cells, array $columns): ?array
    {
        // A dash or a rule drawn with characters is a separator, not a value.
        $cells = array_map(fn ($value) => is_string($value) && preg_match('/^[\s\-–—=_*.~]+$/u', $value) === 1 ? null : $value, $cells);
        $get = fn (string $field) => isset($columns[$field]) ? ($cells[$columns[$field]] ?? null) : null;
        $row = [];

        foreach (SheetLayout::FIELDS as $field) {
            $value = $get($field);
            $row[$field] = in_array($field, ['qty', 'rate', 'amount'], true) ? CellValues::number($value) : CellValues::text($value);
            $row[$field.'_raw'] = $value;
        }

        // An amount formula that comes to 0 on a row with no quantity or rate is an empty cell, as on a heading row.
        if ($row['qty'] === null && $row['rate'] === null && $row['amount'] !== null && Dec::of($row['amount'])->isZero()) {
            $row['amount'] = null;
        }

        $any = false;

        foreach (SheetLayout::FIELDS as $field) {
            $any = $any || (in_array($field, ['qty', 'rate', 'amount'], true) ? $row[$field] !== null || CellValues::text($row[$field.'_raw']) !== '' && ! CellValues::isNumber($row[$field.'_raw']) : $row[$field] !== '');
        }

        if (! $any) {
            return null;
        }

        $texts = [];

        foreach ($cells as $value) {
            if (! CellValues::isNumber($value) && ($text = CellValues::text($value)) !== '') {
                $texts[] = $text;
            }
        }

        $row['serial'] = rtrim(CellValues::asciiDigits($row['serial']), '.');
        $row['all'] = implode(' ', $texts);
        $row['rate_only'] = CellValues::isRateOnly($row['qty_raw']) || CellValues::isRateOnly($row['rate_raw']) || CellValues::isRateOnly($row['amount_raw']) || CellValues::isRateOnly($row['unit']);
        $row['figures'] = $row['qty'] !== null || $row['rate'] !== null || $row['amount'] !== null || $row['rate_only'];

        return $row;
    }

    private function row(int $number, array $row, ?array $next, array $columns, ?string $fallback): void
    {
        $label = $row['description'] !== '' ? $row['description'] : $row['all'];
        $priced = $row['qty'] !== null && $row['rate'] !== null;

        if (! $priced && $this->isWords($row['all'])) {
            $this->kind($number, 'words', $label, __('The amount written in words.'));

            return;
        }

        if (! $priced && $this->isCarried($label)) {
            $this->kind($number, 'carried', $label, __('Carried or brought forward between pages. Not a line.'));

            return;
        }

        if (! $priced && preg_match('/^say\b/iu', $label) === 1) {
            $this->kind($number, 'say', $label, __('A rounded figure. Not a line.'));

            return;
        }

        if (! $priced && ($total = $this->totalKind($label, $row)) !== null) {
            $this->total($number, $label, $total, $row['amount']);

            return;
        }

        $percentUnit = CellValues::unit($row['unit']) === '%';

        if (($percent = $this->percent($label, $row)) !== null
            && ($percentUnit || (! $priced && ! $row['rate_only'] && $row['rate'] === null && ($row['amount'] !== null || mb_strlen($label) <= self::SPEC_LENGTH)))) {
            $this->adjustment($number, $label, $percent, $row['amount']);

            return;
        }

        if (! $row['figures']) {
            $this->textOnly($number, $row, $next);

            return;
        }

        if ($row['description'] === '') {
            $this->kind($number, 'skipped', $row['all'], __('Figures with no description. Left out.'));

            return;
        }

        // A lump-sum figure with lettered rows under it that carry their own quantity and rate: the
        // figure is what the rows add up to, so it is a head, not a line of its own.
        if ($row['qty'] === null && $row['rate'] === null && $row['amount'] !== null && ! $row['rate_only']
            && $next !== null && $this->lettered($next['description']) !== null && ($next['qty'] !== null || $next['rate'] !== null)) {
            $this->closeSpec();
            $this->openSpec($number, $row);

            return;
        }

        $this->line($number, $row, $columns, $fallback);
    }

    /** A row with words and no figures: a section, a specification, a sub-heading, or a note. */
    private function textOnly(int $number, array $row, ?array $next): void
    {
        $text = $row['description'];

        if ($this->afterGrand) {
            $this->kind($number, 'after_total', $row['all'], __('Below the total of the sheet. Left out.'));

            return;
        }

        if (($marker = $this->sectionMarker($row)) !== null) {
            $this->closeSpec();
            $name = trim((string) preg_replace('/^(?:(?:part|section|bill\s*no\.?|schedule)\s*[-:.]?\s*)?'.preg_quote($marker, '/').'\s*[.):\-]\s*/iu', '', $text), " :-\t");
            $this->section = Str::limit($name !== '' ? $name : __('Section :marker', ['marker' => $marker]), 120, '');
            $this->marker = $marker;
            $this->subheading = null;
            $this->lastBase = null;
            $this->running = 0;
            $this->kind($number, 'section', $text !== '' ? $text : $marker, null);

            return;
        }

        if ($text === '') {
            $this->kind($number, 'skipped', $row['all'], __('No description and no figures. Left out.'));

            return;
        }

        $short = mb_strlen($text) < self::SPEC_LENGTH && $row['code'] === '';

        if ($this->spec !== null && $short && $this->spec['children'] === 0) {
            $this->spec['text'] = rtrim($this->spec['text'], ' :').' - '.trim($text, ' :');
            $this->kind($number, 'spec', $text, __('Part of the specification above.'));

            return;
        }

        if ($this->spec !== null && $short && $this->lettered($text) === null) {
            $this->spec['stage'] = trim($text, ' :');
            $this->kind($number, 'subheading', $text, null);

            return;
        }

        if (! $short || $this->startsLikeSpecification($text)) {
            $this->closeSpec();
            $this->openSpec($number, $row);

            return;
        }

        if ($this->lastLine !== null && $this->spec === null && $this->lettered($text) !== null) {
            $this->made[$this->lastLine]['parts'][] = $text;
            $this->kind($number, 'part', $text, __('Explains the line above. Kept in its details.'));

            return;
        }

        $this->closeSpec();
        $this->subheading = Str::limit(trim($text, " :-\t"), 80, '');
        $this->kind($number, 'subheading', $text, null);
    }

    private function line(int $number, array $row, array $columns, ?string $fallback): void
    {
        $lettered = $this->lettered($row['description']);
        $spec = $this->spec;
        $child = $spec !== null && $this->isChild($row, $spec, $lettered);

        // A lettered row with a quantity and no rate under a line explains that line, as in an estimate.
        if (! $child && $lettered !== null && $this->lastLine !== null && $row['rate'] === null && $row['amount'] === null && $row['unit'] === '' && ! $row['rate_only']) {
            $this->made[$this->lastLine]['parts'][] = $this->part(['description' => $row['description'], 'qty' => $row['qty']]);
            $this->kind($number, 'part', $row['description'], __('Explains the line above. Kept in its details.'));

            return;
        }

        if (! $child) {
            $this->closeSpec();
            $spec = null;
        }

        ['qty' => $qty, 'rate' => $rate, 'amount' => $amount] = $row;
        $kind = 'line';
        $notes = [];
        $unitText = $row['unit'];

        if ($row['rate_only'] || ($qty === null && $rate !== null && ($amount === null || Dec::of($amount)->isZero()))) {
            $kind = 'rate_only';
            $qty = '0';
            $rate ??= '0';
        } elseif ($qty === null && $rate !== null && $amount !== null && ! Dec::of($rate)->isZero()) {
            $qty = (string) Dec::of($amount)->dividedBy(Dec::of($rate), 3, RoundingMode::HalfUp);
            $notes[] = __('No quantity in the file. It was worked out as amount divided by rate.');
        } elseif ($qty === null && $rate === null && $amount !== null) {
            $qty = '1';
            $rate = $amount;
            $unitText = $unitText !== '' ? $unitText : 'LS';
            $notes[] = __('Only an amount in the file. It was read as 1 lump sum at that amount.');
        } elseif ($qty !== null && $rate === null && $amount !== null && ! Dec::of($qty)->isZero()) {
            $rate = (string) Dec::of($amount)->dividedBy(Dec::of($qty), 2, RoundingMode::HalfUp);
            $notes[] = __('No rate in the file. It was worked out as amount divided by quantity.');
        } elseif ($qty !== null && $rate === null) {
            $rate = '0';
            $this->unpriced++;
        }

        if ($row['qty'] !== null && $row['rate'] !== null && $amount !== null && $this->differs(Dec::of($row['qty'])->multipliedBy(Dec::of($row['rate'])), Dec::of($amount))) {
            $this->mismatches[] = [
                'row' => $number, 'description' => $row['description'], 'qty' => $row['qty'], 'rate' => $row['rate'], 'amount' => $amount,
                'computed' => Dec::money(Dec::of($row['qty'])->multipliedBy(Dec::of($row['rate']))),
            ];
        }

        $letter = $lettered[0] ?? null;
        $text = $lettered[1] ?? $row['description'];
        $unit = CellValues::unit($unitText) ?? ($spec !== null && $spec['unit'] !== '' ? $spec['unit'] : null) ?? $fallback ?? '';
        $code = $this->lineCode($row, $spec, $letter, isset($columns['code']), isset($columns['code']) || isset($columns['serial']));
        $description = $row['description'];
        $details = [];

        if ($spec !== null) {
            $context = $spec['stage'] !== null ? $text.' ('.$spec['stage'].')' : $text;
            $description = $context.' - '.Str::limit($spec['text'], self::SPEC_IN_DESCRIPTION);
            $details[] = __('Specification: :text', ['text' => $spec['text']]);
        }

        if (mb_strlen($description) > self::MAX_DESCRIPTION) {
            $details[] = __('Full description: :text', ['text' => $description]);
            $description = mb_substr($description, 0, self::MAX_DESCRIPTION - 1).'…';
            $notes[] = __('The description is longer than :max characters. It was shortened, and the whole of it is in the details.', ['max' => self::MAX_DESCRIPTION]);
        }

        foreach (['details' => null, 'reference' => __('Reference: :text', ['text' => $row['reference']])] as $field => $wording) {
            if ($row[$field] !== '') {
                $details[] = $wording ?? $row[$field];
            }
        }

        if (isset($columns['code']) && $row['code'] !== '' && $code !== $row['code']) {
            $details[] = __('Item code in the file: :code', ['code' => $row['code']]);
        }

        if ($kind === 'rate_only') {
            $details[] = __('Rate only: the file gives no quantity.');
        }

        $this->made[$number] = [
            'kind' => $kind,
            'section' => $this->sectionName($row),
            'code' => $code,
            'description' => $description,
            'unit' => $unit,
            'qty' => $qty,
            'rate' => $rate,
            'amount' => $amount,
            'category' => BoqCategories::fromText($row['category']) ?? $this->category($description.' '.($spec['text'] ?? '')),
            'details' => $details,
            'parts' => [],
            'remarks' => $row['remark'] === '' ? [] : [$row['remark']],
            'spec_row' => $spec['row'] ?? null,
        ];

        foreach ($notes as $note) {
            $this->note($number, $note);
        }

        $value = Dec::of($qty)->multipliedBy(Dec::of($rate));
        $this->linesSum = $this->linesSum->plus($value);
        $this->sinceTotal = $this->sinceTotal->plus($value);
        $this->lastLine = $number;
        $this->totalsAbove = BigDecimal::zero();
        $this->kind($number, $kind, $row['description'], null);

        if ($spec !== null) {
            $this->spec['children']++;
            $this->spec['sum'] = $this->spec['sum']->plus($value);
        }
    }

    /**
     * Whether a row with figures belongs to the open specification: lettered or dashed, or with no number of
     * its own, or a few words that only finish the specification ("Footing", "Flooring in bath:").
     */
    private function isChild(array $row, array $spec, ?array $lettered): bool
    {
        if ($lettered !== null || ($row['serial'] === '' && $row['code'] === '')) {
            return true;
        }

        $text = $row['description'];

        return mb_strlen($text) < 40 && ! $this->startsLikeSpecification($text)
            && (str_ends_with($text, ':') || count(preg_split('/\s+/u', $text) ?: []) <= 3 || ($row['code'] !== '' && $row['code'] === $spec['code']));
    }

    /** The line's item code: the file's code, else the serial number, else the specification's, else the next number in order. */
    private function lineCode(array $row, ?array $spec, ?string $letter, bool $codeColumn, bool $numbered): string
    {
        $prefix = fn (string $serial) => $this->marker !== null && preg_match('/^\d+$/', $serial) === 1 ? $this->marker.'.'.$serial : $serial;
        $letter = $letter !== null && mb_strlen($letter) === 1 ? mb_strtolower($letter) : '';

        $base = match (true) {
            $codeColumn && $row['code'] !== '' => $row['code'],
            $row['serial'] !== '' => $prefix($row['serial']),
            $spec !== null && $spec['code'] !== '' => $spec['code'].$letter,
            $spec !== null && $spec['serial'] !== '' => $prefix($spec['serial']).$letter,
            $numbered && $this->lastBase !== null => $this->lastBase,
            default => $prefix((string) ++$this->running),
        };

        $base = mb_substr(trim($base), 0, 28);
        $this->lastBase = $base;

        return $this->unique($base);
    }

    private function openSpec(int $number, array $row): void
    {
        $this->spec = [
            'row' => $number, 'text' => $row['description'], 'code' => $row['code'], 'serial' => $row['serial'],
            'unit' => (string) CellValues::unit($row['unit']), 'amount' => $row['amount'], 'children' => 0, 'sum' => BigDecimal::zero(), 'stage' => null,
        ];
        $this->kind($number, 'spec', $row['description'], null);
    }

    /** Finish the specification lines were being added to. One with no lines under it is a note, left out. */
    private function closeSpec(): void
    {
        $spec = $this->spec;
        $this->spec = null;

        if ($spec === null) {
            return;
        }

        if ($spec['children'] === 0) {
            if ($spec['amount'] !== null) {
                $this->kind($spec['row'], 'skipped', $spec['text'], __('An amount with no quantity, rate or lines under it. Left out.'));
            } else {
                $this->kind($spec['row'], 'note', $spec['text'], __('Text with no figures and no lines under it. Left out.'));
            }

            return;
        }

        if ($spec['amount'] !== null && $this->differs($spec['sum'], Dec::of($spec['amount']))) {
            $this->note($spec['row'], __('The parts add up to :parts but the file shows :file for this item. The parts were imported.', ['parts' => inr($spec['sum']), 'file' => inr($spec['amount'])]));
        }
    }

    /**
     * A total row, checked against what it can stand for: every line so far, the lines since the previous
     * total or since any earlier one (a section or a part), the lines with the adjustments, an earlier total
     * repeated in a summary, or the totals listed just above it.
     */
    private function total(int $number, string $label, string $kind, ?string $amount): void
    {
        $this->closeSpec();
        $this->lastLine = null;

        $adjusted = $this->linesSum->plus($this->effects);
        $matches = null;

        if ($amount !== null) {
            $stated = Dec::of($amount);
            $figures = [$this->linesSum, $this->sinceTotal, $adjusted, $this->totalsAbove];

            foreach ($this->totals as $earlier) {
                $figures[] = $this->linesSum->minus(Dec::of($earlier['lines']));

                if ($earlier['amount'] !== null) {
                    $figures[] = Dec::of($earlier['amount']);
                }
            }

            $matches = false;

            foreach ($figures as $figure) {
                if (! $this->differs($figure, $stated)) {
                    $matches = true;
                    break;
                }
            }

            $this->totalsAbove = $this->totalsAbove->plus($stated);
        }

        $this->totals[] = [
            'row' => $number, 'label' => $label, 'kind' => $kind, 'amount' => $amount, 'adjusted' => $this->adjustments !== [],
            'lines' => Dec::money($this->linesSum), 'section' => Dec::money($this->sinceTotal), 'with_adjustments' => Dec::money($adjusted), 'matches' => $matches,
        ];
        $this->sinceTotal = BigDecimal::zero();
        $this->afterGrand = $this->afterGrand || $kind === 'total';
        $this->kind($number, $kind, $label, null);
    }

    private function adjustment(int $number, string $label, string $percent, ?string $amount): void
    {
        $this->closeSpec();
        $this->lastLine = null;

        $base = $this->linesSum;
        $effect = $base->multipliedBy(Dec::of($percent))->dividedBy(100, 2, RoundingMode::HalfUp);
        $shows = null;

        if ($amount !== null) {
            $shows = match (true) {
                ! $this->differs($effect->abs(), Dec::of($amount)->abs()) => 'amount',
                ! $this->differs($base->plus($this->effects)->plus($effect), Dec::of($amount)) || ! $this->differs($base->plus($effect), Dec::of($amount)) => 'total',
                default => 'other',
            };
        }

        $this->effects = $this->effects->plus($effect);
        $this->adjustments[] = [
            'row' => $number, 'label' => $label, 'percent' => $percent, 'base' => Dec::money($base),
            'effect' => Dec::money($effect), 'amount' => $amount, 'shows' => $shows,
        ];
        $this->kind($number, 'adjustment', $label, null);
    }

    /** The share an adjustment row adds or takes off, signed, or null when the row names no percentage. */
    private function percent(string $label, array $row): ?string
    {
        if (CellValues::unit($row['unit']) === '%') {
            $value = $row['qty'] ?? $row['rate'];

            return $value === null ? null : $this->signed($label, $value, false);
        }

        if (preg_match('/([-−–+]?)\s*(\d+(?:\.\d+)?)\s*(?:%|percent\b|per\s*cent\b|टक्के|प्रतिशत)/iu', CellValues::asciiDigits($label), $match) !== 1) {
            return null;
        }

        return $this->signed($label, $match[2], $match[1] !== '' && $match[1] !== '+');
    }

    private function signed(string $label, string $value, bool $minus): string
    {
        $less = $minus || preg_match('/\b(less|below|rebate|discount|deduct|deduction|minus|reduction)\b|कमी|वजा|घटा|कम\s/iu', $label) === 1;
        $value = ltrim($value, '-');

        return $less && ! Dec::of($value)->isZero() ? '-'.$value : $value;
    }

    /** @return ?string "subtotal", "total" for a grand total, or null when the row is not a total. */
    private function totalKind(string $label, array $row): ?string
    {
        $texts = array_filter([$label, $row['unit'], CellValues::text($row['rate_raw']), CellValues::text($row['qty_raw']), $row['serial']], fn ($text) => is_string($text) && $text !== '');

        foreach ($texts as $text) {
            $text = trim(mb_strtolower($text), " \t:.-–");

            if (preg_match('/^(grand\s*total|net\s*total|net\s*amount|total\s+(estimate|estimated|amount|cost|project|of\s+estimate)|estimated\s+cost|total\s*$)/u', $text) === 1
                && preg_match('/^total\s*$/u', $text) !== 1) {
                return 'total';
            }

            if (preg_match('/^(एकूण\s+रक्कम|एकंदर|कुल\s+(राशि|योग|लागत)|महायोग|सर्व\s+एकूण)/u', $text) === 1) {
                return 'total';
            }

            if (preg_match('/^(sub\s*-?\s*total|total)(\s|$|[:.(])|\b(sub\s*-?\s*total|total)\s*(amount|cost|rs\.?)?$/u', $text) === 1
                || preg_match('/^(एकूण|कुल|योग|बेरीज|उप\s*योग|उपयोग)(\s|$|[:.])/u', $text) === 1) {
                return 'subtotal';
            }
        }

        return null;
    }

    private function isCarried(string $label): bool
    {
        return preg_match('/^(total\s+)?(carried|brought)\s*(forward|fwd|over|down)|^(total\s+)?[cb]\s*\/\s*[fo]\b|^[cb]\.\s*f\.|\b[cb]\s*\/\s*f\.?$|पुढे\s+नेले|मागील\s+पान|आगे\s+ले\s+जाया|पिछले\s+पृष्ठ/iu', trim($label)) === 1;
    }

    /** "Rupees nineteen lakh ... only" and the like: an amount written in words. */
    private function isWords(string $text): bool
    {
        $lower = mb_strtolower($text);

        if (preg_match('/in\s+words|अक्षरी|शब्दात|शब्दों\s+में/u', $lower) === 1) {
            return true;
        }

        $numberWords = preg_match('/\b(lakh|lakhs|lac|lacs|crore|crores|thousand|hundred)\b|लाख|हजार|हज़ार|कोटी|करोड|शंभर|सौ/u', $lower) === 1;

        return $numberWords && preg_match('/\bonly\b|\brupees\b|^rs\.?\s|^say\b|फक्त|मात्र|केवल|रुपये/u', $lower) === 1;
    }

    /** A letter or roman numeral that marks a section: in the serial or code column, or opening the description. */
    private function sectionMarker(array $row): ?string
    {
        foreach ([$row['serial'], $row['code']] as $cell) {
            if (preg_match('/^(?:(?:part|section|bill\s*no\.?|schedule)\s*[-:.]?\s*)?([A-Z]|[IVXL]{1,5})\s*[.):]?$/u', $cell, $match) === 1) {
                return $match[1];
            }
        }

        $text = $row['description'];

        if (preg_match('/^(?:part|section|bill\s*no\.?|schedule)\s*[-:.]?\s*([A-Z0-9]{1,4})\b/iu', $text, $match) === 1) {
            return mb_strtoupper($match[1]);
        }

        if (mb_strlen($text) <= 80 && $row['serial'] === '' && preg_match('/^([A-Z]|[IVX]{1,5})[.)]\s+\S/u', $text, $match) === 1) {
            return $match[1];
        }

        return null;
    }

    /** @return array{0: string, 1: string}|null The letter, roman numeral or dash a child row opens with, and its text after that. */
    private function lettered(string $text): ?array
    {
        $patterns = [
            '/^\(?([ivx]{1,4})\)\s*(.+)$/u',
            '/^\(?([a-zA-Z])\)\s*(.+)$/u',
            '/^([a-z])[.:]\s+(.+)$/u',
            '/^([ivx]{1,4})[.:]\s+(.+)$/u',
            '/^([-–—•*])\s*(.+)$/u',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, trim($text), $match) === 1) {
                return [$match[1], trim($match[2])];
            }
        }

        return null;
    }

    private function startsLikeSpecification(string $text): bool
    {
        return preg_match('/^(providing|supplying|constructing|laying|fixing|making|carrying|excavat|dismantl|filling|applying|p\s*[\/&]\s*f|s\s*[\/&]\s*f)/iu', $text) === 1;
    }

    private function repeatsHeading(array $cells, array $headings): bool
    {
        $same = 0;

        foreach ($headings as $column => $heading) {
            $same += BoqHeadings::normalise(CellValues::text($cells[$column] ?? null)) === $heading ? 1 : 0;
        }

        return $same >= 2 && $same >= count($headings) - 1;
    }

    private function sectionName(array $row): ?string
    {
        if ($row['section'] !== '') {
            return Str::limit($row['section'], 120, '');
        }

        $name = implode(' - ', array_filter([$this->section, $this->subheading]));

        return $name === '' ? null : Str::limit($name, 120, '');
    }

    private function kind(int $number, string $kind, string $text, ?string $reason): void
    {
        $this->kinds[$number] = ['kind' => $kind, 'text' => Str::limit($text, 300), 'reason' => $reason];
    }

    /**
     * The sheet's own total of the work before any adjustment: the largest total row above every adjustment,
     * which on a sheet with section totals is the total of them all.
     */
    private function fileSubtotal(): ?string
    {
        $largest = null;

        foreach ($this->totals as $total) {
            if (! $total['adjusted'] && $total['amount'] !== null && ($largest === null || Dec::of($total['amount'])->isGreaterThan(Dec::of($largest)))) {
                $largest = $total['amount'];
            }
        }

        return $largest;
    }

    /** The sheet's grand total: the last total row, when it comes after the lines and adjustments. */
    private function fileTotal(): ?string
    {
        foreach (array_reverse($this->totals) as $total) {
            if ($total['amount'] !== null && ($total['kind'] === 'total' || $total['adjusted'])) {
                return $total['amount'];
            }
        }

        return null;
    }

    /** One note for things that touch many lines, and one for the totals. */
    private function summarise(): void
    {
        $rateOnly = count(array_filter($this->made, fn (array $line) => $line['kind'] === 'rate_only'));

        if ($rateOnly > 0) {
            $this->note(0, __(':count lines are priced by rate only. They were read with quantity 0; measurements against them go for approval as beyond the BOQ.', ['count' => $rateOnly]));
        }

        if ($this->unpriced > 0) {
            $this->note(0, __(':count lines have a quantity but no rate. They were read at rate 0; fill in the rates later.', ['count' => $this->unpriced]));
        }

        if ($this->mismatches !== []) {
            $this->note(0, __(':count lines have an amount that is not quantity times rate. The quantity and rate were kept as the file gives them.', ['count' => count($this->mismatches)]));
        }

        $subtotal = $this->fileSubtotal();

        if ($subtotal !== null && $this->differs($this->linesSum, Dec::of($subtotal))) {
            $this->note(0, __('The lines add up to :lines but the total written in the file is :file. The lines were imported as they are, so please check that total.', ['lines' => inr($this->linesSum), 'file' => inr($subtotal)]));
        }
    }

    /** @return array<int, array<int, ?string>> */
    private function output(): array
    {
        $rows = [];

        foreach ($this->made as $number => $line) {
            $details = $line['details'];

            if ($line['parts'] !== []) {
                $details[] = __('Break-up: :parts', ['parts' => implode('; ', $line['parts'])]);
            }

            if ($line['remarks'] !== []) {
                $details[] = __('Remark: :text', ['text' => implode('; ', $line['remarks'])]);
            }

            $rows[$number] = [
                self::OUTPUT['section'] => $line['section'],
                self::OUTPUT['code'] => $line['code'],
                self::OUTPUT['description'] => $line['description'],
                self::OUTPUT['unit'] => $line['unit'],
                self::OUTPUT['qty'] => $line['qty'],
                self::OUTPUT['rate'] => $line['rate'],
                self::OUTPUT['category'] => $line['category'],
                self::OUTPUT['details'] => $details === [] ? null : Str::limit(implode("\n", $details), 10000, ''),
            ];
        }

        return $rows;
    }

    /** Amounts in a sheet are rounded. A rupee, or half a paisa in every rupee, is not a difference. */
    protected function differs(BigDecimal $first, BigDecimal $second): bool
    {
        $allowed = $second->abs()->multipliedBy('0.0005');

        return $first->minus($second)->abs()->isGreaterThan($allowed->isLessThan(1) ? BigDecimal::one() : $allowed);
    }
}
