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
 * - spec: a specification with no figures whose lettered, roman, dashed, dotted (13.1 over 13.1.1) or
 *   unnumbered rows below carry them. Those child lines get the specification in their details, and a
 *   specification inside another (1.3 over 1.3.1 over 1.3.1.1) keeps the outer one too; text wrapped over rows
 *   with the figures on the last one becomes one line's text. With no children it is left out as a note,
 *   unless it gives a unit: then it is an item to be priced, with no quantity and no rate.
 * - subheading: a short row with no figures, as "1 | EARTH WORK" over its items. It is added to the section
 *   name. A numbered row followed by the next number at its own level ("2 | Item deleted", then 3) is never one.
 * - part: a lettered row with no rate under a line, explaining how its quantity is made up.
 * - subtotal, total, carried (c/f, b/f, "carried to summary"), say, words (the amount in words): never
 *   lines. Totals are checked against the lines above them. A total is the sheet's total only when no
 *   line follows it; one in the middle, as "Total amount of Part A" or a "Grand Total" of part 1, is a part
 *   total. A summary block repeating the parts' totals is checked, never imported.
 * - adjustment: "Add 6% contingencies", "-5% below DSR", "Water supply 3%", and amounts such as "Round off",
 *   "Add GST", a discount, a credit or recovery. Shown, never imported. A percentage inside an item's text is not one.
 * - rate_only: a line whose quantity cell is QRO, "rate only", words with no figure, or blank with a rate.
 *   Imported with quantity 0; no quantity is ever made up.
 * - error: a line whose quantity or rate cell cannot be read (a figure among words, a spreadsheet error, a
 *   formula with no saved value) or whose "Per" cannot be applied. Nothing is imported while one is left in.
 * - furniture: a page number, page heading or signature line. note: a note, condition, or the tenderer's quote.
 * - excluded: a row the person left out on the column check.
 * - note, skipped, after_total, column_numbers, heading_repeat: left out, each with a reason.
 *
 * A quantity written with its unit ("12.5 Cum", "L.S.") gives both. A rate given per 10 or 100 units, or
 * per quintal, is turned into the rate per unit, and the line keeps what the file said ("per 100 Sqm").
 * Quantity times rate is checked against the sheet's amount and every mismatch is listed. The figures are
 * imported as the sheet gives them; nothing is quietly corrected.
 *
 * Item codes are text: 21.30 is not 21.3, 011010 keeps its zero, 13.48A keeps its letter. A line's code is the
 * file's own (its code or serial column, a section's letter or a numbered heading before it, or a lettered or
 * roman part under its own number: 2a, 2(ii)) or one made up here (a dash or unmarked part: 2(1); a row with no
 * number: R12; a sheet with no numbers: 1, 2, 3), and each line says which.
 */
final class BoqSheetRows extends EstimateSheet
{
    /** Kinds that become BOQ lines. */
    public const LINE_KINDS = ['line', 'rate_only'];

    /** Kinds that are left out of the BOQ and used for nothing: what the preview counts as rows left out. */
    public const LEFT_OUT_KINDS = ['skipped', 'after_total', 'note', 'column_numbers', 'heading_repeat', 'excluded', 'furniture'];

    /** Text this long, with no figures, is a specification rather than a heading. */
    private const SPEC_LENGTH = 60;

    /** How much of the specification a child line's description repeats. All of it is in the details. */
    private const SPEC_IN_DESCRIPTION = 150;

    private const MAX_DESCRIPTION = 2000;

    /** Kinds a row with words and no figures is given, which a grand total above it makes "after_total". */
    private const TEXT_KINDS = ['section', 'spec', 'subheading', 'note', 'skipped', 'part', 'furniture'];

    /** Mass units and how many kilograms each is: a rate per quintal is turned into one per kg. */
    private const MASS = ['kg' => 1, 'qtl' => 100, 'MT' => 1000];

    /** Words that say an item was taken out of the BOQ. */
    private const DELETED = '/\b(item\s+)?(deleted|omitted|dropped|cancell?ed|withdrawn)\b|^\s*(not\s+(used|required|applicable)|n\.?\s?a\.?)\s*$|रद्द|वगळल/iu';

    private ?string $section = null;

    private ?string $marker = null;

    private ?string $subheading = null;

    /** The number of the numbered heading the sub-heading came from, as 1 for "1 | EARTH WORK": item 2 is past it. */
    private ?string $headNumber = null;

    /** Whether items under that heading are numbered from it, as 1.1 and 1.2: then 2 is past it, not one of them. */
    private bool $headChildren = false;

    /** Whether the sheet numbers its items afresh under each heading (a plain number used twice): then a numbered heading's number goes before them. */
    private bool $restarts = false;

    /** @var array{row: int, label: string, amount: string, from: BigDecimal}|null A section row's own total, checked when the section ends. */
    private ?array $sectionCheck = null;

    /** @var array<string, mixed>|null The open specification, with the specifications it sits inside in "parents". */
    private ?array $spec = null;

    private ?int $lastLine = null;

    private int $running = 0;

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

    /** @var array<string, int> Field => column, as the sheet is read. */
    private array $columns = [];

    private ?string $fallback = null;

    /** @var array<int, array<int, true>> Formula cells with no saved value. */
    private array $missing = [];

    /** @var array<int, array<int, string>> Number cells as the sheet shows them, where that keeps a code's zeros. */
    private array $texts = [];

    /** @var array<int, array{code: string, from: string}> Sheet row => an item code whose last zero a spreadsheet dropped, put back. */
    private array $restored = [];

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

    /** @var list<int> Rows of items with neither a quantity nor a rate, to be priced. */
    private array $toPrice = [];

    /** @var array<string, array{unit: string, rows: list<int>, how: string}> Unit spellings read with less than full certainty. */
    private array $unitsToCheck = [];

    /** @var array<int, string> Rows of lines that are provisional sums ("provisional") or items outside the schedule ("outside"). */
    private array $flagged = [];

    /**
     * $options holds the formula cells with no saved value ("missing"), the sheet's merged ranges ("merges"),
     * the rows the person left out ("skip") and number cells as the sheet shows them ("texts").
     *
     * @param  array<int, array<int, mixed>>  $grid  Sheet row => column => value.
     * @param  ?string  $unit  The unit the person gave for lines with none.
     * @param  array{missing?: array<int, array<int, true>>, merges?: list<array{0: int, 1: int, 2: int, 3: int}>, skip?: list<int>, texts?: array<int, array<int, string>>}  $options
     * @return array{grid: array<int, array<int, ?string>>, columns: array<string, int>, header_row: int, kinds: array<int, array{kind: string, text: string, reason: ?string}>, lines: array<int, array<string, mixed>>, adjustments: list<array<string, mixed>>, totals: list<array<string, mixed>>, mismatches: list<array<string, mixed>>, notes: list<array{row: int, message: string, weight?: int}>, errors: list<array{row: int, field: ?string, message: string}>, lines_total: string, adjustments_total: string, with_adjustments: string, file_subtotal: ?string, file_total: ?string}
     */
    public function classify(array $grid, SheetLayout $layout, ?string $unit = null, array $options = []): array
    {
        $this->linesSum = $this->sinceTotal = $this->effects = $this->totalsAbove = BigDecimal::zero();
        $this->missing = $options['missing'] ?? [];
        $this->texts = $options['texts'] ?? [];
        $columns = $layout->columns();
        $this->descriptionColumns = $layout->columnsOf('description');
        $this->numbering = isset($columns['serial']) || isset($columns['code']);
        $fallback = $layout->headingUnit() ?? CellValues::unit($unit);
        $this->columns = $columns;
        $this->fallback = $fallback;
        $grid = $this->unmerge($grid, $options['merges'] ?? [], $layout->lastRow(), array_values(array_intersect_key($columns, array_flip(['qty', 'rate', 'amount']))));
        $headings = array_filter(array_map(fn (string $text) => BoqHeadings::normalise($text), $layout->headings));
        $headingRows = [];

        for ($number = $layout->row ?? 1; $layout->span > 1 && $number <= $layout->lastRow(); $number++) {
            $headingRows[] = array_filter(array_map(fn ($value) => BoqHeadings::normalise(CellValues::text($value)), $grid[$number] ?? []));
        }

        $skip = array_flip(array_map('intval', $options['skip'] ?? []));
        $numbers = array_values(array_filter(array_keys($grid), fn (int $number) => $number > $layout->lastRow()));
        $this->restarts = $this->numberedAfresh($grid, $numbers, $columns);
        $this->restored = $this->droppedZeros($grid, $numbers, $columns);
        $detector = new BoqHeadings;

        if (BoqHeadings::isMeasurement($layout->headings)) {
            $this->problems[] = ['row' => 0, 'field' => null, 'message' => __('This is a measurement sheet: it has length, breadth and depth or height columns. Measurement rows are never BOQ lines. Import the abstract, which gives each item\'s quantity: choose its sheet above, or upload it.')];
        }

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

                if (isset($this->texts[$top][$left])) {
                    $this->texts[$target][$left] = $this->texts[$top][$left];
                }
            }

            // The first row has no figures: what else is typed on it, as the serial number, goes with the text.
            foreach ($grid[$top] ?? [] as $column => $value) {
                if (CellValues::text($grid[$target][$column] ?? null) === '') {
                    $grid[$target][$column] = $value;
                    unset($grid[$top][$column]);

                    if (isset($this->texts[$top][$column])) {
                        $this->texts[$target][$column] = $this->texts[$top][$column];
                    }
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

    /** Whether a plain number in the serial column is used twice: the items are numbered afresh under each heading. */
    private function numberedAfresh(array $grid, array $numbers, array $columns): bool
    {
        if (! isset($columns['serial'])) {
            return false;
        }

        $seen = [];

        foreach ($numbers as $number) {
            $serial = self::codeText(CellValues::asciiDigits(CellValues::text($grid[$number][$columns['serial']] ?? null)));

            if (preg_match('/^\d+$/', $serial) === 1) {
                if (isset($seen[$serial])) {
                    return true;
                }

                $seen[$serial] = true;
            }
        }

        return false;
    }

    /**
     * Item codes typed as numbers whose last zero the spreadsheet dropped, put back from the codes around them:
     * 21.3 between 21.29 and 21.31 is 21.30, 2.1 between 2.09 and 2.11 is 2.10. Only a number cell is looked at;
     * a code typed as text is read exactly as it is.
     *
     * @return array<int, array{code: string, from: string}>
     */
    private function droppedZeros(array $grid, array $numbers, array $columns): array
    {
        $restored = [];

        foreach (['code', 'serial'] as $field) {
            if (! isset($columns[$field])) {
                continue;
            }

            $column = $columns[$field];
            $codes = [];

            foreach ($numbers as $number) {
                $value = $grid[$number][$column] ?? null;

                if (is_int($value) || is_float($value)) {
                    $codes[] = [$number, $this->texts[$number][$column] ?? CellValues::text($value)];
                }
            }

            foreach ($codes as $index => [$number, $code]) {
                if (! isset($codes[$index - 1], $codes[$index + 1]) || preg_match('/^(\d+)\.(\d+)$/', $code, $own) !== 1) {
                    continue;
                }

                if (preg_match('/^(\d+)\.(\d+)$/', $codes[$index - 1][1], $before) !== 1 || preg_match('/^(\d+)\.(\d+)$/', $codes[$index + 1][1], $after) !== 1) {
                    continue;
                }

                $length = strlen($before[2]);

                if ($before[1] !== $own[1] || $after[1] !== $own[1] || strlen($after[2]) !== $length || strlen($own[2]) >= $length) {
                    continue;
                }

                $padded = str_pad($own[2], $length, '0');

                if ((int) $padded === (int) $before[2] + 1 && (int) $padded === (int) $after[2] - 1) {
                    $restored[$number] = ['code' => $own[1].'.'.$padded, 'from' => $code, 'field' => $field];
                }
            }
        }

        return $restored;
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

            // A code or serial number as the sheet shows it: 21.30 with its zero, as text.
            if (in_array($field, ['serial', 'code'], true) && isset($columns[$field], $this->texts[$number][$columns[$field]])) {
                $value = $this->texts[$number][$columns[$field]];
            }

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

        // A quantity cell that is not a plain number: the number with its unit, a rate-only mark, words, or something unreadable.
        $row['qty_unit'] = null;
        $row['qty_text'] = null;
        $row['qty_words'] = null;
        $qtyText = CellValues::text($row['qty_raw']);

        if ($row['qty'] === null && $qtyText !== '' && ! CellValues::isRateOnly($row['qty_raw'])) {
            if (($both = CellValues::quantityWithUnit($row['qty_raw'])) !== null) {
                [$row['qty'], $row['qty_unit']] = $both;
            } elseif (preg_match('/[0-9०-९]/u', $qtyText) !== 1 && ! CellValues::isError($qtyText)) {
                // Words with no figure, as "As reqd." or "Nil": no quantity is given, so none is made up.
                $row['qty_words'] = $qtyText;
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

        $row['unit_reading'] = $row['unit'] === '' ? null : CellValues::unitReading($row['unit']);
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

        $row['serial'] = self::codeText(CellValues::asciiDigits($row['serial']));
        $row['code'] = self::codeText($row['code']);

        if (isset($this->restored[$number])) {
            $row[$this->restored[$number]['field']] = $this->restored[$number]['code'];
        }

        $row['all'] = implode(' ', $texts);
        // Rate only when the quantity cell says so (or gives words and no figure), or is blank beside a rate-only mark. A quantity in the file is never dropped.
        $row['rate_only'] = CellValues::isRateOnly($row['qty_raw']) || $row['qty_words'] !== null
            || ($row['qty'] === null && $row['qty_text'] === null && (CellValues::isRateOnly($row['rate_raw']) || CellValues::isRateOnly($row['amount_raw']) || CellValues::isRateOnly($row['unit_raw'])));
        $row['figures'] = $row['qty'] !== null || $row['rate'] !== null || $row['amount'] !== null || $row['rate_only'] || $row['qty_text'] !== null || $row['missing'] !== [];

        return $row;
    }

    /** An item code as text: dashes alike, no stray spaces around its dots, no dot at its end ("17.28.1 ." is 17.28.1). */
    private static function codeText(string $code): string
    {
        $code = (string) preg_replace('/[\x{2010}-\x{2013}\x{2212}]/u', '-', $code);
        $code = (string) preg_replace('/\s*\.\s*/u', '.', trim($code));

        return trim(rtrim($code, '.'));
    }

    private function row(int $number, array $row, ?array $next, array $columns, ?string $fallback): void
    {
        $label = $row['description'] !== '' ? $row['description'] : $row['all'];
        $priced = $row['qty'] !== null && $row['rate'] !== null;
        $amountOnly = $row['qty'] === null && $row['rate'] === null && $row['qty_text'] === null && $row['amount'] !== null && ! $row['rate_only'];
        $unit = $row['unit_reading']['unit'] ?? null;
        $known = $unit !== null && $row['unit_reading']['how'] !== 'raw';
        $totalWords = $this->isTotalWording($label);
        // A number of its own or a unit makes a row an item, whatever its words say: "Testing of samples brought to
        // site", "Provision for inspection inclusive of GST", "Total station survey". Not "A | Sub Total (A)".
        $numbered = $this->ownNumber($row, $totalWords);
        $ownUnit = $known && $unit !== '%';
        // What makes a row a line and not a total: its own number, a rate, a rate-only mark, or a unit or quantity
        // beside words that are not a total's ("Total Qty | Cum | 120" is the quantities added up).
        $lineLike = $numbered || $row['rate_only'] || $row['rate'] !== null || $row['qty_text'] !== null || (($ownUnit || $row['qty'] !== null) && ! $totalWords);
        // Nothing but words, and perhaps an amount: what a footer, a note or a page line is.
        $wordsOnly = $row['qty'] === null && $row['qty_text'] === null && $row['qty_words'] === null && $row['rate'] === null && ! $row['rate_only'] && ! $ownUnit && $row['missing'] === [];

        if (! $priced && preg_match('/^(or\s+)?say\b/iu', $label) === 1) {
            $this->kind($number, 'say', $label, __('A rounded figure. Not a line.'));

            return;
        }

        if ($wordsOnly && $row['amount'] === null && ($footer = $this->footerLine($label, $row)) !== null) {
            $this->kind($number, $footer[0], $label, $footer[1]);

            return;
        }

        if (! $priced && $this->isWords($label)) {
            $this->kind($number, 'words', $label, __('The amount written in words.'));

            return;
        }

        if (! $priced && ! $numbered && $this->isCarried($label)) {
            $this->kind($number, 'carried', $label, __('Carried or brought forward between pages. Not a line.'));

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

        // A summary of the parts after their totals, as "Part A | Civil works | 12,34,567": each part's total again.
        if ($amountOnly && ! $ownUnit && $this->repeatsATotal($row['amount'])) {
            $this->total($number, $label, 'subtotal', $row['amount']);
            $this->kinds[$number]['reason'] = __('A total of a part repeated in a summary. Not a line.');

            return;
        }

        if ($wordsOnly && ($footer = $this->footerLine($label, $row)) !== null) {
            $this->kind($number, $footer[0], $label, $footer[1]);

            return;
        }

        if (($row['qty'] === null || Dec::of($row['qty'])->isZero()) && ($row['rate'] === null || Dec::of($row['rate'])->isZero())
            && (preg_match(self::DELETED, $row['description']) === 1 || preg_match(self::DELETED, (string) $row['qty_words']) === 1) && mb_strlen($row['description']) < 80) {
            $this->kind($number, 'note', $label, __('The file marks this item as deleted. Left out.'));

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
        $notLine = $row['qty'] === null && $row['qty_text'] === null && ! $row['rate_only'] && (! $known || $percentUnit);

        // A numbered "Add GST difference @ 12% on DSR" row, with the percentage as its rate: an adjustment, not work.
        if ($percent !== null && preg_match('/\bgst\b.*\bdifference\b|\bdifference\b.*\bgst\b/iu', $label) === 1) {
            $this->adjustment($number, $label, $percent, $row['amount'] ?? ($row['qty'] !== null && $row['rate'] !== null ? (string) Dec::of($row['qty'])->multipliedBy(Dec::of($row['rate'])) : null));

            return;
        }

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

        // A credit or recovery ("Recovery of Bricks"), or any row with a figure below zero: taken off, never a line.
        if ($this->isTakenBack($label, $row)) {
            $this->takenBack($number, $label, $row);

            return;
        }

        // An amount alone, with no number of its own, that is what the lines above come to: a total, checked.
        if ($amountOnly && $row['serial'] === '' && $row['code'] === '' && ! $ownUnit && $this->isTheLinesFigure($label, Dec::of($row['amount']))) {
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
        $unit = $row['unit_reading'];
        $ownUnit = $unit !== null && $unit['how'] !== 'raw' && $unit['unit'] !== '%';

        if (! $ownUnit && ($marker = $this->sectionMarker($row)) !== null) {
            $this->openSection($number, $row, $marker, null);

            return;
        }

        if ($text === '') {
            $this->kind($number, 'skipped', $row['all'], __('No description and no figures. Left out.'));

            return;
        }

        $numbered = $row['serial'] !== '' || $row['code'] !== '';
        $short = mb_strlen($text) < self::SPEC_LENGTH && ! $numbered;
        $marked = $this->childMarker($row);

        // Text going on from the specification above, before any line of it: the same specification.
        if ($this->spec !== null && $this->spec['children'] === 0 && ! $this->spec['nested'] && ! $numbered && ($short || $this->numbering) && $marked === null) {
            $this->spec['text'] = $this->continues($text)
                ? rtrim($this->spec['text']).' '.trim($text)
                : rtrim($this->spec['text'], ' :').' - '.trim($text, ' :');

            if ($ownUnit && $this->spec['unit'] === '') {
                $this->spec['unit'] = $unit['unit'];
            }

            $this->kind($number, 'spec', $text, __('Part of the specification above.'));

            return;
        }

        // A lettered or roman row with no figures inside a specification: a stage of it, whose parts follow, as
        // "(b) Columns:" over "(i) ground floor" and "(ii) first floor".
        if ($this->spec !== null && $marked !== null && ! $ownUnit && in_array($marked['type'], ['letter', 'roman'], true)) {
            $this->spec['stage'] = trim($marked['rest'] !== '' ? $marked['rest'] : $text, ' :');
            $this->spec['stageMark'] = $marked['type'] === 'letter' ? $marked['mark'] : null;
            $this->spec['stageChildren'] = 0;
            $this->spec['lastMark'] = $marked;
            $this->kind($number, 'subheading', $text, __('A part of the specification above, whose lines follow.'));

            return;
        }

        if ($this->spec !== null && $short && $marked === null && ! $ownUnit) {
            $this->spec['stage'] = trim($text, ' :');
            $this->spec['stageMark'] = null;
            $this->spec['stageChildren'] = 0;
            $this->kind($number, 'subheading', $text, null);

            return;
        }

        // A numbered row with no figures is an item whose parts follow, however short its text; so is one that
        // gives a unit, or one with lettered parts below it. With none, a short one is the heading of the items
        // after it ("1 | EARTH WORK" over 1.1, 1.2).
        $partsBelow = $next !== null && $next['figures'] && $this->childMarker($next) !== null;

        if (! $short || $ownUnit || $partsBelow || $this->startsLikeSpecification($text)) {
            $this->closeSpec($row, $this->parentFor($row));
            $this->leaveHeading($row);
            $this->openSpec($number, $row, $this->spec);

            return;
        }

        if ($this->lastLine !== null && $this->spec === null && $marked !== null) {
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
     * The open specification, or one it sits inside, that this numbered row is a part of by its number, as 1.3.1
     * under "1.3 Cement plaster :". Null when it is not one.
     *
     * @return array<string, mixed>|null
     */
    private function parentFor(array $row): ?array
    {
        if ($this->spec === null) {
            return null;
        }

        $chain = [...$this->spec['parents'], $this->spec];

        for ($index = count($chain) - 1; $index >= 0; $index--) {
            if ($this->underByNumber($row, $chain[$index]) && $this->finishable($chain[$index]['text'])) {
                return [...$chain[$index], 'parents' => array_slice($chain, 0, $index)];
            }
        }

        return null;
    }

    /** Whether a row's number is under a specification's, as 13.1.1 under 13.1. */
    private function underByNumber(array $row, array $spec): bool
    {
        foreach ([[$row['serial'], $spec['serial']], [$row['code'], $spec['code']]] as [$own, $parent]) {
            if ($own !== '' && $parent !== '' && str_starts_with($own, $parent.'.') && strlen($own) > strlen($parent) + 1) {
                return true;
            }
        }

        return false;
    }

    /** Whether a specification's text is one that parts finish: long, ending in a colon, or worded as a specification. */
    private function finishable(string $text): bool
    {
        $text = rtrim($text);

        return mb_strlen($text) >= self::SPEC_LENGTH || str_ends_with($text, ':') || preg_match('/\b(with|in|of)\s*:?$/iu', $text) === 1 || $this->startsLikeSpecification($text);
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
            if ($cell === '' || $this->isBareTotal($cell) || $this->isCarried($cell) || $this->isTotalWording($cell)) {
                continue;
            }

            if ($totalWords && $this->sectionMarker(['serial' => $cell, 'code' => '', 'description' => '']) !== null) {
                continue;
            }

            // Words in the serial column ("Total in Figures") are not a number; a code has a figure or is a short mark.
            if (preg_match('/\d/u', CellValues::asciiDigits($cell)) !== 1 && mb_strlen($cell) > 6) {
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

        return preg_match('/^(sub\s*[-‐]?\s*total|grand\s*total|net\s*total|page\s*total|total)(\s*(qty|qnty|quantity|quantities|amount|amt|cost|value|of|for|in|carried|c\/f|b\/f|rs\.?|₹)\b|\s*[:(\-–.=]|\s*$)/u', $text) === 1
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

    private function line(int $number, array $row, array $columns, ?string $fallback, bool $toPrice = false): void
    {
        if ($this->refused($number, $row)) {
            return;
        }

        $marked = $this->childMarker($row);
        $spec = $this->spec;
        $child = $spec !== null && $this->isChild($row, $spec, $marked);

        // A lettered row with a quantity and no rate under a line explains that line, as in an estimate.
        if (! $child && $marked !== null && $marked['type'] !== 'serial' && $this->lastLine !== null && $row['rate'] === null && $row['amount'] === null && $row['unit'] === '' && $row['qty_unit'] === null && ! $row['rate_only']) {
            $this->made[$this->lastLine]['parts'][] = $this->part(['description' => $row['description'], 'qty' => $row['qty']]);
            $this->kind($number, 'part', $row['description'], __('Explains the line above. Kept in its details.'));

            return;
        }

        if (! $child) {
            // A row numbered under a specification the open one sits inside, as 1.3.2 after 1.3.1's parts, is that one's part.
            $this->closeSpec($row, $this->parentFor($row));
            $spec = $this->spec;
            $child = $spec !== null;
        }

        $this->leaveHeading($row);

        ['qty' => $qty, 'rate' => $rate, 'amount' => $amount] = $row;
        $kind = 'line';
        $notes = [];
        $details = [];
        // The figures the file does not give, read as 0 here: a line that updates an item keeps the item's own (BoqSheetImport).
        $filled = [];
        // The figures worked out here from the amount, and the unit put in with them, rather than read from the file: the
        // tender BOQ, which has no preview, keeps a line's own in their place too (TenderBoqService::import).
        $derived = [];
        // Which of the details only say that the file gives no quantity, for a line that keeps the item's to drop.
        $figureDetails = [];
        // Which of the details are text taken from the file (its details, reference or remark cell, a specification,
        // the whole description), not words of this reader: only such text replaces an item's own details (BoqSheetImport).
        $fileDetails = [];
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
                $notes[] = __('The quantity cell says ":text". It was read as 1 lump sum.', ['text' => $qtyText]);
            }
        }

        // A unit cell such as "100 Sqm" or "Dozen" says what the rate is for, as a "Per" column does.
        $per = $row['per_raw'];
        $reading = CellValues::unitReading($unitText);

        if (! isset($columns['per']) && $reading !== null && $reading['count'] !== null) {
            $unitText = $reading['unit'];
            $per = $reading['written'];
        }

        if ($toPrice) {
            $kind = 'rate_only';
            $qty = '0';
            $rate = '0';
            $filled = ['qty', 'rate'];
            $this->toPrice[] = $number;
            $details[] = __('To be priced: the file gives no quantity and no rate.');
            $figureDetails[] = array_key_last($details);
        } elseif ($row['rate_only']) {
            $kind = 'rate_only';
            $filled = $rate === null ? ['qty', 'rate'] : ['qty'];
            $qty = '0';
            $rate ??= '0';

            if ($row['qty_words'] !== null) {
                $notes[] = [__('The quantity cell says ":text". The line comes in as rate only, with quantity 0; fill in the quantity later.', ['text' => Str::limit($row['qty_words'], 40)]), 'qty'];
            }
        } elseif ($qty === null && $rate !== null && ($amount === null || Dec::of($amount)->isZero())) {
            $kind = 'rate_only';
            $qty = '0';
            $filled = ['qty'];
        } elseif ($qty === null && $rate !== null && $amount !== null && ! Dec::of($rate)->isZero()) {
            $qty = (string) Dec::of($amount)->dividedBy(Dec::of($rate), 3, RoundingMode::HalfUp);
            $derived = ['qty'];
            $notes[] = __('No quantity in the file. It was worked out as amount divided by rate.');
        } elseif ($qty === null && $rate === null && $amount !== null) {
            $qty = '1';
            $rate = $amount;
            $derived = $unitText === '' ? ['qty', 'rate', 'unit'] : ['qty', 'rate'];
            $unitText = $unitText !== '' ? $unitText : 'LS';
            $notes[] = __('Only an amount in the file. It was read as 1 lump sum at that amount.');
        } elseif ($qty !== null && $rate === null && $amount !== null && ! Dec::of($qty)->isZero()) {
            $rate = (string) Dec::of($amount)->dividedBy(Dec::of($qty), 2, RoundingMode::HalfUp);
            $derived = ['rate'];
            $notes[] = __('No rate in the file. It was worked out as amount divided by quantity.');
        } elseif ($qty !== null && $rate === null) {
            $rate = '0';
            $filled = ['rate'];
            $this->unpriced[] = $number;

            if ($row['rate_text'] !== null) {
                $notes[] = [__('The rate cell says ":text", which is not a number. The line comes in at rate 0; fill in the rate later.', ['text' => Str::limit($row['rate_text'], 40)]), 'rate'];
            }
        }

        $unit = $this->lineUnit($number, $unitText, $row['description'].' '.($spec['text'] ?? ''), $spec, $fallback);
        $fileRate = null;

        // A rate for 10 or 100 units, or for a quintal of a line in kg, is turned into the rate for one unit; the line keeps what the file said.
        if (CellValues::text($per) !== '' && $row['rate'] !== null) {
            $for = CellValues::per($per);
            $factor = $for === null ? null : $this->perFactor($for, $unit);

            if ($factor === null) {
                $this->refuse($number, $row, 'per', __('The rate is given per ":per", which does not fit the unit :unit. Leave the Per column out, or fix the row.', ['per' => CellValues::text($per), 'unit' => $unit !== '' ? $unit : '—']));

                return;
            }

            $unit = $unit !== '' ? $unit : (string) $for[1];
            $direct = $row['qty'] !== null && $amount !== null ? Dec::of($row['qty'])->multipliedBy(Dec::of($rate)) : null;

            if (! $factor->isEqualTo(1) && $direct !== null && ! $this->differs($direct, Dec::of($amount))
                && $this->differs($direct->dividedBy($factor, 6, RoundingMode::HalfUp), Dec::of($amount))) {
                // The amount is the quantity times the rate as it stands: the quantity is counted in the unit the rate is for.
                $unit = mb_substr(self::trimmed(Dec::of($for[0])).' '.($for[1] ?? $unit), 0, 16);
                $notes[] = __('The amount in the file is the quantity times the rate per :per, so the quantity is counted in :per. The line keeps the unit :unit; nothing was divided.', ['per' => CellValues::text($per), 'unit' => $unit]);
            } elseif (! $factor->isEqualTo(1)) {
                $fileRate = $rate;
                $exact = Dec::of($rate)->dividedBy($factor, 6, RoundingMode::HalfUp);
                $rate = self::trimmed($exact->toScale(2, RoundingMode::HalfUp));
                $notes[] = __('The rate in the file is for :per. It was divided by :factor to give the rate per :unit.', ['per' => CellValues::text($per), 'factor' => self::trimmed($factor), 'unit' => $unit]);
                $details[] = __('Rate in the file: :rate per :per. The line\'s rate is per :unit.', ['rate' => $fileRate, 'per' => CellValues::text($per), 'unit' => $unit]);

                if ($amount === null && $row['qty'] !== null && ! Dec::of($row['qty'])->isZero()) {
                    $notes[] = __('No amount on the row says whether the quantity is in single units or in :per; it was taken as single units. Check it.', ['per' => CellValues::text($per)]);
                }

                if (! $exact->isEqualTo(Dec::of($rate))) {
                    $notes[] = __('Divided, the rate is :exact. It was rounded to :rate, so quantity times rate can differ from the amount by a little.', ['exact' => self::trimmed($exact), 'rate' => $rate]);
                }
            }
        }

        if ($row['qty'] !== null && $row['rate'] !== null && $amount !== null && $this->differs(Dec::of($row['qty'])->multipliedBy(Dec::of($rate)), Dec::of($amount))) {
            $this->mismatches[] = [
                'row' => $number, 'description' => $row['description'], 'qty' => $row['qty'], 'rate' => $rate, 'amount' => $amount,
                'computed' => Dec::money(Dec::of($row['qty'])->multipliedBy(Dec::of($rate))),
            ];
        }

        $text = $marked !== null && $marked['type'] !== 'serial' ? $marked['rest'] : $row['description'];
        $description = $row['description'];
        $wrapped = false;

        // "-do-": the same as the line above.
        if (preg_match('/^[\s_\-–.]*(do|ditto)[\s_\-–.]*$/iu', $description) === 1 && $this->lastLine !== null) {
            $description = $text = (string) $this->made[$this->lastLine]['description'];
            $notes[] = __('The description says "-do-". The description of row :row was used.', ['row' => $this->lastLine]);
        }

        if ($spec !== null) {
            if ($marked === null && $spec['children'] === 0 && $this->wraps($spec['text'], $text)) {
                // The item's text typed over several rows, its figures on the last: one text.
                $description = rtrim($spec['text']).' '.$text;
                $wrapped = true;
            } else {
                $stage = $spec['stage'] !== null ? $text.' ('.$spec['stage'].')' : $text;
                $outer = array_map(fn (array $parent) => rtrim($parent['text'], ' :'), $spec['parents']);
                $context = implode(' - ', array_reverse([...$outer, rtrim($spec['text'], ' :')]));
                $description = $stage.' - '.Str::limit($context, self::SPEC_IN_DESCRIPTION);

                foreach ([...$outer, $spec['text']] as $level) {
                    $details[] = __('Specification: :text', ['text' => $level]);
                    $fileDetails[] = array_key_last($details);
                }
            }
        }

        if (mb_strlen($description) > self::MAX_DESCRIPTION) {
            $details[] = __('Full description: :text', ['text' => $description]);
            $fileDetails[] = array_key_last($details);
            $description = mb_substr($description, 0, self::MAX_DESCRIPTION - 1).'…';
            $notes[] = __('The description is longer than :max characters. It was shortened, and the whole of it is in the details.', ['max' => self::MAX_DESCRIPTION]);
        }

        foreach (['details' => null, 'reference' => __('Reference: :text', ['text' => $row['reference']])] as $field => $wording) {
            if ($row[$field] !== '') {
                $details[] = $wording ?? $row[$field];
                $fileDetails[] = array_key_last($details);
            }
        }

        // A schedule's item code written in the description ("10.16.1 : Steel work ...", "... (16.83)"): kept in the details, the text as written.
        if (preg_match('/^(\d{1,2}(?:\.\d{1,3}[A-Z]?){1,3})\s?:\s|\((\d{1,2}(?:\.\d{1,3}){1,3})\)\s*$/u', $row['description'], $scheduled) === 1) {
            $details[] = __('Schedule item in the description: :code', ['code' => $scheduled[1] !== '' ? $scheduled[1] : $scheduled[2]]);
        }

        [$code, $origin] = $this->lineCode($number, $row, $spec, $marked, isset($columns['code']), $wrapped);

        if (isset($columns['code']) && $row['code'] !== '' && $code !== $row['code']) {
            $details[] = __('Item code in the file: :code', ['code' => $row['code']]);
        }

        if (isset($this->restored[$number])) {
            $notes[] = __('Item code :from was read as :code, between the codes above and below it: a spreadsheet drops the last zero of a number.', ['from' => $this->restored[$number]['from'], 'code' => $this->restored[$number]['code']]);
        }

        if ($kind === 'rate_only' && ! $toPrice) {
            $details[] = __('Rate only: the file gives no quantity.');
            $figureDetails[] = array_key_last($details);
        }

        if (preg_match('/\bprovisional\s+sum\b|\bp\.?\s?c\.?\s+sum\b/iu', $description) === 1) {
            $this->flagged[$number] = 'provisional';
            $details[] = __('A provisional sum in the file.');
        } elseif (preg_match('/^NS\s?\d{1,3}$/u', $row['serial']) === 1 || preg_match('/^NS\s?\d{1,3}$/u', $row['code']) === 1 || preg_match('/\(\s*non[\s-]*(sor|schedule|psr)\s*(item)?\s*\)|\bnpsr\b|\bnon[\s-]*schedule\s+item\b/iu', $description) === 1) {
            $this->flagged[$number] = 'outside';
            $details[] = __('An item outside the schedule of rates.');
        }

        $this->made[$number] = [
            'kind' => $kind,
            'section' => $this->sectionName($row),
            'code' => $code,
            // "code": the sheet's own item code; "serial": made from its serial numbers (with a section's letter, or a part's
            // letter or numeral); "made": made up here, as for a dash or a row with no number.
            'origin' => $origin,
            'description' => $description,
            'unit' => $unit,
            'qty' => $qty,
            'rate' => $rate,
            'filled' => $filled,
            'derived' => $derived,
            'amount' => $amount,
            'per' => $fileRate === null ? null : CellValues::text($per),
            'file_rate' => $fileRate,
            'category' => BoqCategories::fromText($row['category']) ?? $this->category($description.' '.($spec['text'] ?? '')),
            // Whether the category is the one the file's category cell names, not one guessed from the words.
            'file_category' => BoqCategories::fromText($row['category']) !== null,
            'details' => $details,
            'figure_details' => $figureDetails,
            'file_details' => $fileDetails,
            'parts' => [],
            'remarks' => $row['remark'] === '' ? [] : [$row['remark']],
            'spec_row' => $spec['row'] ?? null,
        ];

        foreach ($notes as $note) {
            // A note that says a figure came in as 0 names it, so it can be taken back when the line keeps an item's figure.
            is_array($note) ? $this->notes[] = ['row' => $number, 'message' => $note[0], 'filled' => [$note[1]], 'rows' => [$number]] : $this->note($number, $note);
        }

        $value = Dec::of($qty)->multipliedBy(Dec::of($rate));
        $this->linesSum = $this->linesSum->plus($value);
        $this->sinceTotal = $this->sinceTotal->plus($value);
        $this->linesSinceTotal++;
        $this->lastLine = $number;
        $this->totalsAbove = BigDecimal::zero();
        $this->kind($number, $kind, $row['description'], null);

        if ($spec !== null && $this->spec !== null) {
            $this->spec['children']++;
            $this->spec['lettered'] += $marked !== null && $marked['type'] !== 'dash' ? 1 : 0;
            $this->spec['sum'] = $this->spec['sum']->plus($value);
            $this->spec['lastMark'] = $marked ?? $this->spec['lastMark'];
            $this->spec['stageChildren'] = ($this->spec['stageChildren'] ?? 0) + 1;
        }
    }

    /**
     * The unit of a line: its own cell read as a unit, else the specification's, else the one the heading or the
     * person gave. A spelling read with less than full certainty is noted for the page; "Mt" is read from the
     * item's own words (steel, cement or carriage in tonnes; pipes, railing or length in metres), and kept as
     * written when they do not say.
     */
    private function lineUnit(int $number, string $unitText, string $words, ?array $spec, ?string $fallback): string
    {
        $reading = CellValues::unitReading($unitText);

        if ($reading !== null && $reading['how'] === 'ask' && in_array(mb_strtolower(trim($reading['written'], ' .')), ['mt', 'mt.'], true)) {
            $tonne = preg_match('/\b(steel|cement|carriage|lead|bitumen|reinforce\w*|tmt|hysd|tor|rebar|bars?)\b/iu', $words) === 1;
            $metre = preg_match('/\b(pipes?|railings?|length|running|kerbs?|drains?|cables?|conduits?|fencing|skirting|mouldings?)\b/iu', $words) === 1;

            if ($tonne xor $metre) {
                $reading = ['unit' => $tonne ? 'MT' : 'rmt', 'count' => null, 'written' => $reading['written'], 'how' => 'shown'];
            }
        }

        if ($reading !== null && in_array($reading['how'], ['shown', 'ask'], true)) {
            $key = $reading['written']."\0".$reading['unit']."\0".$reading['how'];
            $this->unitsToCheck[$key] ??= ['written' => $reading['written'], 'unit' => $reading['unit'], 'rows' => [], 'how' => $reading['how']];
            $this->unitsToCheck[$key]['rows'][] = $number;
        }

        return $reading['unit'] ?? ($spec !== null && $spec['unit'] !== '' ? $spec['unit'] : null) ?? $fallback ?? '';
    }

    /**
     * A row with a quantity or rate cell that cannot be read: a figure among words, a spreadsheet error, a formula
     * with no saved value. Nothing is guessed for it. Whether it was refused.
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
     * Whether a row with figures belongs to the open specification: lettered, roman or dashed, numbered under it
     * (13.1.1 under 13.1), with no number of its own, or a few words that only finish the specification
     * ("Footing", "Flooring in bath:"). Once a specification's parts are lettered, a numbered row is the next item.
     */
    private function isChild(array $row, array $spec, ?array $marked): bool
    {
        if ($marked !== null || ($row['serial'] === '' && $row['code'] === '')) {
            return true;
        }

        if ($this->underByNumber($row, $spec) && $this->finishable($spec['text'])) {
            return true;
        }

        // Once a specification's parts are lettered, a numbered row is the next item; and an item to be priced, which
        // gives its own unit, takes no numbered parts.
        if ($spec['lettered'] > 0 || $spec['unit'] !== '') {
            return false;
        }

        $text = $row['description'];

        // A short heading with no colon ("EARTH WORK", "Earth work") is finished by nothing: its numbered items are items.
        // A row numbered past the specification ("5 | Sample painting" after 4) finishes it only by ending in a colon.
        $past = preg_match('/^\d+$/', $row['serial']) === 1 && preg_match('/^\d+$/', $spec['serial']) === 1 && (int) $row['serial'] > (int) $spec['serial'];

        return mb_strlen($text) < 40 && ! $this->startsLikeSpecification($text) && ! $this->followsOn($row['serial'], $spec['serial'])
            && (($this->finishable($spec['text']) && (str_ends_with($text, ':') || (! $past && count(preg_split('/\s+/u', $text) ?: []) <= 3))) || ($row['code'] !== '' && $row['code'] === $spec['code']));
    }

    /**
     * Whether a number comes after another at its own level or above, not under it, in numbering by steps:
     * 1.4 or 2 after 1.3. Under it is 1.3.1. Plain numbers, as 4 after 3, say nothing here: a sheet can number
     * every row, a specification's parts too.
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

    /**
     * The line's item code and where it came from ("file" or "made"):
     * - the file's code, else its serial number (after a section's letter, or a numbered heading's number where the
     *   items are numbered afresh under each heading: A.1, 2.1);
     * - a part of a specification: its number and the part's letter (2a, 13.48A(a)) or roman numeral (2(ii), 2b(ii)
     *   under a lettered stage), from the file; a dash, a bullet or an unmarked part, the part's place (2(1), 2(2)),
     *   made up here; the one text wrapped over rows, the specification's own number;
     * - a row with no number in a numbered sheet: R and its row (R12), made up here, never the number of the line
     *   above; a sheet with no numbers at all: 1, 2, 3 in order.
     * A code that an earlier line already has gets .1, .2, which is said when the file gave it.
     *
     * @return array{0: string, 1: string}
     */
    private function lineCode(int $number, array $row, ?array $spec, ?array $marked, bool $codeColumn, bool $wrapped): array
    {
        $origin = 'serial';

        if ($codeColumn && $row['code'] !== '') {
            $base = $row['code'];
            $origin = 'code';
        } elseif ($row['serial'] !== '' && ($marked === null || $marked['type'] !== 'serial' || $spec === null)) {
            $base = $this->prefixed($row['serial']);
        } elseif ($spec !== null && ($specBase = $this->specBase($spec)) !== null) {
            [$base, $origin] = $this->partCode($specBase, $spec, $marked, $wrapped);
        } elseif ($spec !== null && $wrapped) {
            $base = $this->prefixed((string) ++$this->running);
            $origin = 'made';
        } elseif ($this->numbering) {
            $base = 'R'.$number;
            $origin = 'made';
            $this->note($number, __('This row has no item number of its own in the file. It was given the code :code; it does not take the number of the line above.', ['code' => $base]));
        } else {
            $base = $this->prefixed((string) ++$this->running);
            $origin = 'made';
        }

        $base = mb_substr(trim($base), 0, 28);
        $code = $this->unique($base);

        if ($code !== $base) {
            // A number the row gives itself that an earlier row has too, as 1.10 typed as the number 1.1: said, not hidden.
            if ($origin !== 'made' && (($codeColumn && $row['code'] !== '') || $row['serial'] !== '')) {
                $this->note($number, __('Item code :code is used more than once in the file. This line was given :new.', ['code' => $base, 'new' => $code]));
            }

            $origin = 'made';
        }

        return [$code, $origin];
    }

    /** A specification's own number, with its section's letter, or null when it has none. */
    private function specBase(array $spec): ?string
    {
        if ($spec['code'] !== '' && isset($this->columns['code'])) {
            return $spec['code'];
        }

        return $spec['serial'] !== '' ? $this->prefixed($spec['serial']) : null;
    }

    /**
     * The code of a part of a specification numbered $base.
     *
     * @return array{0: string, 1: string}
     */
    private function partCode(string $base, array $spec, ?array $marked, bool $wrapped): array
    {
        $origin = $spec['code'] !== '' && isset($this->columns['code']) ? 'code' : 'serial';

        if ($marked === null && $wrapped) {
            return [$base, $origin];
        }

        $type = $marked === null ? null : ($marked['type'] === 'serial' ? $marked['as'] : $marked['type']);

        if ($type === 'letter') {
            $letter = mb_strtolower($marked['mark']);

            return [preg_match('/\d$/u', $base) === 1 ? $base.$letter : $base.'('.$letter.')', $origin];
        }

        if ($type === 'roman') {
            $stage = $spec['stageMark'] !== null ? mb_strtolower($spec['stageMark']) : '';

            return [$base.$stage.'('.mb_strtolower($marked['mark']).')', $origin];
        }

        // A dash, a bullet, or no mark at all: the part's place among such parts, made up here.
        $this->spec['parts'] = ($this->spec['parts'] ?? 0) + 1;

        return [$base.'('.$this->spec['parts'].')', 'made'];
    }

    /** A serial number with the section's letter, and a numbered heading's number where items are numbered afresh, before it. */
    private function prefixed(string $serial): string
    {
        if (preg_match('/^\d+$/', $serial) !== 1) {
            return $serial;
        }

        $head = $this->restarts && $this->headNumber !== null && preg_match('/^\d+$/', $this->headNumber) === 1 ? $this->headNumber : null;
        // A part numbered in figures ("PART 2") goes before its items only where they are numbered afresh in each part.
        $marker = $this->marker !== null && (preg_match('/^\d+$/', $this->marker) !== 1 || $this->restarts) ? $this->marker : null;

        return implode('.', array_filter([$marker, $head, $serial], fn (?string $part) => $part !== null && $part !== ''));
    }

    /** @param  array<string, mixed>|null  $parent  The specification this one sits inside, by its number. */
    private function openSpec(int $number, array $row, ?array $parent = null): void
    {
        $parents = $parent === null ? [] : [...$parent['parents'], [...$parent, 'parents' => [], 'nested' => true]];
        $unit = $row['unit_reading'];

        $this->spec = [
            'row' => $number, 'text' => $row['description'], 'code' => $row['code'], 'serial' => $row['serial'],
            'unit' => $unit !== null && $unit['how'] !== 'raw' && $unit['unit'] !== '%' ? $unit['unit'] : '', 'amount' => $row['amount'],
            'children' => 0, 'lettered' => 0, 'sum' => BigDecimal::zero(), 'stage' => null, 'stageMark' => null, 'stageChildren' => 0, 'lastMark' => null,
            'parents' => $parents, 'nested' => false, 'parts' => 0, 'data' => $row,
        ];
        $this->kind($number, 'spec', $row['description'], null);
    }

    /**
     * Finish the specification lines were being added to, and those it sits inside unless $keepParents (the next
     * row is numbered under one of them). One with no lines under it is a note, left out; but one that gives a
     * unit is an item to be priced, with quantity 0 and rate 0; one the file marks as deleted is a note; and a
     * short numbered heading with an item after it ($next) that is neither numbered past it nor the next number
     * at its own level heads the items, as "1 | EARTH WORK" over 1.1 and 1.2. A heading in capitals stays one
     * whatever number follows it.
     */
    private function closeSpec(?array $next = null, ?array $parent = null): void
    {
        $spec = $this->spec;
        $this->spec = null;

        if ($spec === null) {
            return;
        }

        // A specification a numbered row now opens under is not closing: it becomes that row's parent.
        if ($parent !== null && $parent['row'] === $spec['row']) {
            $this->spec = $parent;

            return;
        }

        if ($spec['children'] === 0 && ! $spec['nested']) {
            $this->closeEmpty($spec, $next);
        } elseif ($spec['amount'] !== null && $spec['children'] > 0 && $this->differs($spec['sum'], Dec::of($spec['amount']))) {
            $this->note($spec['row'], __('The parts add up to :parts but the file shows :file for this item. The parts are taken as they are.', ['parts' => inr($spec['sum']), 'file' => inr($spec['amount'])]));
        }

        // A short row after the parts with nothing under it, as "EARTHING" before the next numbered item, heads what follows.
        if ($next !== null && $spec['stage'] !== null && ($spec['stageChildren'] ?? 0) === 0 && $spec['children'] > 0 && ! str_ends_with($spec['stage'], ':') && $spec['stageMark'] === null) {
            $this->subheading = Str::limit($spec['stage'], 80, '');
            $this->headNumber = null;
        }

        // The specifications it sat inside end with it (they had parts, so they stay specifications), unless the
        // next row is numbered under one of them: that one stays open, as an item to be priced may have added to it.
        $outer = $this->spec;
        $this->spec = $parent === null ? null : ($outer !== null && $outer['row'] === $parent['row'] ? $outer : $parent);
    }

    /** A specification with no lines under it: an item to be priced, a deleted item, a heading, or a note. */
    private function closeEmpty(array $spec, ?array $next): void
    {
        $text = rtrim($spec['text']);
        $numbered = $spec['serial'] !== '' || $spec['code'] !== '';

        if (preg_match(self::DELETED, $text) === 1 && mb_strlen($text) < 80) {
            $this->kind($spec['row'], 'note', $spec['text'], __('The file marks this item as deleted. Left out.'));

            return;
        }

        // An item with its unit but no quantity and no rate, as one to be quoted: a line to price, never a heading.
        if ($spec['unit'] !== '' && $spec['amount'] === null) {
            $outer = $spec['parents'] === [] ? null : [...end($spec['parents']), 'parents' => array_slice($spec['parents'], 0, -1)];
            $this->spec = $outer;
            $this->line($spec['row'], [...$spec['data'], 'rate_only' => true, 'figures' => true], $this->columns, $this->fallback, true);

            return;
        }

        $peer = $next !== null && preg_match('/^\d+$/', $spec['serial']) === 1 && preg_match('/^\d+$/', $next['serial']) === 1 && (int) $next['serial'] > (int) $spec['serial'];

        if ($next !== null && $spec['amount'] === null && $numbered && ! $this->followsOn($next['serial'], $spec['serial'])
            && (! $peer || $this->isCapitals($text))
            && mb_strlen($text) < self::SPEC_LENGTH && ! $this->startsLikeSpecification($text) && ! str_ends_with($text, ':')) {
            $this->subheading = Str::limit(trim($spec['text'], " :-\t"), 80, '');
            $this->headNumber = $spec['serial'] !== '' ? $spec['serial'] : $spec['code'];
            $this->headChildren = false;
            $this->kind($spec['row'], 'subheading', $spec['text'], null);

            return;
        }

        if ($spec['amount'] !== null) {
            $this->kind($spec['row'], 'skipped', $spec['text'], __('An amount with no quantity, rate or lines under it. Left out.'));
        } elseif ($peer) {
            $this->kind($spec['row'], 'note', $spec['text'], __('A numbered row with no figures and no unit, followed by the next number: an item with nothing to import, never a heading. Left out.'));
        } else {
            $this->kind($spec['row'], 'note', $spec['text'], __('Text with no figures and no lines under it. Left out.'));
        }
    }

    /** Whether text is a caption in capitals, as "EARTH WORK" or "21 MISCELLANEOUS". */
    private function isCapitals(string $text): bool
    {
        $letters = (string) preg_replace('/[^\p{Lu}\p{Ll}]/u', '', $text);

        return mb_strlen($letters) >= 3 && preg_match('/\p{Ll}/u', $letters) !== 1;
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
     * Part A", "Estimated cost of Building Work" or a "Grand Total" under part 1; below the last grand total,
     * rows of text are left out.
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

    /**
     * "Round off", "Add GST", "Labour welfare cess", "Less: discount": an amount added to or taken off the work.
     * A "Modified estimated cost after correction factor ... on account of GST" row shows the total after it.
     */
    private function amountAdjustment(int $number, string $label, string $amount): void
    {
        $this->closeSpec();
        $this->lastLine = null;

        $effect = Dec::of($amount);
        $shows = 'amount';

        if (preg_match('/correction\s+factor|modified\s+estimated\s+cost/iu', $label) === 1 && $effect->isGreaterThan($this->linesSum->plus($this->effects))) {
            $effect = $effect->minus($this->linesSum->plus($this->effects));
            $shows = 'total';
        } elseif ($effect->isPositive() && $this->takesOff($label)) {
            $effect = $effect->negated();
        }

        $this->effects = $this->effects->plus($effect);
        $this->adjustments[] = [
            'row' => $number, 'label' => $label, 'percent' => null, 'base' => Dec::money($this->linesSum),
            'effect' => Dec::money($effect), 'amount' => $amount, 'shows' => $shows,
        ];
        $this->kind($number, 'adjustment', $label, null);
    }

    /** "Credit for ... material", "Recovery of bricks", or a row whose rate or amount is below zero. */
    private function isTakenBack(string $label, array $row): bool
    {
        $negative = ($row['rate'] !== null && Dec::of($row['rate'])->isNegative()) || ($row['amount'] !== null && Dec::of($row['amount'])->isNegative() && $row['qty'] !== null);

        return $negative || (preg_match('/^\s*(credit|recovery)\s+(for|of)\b/iu', $label) === 1 && ($row['qty'] === null || $row['rate'] !== null || $row['amount'] !== null));
    }

    /** A credit or recovery: what it takes off, shown as an adjustment; with no figure, a note. */
    private function takenBack(int $number, string $label, array $row): void
    {
        $amount = $row['amount'] ?? ($row['qty'] !== null && $row['rate'] !== null ? (string) Dec::of($row['qty'])->multipliedBy(Dec::of($row['rate'])) : null);

        if ($amount === null) {
            $this->kind($number, 'note', $label, __('A credit or recovery with no figure. Not a line.'));

            return;
        }

        $this->closeSpec();
        $this->lastLine = null;
        $effect = Dec::of($amount)->abs()->negated();
        $this->effects = $this->effects->plus($effect);
        $this->adjustments[] = [
            'row' => $number, 'label' => $label, 'percent' => null, 'base' => Dec::money($this->linesSum),
            'effect' => Dec::money($effect), 'amount' => $amount, 'shows' => 'amount',
        ];
        $this->kind($number, 'adjustment', $label, __('A credit or recovery: taken off the work, never a line.'));
    }

    /**
     * Whether an amount alone is what the lines above come to: all the lines, those since the last total (a page
     * or part total, on a row of a few words), or the lines with the adjustments so far ("Cost including cess").
     */
    private function isTheLinesFigure(string $label, BigDecimal $stated): bool
    {
        $short = count(preg_split('/\s+/u', trim($label)) ?: []) <= 5;

        if ($this->linesSinceTotal >= 2 && $short && (! $this->differs($this->linesSum, $stated) || ! $this->differs($this->sinceTotal, $stated))) {
            return true;
        }

        return ! $this->effects->isZero() && $this->adjustments !== [] && ! $this->differs($this->linesSum->plus($this->effects), $stated);
    }

    /** Whether an amount, with no line since the last total, repeats an earlier total to the rupee: a summary of the parts. */
    private function repeatsATotal(string $amount): bool
    {
        if ($this->linesSinceTotal > 0 || $this->totals === [] || $this->made === []) {
            return false;
        }

        $stated = Dec::of($amount);

        foreach ($this->totals as $total) {
            if ($total['amount'] !== null && Dec::of($total['amount'])->minus($stated)->abs()->isLessThanOrEqualTo(1)) {
                return true;
            }
        }

        return false;
    }

    /**
     * A row of words only that is page furniture or not part of the work: a page number or page heading, a
     * signature or name line, the tenderer's quote ("I/We agree to carry out the work at ...% above/below"), or a
     * note, condition or declaration. The kind and the reason, or null.
     *
     * @return array{0: string, 1: string}|null
     */
    private function footerLine(string $label, array $row): ?array
    {
        $text = trim(CellValues::asciiDigits($label));
        $words = count(preg_split('/\s+/u', $text) ?: []);

        if (preg_match('/^(page|pg|p\s?a\s?g\s?e)\s*(no\.?)?\s*[:|\-–]*\s*\d+(\s*(of|\/)\s*\d+)?\s*$|^\d+\s*(of|\/)\s*\d+$|^correction\s*[-–]\s*nil\b|^disclaimer\b/iu', $text) === 1
            || ($words <= 16 && preg_match('/\bpage\s*(no\.?)?\s*[:\-–]*\s*\d+(\s*of\s*\d+)?\b/iu', $text) === 1)) {
            return ['furniture', __('A page number or page heading. Left out.')];
        }

        if (preg_match('/\bsignature\b|\bseal\b|^(sd\/-|sd-)|^(prepared|checked|verified|approved|submitted|recommended|scrutini[sz]ed)\s+by\b/iu', $text) === 1
            || ($words <= 8 && $this->isDesignations($text))) {
            return ['furniture', __('A signature or name line. Left out.')];
        }

        if ((preg_match('/\d\s*%/u', $text) !== 1 && preg_match('/%\s*\(?\s*(above|below)\b/iu', $text) === 1) || preg_match('/\bat\s+par\b|\bexcess\s*\(?\+?\)?\s*\/?\s*(below|less)\b|^quoted\s+(rates?|currency)\b|^(i|we)\s*\/\s*(we|i)\b|\b(willing|agree)\s+to\s+(carry\s+out|execute)\b|^estimated\s+amount\s+put\s+to\s+tender|^add\s*\/\s*deduct\b|^(our\s+rates\s+are|the\s+bidder\s+is\s+required|bidder\s+to\s+confirm)\b|quote\s+(his|their|the)\s+rates?\b/iu', $text) === 1) {
            return ['note', __('A line for the tenderer\'s quote. Left out.')];
        }

        if (preg_match('/^(notes?|n\s*:?\s*b|terms\s*(&|and)\s*conditions?|conditions?|declaration|important\s+note|explanatory\s+notes?(\s+for\s+boq)?)\s*([:\-–.]|$)|^(this\s+nit\s+contains|pac\s+rs|estimated\s+contract\s+value|no\.?\s+of\s+boq\s+items|the\s+rates\s+are\s+based\s+on|in\s+case\s+of\s+any\s+typographical|tender\s+schedule\s+set\s+no)\b/iu', $text) === 1) {
            return ['note', __('A note or condition. Left out.')];
        }

        // The sample row a tender template carries under its heading.
        if (preg_match('/^x{2,}$/iu', $text) === 1) {
            return ['skipped', __('The template\'s sample row. Left out.')];
        }

        return null;
    }

    /** Whether a row is only designations, as "CONTRACTOR  EXECUTIVE ENGINEER" or "AE(P) EE (P)": a line for signatures. */
    private function isDesignations(string $text): bool
    {
        $words = preg_split('/[^\p{L}]+/u', mb_strtolower($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $titles = ['contractor', 'contractors', 'tenderer', 'bidder', 'engineer', 'director', 'officer', 'ae', 'aee', 'ee', 'je', 'se', 'ce', 'sdo', 'bdo', 'dee', 'jen', 'aen', 'xen'];
        $allowed = [...$titles, 'executive', 'assistant', 'asst', 'deputy', 'dy', 'junior', 'jr', 'sub', 'divisional', 'superintending', 'chief', 'university',
            'site', 'estate', 'in', 'charge', 'incharge', 'joint', 'admn', 'the', 'and', 'p', 'civil', 'electrical', 'municipal', 'project', 'manager', 'general', 'technical'];

        return $words !== [] && array_diff($words, $allowed) === [] && array_intersect($words, $titles) !== [];
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
            || preg_match('/\b(contingenc\w*|contengency|escalation|gst|cgst|sgst|igst|tax|taxes|cess|overheads?|profit|supervision|rebate|discount|below|above|insurance|welfare|premium|work\s*charged?|ohc|t\s*&\s*p)\b|आकस्मिक|जीएसटी|वजा|कमी|अधिक|जादा|घटा|जोड़|जोडा/iu', $text) === 1) {
            return true;
        }

        return preg_match('/\d\s*(%|percent|per\s*cent|टक्के|प्रतिशत)\s*\)?\.?\s*$/iu', $text) === 1 && count(preg_split('/\s+/u', $text) ?: []) <= 6;
    }

    /**
     * "Add GST", "Round off", "Labour welfare cess", "Less: discount", "Provision for contingency (+) @",
     * "Seigniorage charges": an amount added to or taken off the work. The words open the row, or, on a short row
     * with no number of its own, stand anywhere in it. An item that only mentions tax, as "Provision for
     * inspection charges inclusive of GST", is not one.
     */
    private function isAmountAdjustment(string $label, bool $numbered): bool
    {
        $text = trim($label);

        if (preg_match('/^(add|less|deduct)\b|^(round(ed|ing)?\s*-?\s*off|gst|cgst|sgst|igst|vat|tax|taxes|cess|discount|rebate|contingenc\w*|escalation|miscellaneous\s+and\s+rounding)\b|^(पूर्णांक|जीएसटी|सूट|वजावट)/iu', $text) === 1) {
            return true;
        }

        if (preg_match('/^provision\s+for\b.*\(\s*\+\s*\)\s*@|correction\s+factor|modified\s+estimated\s+cost|^(seigniorage|price\s+adjustment|unforeseen\s+items?|qc\s+charges|nac)\b/iu', $text) === 1 && ! $numbered) {
            return true;
        }

        return ! $numbered && count(preg_split('/\s+/u', $text) ?: []) <= 6
            && preg_match('/round(ed|ing)?\s*-?\s*off|\b(gst|cgst|sgst|igst|tax|taxes|cess|discount|rebate|contingenc\w*)\b|पूर्णांक|जीएसटी|सूट|वजावट/iu', $text) === 1;
    }

    private function takesOff(string $label): bool
    {
        return preg_match('/\b(less|below|rebate|discount|deduct|deduction|minus|reduction|credit|recovery)\b|कमी|वजा|घटा|सूट|वजावट|कम\s/iu', $label) === 1;
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

            if (preg_match('/^(grand\s*(net\s*)?total|net\s*total|net\s*amount|total\s+(estimate|estimated|amount|cost|project|of\s+estimate)|estimated\s+cost|total\s*$)/u', $text) === 1
                && preg_match('/^total\s*$/u', $text) !== 1) {
                return 'total';
            }

            if (preg_match('/^(एकूण\s+रक्कम|एकंदर|कुल\s+(राशि|योग|लागत)|महायोग|सर्व\s+एकूण)/u', $text) === 1) {
                return 'total';
            }

            if (preg_match('/^(sub\s*[-‐]?\s*total|total)(\s|$|[:.(])|\b(sub\s*[-‐]?\s*total|total)\s*(amount|cost|rs\.?)?$|^[a-z]\.\s*total\b|^(construction\s+cost\s+sub\s+total|(main\s+|sub[\s-]*)?component\s+total)/u', $text) === 1
                || preg_match('/^(एकूण|कुल|योग|बेरीज|उप\s*योग|उपयोग)(\s|$|[:.])/u', $text) === 1) {
                return 'subtotal';
            }
        }

        return null;
    }

    /** A label that is a total word and nothing else: "Total", "Sub Total", "Grand Total", "एकूण". */
    private function isBareTotal(string $label): bool
    {
        return preg_match('/^(sub\s*[-‐]?\s*total|grand\s*total|net\s*total|total|एकूण|कुल|योग|बेरीज|उप\s*योग|महायोग)\s*[:.\-–]?\s*(rs\.?|₹)?\s*$/iu', trim($label)) === 1;
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

        return preg_match('/\b[cb]\s*\/\s*[fo]\b|\b[cb]\.\s*[fo]\.|\b(next|previous|preceding|following|last)\s+page\b|\b(to|from)\s+(the\s+)?(general\s+)?(summ[ae]ry|abstract|collection)\b|carried\s+(over\s+)?to\s+summ[ae]ry'
            .'|पुढील\s+(पान|पृष्ठ)|मागील\s+(पान|पृष्ठ)|अगले\s+(पृष्ठ|पेज|पन्ने)|पिछले\s+(पृष्ठ|पेज|पन्ने)|(नेलेली|आणलेली|ले\s+जाया|लाया\s+गया).*(गोषवार|सारांश)|(गोषवार|सारांश).*(नेलेली|आणलेली|ले\s+जाया|लाया\s+गया)/iu', $text) === 1
            || preg_match('/^(amount\s+|total\s+)?(carried|brought|transferred)(\s+(over|forward|fwd|down|on))?\s*$/iu', $text) === 1;
    }

    /** "Rupees nineteen lakh ... only", "INR Zero Only", "(Rupees ... only)" and the like: an amount written in words. */
    private function isWords(string $text): bool
    {
        $lower = mb_strtolower($text);

        if (preg_match('/in\s+words?\b|अक्षरी|शब्दात|शब्दों\s+में/u', $lower) === 1) {
            return true;
        }

        if (preg_match('/^\W*(rupees|inr|rs\.?)\b.*\bonly\b\W*$/u', $lower) === 1 && preg_match('/\d/u', CellValues::asciiDigits($lower)) !== 1) {
            return true;
        }

        $numberWords = preg_match('/\b(lakh|lakhs|lac|lacs|crore|crores|thousand|hundred|paise|zero)\b|लाख|हजार|हज़ार|कोटी|करोड|शंभर|सौ/u', $lower) === 1;

        return $numberWords && preg_match('/\bonly\b|\brupees\b|^rs\.?\s|^inr\s|^say\b|फक्त|मात्र|केवल|रुपये/u', $lower) === 1;
    }

    /** A letter or roman numeral that marks a section: in the serial or code column, or opening the description. */
    private function sectionMarker(array $row): ?string
    {
        foreach ([$row['serial'], $row['code']] as $cell) {
            if (preg_match('/^([A-Z]|[IVXL]{1,5})\s*[.):]?$/u', $cell, $match) === 1
                || preg_match('/^(?:part|section|bill\s*no\.?|schedule|sub[\s-]*head)\s*[-:.]?\s*([A-Z0-9]{1,4})\s*[.):]?$/iu', $cell, $match) === 1) {
                return mb_strtoupper($match[1]);
            }
        }

        $text = $row['description'];

        if (preg_match('/^(?:part|section|bill\s*no\.?|schedule|sub[\s-]*head)\s*[-:.]?\s*([A-Z0-9]{1,4})\b/iu', $text, $match) === 1) {
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
        $marker = $this->markerOf($text);

        return $marker === null ? null : [$marker['mark'], $marker['rest']];
    }

    /**
     * How a row marks itself as a part of the specification above: by a letter, a roman numeral, a dash or a
     * bullet opening its description ("(a) Footing", "ii) first floor", "[C] For electrical", "- Columns"), or by
     * a bare letter or roman numeral in its serial column ("b", "(iii)"). A single i, v or x after the letter
     * before it in the alphabet (h, u, w) is a letter.
     *
     * @return array{type: string, mark: string, rest: string}|null
     */
    private function childMarker(array $row): ?array
    {
        if (($marker = $this->markerOf($row['description'])) !== null) {
            return $marker;
        }

        if ($row['serial'] !== '' && preg_match('/^[\(\[]?([a-z]|[ivx]{1,5})[\)\].]?$/u', $row['serial'], $match) === 1) {
            return ['type' => 'serial', 'mark' => $match[1], 'rest' => $row['description'], 'as' => preg_match('/^[ivx]+$/u', $match[1]) === 1 ? $this->romanOrLetter($match[1]) : 'letter'];
        }

        return null;
    }

    /** @return array{type: string, mark: string, rest: string}|null */
    private function markerOf(string $text): ?array
    {
        $text = trim($text);

        // "-do-" is "the same as above", not a dashed part.
        if (preg_match('/^[\s_\-–.]*(do|ditto)[\s_\-–.]*$/iu', $text) === 1) {
            return null;
        }

        if (preg_match('/^[\(\[]?([ivx]{1,5})[\)\]]\s*(.+)$/u', $text, $match) === 1 || preg_match('/^([ivx]{1,5})[.:]\s+(.+)$/u', $text, $match) === 1) {
            return ['type' => $this->romanOrLetter($match[1]), 'mark' => $match[1], 'rest' => trim($match[2])];
        }

        if (preg_match('/^[\(\[]?([a-zA-Z])[\)\]]\s*(.+)$/u', $text, $match) === 1 || preg_match('/^([a-z])[.:]\s+(.+)$/u', $text, $match) === 1) {
            return ['type' => 'letter', 'mark' => $match[1], 'rest' => trim($match[2])];
        }

        if (preg_match('/^([-–—•*‐])\s*(.+)$/u', $text, $match) === 1) {
            return ['type' => 'dash', 'mark' => $match[1], 'rest' => trim($match[2])];
        }

        return null;
    }

    /** A roman numeral, unless it is a single i, v or x that comes after h, u or w among the parts above. */
    private function romanOrLetter(string $mark): string
    {
        $last = $this->spec['lastMark'] ?? null;
        $before = ['i' => 'h', 'v' => 'u', 'x' => 'w'];

        if (strlen($mark) === 1 && $last !== null && $last['type'] === 'letter' && mb_strtolower($last['mark']) === ($before[$mark] ?? null)) {
            return 'letter';
        }

        return 'roman';
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
     * The sheet's own total of the work before any adjustment: the largest total row after the last line and above
     * every adjustment, which on a sheet with section totals is the total of them all. A total there that is only
     * one part's (it matches the lines since an earlier total, not all of them) is no total of the sheet.
     */
    private function fileSubtotal(): ?string
    {
        $lastLine = $this->made === [] ? 0 : max(array_keys($this->made));
        $largest = null;

        foreach ($this->totals as $total) {
            if ($total['kind'] !== 'section' && ! $total['adjusted'] && $total['amount'] !== null && $total['row'] > $lastLine
                && ($largest === null || Dec::of($total['amount'])->isGreaterThan(Dec::of($largest['amount'])))) {
                $largest = $total;
            }
        }

        if ($largest === null) {
            return null;
        }

        if ($largest['matches'] === true && $this->differs($this->linesSum, Dec::of($largest['amount']))) {
            return null;
        }

        return $largest['amount'];
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

    /**
     * One note for things that touch many lines, and one for the totals. The note that the lines and the sheet's
     * own total disagree comes first of all, as it is the one that matters.
     */
    private function summarise(): void
    {
        $subtotal = $this->fileSubtotal();

        if ($subtotal !== null && $this->differs($this->linesSum, Dec::of($subtotal))) {
            $this->notes[] = ['row' => 0, 'message' => __('The lines add up to :lines but the total written in the file is :file. The lines are taken as they are, so please check that total.', ['lines' => inr($this->linesSum), 'file' => inr($subtotal)]), 'weight' => 2];
        }

        $rateOnly = array_values(array_diff(array_keys(array_filter($this->made, fn (array $line) => $line['kind'] === 'rate_only')), $this->toPrice));

        foreach (['rate_only' => $rateOnly, 'to_price' => $this->toPrice, 'unpriced' => $this->unpriced] as $about => $rows) {
            if ($rows !== []) {
                $this->notes[] = self::zeroNote($about, $rows);
            }
        }

        if ($this->mismatches !== []) {
            $this->note(0, __(':count lines have an amount that is not quantity times rate. The quantity and rate were kept as the file gives them.', ['count' => count($this->mismatches)]));
        }

        $shown = array_filter($this->unitsToCheck, fn (array $unit) => $unit['how'] === 'shown');
        $asked = array_filter($this->unitsToCheck, fn (array $unit) => $unit['how'] === 'ask');

        if ($shown !== []) {
            $read = array_map(fn (array $unit) => __(':written as :unit (rows :rows)', ['written' => $unit['written'], 'unit' => $unit['unit'], 'rows' => self::rows($unit['rows'])]), array_values($shown));
            $this->note(0, __('Units read from spellings that schedules do not print: :units. Check that each is right.', ['units' => implode('; ', $read)]));
        }

        if ($asked !== []) {
            $kept = array_map(fn (array $unit) => __(':written (rows :rows)', ['written' => $unit['written'], 'rows' => self::rows($unit['rows'])]), array_values($asked));
            $this->notes[] = ['row' => 0, 'message' => __('Units kept as written because they could mean more than one unit: :units. Change them in the file, or on the lines after importing.', ['units' => implode('; ', $kept)]), 'weight' => 1];
        }

        if ($this->flagged !== []) {
            $this->notes[] = ['row' => 0, 'message' => __(':count lines are provisional sums or items outside the schedule of rates (rows :rows). They are imported; check them.', ['count' => count($this->flagged), 'rows' => self::rows(array_keys($this->flagged))]), 'weight' => 1];
        }
    }

    /**
     * The note on the lines that came in with a 0 the file does not give: rate-only lines ("rate_only", quantity 0),
     * items to be priced ("to_price", quantity and rate 0), lines with no rate ("unpriced", rate 0). It names the
     * figures and the rows, so BoqSheetImport can say it again for the rows left when some keep an item's figures.
     *
     * @param  list<int>  $rows
     * @return array{row: int, message: string, filled: list<string>, rows: list<int>, about: string}
     */
    public static function zeroNote(string $about, array $rows): array
    {
        [$message, $filled] = match ($about) {
            'rate_only' => [__(':count lines are priced by rate only. They were read with quantity 0; measurements against them go for approval as beyond the BOQ.', ['count' => count($rows)]), ['qty']],
            'to_price' => [__(':count items have neither a quantity nor a rate in the file (rows :rows). They come in at quantity 0 and rate 0, to be priced later.', ['count' => count($rows), 'rows' => self::rows($rows)]), ['qty', 'rate']],
            'unpriced' => [__(':count lines have a quantity but no rate (rows :rows). They are read at rate 0; fill in the rates later.', ['count' => count($rows), 'rows' => self::rows($rows)]), ['rate']],
        };

        return ['row' => 0, 'message' => $message, 'filled' => $filled, 'rows' => array_values($rows), 'about' => $about];
    }

    /**
     * Row numbers as a note names them: the first 30, then an ellipsis.
     *
     * @param  list<int>  $rows
     */
    public static function rows(array $rows): string
    {
        return implode(', ', array_slice($rows, 0, 30)).(count($rows) > 30 ? ', …' : '');
    }

    /** @return array<int, array<int, ?string>> */
    private function output(): array
    {
        $rows = [];

        foreach ($this->made as $number => $line) {
            $rows[$number] = [
                self::OUTPUT['section'] => $line['section'],
                self::OUTPUT['code'] => $line['code'],
                self::OUTPUT['description'] => $line['description'],
                self::OUTPUT['unit'] => $line['unit'],
                self::OUTPUT['qty'] => $line['qty'],
                self::OUTPUT['rate'] => $line['rate'],
                self::OUTPUT['category'] => $line['category'],
                self::OUTPUT['details'] => self::detailsText($line),
            ];
        }

        return $rows;
    }

    /**
     * A line's details cell as it is imported: its details, its break-up and its remarks, one to a line.
     *
     * @param  array{details: list<string>, parts: list<string>, remarks: list<string>}  $line
     */
    public static function detailsText(array $line): ?string
    {
        $details = $line['details'];

        if ($line['parts'] !== []) {
            $details[] = __('Break-up: :parts', ['parts' => implode('; ', $line['parts'])]);
        }

        if ($line['remarks'] !== []) {
            $details[] = __('Remark: :text', ['text' => implode('; ', $line['remarks'])]);
        }

        return $details === [] ? null : Str::limit(implode("\n", $details), 10000, '');
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
