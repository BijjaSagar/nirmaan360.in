<?php

namespace App\Services\Reports;

/**
 * Puts a table back together from the pieces of text on PDF pages.
 *
 * Pieces at the same height form a line. The heading line is the one whose words are the
 * usual column names. Columns come from the vertical rules of the table when it has them,
 * otherwise from the gaps that run down the whole table between the pieces. Rows come from
 * the horizontal rules, otherwise from the spacing of the lines: a wrapped description sits
 * at the usual line pitch under its first line, while a new row starts after a bigger gap,
 * after a lettered part such as "a.", or when a column that already has a value gets another.
 *
 * The grid handed back has the heading in row 1 and one row per table row after it, with the
 * columns in the order they appear, so BoqExcelService reads it exactly like a sheet.
 *
 * Text that is not part of the table is left out where it can be told apart, and nowhere else:
 * anything above the heading; page furniture, that is a letterhead or a page heading printed again
 * in the place it has above the heading, and a footer printed again in the same place at the foot
 * of other pages, apart from the table (furniture()); and rows with no description beside a figure, which
 * belong to no row that has one, where they lie above the heading printed again on a later page
 * with no row of the table above them, below the last row of the table, or at the foot of a page
 * whose table goes on under its heading on the next page (a signature, a note). Every other row
 * stays in the grid as it always did, a row with no figure in the middle of the table included,
 * for the import to report.
 *
 * The file is untrusted, so the work grows about in step with the text on the pages, and with a
 * deadline grid() stops with PdfTableTimedOut once it is passed.
 */
final class PdfTable
{
    /** A line this close to the heading line, in multiples of the text size, can be the rest of a two-line heading. */
    private const HEADING_GAP = 2.2;

    /** Vertical rules shorter than this share of the table height are not column lines. */
    private const COLUMN_RULE_SHARE = 0.3;

    /** Horizontal rules shorter than this share of the table width are underlines, not row lines. */
    private const ROW_RULE_SHARE = 0.4;

    /** How far apart, in points, two pieces of text on two pages may be and still count as printed in the same place. */
    private const SAME_PLACE = 2.0;

    /** A footer stands this far below the line over it, in multiples of the line pitch, on a page where it is told apart. */
    private const FOOT_GAP = 2.5;

    /**
     * A piece that can be a page number: up to four digits, alone or as "Page 3", "Page 3 of 9", "3/9" or "- 3 -".
     * It may differ from one page to the next where the rest of a line is printed again.
     */
    private const PAGE_NUMBER = '/^(?:(?:page|pg|p|पृष्ठ|पृ|पान)\.?\s*(?:no\.?\s*)?)?[-–(]?\s*[0-9०-९]{1,4}\s*(?:(?:of|\/|पैकी|में से)\s*[0-9०-९]{1,4})?\s*[-–)]?$/iu';

    /** @var list<array{page: int, y: float, size: float, x: float, x1: float, n: int, pieces: list<array<string, mixed>>}> */
    private array $lines = [];

    /** @var list<array{page: int, y: float, size: float, pieces: list<array<string, mixed>>, cells: array<int, list<array<string, mixed>>>}> */
    private array $body = [];

    /** @var list<int> The lines the heading spans, by their place among the lines of the pages. */
    private array $headingLines = [];

    /** @var array<int, float> Page => the height of the heading where it is printed again on that later page. The table starts again below it. */
    private array $restarts = [];

    /** @var list<array<string, mixed>> The pieces of the heading and of the rows of the grid that make BOQ lines. keeps() looks at these. */
    private array $table = [];

    /**
     * Each line by its place among the lines, as samePlace() compares it: its text with the digits of a page
     * number as "#" (key), whether it is printed above the heading on the page of the heading, and each piece
     * with the numbers it holds when it can be a page number.
     *
     * @var array<int, array{page: int, y: float, key: string, above: bool, pieces: list<array{text: string, x: float, width: float, numbers: ?list<int>}>}>
     */
    private array $shapes = [];

    /** @var array<string, array<int, list<int>>> The lines by key and by height in steps of SAME_PLACE, to find what is printed again in the same place. */
    private array $printed = [];

    /** @var array<string, array<int, list<int>>> The same, for the lines above the heading on the page of the heading. */
    private array $printedAbove = [];

    /** @var array<int, ?string> printedAgain() of each line, once worked out. */
    private array $again = [];

    private int $ticks = 0;

    /** @var array<int, array{lines: int, top: ?float, bottom: float, width: float}>|null bodyPage() for the body as it is now. */
    private ?array $bodyPages = null;

    /**
     * @param  list<array{pieces: list<array{x: float, x1: float, y: float, size: float, text: string}>, h: list<array{0: float, 1: float, 2: float}>, v: list<array{0: float, 1: float, 2: float}>}>  $pages
     * @param  ?int  $deadline  hrtime(true) after which grid() stops with PdfTableTimedOut.
     */
    public function __construct(private array $pages, private ?int $deadline = null) {}

    /**
     * @return array<int, array<int, ?string>> Empty when no heading row was found.
     *
     * @throws PdfTableTimedOut when the deadline passes first
     */
    public function grid(): array
    {
        $this->ticks = 0;
        $this->lines = $this->lines();
        $this->restarts = $this->table = [];
        $this->inTime();
        $heading = $this->heading();

        if ($heading === null) {
            $this->headingLines = $this->body = [];

            return [];
        }

        [$cells, $headingLines] = $heading;
        $this->headingLines = $headingLines;
        $this->printed($headingLines);
        $this->body = $this->body($headingLines, $cells);
        $this->inTime();
        [$columns, $rows] = $this->layout($cells, $headingLines);
        $this->inTime();
        $outside = $this->outside($columns, $rows);
        $this->inTime();

        // Laid out again without the text outside the table, so a letterhead or a footer cannot move a column.
        if ($outside !== []) {
            $this->body = array_values(array_diff_key($this->body, $outside));
            [$columns, $rows] = $this->layout($cells, $headingLines);
            $this->inTime();
        }

        // Every row left in the grid that makes BOQ lines is looked at, whatever the first layout made of it,
        // and a row joins the rows it belongs to across a page as the import joins them.
        $this->table = $this->tablePieces($rows, $this->makesLines($columns, $rows, true));
        $grid = [1 => []];

        foreach ($columns as $index => $column) {
            $grid[1][$index + 1] = $column['text'] === '' ? null : $column['text'];
        }

        $number = 1;

        foreach ($rows as $row) {
            $number++;

            foreach ($columns as $index => $column) {
                $grid[$number][$index + 1] = $this->cellText($row['cells'][$index] ?? []);
            }
        }

        return $grid;
    }

    /**
     * Whether a piece of text that makes BOQ lines carries this mark, as the caller set it on the pieces:
     * a piece of the heading, of a row of the grid with a description and a quantity, rate or amount, or of
     * a row of the grid that belongs to one (the head of its lettered parts, or a lettered part under it, on
     * the same page or the next, as the import joins them).
     * Text left out of the grid (a title, page furniture, a signature below the rows) is not looked at, nor
     * is a row of the grid with no figure or no description, which makes no BOQ line. Asked after grid();
     * false when no heading was found.
     */
    public function keeps(string $mark): bool
    {
        foreach ($this->table as $piece) {
            if (! empty($piece[$mark])) {
                return true;
            }
        }

        return false;
    }

    /** A rough width for a run of text in a proportional font, in points. Only used to judge overlaps and gaps. */
    public static function width(string $text, float $size): float
    {
        $width = 0.0;

        foreach (preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $char) {
            $width += match (true) {
                $char === ' ' => 0.28,
                ctype_digit($char) => 0.56,
                ctype_upper($char) => 0.68,
                ctype_lower($char) => 0.5,
                str_contains('.,:;\'`', $char) => 0.28,
                str_contains('()[]/-|!', $char) => 0.34,
                default => 0.62,
            };
        }

        return $width * $size;
    }

    /** Heading words the way BoqExcelService compares them: lower case, letters and digits only. */
    public static function key(string $text): string
    {
        return trim((string) preg_replace('/\s+/', ' ', (string) preg_replace('/[^a-z0-9 ]/', '', mb_strtolower($text))));
    }

    /** Pieces at the same height on a page, in reading order: page by page, top to bottom, left to right. */
    private function lines(): array
    {
        $lines = [];

        foreach ($this->pages as $index => $page) {
            $pieces = $page['pieces'];
            usort($pieces, fn (array $a, array $b) => $b['y'] <=> $a['y'] ?: $a['x'] <=> $b['x']);
            $current = null;

            foreach ($pieces as $piece) {
                if ($current !== null && abs($piece['y'] - $current['y']) <= max(1.0, 0.35 * min($piece['size'], $current['size']))) {
                    $current['pieces'][] = $piece;

                    continue;
                }

                if ($current !== null) {
                    $lines[] = $current;
                }

                $current = ['page' => $index, 'y' => $piece['y'], 'size' => $piece['size'], 'pieces' => [$piece]];
            }

            if ($current !== null) {
                $lines[] = $current;
            }
        }

        foreach ($lines as $n => &$line) {
            $this->tick();
            usort($line['pieces'], fn (array $a, array $b) => $a['x'] <=> $b['x']);
            $sizes = array_column($line['pieces'], 'size');
            sort($sizes);
            $line['size'] = $sizes[intdiv(count($sizes), 2)];
            $line['x'] = min(array_column($line['pieces'], 'x'));
            $line['x1'] = max(array_column($line['pieces'], 'x1'));
            $line['n'] = $n;
        }

        return $lines;
    }

    /**
     * The heading: its cells, each with the text and the x-range it covers, and the line numbers it spans.
     *
     * @return array{0: list<array{x: float, x1: float, text: string, keys: list<string>}>, 1: list<int>}|null
     */
    private function heading(): ?array
    {
        foreach ($this->lines as $index => $line) {
            $this->tick();
            $keys = array_map(fn (array $piece) => self::key($piece['text']), $line['pieces']);
            $named = array_filter($keys, fn (string $key) => preg_match('/^(descri|particular|item descr)/', $key) === 1);

            if ($named === [] || count($this->fields($keys)) < 2) {
                continue;
            }

            $group = [$index];
            $cells = $this->headingCells($group);
            $found = count($this->fields($this->cellKeys($cells)));

            // "Sr." over "No." and "Amount in" over "Rs." are one heading on two lines. A neighbouring
            // line is taken in only when it names more columns, so a first data row never joins the heading.
            foreach ([$index - 1, $index + 1] as $neighbour) {
                if (! $this->continuesHeading($line, $this->lines[$neighbour] ?? null)) {
                    continue;
                }

                $with = $this->headingCells([...$group, $neighbour]);
                $count = count($this->fields($this->cellKeys($with)));

                if ($count > $found) {
                    $group[] = $neighbour;
                    $cells = $with;
                    $found = $count;
                }
            }

            sort($group);
            $fields = $this->fields($this->cellKeys($cells));

            if (count($fields) >= 3 && isset($fields['description']) && (isset($fields['qty']) || isset($fields['rate']) || isset($fields['amount']))) {
                return [$cells, $group];
            }
        }

        return null;
    }

    /** A line just above or below the heading whose pieces all sit under heading pieces and carry no numbers. */
    private function continuesHeading(array $heading, ?array $line): bool
    {
        if ($line === null || $line['page'] !== $heading['page'] || abs($line['y'] - $heading['y']) > self::HEADING_GAP * $heading['size']) {
            return false;
        }

        foreach ($line['pieces'] as $piece) {
            if ($this->looksNumeric($piece['text'])) {
                return false;
            }

            $under = false;

            foreach ($heading['pieces'] as $other) {
                if ($piece['x'] <= $other['x1'] + 2 && $piece['x1'] >= $other['x'] - 2) {
                    $under = true;
                    break;
                }
            }

            if (! $under) {
                return false;
            }
        }

        return true;
    }

    /**
     * Pieces of the heading lines grouped into cells by horizontal overlap, so "Sr." over "No." is one cell.
     *
     * @param  list<int>  $group
     * @return list<array{x: float, x1: float, text: string, keys: list<string>}>
     */
    private function headingCells(array $group): array
    {
        $pieces = [];

        foreach ($group as $index) {
            array_push($pieces, ...$this->lines[$index]['pieces']);
        }

        usort($pieces, fn (array $a, array $b) => $a['x'] <=> $b['x']);
        $cells = [];

        foreach ($pieces as $piece) {
            $last = count($cells) - 1;

            if ($last >= 0 && $piece['x'] <= $cells[$last]['x1'] + 1) {
                $cells[$last]['x1'] = max($cells[$last]['x1'], $piece['x1']);
                $cells[$last]['pieces'][] = $piece;

                continue;
            }

            $cells[] = ['x' => $piece['x'], 'x1' => $piece['x1'], 'pieces' => [$piece]];
        }

        foreach ($cells as &$cell) {
            usort($cell['pieces'], fn (array $a, array $b) => $b['y'] <=> $a['y'] ?: $a['x'] <=> $b['x']);
            $cell['text'] = trim(implode(' ', array_column($cell['pieces'], 'text')));
            $cell['keys'] = array_map(fn (array $piece) => self::key($piece['text']), $cell['pieces']);
            unset($cell['pieces']);
        }

        return $cells;
    }

    /** @return list<string> */
    private function cellKeys(array $cells): array
    {
        return array_map(fn (array $cell) => self::key($cell['text']), $cells);
    }

    /** @param  list<string>  $keys  @return array<string, true> Fields named by these heading words. */
    private function fields(array $keys): array
    {
        $fields = [];

        foreach ($keys as $key) {
            foreach (BoqExcelService::ALIASES as $field => $aliases) {
                if (in_array($key, $aliases, true)) {
                    $fields[$field] = true;
                }
            }
        }

        return $fields;
    }

    /**
     * The lines after the heading, minus the heading repeated at the top of later pages. Where the
     * heading is printed again on a later page, naming two of its columns or more, the height of its
     * first line there is kept in $restarts: the table starts again below it.
     *
     * @param  list<int>  $headingLines
     */
    private function body(array $headingLines, array $cells): array
    {
        $known = [];

        foreach ($cells as $number => $cell) {
            $known[self::key($cell['text'])] = $number;

            foreach ($cell['keys'] as $key) {
                $known[$key] = $number;
            }
        }

        $headingPage = $this->lines[min($headingLines)]['page'];
        $body = [];

        foreach ($this->lines as $index => $line) {
            if ($index <= max($headingLines)) {
                continue;
            }

            $this->tick();
            $named = [];

            foreach ($line['pieces'] as $piece) {
                $key = self::key($piece['text']);

                if (! isset($known[$key])) {
                    $named = null;
                    break;
                }

                $named[$known[$key]] = true;
            }

            if ($named === null) {
                $body[] = $line;

                continue;
            }

            if (count($named) >= 2 && $line['page'] !== $headingPage && ! isset($this->restarts[$line['page']])) {
                $this->restarts[$line['page']] = $line['y'];
            }
        }

        return $body;
    }

    /**
     * Columns and rows from the body lines as they are now.
     *
     * @param  list<int>  $headingLines
     * @return array{0: list<array{x0: float, x1: float, text: string}>, 1: list<array{cells: array<int, list<array<string, mixed>>>, page: int, top: float, bottom: float, lines: list<int>}>}
     */
    private function layout(array $cells, array $headingLines): array
    {
        $this->bodyPages = null;
        [$x0, $x1] = $this->span($cells);
        $bands = $this->ruleBands($cells, $headingLines, $x0, $x1) ?? $this->gapBands($cells, $x0, $x1);
        $columns = $this->columns($cells, $bands);

        return [$columns, $this->rows($columns, $headingLines)];
    }

    /**
     * Each line of the pages in the shape samePlace() compares, filed by its key and its height, and the lines
     * printed above the heading on the page of the heading, where nothing is part of the table, filed again apart.
     *
     * @param  list<int>  $headingLines
     */
    private function printed(array $headingLines): void
    {
        $first = min($headingLines);
        $this->shapes = $this->printed = $this->printedAbove = $this->again = [];

        foreach ($this->lines as $n => $line) {
            $this->tick();
            $pieces = [];
            $key = [];

            foreach ($line['pieces'] as $piece) {
                $text = trim((string) preg_replace('/\s+/u', ' ', $piece['text']));
                $numbers = preg_match(self::PAGE_NUMBER, $text) === 1 ? $this->numbers($text) : null;
                $pieces[] = ['text' => $text, 'x' => $piece['x'], 'width' => $piece['x1'] - $piece['x'], 'numbers' => $numbers];
                $key[] = $numbers === null ? $text : (string) preg_replace('/[0-9०-९]+/u', '#', $text);
            }

            $above = $n < $first && $line['page'] === $this->lines[$first]['page'];
            $this->shapes[$n] = ['page' => $line['page'], 'y' => $line['y'], 'key' => implode("\x1F", $key), 'above' => $above, 'pieces' => $pieces];
            $height = (int) floor($line['y'] / self::SAME_PLACE);
            $this->printed[$this->shapes[$n]['key']][$height][] = $n;

            if ($above) {
                $this->printedAbove[$this->shapes[$n]['key']][$height][] = $n;
            }
        }
    }

    /** @return list<int> The numbers in a page number, in order, Devanagari digits read as digits. */
    private function numbers(string $text): array
    {
        preg_match_all('/[0-9०-९]+/u', $text, $matches);
        $digits = ['०' => '0', '१' => '1', '२' => '2', '३' => '3', '४' => '4', '५' => '5', '६' => '6', '७' => '7', '८' => '8', '९' => '9'];

        return array_map(fn (string $number) => (int) strtr($number, $digits), $matches[0]);
    }

    /**
     * The lines filed under the key of line $n at about its height, on every page, $n among them. Only these can
     * be printed in the same place as it, so finding them costs about the same however many lines there are.
     *
     * @param  array<string, array<int, list<int>>>  $index
     * @return list<int>
     */
    private function filedNear(int $n, array $index): array
    {
        $shape = $this->shapes[$n];
        $height = (int) floor($shape['y'] / self::SAME_PLACE);
        $found = [];

        foreach ([$height - 1, $height, $height + 1] as $step) {
            array_push($found, ...($index[$shape['key']][$step] ?? []));
        }

        return $found;
    }

    /**
     * Whether lines $n and $m are one line printed in the same place on two pages: at the same height, with the
     * same text in each piece, starting at the same place. Unless $exact, a page number may be higher by as many
     * as the pages lie apart, and move by as much as its width changes, as a centred or right-aligned one does.
     */
    private function samePlace(int $n, int $m, bool $exact = false): bool
    {
        $this->tick();
        $a = $this->shapes[$n];
        $b = $this->shapes[$m];

        if ($a['page'] === $b['page'] || $a['key'] !== $b['key'] || abs($a['y'] - $b['y']) > self::SAME_PLACE) {
            return false;
        }

        foreach ($a['pieces'] as $index => $piece) {
            $other = $b['pieces'][$index];

            if ($piece['text'] === $other['text']) {
                if (abs($piece['x'] - $other['x']) > self::SAME_PLACE) {
                    return false;
                }

                continue;
            }

            // The keys are the same, so here only the digits of a page number can differ.
            if ($exact
                || $piece['numbers'] === null
                || $other['numbers'] === null
                || count($piece['numbers']) !== count($other['numbers'])
                || $piece['numbers'][0] - $other['numbers'][0] !== $a['page'] - $b['page']
                || array_slice($piece['numbers'], 1) !== array_slice($other['numbers'], 1)
                || abs($piece['x'] - $other['x']) > self::SAME_PLACE + abs($piece['width'] - $other['width'])) {
                return false;
            }
        }

        return true;
    }

    /**
     * Where else line $n is printed in the same place (samePlace()): null when nowhere, 'above' when that is above
     * the heading on the page of the heading, as a letterhead or a title is, and 'page' otherwise.
     */
    private function printedAgain(int $n): ?string
    {
        if (array_key_exists($n, $this->again)) {
            return $this->again[$n];
        }

        foreach ($this->filedNear($n, $this->printedAbove) as $m) {
            if ($this->samePlace($n, $m)) {
                return $this->again[$n] = 'above';
            }
        }

        foreach ($this->filedNear($n, $this->printed) as $m) {
            if ($this->samePlace($n, $m)) {
                return $this->again[$n] = 'page';
            }
        }

        return $this->again[$n] = null;
    }

    /** Whether line $n is printed again in the same place exactly, page numbers and all. */
    private function printedExactly(int $n): bool
    {
        foreach ($this->filedNear($n, $this->printed) as $m) {
            if ($this->samePlace($n, $m, true)) {
                return true;
            }
        }

        return false;
    }

    /** The line printed just above line $n on its page, or null for the first line of a page. */
    private function lineAbove(int $n): ?int
    {
        return $n > 0 && $this->lines[$n - 1]['page'] === $this->lines[$n]['page'] ? $n - 1 : null;
    }

    /**
     * The body lines of the rows that are not part of the table, to leave out of the grid. A row is left out only
     * where it can be told apart from the rows of the table:
     *
     * - page furniture (furniture()), whatever figures it holds;
     * - on a later page where the heading is printed again, the rows above it, when rows follow below it and none
     *   of the rows above is a row of the table (tableRows()): a letterhead or a page heading. The rows of the
     *   table above a heading printed again lower down, such as the end of Part A over the heading of Part B, stay;
     * - the rows after the last row of the table (a signature, a note), and at the foot of a page the rows after
     *   its last row of the table, when the next row kept is under the heading printed again on its page and is no
     *   lettered part of a row up here.
     *
     * So, furniture apart, no row left out has a description beside a figure, nor belongs to a row that has, and
     * none makes BOQ lines. A row with no figure anywhere else stays in the grid.
     *
     * @param  list<array{x0: float, x1: float, text: string}>  $columns
     * @param  list<array{cells: array<int, list<array<string, mixed>>>, page: int, top: float, bottom: float, lines: list<int>}>  $rows
     * @return array<int, true> The body lines to leave out.
     */
    private function outside(array $columns, array $rows): array
    {
        $description = $this->descriptionColumn($columns);
        $part = $this->tableRows($rows, $this->makesLines($columns, $rows, false), $description);
        $pages = [];
        $drop = [];

        foreach ($rows as $index => $row) {
            $pages[$row['page']][] = $index;
        }

        foreach ($this->furniture($columns, $rows, $pages) as $index) {
            $drop[$index] = true;
            $part[$index] = false;
        }

        foreach ($pages as $page => $indexes) {
            $restart = $this->restarts[$page] ?? null;

            if ($restart === null) {
                continue;
            }

            $above = array_values(array_filter($indexes, fn (int $index) => $rows[$index]['top'] > $restart));

            if (count($above) < count($indexes) && array_filter($above, fn (int $index) => $part[$index]) === []) {
                foreach ($above as $index) {
                    $drop[$index] = true;
                }
            }
        }

        if (in_array(true, $part, true)) {
            $last = max(array_keys(array_filter($part)));

            foreach ($pages as $indexes) {
                $lastHere = null;

                foreach ($indexes as $index) {
                    $lastHere = $part[$index] ? $index : $lastHere;
                }

                $tail = array_values(array_filter($indexes, fn (int $index) => $index > ($lastHere ?? $last) && ! isset($drop[$index])));

                if ($tail !== [] && (($lastHere ?? $last) === $last || $this->goesOnUnderHeading($rows, $drop, max($indexes) + 1, $description))) {
                    foreach ($tail as $index) {
                        $drop[$index] = true;
                    }
                }
            }
        }

        $lines = [];

        foreach (array_keys($drop) as $index) {
            foreach ($rows[$index]['lines'] as $line) {
                $lines[$line] = true;
            }
        }

        return $lines;
    }

    /**
     * Page furniture, the rows to leave out wherever they stand, whatever figures they hold:
     *
     * - a letterhead or a page heading: a row every line of which is printed again in the same place as a line
     *   above the heading on the page of the heading (printedAgain() 'above');
     * - a footer: of the rows at the foot of a page every line of which is printed again in the same place on
     *   another page, up to a numbered row printed again exactly (numberedAgain()), the first that stands apart
     *   from the table (standsApart()) and the rows below it; and on every page, the rows at its foot whose every
     *   line is printed in the same place as a line of such a footer. A page number on or beside a line may differ
     *   from page to page (samePlace()).
     *
     * Rows printed alike at the foot of pages laid out alike, such as the end of identical blocks, have the same
     * line over them on each page, or are stopped by a numbered row printed again, so they are no footer, nor are
     * the rows above them, whatever figures they hold. Neither is a row printed on one page only.
     *
     * @param  list<array{x0: float, x1: float, text: string}>  $columns
     * @param  list<array{cells: array<int, list<array<string, mixed>>>, page: int, top: float, bottom: float, lines: list<int>}>  $rows
     * @param  array<int, list<int>>  $pages  The rows of each page, top to bottom.
     * @return list<int>
     */
    private function furniture(array $columns, array $rows, array $pages): array
    {
        $code = $this->fieldColumn($columns, 'code');
        $description = $this->descriptionColumn($columns);
        $lines = fn (array $row) => array_map(fn (int $line) => $this->body[$line]['n'], $row['lines']);
        $furniture = [];
        $again = [];

        foreach ($rows as $index => $row) {
            $where = array_map(fn (int $n) => $this->printedAgain($n), $lines($row));
            $again[$index] = in_array(null, $where, true) ? null : (in_array('page', $where, true) ? 'page' : 'above');

            if ($again[$index] === 'above') {
                $furniture[$index] = true;
            }
        }

        $feet = [];

        foreach ($pages as $page => $indexes) {
            $foot = [];

            foreach (array_reverse($indexes) as $index) {
                if ($again[$index] === null || $this->numberedAgain($rows[$index], $code, $description)) {
                    break;
                }

                $foot[] = $index;
            }

            $feet[$page] = array_reverse($foot);
        }

        $pitch = $this->pitch();
        $footer = [];

        foreach ($feet as $foot) {
            foreach ($foot as $position => $index) {
                if (! $this->standsApart($lines($rows[$index])[0], $pitch)) {
                    continue;
                }

                foreach (array_slice($foot, $position) as $below) {
                    foreach ($lines($rows[$below]) as $n) {
                        $footer[$n] = true;
                    }
                }

                break;
            }
        }

        if ($footer === []) {
            return array_keys($furniture);
        }

        foreach ($feet as $foot) {
            foreach ($foot as $index) {
                if (array_filter($lines($rows[$index]), fn (int $n) => ! $this->inFooter($n, $footer)) === []) {
                    $furniture[$index] = true;
                }
            }
        }

        return array_keys($furniture);
    }

    /**
     * Whether the row whose first line is $n stands apart from the table: well below the line over it (FOOT_GAP),
     * and that line is not the line over $n where $n is printed in the same place on another page, so $n stays in
     * its place while the rows above it change, as a footer does.
     */
    private function standsApart(int $n, float $pitch): bool
    {
        $over = $this->lineAbove($n);

        if ($over === null || $this->lines[$over]['y'] - $this->lines[$n]['y'] <= self::FOOT_GAP * $pitch) {
            return false;
        }

        foreach ($this->filedNear($n, $this->printed) as $m) {
            if (! $this->samePlace($n, $m)) {
                continue;
            }

            // Where nothing is printed over it, the page may hold nothing else, which tells nothing.
            $overThere = $this->lineAbove($m);

            if ($overThere !== null && ! $this->samePlace($over, $overThere)) {
                return true;
            }
        }

        return false;
    }

    /** @param  array<int, true>  $footer  Whether line $n is a line of the footer, or is printed in the same place as one. */
    private function inFooter(int $n, array $footer): bool
    {
        if (isset($footer[$n])) {
            return true;
        }

        foreach ($this->filedNear($n, $this->printed) as $m) {
            if (isset($footer[$m]) && $this->samePlace($n, $m)) {
                return true;
            }
        }

        return false;
    }

    /** A numbered row printed again exactly, as a row of the table is on pages laid out alike: a serial number beside a description, every line the same. */
    private function numberedAgain(array $row, ?int $code, int $description): bool
    {
        if ($code === null || ! $this->isFigure($this->cellText($row['cells'][$code] ?? [])) || $this->cellText($row['cells'][$description] ?? []) === null) {
            return false;
        }

        foreach ($row['lines'] as $line) {
            if (! $this->printedExactly($this->body[$line]['n'])) {
                return false;
            }
        }

        return true;
    }

    /**
     * The rows of the table, which outside() never leaves out but as furniture: those that make BOQ lines, and
     * those with a description beside a figure in any column, such as a total under a column the heading does not
     * name. Leaving one of those out would lose what the import shows.
     *
     * @param  list<bool>  $lines  Which rows make BOQ lines.
     * @return list<bool>
     */
    private function tableRows(array $rows, array $lines, int $description): array
    {
        foreach ($rows as $index => $row) {
            $lines[$index] = $lines[$index] || ($this->figures($row) > 0 && $this->cellText($row['cells'][$description] ?? []) !== null);
        }

        return $lines;
    }

    /** How many pieces of a row are figures: a serial number, a quantity, a rate, an amount, or a phone number. */
    private function figures(array $row): int
    {
        $count = 0;

        foreach ($row['cells'] as $pieces) {
            foreach ($pieces as $piece) {
                $count += $this->isFigure($piece['text']) ? 1 : 0;
            }
        }

        return $count;
    }

    /**
     * Whether the table goes on under its heading printed again: the next row kept from $from on lies below the
     * heading where it is printed again on that row's page, and is not a lettered part of a row before it.
     *
     * @param  array<int, true>  $drop
     */
    private function goesOnUnderHeading(array $rows, array $drop, int $from, int $description): bool
    {
        for ($index = $from; $index < count($rows); $index++) {
            if (isset($drop[$index])) {
                continue;
            }

            $restart = $this->restarts[$rows[$index]['page']] ?? null;

            return $restart !== null && $rows[$index]['top'] < $restart && ! $this->lettered($rows[$index], $description);
        }

        return false;
    }

    /**
     * Which rows make BOQ lines: a row with a description and a quantity, rate or amount, and the rows that go with
     * one as the import joins them (EstimateSheet). Lettered parts (lettered()) go with the row with a description
     * they hang from, their head, passing over rows with no description, and the head and its parts all count
     * when one of them has such a figure. A row with no description makes no line, as EstimateSheet passes over
     * it and the template refuses it. With no such row in the whole table, every row counts, as nothing can be
     * told apart.
     *
     * $acrossPages joins parts to their head wherever they stand, as the import does with the rows of the grid, a
     * head at the foot of one page and its parts on the next included. Without it a part joins only a row just
     * above it on the same page, to tell the rows of the table from the text around it before the grid is made.
     * Either way the work grows in step with the rows.
     *
     * @param  list<array{x0: float, x1: float, text: string}>  $columns
     * @param  list<array{cells: array<int, list<array<string, mixed>>>, page: int, top: float, bottom: float, lines: list<int>}>  $rows
     * @return list<bool>
     */
    private function makesLines(array $columns, array $rows, bool $acrossPages): array
    {
        $figures = [];

        foreach ($columns as $index => $column) {
            $key = self::key($column['text']);

            foreach (['qty', 'rate', 'amount'] as $field) {
                if (in_array($key, BoqExcelService::ALIASES[$field], true)) {
                    $figures[] = $index;
                }
            }
        }

        $description = $this->descriptionColumn($columns);
        $part = [];

        foreach ($rows as $index => $row) {
            $part[$index] = false;

            if ($this->cellText($row['cells'][$description] ?? []) === null) {
                continue;
            }

            foreach ($figures as $column) {
                if ($this->isFigure($this->cellText($row['cells'][$column] ?? []))) {
                    $part[$index] = true;
                    break;
                }
            }
        }

        if (! in_array(true, $part, true)) {
            return array_fill(0, count($rows), true);
        }

        $near = 4 * $this->pitch() + 1;
        $group = [];
        $previous = null;

        foreach ($rows as $index => $row) {
            if ($this->cellText($row['cells'][$description] ?? []) === null) {
                continue;
            }

            $joins = $previous !== null && ($acrossPages || ($rows[$previous]['page'] === $row['page'] && $rows[$previous]['bottom'] - $row['top'] <= $near));
            $previous = $index;

            if ($group !== [] && $joins && $this->lettered($row, $description)) {
                $group[] = $index;

                continue;
            }

            $this->join($group, $part);
            $group = [$index];
        }

        $this->join($group, $part);

        return $part;
    }

    /**
     * Every row of a group makes lines when one of them does.
     *
     * @param  list<int>  $group  A head and the lettered parts under it.
     * @param  list<bool>  $part  Changed in place, so the work stays in step with the rows.
     */
    private function join(array $group, array &$part): void
    {
        if (count($group) > 1 && array_filter($group, fn (int $index) => $part[$index]) !== []) {
            foreach ($group as $index) {
                $part[$index] = true;
            }
        }
    }

    /**
     * @param  list<array{cells: array<int, list<array<string, mixed>>>, page: int, top: float, bottom: float, lines: list<int>}>  $rows
     * @param  list<bool>  $parts  Which rows make BOQ lines.
     * @return list<array<string, mixed>>
     */
    private function tablePieces(array $rows, array $parts): array
    {
        $pieces = [];

        foreach ($this->headingLines as $index) {
            array_push($pieces, ...$this->lines[$index]['pieces']);
        }

        foreach ($parts as $index => $part) {
            if ($part) {
                foreach ($rows[$index]['cells'] as $cell) {
                    array_push($pieces, ...$cell);
                }
            }
        }

        return $pieces;
    }

    /** A figure as the import reads one: digits with an optional point, once commas, spaces and the rupee sign are taken out. */
    private function isFigure(?string $text): bool
    {
        return $text !== null && preg_match('/^-?\d+(\.\d+)?$/', str_replace([',', ' ', '₹'], '', $text)) === 1;
    }

    /** A row whose description starts with a letter or a small roman number on its own, such as "a." or "(ii)": a part of the row above. */
    private function lettered(array $row, int $description): bool
    {
        return preg_match('/^\(?([a-z]|[ivx]{1,4})[.)]?\s+\S/iu', (string) $this->cellText($row['cells'][$description] ?? [])) === 1;
    }

    /** @return array{0: float, 1: float} How far the table reaches left and right. */
    private function span(array $cells): array
    {
        $x0 = min(array_column($cells, 'x'));
        $x1 = max(array_column($cells, 'x1'));

        foreach ($this->body as $line) {
            foreach ($line['pieces'] as $piece) {
                // Text far outside the heading is a margin note or a footer, not a column.
                if ($piece['x'] < $x0 - 60 || $piece['x'] > $x1 + 60) {
                    continue;
                }

                $x0 = min($x0, $piece['x']);
                $x1 = max($x1, $piece['x1']);
            }
        }

        return [$x0, $x1];
    }

    /**
     * Column ranges from the vertical rules of the table. Null when the rules do not separate the heading cells.
     *
     * @return list<array{0: float, 1: float}>|null
     */
    private function ruleBands(array $cells, array $headingLines, float $x0, float $x1): ?array
    {
        $positions = [];

        foreach ($this->pages as $index => $page) {
            [$top, $bottom] = $this->region($index, $headingLines);

            if ($top === null || $top - $bottom < 10) {
                continue;
            }

            $byX = [];

            foreach ($page['v'] as [$x, $ya, $yb]) {
                if ($x < $x0 - 6 || $x > $x1 + 6) {
                    continue;
                }

                $byX[(string) (round($x * 2) / 2)][] = [max($ya, $bottom), min($yb, $top)];
            }

            foreach ($byX as $x => $intervals) {
                if ($this->covered($intervals) >= self::COLUMN_RULE_SHARE * ($top - $bottom)) {
                    $positions[] = (float) $x;
                }
            }
        }

        if ($positions === []) {
            return null;
        }

        sort($positions);
        $edges = [];

        foreach ($positions as $x) {
            $last = count($edges) - 1;

            if ($last >= 0 && $x - $edges[$last][1] <= 1.5) {
                $edges[$last][1] = $x;

                continue;
            }

            $edges[] = [$x, $x];
        }

        $edges = array_map(fn (array $edge) => ($edge[0] + $edge[1]) / 2, $edges);

        if ($edges[0] > $x0 + 2) {
            array_unshift($edges, $x0 - 1);
        }

        if ($edges[count($edges) - 1] < $x1 - 2) {
            $edges[] = $x1 + 1;
        }

        $bands = [];

        for ($i = 1; $i < count($edges); $i++) {
            if ($edges[$i] - $edges[$i - 1] > 2) {
                $bands[] = [$edges[$i - 1], $edges[$i]];
            }
        }

        if (count($bands) < count($cells)) {
            return null;
        }

        // Every heading cell must have a column of its own, or the rules are only a frame.
        $taken = [];

        foreach ($cells as $cell) {
            $band = $this->bandAt(($cell['x'] + $cell['x1']) / 2, $bands);

            if (isset($taken[$band])) {
                return null;
            }

            $taken[$band] = true;
        }

        return $bands;
    }

    /**
     * Column ranges from the gaps between the pieces of text, used when the table has no vertical rules.
     *
     * @return list<array{0: float, 1: float}>
     */
    private function gapBands(array $cells, float $x0, float $x1): array
    {
        $left = (int) floor($x0) - 1;
        $bins = array_fill(0, max(1, (int) ceil($x1) - $left + 2), 0);
        $lines = 0;

        foreach ($this->body as $line) {
            if (count($line['pieces']) < 2) {
                continue;
            }

            $this->tick();
            $lines++;

            foreach ($line['pieces'] as $piece) {
                // A piece lying under two headings spans columns, such as a title in a merged cell.
                $under = 0;

                foreach ($cells as $cell) {
                    if ($piece['x'] < $cell['x1'] && $piece['x1'] > $cell['x']) {
                        $under++;
                    }
                }

                if ($under > 1) {
                    continue;
                }

                for ($bin = max(0, (int) floor($piece['x']) - $left); $bin <= min(count($bins) - 1, (int) ceil($piece['x1']) - $left); $bin++) {
                    $bins[$bin]++;
                }
            }
        }

        $threshold = $lines < 20 ? 0 : (int) ceil(0.03 * $lines);
        $bands = [];
        $open = null;

        foreach ($bins as $bin => $count) {
            if ($count > $threshold) {
                $open ??= $bin;

                continue;
            }

            if ($open !== null) {
                $bands[] = [$open + $left, $bin + $left];
                $open = null;
            }
        }

        if ($open !== null) {
            $bands[] = [$open + $left, count($bins) + $left];
        }

        if ($bands === []) {
            foreach ($cells as $cell) {
                $bands[] = [$cell['x'], $cell['x1']];
            }
        }

        return $bands;
    }

    /**
     * The columns of the grid in x order: each band with the heading cell that sits over it.
     * A band two headings share is split between them, and a band with no heading is kept as an unnamed column.
     *
     * @param  list<array{0: float, 1: float}>  $bands
     * @return list<array{x0: float, x1: float, text: string}>
     */
    private function columns(array $cells, array $bands): array
    {
        $owners = [];

        foreach ($cells as $index => $cell) {
            $owners[$this->bandAt(($cell['x'] + $cell['x1']) / 2, $bands)][] = $index;
        }

        $columns = [];

        foreach ($bands as $band => [$from, $to]) {
            $here = $owners[$band] ?? [];

            if ($here === []) {
                $columns[] = ['x0' => $from, 'x1' => $to, 'text' => ''];

                continue;
            }

            usort($here, fn (int $a, int $b) => $cells[$a]['x'] <=> $cells[$b]['x']);
            $start = $from;

            foreach ($here as $position => $index) {
                $next = $here[$position + 1] ?? null;
                $end = $next === null ? $to : (($cells[$index]['x'] + $cells[$index]['x1']) / 2 + ($cells[$next]['x'] + $cells[$next]['x1']) / 2) / 2;
                $columns[] = ['x0' => $start, 'x1' => $end, 'text' => $cells[$index]['text']];
                $start = $end;
            }
        }

        usort($columns, fn (array $a, array $b) => $a['x0'] <=> $b['x0']);

        return $columns;
    }

    /** The band holding x, or the nearest one when x falls in a gap. @param  list<array{0: float, 1: float}>  $bands */
    private function bandAt(float $x, array $bands): int
    {
        $best = 0;
        $distance = PHP_FLOAT_MAX;

        foreach ($bands as $index => [$from, $to]) {
            if ($x >= $from && $x < $to) {
                return $index;
            }

            $gap = $x < $from ? $from - $x : $x - $to;

            if ($gap < $distance) {
                $distance = $gap;
                $best = $index;
            }
        }

        return $best;
    }

    /**
     * The rows: lists of pieces per column, built from the body lines, with the page, the height of the
     * first and the last line, and the body lines each row is made of.
     *
     * @param  list<array{x0: float, x1: float, text: string}>  $columns
     * @return list<array{cells: array<int, list<array<string, mixed>>>, page: int, top: float, bottom: float, lines: list<int>}>
     */
    private function rows(array $columns, array $headingLines): array
    {
        $description = $this->descriptionColumn($columns);
        $ruled = [];
        $rulesByPage = [];
        $pitch = $this->pitch();

        foreach (array_keys($this->pages) as $index) {
            $rulesByPage[$index] = $this->rowRules($index, $headingLines);
            $ruled[$index] = count($rulesByPage[$index]) >= 0.4 * max(1, $this->bodyPage($index)['lines']);
        }

        $rows = [];
        $row = null;
        $previous = null;

        foreach ($this->body as $number => $line) {
            $this->tick();
            $cells = [];

            foreach ($line['pieces'] as $piece) {
                $cells[$this->columnAt($piece['x'], $columns)][] = $piece;
            }

            $text = isset($cells[$description]) ? trim(implode(' ', array_column($cells[$description], 'text'))) : '';
            $onlyDescription = $text !== '' && count($cells) === 1;
            $restart = $this->restarts[$line['page']] ?? null;

            $starts = $previous === null
                || $previous['page'] !== $line['page']
                || ($restart !== null && $previous['y'] > $restart && $line['y'] < $restart)
                || $this->ruleBetween($rulesByPage[$line['page']], $previous['y'], $line['y'])
                || (! $ruled[$line['page']] && 1.15 * $pitch + 0.5 < $previous['y'] - $line['y']);

            if (! $starts && $row !== null) {
                $starts = $onlyDescription
                    ? $this->startsNewRow($text)
                    : $this->repeatsColumn($row['cells'], $cells, $description);
            }

            if ($starts) {
                if ($row !== null) {
                    $rows[] = $row;
                }

                $row = ['cells' => [], 'page' => $line['page'], 'top' => $line['y'], 'bottom' => $line['y'], 'lines' => []];
            }

            foreach ($cells as $column => $pieces) {
                $row['cells'][$column] = [...($row['cells'][$column] ?? []), ...$pieces];
            }

            $row['bottom'] = $line['y'];
            $row['lines'][] = $number;
            $previous = $line;
        }

        if ($row !== null) {
            $rows[] = $row;
        }

        return $rows;
    }

    /** @param  list<array{x0: float, x1: float, text: string}>  $columns */
    private function descriptionColumn(array $columns): int
    {
        return $this->fieldColumn($columns, 'description') ?? 1;
    }

    /** @param  list<array{x0: float, x1: float, text: string}>  $columns  The column whose heading names this field, or null. */
    private function fieldColumn(array $columns, string $field): ?int
    {
        foreach ($columns as $index => $column) {
            if (in_array(self::key($column['text']), BoqExcelService::ALIASES[$field], true)) {
                return $index;
            }
        }

        return null;
    }

    /** @param  list<array{x0: float, x1: float, text: string}>  $columns */
    private function columnAt(float $x, array $columns): int
    {
        $best = 0;
        $distance = PHP_FLOAT_MAX;

        foreach ($columns as $index => $column) {
            if ($x >= $column['x0'] && $x < $column['x1']) {
                return $index;
            }

            $gap = $x < $column['x0'] ? $column['x0'] - $x : $x - $column['x1'];

            if ($gap < $distance) {
                $distance = $gap;
                $best = $index;
            }
        }

        return $best;
    }

    /**
     * The line pitch of wrapped text: the smallest distance from one line to the next that occurs
     * more than once. Rows sit further apart than that because of the padding of their cells.
     */
    private function pitch(): float
    {
        $gaps = [];
        $previous = null;

        foreach ($this->body as $line) {
            if ($previous !== null && $previous['page'] === $line['page']) {
                $gap = round(($previous['y'] - $line['y']) * 2) / 2;

                if ($gap > 0) {
                    $gaps[(string) $gap] = ($gaps[(string) $gap] ?? 0) + 1;
                }
            }

            $previous = $line;
        }

        $common = array_filter($gaps, fn (int $count) => $count >= max(2, (int) ceil(0.05 * array_sum($gaps))));

        if ($common === []) {
            $common = $gaps;
        }

        return $common === [] ? 12.0 : (float) min(array_map('floatval', array_keys($common)));
    }

    /** @param  list<float>  $rules  Row rules on the page, highest first. */
    private function ruleBetween(array $rules, float $above, float $below): bool
    {
        foreach ($rules as $y) {
            if ($y < $above && $y > $below) {
                return true;
            }
        }

        return false;
    }

    /** A description-only line that is plainly a new row: a lettered part, or a total or add-on line. */
    private function startsNewRow(string $text): bool
    {
        return preg_match('/^\(?([a-z]|[ivx]{1,4})[.)]\s+\S/i', $text) === 1
            || preg_match('/^(sub\s*-?\s*)?total\b|^grand\s+total|^say\b|^add\s+[\d.]+\s*%/i', $text) === 1;
    }

    /** A line that fills a column the row already has a value in, other than the description, is another row. */
    private function repeatsColumn(array $row, array $cells, int $description): bool
    {
        foreach ($cells as $column => $pieces) {
            if ($column !== $description && isset($row[$column]) && $row[$column] !== []) {
                return true;
            }
        }

        return false;
    }

    /**
     * Horizontal rules long enough to be row lines, inside the table region of the page, highest first.
     *
     * @return list<float>
     */
    private function rowRules(int $page, array $headingLines): array
    {
        [$top, $bottom] = $this->region($page, $headingLines);

        if ($top === null) {
            return [];
        }

        $width = $this->bodyPage($page)['width'];
        $byY = [];

        foreach ($this->pages[$page]['h'] as [$y, $xa, $xb]) {
            if ($y > $top + 2 || $y < $bottom - 2) {
                continue;
            }

            $byY[(string) (round($y * 2) / 2)][] = [$xa, $xb];
        }

        $rules = [];

        foreach ($byY as $y => $intervals) {
            if ($this->covered($intervals) >= self::ROW_RULE_SHARE * max($width, 1)) {
                $rules[] = (float) $y;
            }
        }

        rsort($rules);

        return $rules;
    }

    /** @return array{0: ?float, 1: float} Top and bottom of the table on a page: below the heading, around the body lines. */
    private function region(int $page, array $headingLines): array
    {
        $top = null;
        $bottom = PHP_FLOAT_MAX;

        foreach ($headingLines as $index) {
            if ($this->lines[$index]['page'] === $page) {
                $top = $top === null ? $this->lines[$index]['y'] + $this->lines[$index]['size'] : $top;
                $bottom = min($bottom, $this->lines[$index]['y'] - 0.3 * $this->lines[$index]['size']);
            }
        }

        $body = $this->bodyPage($page);
        $top ??= $body['top'];
        $bottom = min($bottom, $body['bottom']);

        return [$top, $bottom === PHP_FLOAT_MAX ? 0.0 : $bottom];
    }

    /**
     * The body lines of a page as region(), rows() and rowRules() ask for them, worked out in one pass over the
     * body for every page: how many there are, the top of the first, the bottom of the lowest, the widest.
     *
     * @return array{lines: int, top: ?float, bottom: float, width: float}
     */
    private function bodyPage(int $page): array
    {
        if ($this->bodyPages === null) {
            $this->bodyPages = [];

            foreach ($this->body as $line) {
                $this->tick();
                $here = $this->bodyPages[$line['page']] ?? ['lines' => 0, 'top' => $line['y'] + 1.2 * $line['size'], 'bottom' => PHP_FLOAT_MAX, 'width' => 0.0];
                $here['lines']++;
                $here['bottom'] = min($here['bottom'], $line['y'] - 0.3 * $line['size']);
                $here['width'] = max($here['width'], $line['x1'] - $line['x']);
                $this->bodyPages[$line['page']] = $here;
            }
        }

        return $this->bodyPages[$page] ?? ['lines' => 0, 'top' => null, 'bottom' => PHP_FLOAT_MAX, 'width' => 0.0];
    }

    /** @param  list<array{0: float, 1: float}>  $intervals  @return float Length covered by the union of the intervals. */
    private function covered(array $intervals): float
    {
        usort($intervals, fn (array $a, array $b) => $a[0] <=> $b[0]);
        $length = 0.0;
        $end = null;

        foreach ($intervals as [$from, $to]) {
            if ($to <= $from) {
                continue;
            }

            if ($end === null || $from > $end) {
                $length += $to - $from;
                $end = $to;
            } elseif ($to > $end) {
                $length += $to - $end;
                $end = $to;
            }
        }

        return $length;
    }

    /** Counts a step of work, and every so many steps stops with PdfTableTimedOut when the deadline has passed. */
    private function tick(): void
    {
        if ((++$this->ticks & 1023) === 0) {
            $this->inTime();
        }
    }

    /** @throws PdfTableTimedOut when the deadline has passed */
    private function inTime(): void
    {
        if ($this->deadline !== null && hrtime(true) > $this->deadline) {
            throw new PdfTableTimedOut('The table took longer to put together than the time allowed.');
        }
    }

    private function looksNumeric(string $text): bool
    {
        return preg_match('/^[₹\s]*-?[\d,]*\d(\.\d+)?\s*%?$/u', trim($text)) === 1;
    }

    /** The pieces of one cell joined in reading order. A line ending in a hyphen joins the next without a space. */
    private function cellText(array $pieces): ?string
    {
        if ($pieces === []) {
            return null;
        }

        usort($pieces, fn (array $a, array $b) => $b['y'] <=> $a['y'] ?: $a['x'] <=> $b['x']);
        $text = '';
        $lastY = null;

        foreach ($pieces as $piece) {
            if ($text === '') {
                $text = $piece['text'];
            } elseif ($lastY !== null && abs($piece['y'] - $lastY) > 0.35 * $piece['size'] && str_ends_with($text, '-')) {
                $text .= $piece['text'];
            } else {
                $text .= ' '.$piece['text'];
            }

            $lastY = $piece['y'];
        }

        $text = trim((string) preg_replace('/\s+/u', ' ', $text));

        return $text === '' ? null : $text;
    }
}
