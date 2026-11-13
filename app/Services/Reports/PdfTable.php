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
 */
final class PdfTable
{
    /** A line this close to the heading line, in multiples of the text size, can be the rest of a two-line heading. */
    private const HEADING_GAP = 2.2;

    /** Vertical rules shorter than this share of the table height are not column lines. */
    private const COLUMN_RULE_SHARE = 0.3;

    /** Horizontal rules shorter than this share of the table width are underlines, not row lines. */
    private const ROW_RULE_SHARE = 0.4;

    /** @var list<array{page: int, y: float, size: float, x: float, x1: float, pieces: list<array<string, mixed>>}> */
    private array $lines = [];

    /** @var list<array{page: int, y: float, size: float, pieces: list<array<string, mixed>>, cells: array<int, list<array<string, mixed>>>}> */
    private array $body = [];

    /** @var list<int> The lines the heading spans, by their place among the lines of the pages. */
    private array $headingLines = [];

    /**
     * @param  list<array{pieces: list<array{x: float, x1: float, y: float, size: float, text: string}>, h: list<array{0: float, 1: float, 2: float}>, v: list<array{0: float, 1: float, 2: float}>}>  $pages
     */
    public function __construct(private array $pages) {}

    /** @return array<int, array<int, ?string>> Empty when no heading row was found. */
    public function grid(): array
    {
        $this->lines = $this->lines();
        $heading = $this->heading();

        if ($heading === null) {
            $this->headingLines = $this->body = [];

            return [];
        }

        [$cells, $headingLines] = $heading;
        $this->headingLines = $headingLines;
        $this->body = $this->body($headingLines, $cells);
        [$x0, $x1] = $this->span($cells);
        $bands = $this->ruleBands($cells, $headingLines, $x0, $x1) ?? $this->gapBands($cells, $x0, $x1);
        $columns = $this->columns($cells, $bands);

        $grid = [1 => []];

        foreach ($columns as $index => $column) {
            $grid[1][$index + 1] = $column['text'] === '' ? null : $column['text'];
        }

        $number = 1;

        foreach ($this->rows($columns, $headingLines) as $row) {
            $number++;

            foreach ($columns as $index => $column) {
                $grid[$number][$index + 1] = $this->cellText($row[$index] ?? []);
            }
        }

        return $grid;
    }

    /**
     * Whether a piece of text the grid is made from carries this mark, as the caller set it on the
     * pieces: a piece of the heading or of a line after it. A title or anything else above the heading
     * is not in the grid. Asked after grid(); false when no heading was found.
     */
    public function keeps(string $mark): bool
    {
        foreach ([...array_map(fn (int $index) => $this->lines[$index], $this->headingLines), ...$this->body] as $line) {
            foreach ($line['pieces'] as $piece) {
                if (! empty($piece[$mark])) {
                    return true;
                }
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

        foreach ($lines as &$line) {
            usort($line['pieces'], fn (array $a, array $b) => $a['x'] <=> $b['x']);
            $sizes = array_column($line['pieces'], 'size');
            sort($sizes);
            $line['size'] = $sizes[intdiv(count($sizes), 2)];
            $line['x'] = min(array_column($line['pieces'], 'x'));
            $line['x1'] = max(array_column($line['pieces'], 'x1'));
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
     * The lines after the heading, minus the heading repeated at the top of later pages.
     *
     * @param  list<int>  $headingLines
     */
    private function body(array $headingLines, array $cells): array
    {
        $known = [];

        foreach ($cells as $cell) {
            $known[self::key($cell['text'])] = true;

            foreach ($cell['keys'] as $key) {
                $known[$key] = true;
            }
        }

        $body = [];

        foreach ($this->lines as $index => $line) {
            if ($index <= max($headingLines)) {
                continue;
            }

            $repeated = true;

            foreach ($line['pieces'] as $piece) {
                if (! isset($known[self::key($piece['text'])])) {
                    $repeated = false;
                    break;
                }
            }

            if (! $repeated) {
                $body[] = $line;
            }
        }

        return $body;
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
     * The rows: lists of pieces per column, built from the body lines.
     *
     * @param  list<array{x0: float, x1: float, text: string}>  $columns
     * @return list<array<int, list<array<string, mixed>>>>
     */
    private function rows(array $columns, array $headingLines): array
    {
        $description = $this->descriptionColumn($columns);
        $ruled = [];
        $rulesByPage = [];
        $pitch = $this->pitch();

        foreach (array_keys($this->pages) as $index) {
            $rulesByPage[$index] = $this->rowRules($index, $headingLines);
            $lines = count(array_filter($this->body, fn (array $line) => $line['page'] === $index));
            $ruled[$index] = count($rulesByPage[$index]) >= 0.4 * max(1, $lines);
        }

        $rows = [];
        $row = null;
        $previous = null;

        foreach ($this->body as $line) {
            $cells = [];

            foreach ($line['pieces'] as $piece) {
                $cells[$this->columnAt($piece['x'], $columns)][] = $piece;
            }

            $text = isset($cells[$description]) ? trim(implode(' ', array_column($cells[$description], 'text'))) : '';
            $onlyDescription = $text !== '' && count($cells) === 1;

            $starts = $previous === null
                || $previous['page'] !== $line['page']
                || $this->ruleBetween($rulesByPage[$line['page']], $previous['y'], $line['y'])
                || (! $ruled[$line['page']] && 1.15 * $pitch + 0.5 < $previous['y'] - $line['y']);

            if (! $starts && $row !== null) {
                $starts = $onlyDescription
                    ? $this->startsNewRow($text)
                    : $this->repeatsColumn($row, $cells, $description);
            }

            if ($starts) {
                if ($row !== null) {
                    $rows[] = $row;
                }

                $row = [];
            }

            foreach ($cells as $column => $pieces) {
                $row[$column] = [...($row[$column] ?? []), ...$pieces];
            }

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
        foreach ($columns as $index => $column) {
            if (in_array(self::key($column['text']), BoqExcelService::ALIASES['description'], true)) {
                return $index;
            }
        }

        return 1;
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

        $width = 0.0;
        $byY = [];

        foreach ($this->body as $line) {
            if ($line['page'] === $page) {
                $width = max($width, $line['x1'] - $line['x']);
            }
        }

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

        foreach ($this->body as $line) {
            if ($line['page'] === $page) {
                $top ??= $line['y'] + 1.2 * $line['size'];
                $bottom = min($bottom, $line['y'] - 0.3 * $line['size']);
            }
        }

        return [$top, $bottom === PHP_FLOAT_MAX ? 0.0 : $bottom];
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
