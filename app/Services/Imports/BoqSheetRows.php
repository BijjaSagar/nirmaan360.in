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
 * - section: a row with no quantity or rate whose number is a letter or a roman numeral (A, B, II), or
 *   "Part A". An amount on it is the section's total, checked against the section's lines.
 * - spec: a specification with no figures whose lettered, dashed or unnumbered rows below carry them.
 *   A numbered row with no figures is one, however short. Those child lines get the specification in
 *   their details; text wrapped over rows with the figures on the last one becomes one line's text.
 *   Without children it is left out as a note.
 * - subheading: a short unnumbered row with no figures inside a section. It is added to the section name.
 * - part: a lettered row with no rate under a line, explaining how its quantity is made up.
 * - subtotal, total, carried (c/f, b/f, "carried to summary"), say, words (the amount in words): never
 *   lines. Totals are checked against the lines above them. A total is the sheet's total only when no
 *   line follows it; one in the middle, as "Total amount of Part A", is a part total.
 * - adjustment: "Add 6% contingencies", "-5% below DSR", "Water supply 3%", and amounts such as "Round off",
 *   "Add GST" or a discount. Shown, never imported. A percentage inside an item's text is not one.
 * - rate_only: a line whose quantity cell is QRO, "rate only", or blank with a rate. Imported with
 *   quantity 0; no quantity is ever made up.
 * - error: a line whose quantity or rate cell cannot be read (words, a spreadsheet error, a formula with
 *   no saved value) or whose "Per" cannot be applied. Nothing is imported while one is left in.
 * - excluded: a row the person left out on the column check.
 * - note, skipped, after_total, column_numbers, heading_repeat: left out, each with a reason.
 *
 * A quantity written with its unit ("12.5 Cum", "L.S.") gives both. A rate given per 10 or 100 units, or
 * per quintal, is turned into the rate per unit. Quantity times rate is checked against the sheet's amount
 * and every mismatch is listed. The figures are imported as the sheet gives them; nothing is quietly
 * corrected.
 */
final class BoqSheetRows extends EstimateSheet
{
    /** Kinds that become BOQ lines. */
    public const LINE_KINDS = ['line', 'rate_only'];

    /** Kinds that are left out of the BOQ and used for nothing: what the preview counts as rows left out. */
    public const LEFT_OUT_KINDS = ['skipped', 'after_total', 'note', 'column_numbers', 'heading_repeat', 'excluded'];

    /** Text this long, with no figures, is a specification rather than a heading. */
    private const SPEC_LENGTH = 60;

    /** How much of the specification a child line's description repeats. All of it is in the details. */
    private const SPEC_IN_DESCRIPTION = 150;

    private const MAX_DESCRIPTION = 2000;

    /** Kinds a row with words and no figures is given, which a grand total above it makes "after_total". */
    private const TEXT_KINDS = ['section', 'spec', 'subheading', 'note', 'skipped', 'part'];

    /** Mass units and how many kilograms each is: a rate per quintal is turned into one per kg. */
    private const MASS = ['kg' => 1, 'qtl' => 100, 'MT' => 1000];

    private ?string $section = null;

    private ?string $marker = null;

    private ?string $subheading = null;

    /** The number of the numbered heading the sub-heading came from, as 1 for "1 | EARTH WORK": item 2 is past it. */
    private ?string $headNumber = null;

    /** Whether items under that heading are numbered from it, as 1.1 and 1.2: then 2 is past it, not one of them. */
    private bool $headChildren = false;

    /** @var array{row: int, label: string, amount: string, from: BigDecimal}|null A section row's own total, checked when the section ends. */
    private ?array $sectionCheck = null;

    /** @var array{row: int, text: string, code: string, serial: string, unit: string, amount: ?string, children: int, lettered: int, sum: BigDecimal, stage: ?string}|null */
    private ?array $spec = null;

    private ?int $lastLine = null;

    private int $running = 0;

    /** The code of the last line in this section, which a line with no number of its own follows, as 4 then 4.1. */
    private ?string $lastBase = null;

    private BigDecimal $linesSum;

    private BigDecimal $sinceTotal;

    private int $linesSinceTotal = 0;

    private BigDecimal $effects;

    /** What the total rows since the last line add up to, for a summary total of totals. */
    private BigDecimal $totalsAbove;

    /** Whether the sheet has a serial or code column, so an unnumbered row is told from a numbered one. */
    private bool $numbering = false;

    /** @var list<int> */
    private array $descriptionColumns = [];

    /** @var array<int, array<int, true>> Formula cells with no saved value. */
    private array $missing = [];

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

    /** @var list<array{row: int, field: ?string, message: string}> */
    private array $problems = [];

    /** @var list<int> Rows of lines with a quantity and no rate. */
    private array $unpriced = [];

    /**
     * $options holds the formula cells with no saved value ("missing"), the sheet's merged ranges ("merges")
     * and the rows the person left out ("skip").
     *
     * @param  array<int, array<int, mixed>>  $grid  Sheet row => column => value.
     * @param  ?string  $unit  The unit the person gave for lines with none.
     * @param  array{missing?: array<int, array<int, true>>, merges?: list<array{0: int, 1: int, 2: int, 3: int}>, skip?: list<int>}  $options
     * @return array{grid: array<int, array<int, ?string>>, columns: array<string, int>, header_row: int, kinds: array<int, array{kind: string, text: string, reason: ?string}>, lines: array<int, array<string, mixed>>, adjustments: list<array<string, mixed>>, totals: list<array<string, mixed>>, mismatches: list<array<string, mixed>>, notes: list<array{row: int, message: string}>, errors: list<array{row: int, field: ?string, message: string}>, lines_total: string, adjustments_total: string, with_adjustments: string, file_subtotal: ?string, file_total: ?string}
     */
    public function classify(array $grid, SheetLayout $layout, ?string $unit = null, array $options = []): array
    {
        $this->linesSum = $this->sinceTotal = $this->effects = $this->totalsAbove = BigDecimal::zero();
        $this->missing = $options['missing'] ?? [];
        $columns = $layout->columns();
        $this->descriptionColumns = $layout->columnsOf('description');
        $this->numbering = isset($columns['serial']) || isset($columns['code']);
        $fallback = $layout->headingUnit() ?? CellValues::unit($unit);
        $grid = $this->unmerge($grid, $options['merges'] ?? [], $layout->lastRow(), array_values(array_intersect_key($columns, array_flip(['qty', 'rate', 'amount']))));
        $headings = array_filter(array_map(fn (string $text) => BoqHeadings::normalise($text), $layout->headings));
        $headingRows = [];

        for ($number = $layout->row ?? 1; $layout->span > 1 && $number <= $layout->lastRow(); $number++) {
            $headingRows[] = array_filter(array_map(fn ($value) => BoqHeadings::normalise(CellValues::text($value)), $grid[$number] ?? []));
        }

        $skip = array_flip(array_map('intval', $options['skip'] ?? []));
        $numbers = array_values(array_filter(array_keys($grid), fn (int $number) => $number > $layout->lastRow()));
        $detector = new BoqHeadings;

        foreach ($numbers as $index => $number) {
            $cells = $grid[$number];
            $row = $this->read($number, $cells, $columns);

            if ($row === null) {
                continue;
            }

            if (isset($skip[$number])) {
                $this->kind($number, 'excluded', $row['all'], null);

                continue;
            }

            if ($detector->isColumnNumbers($cells)) {
                $this->kind($number, 'column_numbers', $row['all'], __('The column numbers printed under the heading.'));

                continue;
            }

            if ($this->repeatsHeading($cells, $headings, $headingRows)) {
                $this->kind($number, 'heading_repeat', $row['all'], __('The heading, printed again.'));

                continue;
            }

            $next = isset($numbers[$index + 1]) ? $this->read($numbers[$index + 1], $grid[$numbers[$index + 1]], $columns) : null;
            $this->row($number, $row, $next, $columns, $fallback);
        }

        $this->closeSpec();
        $this->closeSectionCheck();
        $this->finishTotals();
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
            'errors' => $this->problems,
            'lines_total' => Dec::money($this->linesSum),
            'adjustments_total' => Dec::money($this->effects),
            'with_adjustments' => Dec::money($this->linesSum->plus($this->effects)),
            'file_subtotal' => $this->fileSubtotal(),
            'file_total' => $this->fileTotal(),
        ];
    }

    /**
     * An item's text in a cell merged down over several rows, with its figures on one of them: the text
     * belongs to that row. Merged ranges over the same rows are taken together, so a merged serial number
     * and a merged description go to the row with the figures; so does a serial number typed, unmerged,
     * in the first row beside the merged text.
     *
     * @param  list<array{0: int, 1: int, 2: int, 3: int}>  $merges
     * @param  list<int>  $figureColumns  The quantity, rate and amount columns.
     */
    private function unmerge(array $grid, array $merges, int $lastHeading, array $figureColumns = []): array
    {
        $groups = [];

        foreach ($merges as [$top, $left, $bottom, $right]) {
            if ($top > $lastHeading && $bottom > $top && CellValues::text($grid[$top][$left] ?? null) !== '') {
                $groups[$top.':'.$bottom][] = [$left, $right];
            }
        }

        foreach ($groups as $span => $ranges) {
            [$top, $bottom] = array_map('intval', explode(':', $span));
            $inside = fn (int $column) => array_filter($ranges, fn (array $range) => $column >= $range[0] && $column <= $range[1]) !== [];
            $rows = [];
            $priced = [];

            for ($number = $top; $number <= $bottom; $number++) {
                foreach ($grid[$number] ?? [] as $column => $value) {
                    if (! $inside($column) && CellValues::text($value) !== '') {
                        $rows[] = $number;
                        break;
                    }
                }

                foreach ($figureColumns as $column) {
                    if (! $inside($column) && CellValues::text($grid[$number][$column] ?? null) !== '') {
                        $priced[] = $number;
                        break;
                    }
                }
            }

            // The one row with figures, else the one row with anything beside the merged cells.
            $target = count($priced) === 1 ? $priced[0] : (count($priced) === 0 && count($rows) === 1 ? $rows[0] : null);

            if ($target === null || $target === $top) {
                continue;
            }

            foreach ($ranges as [$left]) {
                $grid[$target][$left] = $grid[$top][$left];
                unset($grid[$top][$left]);
            }

            // The first row has no figures: what else is typed on it, as the serial number, goes with the text.
            foreach ($grid[$top] ?? [] as $column => $value) {
                if (CellValues::text($grid[$target][$column] ?? null) === '') {
                    $grid[$target][$column] = $value;
                    unset($grid[$top][$column]);
                }
            }

            if (($grid[$top] ?? []) === []) {
                unset($grid[$top]);
            }

            ksort($grid[$target]);
        }

        ksort($grid);

        return $grid;
    }

    /** @return array<string, mixed>|null The row's values by field, or null when the columns read are all empty. */
    private function read(int $number, array $cells, array $columns): ?array
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

        // "Description" merged over two columns: the text of both, as one.
        if (count($this->descriptionColumns) > 1) {
            $row['description'] = implode(' ', array_filter(array_map(fn (int $column) => CellValues::text($cells[$column] ?? null), $this->descriptionColumns), fn (string $text) => $text !== ''));
        }

        // An amount formula that comes to 0 on a row with no quantity or rate is an empty cell, as on a heading row.
        if ($row['qty'] === null && $row['rate'] === null && $row['amount'] !== null && Dec::of($row['amount'])->isZero()) {
            $row['amount'] = null;
        }

        // A quantity cell that is not a plain number: the number with its unit, a rate-only mark, or something unreadable.
        $row['qty_unit'] = null;
        $row['qty_text'] = null;
        $qtyText = CellValues::text($row['qty_raw']);

        if ($row['qty'] === null && $qtyText !== '' && ! CellValues::isRateOnly($row['qty_raw'])) {
            if (($both = CellValues::quantityWithUnit($row['qty_raw'])) !== null) {
                [$row['qty'], $row['qty_unit']] = $both;
            } else {
                $row['qty_text'] = $qtyText;
            }
        }

        $rateText = CellValues::text($row['rate_raw']);
        $row['rate_text'] = $row['rate'] === null && $rateText !== '' && ! CellValues::isRateOnly($row['rate_raw']) ? $rateText : null;
        $row['missing'] = array_values(array_filter(['qty', 'rate'], fn (string $field) => isset($columns[$field], $this->missing[$number][$columns[$field]])));

        if (CellValues::isRateOnly($row['unit'])) {
            $row['unit'] = '';
        }

        $any = $row['missing'] !== [];

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
        // Rate only when the quantity cell says so, or is blank beside a rate-only mark. A quantity in the file is never dropped.
        $row['rate_only'] = CellValues::isRateOnly($row['qty_raw'])
            || ($row['qty'] === null && $row['qty_text'] === null && (CellValues::isRateOnly($row['rate_raw']) || CellValues::isRateOnly($row['amount_raw']) || CellValues::isRateOnly($row['unit_raw'])));
        $row['figures'] = $row['qty'] !== null || $row['rate'] !== null || $row['amount'] !== null || $row['rate_only'] || $row['qty_text'] !== null || $row['missing'] !== [];

        return $row;
    }

    private function row(int $number, array $row, ?array $next, array $columns, ?string $fallback): void
    {
        $label = $row['description'] !== '' ? $row['description'] : $row['all'];
        $priced = $row['qty'] !== null && $row['rate'] !== null;
        $amountOnly = $row['qty'] === null && $row['rate'] === null && $row['qty_text'] === null && $row['amount'] !== null && ! $row['rate_only'];
        $unit = CellValues::knownUnit($row['unit']);
        $totalWords = $this->isTotalWording($label);
        // A number of its own or a unit makes a row an item, whatever its words say: "Testing of samples brought to
        // site", "Provision for inspection inclusive of GST", "Total station survey". Not "A | Sub Total (A)".
        $numbered = $this->ownNumber($row, $totalWords);
        $ownUnit = $unit !== null && $unit !== '%';
        // What makes a row a line and not a total: its own number, a rate, a rate-only mark, or a unit or quantity
        // beside words that are not a total's ("Total Qty | Cum | 120" is the quantities added up).
        $lineLike = $numbered || $row['rate_only'] || $row['rate'] !== null || $row['qty_text'] !== null || (($ownUnit || $row['qty'] !== null) && ! $totalWords);

        if (! $priced && $this->isWords($label)) {
            $this->kind($number, 'words', $label, __('The amount written in words.'));

            return;
        }

        if (! $priced && ! $numbered && $this->isCarried($label)) {
            $this->kind($number, 'carried', $label, __('Carried or brought forward between pages. Not a line.'));

            return;
        }

        if (! $priced && preg_match('/^say\b/iu', $label) === 1) {
            $this->kind($number, 'say', $label, __('A rounded figure. Not a line.'));

            return;
        }

        if (! $priced && (! $lineLike || $this->isBareTotal($label)) && ($total = $this->totalKind($label, $row)) !== null) {
            $this->total($number, $label, $total, $row['amount']);

            return;
        }

        if ($amountOnly && ! $numbered && ! $ownUnit && $this->isCarriedElsewhere($label)) {
            $this->kind($number, 'carried', $label, __('Carried to or brought from another page or the summary. Not a line.'));

            return;
        }

        // A section's letter with an amount: the section's own total when its work follows, else a lettered item of
        // its own, as "C | Provisional sum for electrical works | 5,00,000" after the last section.
        if ($amountOnly && ! $ownUnit && ($marker = $this->sectionMarker($row)) !== null) {
            if ($this->headsWork($next)) {
                $this->openSection($number, $row, $marker, $row['amount']);

                return;
            }
        }

        $percentUnit = $unit === '%';
        $percent = $this->percent($label, $row);
        $notLine = $row['qty'] === null && $row['qty_text'] === null && ! $row['rate_only'] && ($unit === null || $percentUnit);

        // Text with lettered parts under it is a specification, whatever percentage it names.
        $partsBelow = $row['amount'] === null && $next !== null && $this->lettered($next['description']) !== null;

        if ($percent !== null && ($percentUnit || ($notLine && $row['rate'] === null && $this->percentIsSubject($label, $percent, $row['amount'], $numbered || $partsBelow)))) {
            $this->adjustment($number, $label, $percent, $row['amount']);

            return;
        }

        if ($amountOnly && $notLine && $this->isAmountAdjustment($label, $numbered)) {
            $this->amountAdjustment($number, $label, $row['amount']);

            return;
        }

        // An amount alone, with no number of its own, that is what the lines above come to: a total, checked.
        if ($amountOnly && $row['serial'] === '' && $row['code'] === '' && $this->linesSinceTotal >= 2 && count(preg_split('/\s+/u', trim($label)) ?: []) <= 5
            && (! $this->differs($this->linesSum, Dec::of($row['amount'])) || ! $this->differs($this->sinceTotal, Dec::of($row['amount'])))) {
            $this->total($number, $label, 'subtotal', $row['amount']);

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
        if ($amountOnly && $next !== null && $this->lettered($next['description']) !== null && ($next['qty'] !== null || $next['rate'] !== null)) {
            $this->closeSpec($row);
            $this->openSpec($number, $row);

            return;
        }

        // A lettered item after the sections, as a provisional sum: a part of its own, with itself as its one line.
        if ($amountOnly && ! $ownUnit && ($marker = $this->sectionMarker($row)) !== null) {
            $this->openSection($number, $row, $marker, null);
        }

        $this->line($number, $row, $columns, $fallback);
    }

    /** A row with words and no figures: a section, a specification, a sub-heading, or a note. */
    private function textOnly(int $number, array $row, ?array $next): void
    {
        $text = $row['description'];

        if (($marker = $this->sectionMarker($row)) !== null) {
            $this->openSection($number, $row, $marker, null);

            return;
        }

        if ($text === '') {
            $this->kind($number, 'skipped', $row['all'], __('No description and no figures. Left out.'));

            return;
        }

        $numbered = $row['serial'] !== '' || $row['code'] !== '';
        $short = mb_strlen($text) < self::SPEC_LENGTH && ! $numbered;

        // Text going on from the specification above, before any line of it: the same specification.
        if ($this->spec !== null && $this->spec['children'] === 0 && ! $numbered && ($short || $this->numbering) && $this->lettered($text) === null) {
            $this->spec['text'] = $this->continues($text)
                ? rtrim($this->spec['text']).' '.trim($text)
                : rtrim($this->spec['text'], ' :').' - '.trim($text, ' :');
            $this->kind($number, 'spec', $text, __('Part of the specification above.'));

            return;
        }

        if ($this->spec !== null && $short && $this->lettered($text) === null) {
            $this->spec['stage'] = trim($text, ' :');
            $this->kind($number, 'subheading', $text, null);

            return;
        }

        // A numbered row with no figures is an item whose parts follow, however short its text. With none, a
        // short one is the heading of the items after it ("1 | EARTH WORK" over 1.1, 1.2).
        if (! $short || $this->startsLikeSpecification($text)) {
            $this->closeSpec($row);
            $this->leaveHeading($row);
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
        $this->headNumber = null;
        $this->kind($number, 'subheading', $text, null);
    }

    /**
     * A numbered row past the numbered heading above, as 2 after "1 | EARTH WORK", 1.1 and 1.2, is not under that
     * heading. Where the items under a heading are numbered 1, 2, 3 afresh, only the next heading ends it.
     */
    private function leaveHeading(array $row): void
    {
        $serial = $row['serial'] !== '' ? $row['serial'] : $row['code'];

        if ($this->headNumber === null || $serial === '') {
            return;
        }

        if (str_starts_with($serial, $this->headNumber.'.')) {
            $this->headChildren = true;
        } elseif ($this->headChildren) {
            $this->subheading = null;
            $this->headNumber = null;
            $this->headChildren = false;
        }
    }

    /**
     * Whether the row below a section's letter with an amount is work of that section: an item, or text that
     * opens one. A total, another section's letter, or nothing below, says the lettered row is an item itself.
     */
    private function headsWork(?array $next): bool
    {
        if ($next === null || $this->sectionMarker($next) !== null) {
            return false;
        }

        $label = $next['description'] !== '' ? $next['description'] : $next['all'];
        $totalWords = $this->isTotalWording($label);

        if (! $this->ownNumber($next, $totalWords) && ($totalWords || $this->totalKind($label, $next) !== null || $this->isCarried($label) || $this->isWords($label))) {
            return false;
        }

        return $label !== '';
    }

    /**
     * Whether the row has a number of its own in the serial or code column: not a total's word there ("Total",
     * "C/F"), nor a section's letter beside a total's words, as "A | Sub Total (A)".
     */
    private function ownNumber(array $row, bool $totalWords): bool
    {
        foreach ([$row['serial'], $row['code']] as $cell) {
            if ($cell === '' || $this->isBareTotal($cell) || $this->isCarried($cell)) {
                continue;
            }

            if ($totalWords && $this->sectionMarker(['serial' => $cell, 'code' => '', 'description' => '']) !== null) {
                continue;
            }

            return true;
        }

        return false;
    }

    /**
     * Words that are a total's and nothing else: "Total", "Sub Total (A)", "Total Qty", "Total quantity of
     * excavation", "Grand Total", "एकूण". Not "Total station survey", an item.
     */
    private function isTotalWording(string $label): bool
    {
        $text = trim(mb_strtolower($label));

        return preg_match('/^(sub\s*-?\s*total|grand\s*total|net\s*total|page\s*total|total)(\s*(qty|qnty|quantity|quantities|amount|amt|cost|value|of|for|in|carried|c\/f|b\/f|rs\.?|₹)\b|\s*[:(\-–.=]|\s*$)/u', $text) === 1
            || preg_match('/^(एकूण|कुल|योग|बेरीज|उप\s*योग|महायोग)(\s|$|[:.(])/u', $text) === 1;
    }

    private function openSection(int $number, array $row, string $marker, ?string $amount): void
    {
        $this->closeSpec();
        $this->closeSectionCheck();
        $text = $row['description'];
        $name = trim((string) preg_replace('/^(?:(?:part|section|bill\s*no\.?|schedule)\s*[-:.]?\s*)?'.preg_quote($marker, '/').'\s*[.):\-]\s*/iu', '', $text), " :-\t");
        $this->section = Str::limit($name !== '' ? $name : __('Section :marker', ['marker' => $marker]), 120, '');
        $this->marker = $marker;
        $this->subheading = null;
        $this->headNumber = null;
        $this->lastBase = null;
        $this->running = 0;
        $this->lastLine = null;

        if ($amount !== null) {
            $this->sectionCheck = ['row' => $number, 'label' => $this->section, 'amount' => $amount, 'from' => $this->linesSum];
        }

        $this->kind($number, 'section', $text !== '' ? $text : $marker, null);
    }

    /** A section row's own total, checked against the lines of the section, once the section has ended. */
    private function closeSectionCheck(): void
    {
        $check = $this->sectionCheck;
        $this->sectionCheck = null;

        if ($check === null) {
            return;
        }

        $sum = $this->linesSum->minus($check['from']);
        $this->totals[] = [
            'row' => $check['row'], 'label' => $check['label'], 'kind' => 'section', 'amount' => $check['amount'], 'adjusted' => false,
            'lines' => Dec::money($sum), 'section' => Dec::money($sum), 'with_adjustments' => Dec::money($sum), 'matches' => ! $this->differs($sum, Dec::of($check['amount'])),
        ];
    }

    private function line(int $number, array $row, array $columns, ?string $fallback): void
    {
        if ($this->refused($number, $row)) {
            return;
        }

        $lettered = $this->lettered($row['description']);
        $spec = $this->spec;
        $child = $spec !== null && $this->isChild($row, $spec, $lettered);

        // A lettered row with a quantity and no rate under a line explains that line, as in an estimate.
        if (! $child && $lettered !== null && $this->lastLine !== null && $row['rate'] === null && $row['amount'] === null && $row['unit'] === '' && $row['qty_unit'] === null && ! $row['rate_only']) {
            $this->made[$this->lastLine]['parts'][] = $this->part(['description' => $row['description'], 'qty' => $row['qty']]);
            $this->kind($number, 'part', $row['description'], __('Explains the line above. Kept in its details.'));

            return;
        }

        if (! $child) {
            $this->closeSpec($row);
            $spec = null;
        }

        $this->leaveHeading($row);

        ['qty' => $qty, 'rate' => $rate, 'amount' => $amount] = $row;
        $kind = 'line';
        $notes = [];
        $unitText = $row['unit'];
        $qtyText = CellValues::text($row['qty_raw']);

        if ($row['qty_unit'] !== null) {
            $columnUnit = CellValues::unit($unitText);

            if ($columnUnit === null) {
                $unitText = $row['qty_unit'];
            } elseif ($columnUnit !== $row['qty_unit']) {
                $notes[] = __('The quantity cell says :text but the unit column says :unit. The unit column was kept.', ['text' => $qtyText, 'unit' => $columnUnit]);
            }

            if ($row['qty_unit'] === 'LS' && CellValues::knownUnit($qtyText) === 'LS') {
                $notes[] = __('The quantity cell says :text. It was read as 1 lump sum.', ['text' => $qtyText]);
            }
        }

        // A unit cell such as "100 Sqm" says what the rate is for, as a "Per" column does.
        $per = $row['per_raw'];

        if (! isset($columns['per']) && ($both = CellValues::quantityWithUnit($unitText)) !== null && $both[1] !== 'LS') {
            $unitText = $both[1];
            $per = Dec::of($both[0])->isEqualTo(1) ? null : $both[0].' '.$both[1];
        }

        if ($row['rate_only']) {
            $kind = 'rate_only';
            $qty = '0';
            $rate ??= '0';
        } elseif ($qty === null && $rate !== null && ($amount === null || Dec::of($amount)->isZero())) {
            $kind = 'rate_only';
            $qty = '0';
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
            $this->unpriced[] = $number;

            if ($row['rate_text'] !== null) {
                $notes[] = __('The rate cell says ":text", which is not a number. The line comes in at rate 0; fill in the rate later.', ['text' => Str::limit($row['rate_text'], 40)]);
            }
        }

        $unit = CellValues::unit($unitText) ?? ($spec !== null && $spec['unit'] !== '' ? $spec['unit'] : null) ?? $fallback ?? '';

        // A rate for 10 or 100 units, or for a quintal of a line in kg, is turned into the rate for one unit.
        if (CellValues::text($per) !== '' && $row['rate'] !== null) {
            $for = CellValues::per($per);
            $factor = $for === null ? null : $this->perFactor($for, $unit);

            if ($factor === null) {
                $this->refuse($number, $row, 'per', __('The rate is given per ":per", which does not fit the unit :unit. Leave the Per column out, or fix the row.', ['per' => CellValues::text($per), 'unit' => $unit !== '' ? $unit : '—']));

                return;
            }

            $unit = $unit !== '' ? $unit : (string) $for[1];

            if (! $factor->isEqualTo(1)) {
                $exact = Dec::of($rate)->dividedBy($factor, 6, RoundingMode::HalfUp);
                $rate = self::trimmed($exact->toScale(2, RoundingMode::HalfUp));
                $notes[] = __('The rate in the file is for :per. It was divided by :factor to give the rate per :unit.', ['per' => CellValues::text($per), 'factor' => self::trimmed($factor), 'unit' => $unit]);
            }
        }

        if ($row['qty'] !== null && $row['rate'] !== null && $amount !== null && $this->differs(Dec::of($row['qty'])->multipliedBy(Dec::of($rate)), Dec::of($amount))) {
            $this->mismatches[] = [
                'row' => $number, 'description' => $row['description'], 'qty' => $row['qty'], 'rate' => $rate, 'amount' => $amount,
                'computed' => Dec::money(Dec::of($row['qty'])->multipliedBy(Dec::of($rate))),
            ];
        }

        $letter = $lettered[0] ?? null;
        $text = $lettered[1] ?? $row['description'];
        $own = (isset($columns['code']) && $row['code'] !== '') || $row['serial'] !== '';
        $code = $this->lineCode($number, $row, $spec, $letter, isset($columns['code']), $this->numbering);
        $description = $row['description'];
        $details = [];

        if ($spec !== null) {
            if ($lettered === null && $spec['children'] === 0 && $this->wraps($spec['text'], $text)) {
                // The item's text typed over several rows, its figures on the last: one text.
                $description = rtrim($spec['text']).' '.$text;
            } else {
                $context = $spec['stage'] !== null ? $text.' ('.$spec['stage'].')' : $text;
                $description = $context.' - '.Str::limit(rtrim($spec['text'], ' :'), self::SPEC_IN_DESCRIPTION);
                $details[] = __('Specification: :text', ['text' => $spec['text']]);
            }
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
            // Made up here, from a serial number or the order of the rows, not taken from an item code in the file.
            'generated' => ! (isset($columns['code']) && $row['code'] !== '' && $code === $row['code']),
            'own_number' => $own,
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
        $this->linesSinceTotal++;
        $this->lastLine = $number;
        $this->totalsAbove = BigDecimal::zero();
        $this->kind($number, $kind, $row['description'], null);

        if ($spec !== null) {
            $this->spec['children']++;
            $this->spec['lettered'] += $lettered !== null ? 1 : 0;
            $this->spec['sum'] = $this->spec['sum']->plus($value);
        }
    }

    /**
     * A row with a quantity or rate cell that cannot be read: words, a spreadsheet error, a formula with no
     * saved value. Nothing is guessed for it. Whether it was refused.
     */
    private function refused(int $number, array $row): bool
    {
        $labels = ['qty' => __('Quantity'), 'rate' => __('Rate')];

        foreach ($row['missing'] as $field) {
            $this->refuse($number, $row, $field, __(':field is a formula with no saved value. Paste it as a value and try again.', ['field' => $labels[$field]]));
        }

        foreach (['qty' => $row['qty_text'], 'rate' => $row['rate_text']] as $field => $text) {
            if (! in_array($field, $row['missing'], true) && $text !== null && CellValues::isError($text)) {
                $this->refuse($number, $row, $field, __(':field holds the spreadsheet error :error. Fix the formula in the file and upload it again.', ['field' => $labels[$field], 'error' => $text]));
            }
        }

        if (! in_array('qty', $row['missing'], true) && $row['qty_text'] !== null && ! CellValues::isError($row['qty_text'])) {
            $this->refuse($number, $row, 'qty', __('The quantity cell says ":text", which is not a number. Write the number alone, or a number and a unit such as 12.5 cum.', ['text' => Str::limit($row['qty_text'], 40)]));
        }

        return isset($this->kinds[$number]) && $this->kinds[$number]['kind'] === 'error';
    }

    private function refuse(int $number, array $row, string $field, string $message): void
    {
        $this->problems[] = ['row' => $number, 'field' => $field, 'message' => $message];
        $this->kind($number, 'error', $row['description'] !== '' ? $row['description'] : $row['all'], $message);
    }

    /** How much a rate given per this many of this unit is divided by to be the rate per one of the line's unit. Null when the two do not fit. */
    private function perFactor(array $per, string $unit): ?BigDecimal
    {
        [$count, $perUnit] = $per;
        $count = Dec::of($count);

        if ($perUnit === null || $unit === '' || $perUnit === $unit) {
            return $count;
        }

        if (isset(self::MASS[$perUnit], self::MASS[$unit])) {
            return $count->multipliedBy(self::MASS[$perUnit])->dividedBy(self::MASS[$unit], 6, RoundingMode::HalfUp);
        }

        return null;
    }

    /**
     * Whether a row with figures belongs to the open specification: lettered or dashed, or with no number of
     * its own, or a few words that only finish the specification ("Footing", "Flooring in bath:"). Once a
     * specification's parts are lettered, a numbered row is the next item.
     */
    private function isChild(array $row, array $spec, ?array $lettered): bool
    {
        if ($lettered !== null || ($row['serial'] === '' && $row['code'] === '')) {
            return true;
        }

        if ($spec['lettered'] > 0) {
            return false;
        }

        $text = $row['description'];
        $specText = rtrim($spec['text']);
        // A short heading with no colon ("EARTH WORK", "Earth work") is finished by nothing: its numbered items are items.
        $finishable = mb_strlen($specText) >= self::SPEC_LENGTH || str_ends_with($specText, ':') || $this->startsLikeSpecification($specText);

        return mb_strlen($text) < 40 && ! $this->startsLikeSpecification($text) && ! $this->followsOn($row['serial'], $spec['serial'])
            && (($finishable && (str_ends_with($text, ':') || count(preg_split('/\s+/u', $text) ?: []) <= 3)) || ($row['code'] !== '' && $row['code'] === $spec['code']));
    }

    /**
     * Whether a number comes after another at its own level or above, not under it, in numbering by steps:
     * 1.4 or 2 after 1.3. Under it is 1.3.1. Plain numbers, as 4 after 3, say nothing.
     */
    private function followsOn(string $serial, string $after): bool
    {
        $dotted = '/^\d+(\.\d+)*$/';

        return preg_match($dotted, $serial) === 1 && preg_match($dotted, $after) === 1 && str_contains($serial.$after, '.') && ! str_starts_with($serial, $after.'.');
    }

    /** Whether a row's text goes on from the text above it: it starts in lower case or with a figure. */
    private function continues(string $text): bool
    {
        return preg_match('/^[\p{Ll}\d]/u', trim($text)) === 1;
    }

    /** Whether a line's text finishes the specification above it, typed over several rows, rather than naming a part of it. */
    private function wraps(string $spec, string $text): bool
    {
        $spec = rtrim($spec);

        return $this->continues($text) && ! str_ends_with($spec, ':')
            && (preg_match('/[.;]\s*$/u', $text) === 1 || preg_match('/\b(in|of|including|incl|with|and|for|to|on|at|by|the|a|an|or|from)\s*,?$|,$/iu', $spec) === 1);
    }

    /** The line's item code: the file's code, else the serial number, else the specification's, else the next number in order. */
    private function lineCode(int $number, array $row, ?array $spec, ?string $letter, bool $codeColumn, bool $numbered): string
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
        $code = $this->unique($base);

        // A number the row gives itself that an earlier row has too, as 1.10 typed as the number 1.1: said, not hidden.
        if ($code !== $base && (($codeColumn && $row['code'] !== '') || $row['serial'] !== '')) {
            $this->note($number, __('Item code :code is used more than once in the file. This line was given :new.', ['code' => $base, 'new' => $code]));
        }

        return $code;
    }

    private function openSpec(int $number, array $row): void
    {
        $this->spec = [
            'row' => $number, 'text' => $row['description'], 'code' => $row['code'], 'serial' => $row['serial'],
            'unit' => (string) CellValues::unit($row['unit']), 'amount' => $row['amount'], 'children' => 0, 'lettered' => 0, 'sum' => BigDecimal::zero(), 'stage' => null,
        ];
        $this->kind($number, 'spec', $row['description'], null);
    }

    /**
     * Finish the specification lines were being added to. One with no lines under it is a note, left out,
     * unless it is a short numbered heading with an item after it ($next) that is not numbered past it: then it
     * heads the items, as "1 | EARTH WORK" over 1.1 and 1.2.
     */
    private function closeSpec(?array $next = null): void
    {
        $spec = $this->spec;
        $this->spec = null;

        if ($spec === null) {
            return;
        }

        if ($spec['children'] === 0) {
            if ($next !== null && $spec['amount'] === null && ($spec['serial'] !== '' || $spec['code'] !== '') && ! $this->followsOn($next['serial'], $spec['serial'])
                && mb_strlen($spec['text']) < self::SPEC_LENGTH && ! $this->startsLikeSpecification($spec['text']) && ! str_ends_with(rtrim($spec['text']), ':')) {
                $this->subheading = Str::limit(trim($spec['text'], " :-\t"), 80, '');
                $this->headNumber = $spec['serial'] !== '' ? $spec['serial'] : $spec['code'];
                $this->headChildren = false;
                $this->kind($spec['row'], 'subheading', $spec['text'], null);

                return;
            }

            if ($spec['amount'] !== null) {
                $this->kind($spec['row'], 'skipped', $spec['text'], __('An amount with no quantity, rate or lines under it. Left out.'));
            } else {
                $this->kind($spec['row'], 'note', $spec['text'], __('Text with no figures and no lines under it. Left out.'));
            }

            return;
        }

        if ($spec['amount'] !== null && $this->differs($spec['sum'], Dec::of($spec['amount']))) {
            $this->note($spec['row'], __('The parts add up to :parts but the file shows :file for this item. The parts are taken as they are.', ['parts' => inr($spec['sum']), 'file' => inr($spec['amount'])]));
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

        if ($kind === 'total') {
            $this->closeSectionCheck();
        }

        $this->lastLine = null;

        $adjusted = $this->linesSum->plus($this->effects);
        $matches = null;

        if ($amount !== null) {
            $stated = Dec::of($amount);
            $figures = [$this->linesSum, $this->sinceTotal, $adjusted, $this->totalsAbove];

            foreach ($this->totals as $earlier) {
                if ($earlier['kind'] === 'section') {
                    continue;
                }

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
        $this->linesSinceTotal = 0;
        $this->kind($number, $kind, $label, null);
    }

    /**
     * Once every row is read: a grand total with lines after it was a part total, as "Total amount of
     * Part A" or "Estimated cost of Building Work"; below the last grand total, rows of text are left out.
     */
    private function finishTotals(): void
    {
        $lastLine = $this->made === [] ? 0 : max(array_keys($this->made));
        $grand = null;

        foreach ($this->totals as $index => $total) {
            if ($total['kind'] !== 'total') {
                continue;
            }

            if ($total['row'] < $lastLine) {
                $this->totals[$index]['kind'] = 'subtotal';
                $this->kinds[$total['row']]['kind'] = 'subtotal';
            } else {
                $grand ??= $total['row'];
            }
        }

        if ($grand === null) {
            return;
        }

        foreach ($this->kinds as $row => $kind) {
            if ($row > $grand && in_array($kind['kind'], self::TEXT_KINDS, true)) {
                $this->kinds[$row] = ['kind' => 'after_total', 'text' => $kind['text'], 'reason' => __('Below the total of the sheet. Left out.')];
            }
        }
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

    /** "Round off", "Add GST", "Labour welfare cess", "Less: discount": an amount added to or taken off the work. */
    private function amountAdjustment(int $number, string $label, string $amount): void
    {
        $this->closeSpec();
        $this->lastLine = null;

        $effect = Dec::of($amount);

        if ($effect->isPositive() && $this->takesOff($label)) {
            $effect = $effect->negated();
        }

        $this->effects = $this->effects->plus($effect);
        $this->adjustments[] = [
            'row' => $number, 'label' => $label, 'percent' => null, 'base' => Dec::money($this->linesSum),
            'effect' => Dec::money($effect), 'amount' => $amount, 'shows' => 'amount',
        ];
        $this->kind($number, 'adjustment', $label, null);
    }

    /**
     * Whether the percentage is what the row is about, as in "Add 6% contingencies", "-5% below DSR",
     * "GST @ 18%" or "Water supply 3%", and not a figure inside an item's text, as in "Plaster with 2%
     * waterproofing compound". An amount that is that share of the lines also says so.
     */
    private function percentIsSubject(string $label, string $percent, ?string $amount, bool $numbered): bool
    {
        $text = trim(CellValues::asciiDigits($label));

        // It opens with the adjustment.
        if (preg_match('/^(add|less|deduct|deduction|minus|plus|[-+−–])|^[-+]?\s*\d+(\.\d+)?\s*(%|percent|per\s*cent)/iu', $text) === 1) {
            return true;
        }

        // Its amount is that share of the lines.
        if ($amount !== null) {
            $effect = $this->linesSum->multipliedBy(Dec::of($percent))->dividedBy(100, 2, RoundingMode::HalfUp);

            if (! $this->linesSum->isZero() && (! $this->differs($effect->abs(), Dec::of($amount)->abs()) || ! $this->differs($this->linesSum->plus($this->effects)->plus($effect), Dec::of($amount)))) {
                return true;
            }
        }

        // A specification's words around a percentage say nothing: a long text, one that ends in a colon for the
        // parts below it, or a numbered row, or one with parts below it, with no figures ("Plaster ... with
        // compound @ 2% by weight of cement:").
        if (mb_strlen($text) >= self::SPEC_LENGTH || str_ends_with($text, ':') || ($numbered && $amount === null)) {
            return false;
        }

        if (preg_match('/@\s*\d/u', $text) === 1
            || preg_match('/\b(contingenc\w*|escalation|gst|cgst|sgst|igst|tax|taxes|cess|overheads?|profit|supervision|rebate|discount|below|above|insurance|welfare|premium|work\s*charged?)\b|आकस्मिक|जीएसटी|वजा|कमी|अधिक|जादा|घटा|जोड़|जोडा/iu', $text) === 1) {
            return true;
        }

        return preg_match('/\d\s*(%|percent|per\s*cent|टक्के|प्रतिशत)\s*\)?\.?\s*$/iu', $text) === 1 && count(preg_split('/\s+/u', $text) ?: []) <= 6;
    }

    /**
     * "Add GST", "Round off", "Labour welfare cess", "Less: discount": an amount added to or taken off the work.
     * The words open the row, or, on a short row with no number of its own, stand anywhere in it. An item that
     * only mentions tax, as "Provision for inspection charges inclusive of GST", is not one.
     */
    private function isAmountAdjustment(string $label, bool $numbered): bool
    {
        $text = trim($label);

        if (preg_match('/^(add|less|deduct)\b|^(round(ed|ing)?\s*-?\s*off|gst|cgst|sgst|igst|tax|taxes|cess|discount|rebate|contingenc\w*)\b|^(पूर्णांक|जीएसटी|सूट|वजावट)/iu', $text) === 1) {
            return true;
        }

        return ! $numbered && count(preg_split('/\s+/u', $text) ?: []) <= 6
            && preg_match('/round(ed|ing)?\s*-?\s*off|\b(gst|cgst|sgst|igst|tax|taxes|cess|discount|rebate|contingenc\w*)\b|पूर्णांक|जीएसटी|सूट|वजावट/iu', $text) === 1;
    }

    private function takesOff(string $label): bool
    {
        return preg_match('/\b(less|below|rebate|discount|deduct|deduction|minus|reduction)\b|कमी|वजा|घटा|सूट|वजावट|कम\s/iu', $label) === 1;
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
        $less = $minus || $this->takesOff($label);
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

    /** A label that is a total word and nothing else: "Total", "Sub Total", "Grand Total", "एकूण". */
    private function isBareTotal(string $label): bool
    {
        return preg_match('/^(sub\s*-?\s*total|grand\s*total|net\s*total|total|एकूण|कुल|योग|बेरीज|उप\s*योग|महायोग)\s*[:.\-–]?\s*(rs\.?|₹)?\s*$/iu', trim($label)) === 1;
    }

    private function isCarried(string $label): bool
    {
        return preg_match('/^(total\s+)?(carried|brought)\s*(forward|fwd|over|down)|^(total\s+)?[cb]\s*\/\s*[fo]\b|^[cb]\.\s*f\.|\b[cb]\s*\/\s*f\.?$|पुढे\s+नेले|मागील\s+पान|आगे\s+ले\s+जाया|पिछले\s+पृष्ठ/iu', trim($label)) === 1;
    }

    /**
     * An amount carried to or brought from another page or the summary, in any wording: "Carried to next
     * page", "Amount carried to summary", "Amount c/o to next page", "पुढील पानावर नेलेली रक्कम", "अगले पृष्ठ
     * पर ले जाया गया". The words name the page or the summary; work "carried out" or "brought to site" is not one.
     */
    private function isCarriedElsewhere(string $label): bool
    {
        $text = trim($label);

        return preg_match('/\b[cb]\s*\/\s*[fo]\b|\b[cb]\.\s*[fo]\.|\b(next|previous|preceding|following|last)\s+page\b|\b(to|from)\s+(the\s+)?(general\s+)?(summary|abstract|collection)\b'
            .'|पुढील\s+(पान|पृष्ठ)|मागील\s+(पान|पृष्ठ)|अगले\s+(पृष्ठ|पेज|पन्ने)|पिछले\s+(पृष्ठ|पेज|पन्ने)|(नेलेली|आणलेली|ले\s+जाया|लाया\s+गया).*(गोषवार|सारांश)|(गोषवार|सारांश).*(नेलेली|आणलेली|ले\s+जाया|लाया\s+गया)/iu', $text) === 1
            || preg_match('/^(amount\s+|total\s+)?(carried|brought|transferred)(\s+(over|forward|fwd|down|on))?\s*$/iu', $text) === 1;
    }

    /** "Rupees nineteen lakh ... only" and the like: an amount written in words. */
    private function isWords(string $text): bool
    {
        $lower = mb_strtolower($text);

        if (preg_match('/in\s+words|अक्षरी|शब्दात|शब्दों\s+में/u', $lower) === 1) {
            return true;
        }

        $numberWords = preg_match('/\b(lakh|lakhs|lac|lacs|crore|crores|thousand|hundred)\b|लाख|हजार|हज़ार|कोटी|करोड|शंभर|सौ/u', $lower) === 1;

        return $numberWords && preg_match('/\bonly\b|\brupees\b|^rs\.?\s|^inr\s|^say\b|फक्त|मात्र|केवल|रुपये/u', $lower) === 1;
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

    /**
     * Whether a row is the heading printed again, as at the top of a later page: the heading as read, or,
     * for a heading on two or three rows, one of its rows as printed.
     *
     * @param  array<int, string>  $headings
     * @param  list<array<int, string>>  $headingRows
     */
    private function repeatsHeading(array $cells, array $headings, array $headingRows): bool
    {
        $same = 0;

        foreach ($headings as $column => $heading) {
            $same += BoqHeadings::normalise(CellValues::text($cells[$column] ?? null)) === $heading ? 1 : 0;
        }

        if ($same >= 2 && $same >= count($headings) - 1) {
            return true;
        }

        $texts = array_filter(array_map(fn ($value) => BoqHeadings::normalise(CellValues::text($value)), $cells));

        foreach ($headingRows as $printed) {
            if ($printed === [] || $texts === [] || array_diff_assoc($texts, $printed) !== []) {
                continue;
            }

            if ((count($texts) >= 2 || count($printed) === 1) && count($texts) >= count($printed) - 1) {
                return true;
            }
        }

        return false;
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
            if ($total['kind'] !== 'section' && ! $total['adjusted'] && $total['amount'] !== null && ($largest === null || Dec::of($total['amount'])->isGreaterThan(Dec::of($largest)))) {
                $largest = $total['amount'];
            }
        }

        return $largest;
    }

    /** The sheet's grand total: the last total row, when it comes after the lines and adjustments. */
    private function fileTotal(): ?string
    {
        foreach (array_reverse($this->totals) as $total) {
            if ($total['kind'] !== 'section' && $total['amount'] !== null && ($total['kind'] === 'total' || $total['adjusted'])) {
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

        if ($this->unpriced !== []) {
            $rows = implode(', ', array_slice($this->unpriced, 0, 30)).(count($this->unpriced) > 30 ? ', …' : '');
            $this->note(0, __(':count lines have a quantity but no rate (rows :rows). They are read at rate 0; fill in the rates later.', ['count' => count($this->unpriced), 'rows' => $rows]));
        }

        if ($this->mismatches !== []) {
            $this->note(0, __(':count lines have an amount that is not quantity times rate. The quantity and rate were kept as the file gives them.', ['count' => count($this->mismatches)]));
        }

        $subtotal = $this->fileSubtotal();

        if ($subtotal !== null && $this->differs($this->linesSum, Dec::of($subtotal))) {
            $this->note(0, __('The lines add up to :lines but the total written in the file is :file. The lines are taken as they are, so please check that total.', ['lines' => inr($this->linesSum), 'file' => inr($subtotal)]));
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

    /** A figure without the zeros after its decimal point: 4.50 as 4.5, 100.000000 as 100. */
    private static function trimmed(BigDecimal $value): string
    {
        $text = (string) $value;

        return str_contains($text, '.') ? rtrim(rtrim($text, '0'), '.') : $text;
    }

    /** Amounts in a sheet are rounded. A rupee, or half a paisa in every rupee, is not a difference. */
    protected function differs(BigDecimal $first, BigDecimal $second): bool
    {
        $allowed = $second->abs()->multipliedBy('0.0005');

        return $first->minus($second)->abs()->isGreaterThan($allowed->isLessThan(1) ? BigDecimal::one() : $allowed);
    }
}
