<?php

namespace App\Services\Reports;

use App\Services\ProjectSetup\BoqCategories;
use App\Support\Dec;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * Turns an engineer's estimate sheet into ordinary BOQ rows.
 *
 * An estimate has Sr. No., Description, Qty, Rate, Unit and Amount columns, but it is not one
 * line per row. It has heads with no quantity or rate whose lettered rows carry their own
 * (centering), lettered rows that only say how a line's quantity is made up (RCC), rows with no
 * number or a repeated number, "Add 6% ..." rows holding only an amount, and total rows.
 *
 * The rows that come out have the same columns as the BOQ template and keep their Excel row
 * numbers, so the ordinary checks run on them and report against the row the engineer can see.
 *
 * App\Services\Imports\BoqSheetRows extends this for the column-checked import of any contractor's
 * sheet, and shares its helpers. What standardise() does is unchanged by that.
 */
class EstimateSheet
{
    /** Columns of the rows handed back. */
    protected const OUTPUT = ['section' => 1, 'code' => 2, 'description' => 3, 'unit' => 4, 'qty' => 5, 'rate' => 6, 'category' => 7, 'details' => 8];

    /** Checked in this order, because one description can name several kinds of work. */
    protected const CATEGORY_PATTERNS = [
        'excavation' => '/excavat/i',
        'filling' => '/^filling/i',
        'other' => '/centering|shuttering|formwork/i',
        'steel' => '/hysd|reinforcement bar|\btmt\b/i',
        'pcc' => '/\bp\.?\s*c\.?\s*c\b/i',
        'rcc' => '/\br\.?\s*c\.?\s*c\b|cement concrete\s+m-?\d/i',
        'plaster' => '/plaster/i',
        'brick_work' => '/masonary|masonry|\bbrick|\baac\b/i',
        'flooring' => '/flooring|\btiles?\b|granite/i',
        'plumbing' => '/sanitation|plumbing/i',
        'electrical' => '/electrif|electrical/i',
        'finishing' => '/paint|\bdoors?\b|window|railing|grill|glass/i',
        BoqCategories::OVERHEAD => '/supervision|establishment/i',
    ];

    /** @var array<int, array<string, mixed>> Excel row number => line. */
    private array $lines = [];

    /** @var list<array{row:int,message:string}> */
    protected array $notes = [];

    /** @var array<string, true> */
    protected array $used = [];

    /** The line or head that lettered rows belong to. */
    private ?array $open = null;

    private ?string $lastSerial = null;

    /** The template and our own export have a section column. An estimate has an amount column instead. */
    public static function applies(array $columns): bool
    {
        return isset($columns['amount']) && ! isset($columns['section']);
    }

    /**
     * @param  array<int, array<int, mixed>>  $grid  Excel row number => column => saved value.
     * @param  array<string, int>  $columns  Field => column, as found on the heading row.
     * @return array{0: array<int, array<int, ?string>>, 1: array<string, int>, 2: list<array{row:int,message:string}>, 3: array{file_total: ?string, grand_total: ?string, lines_total: string}} The rows, their columns, the notes, and the totals: the file's "Total" row, its grand total, and what the lines before the add-ons come to.
     */
    public function standardise(array $grid, array $columns, int $headerRow): array
    {
        $fileTotal = null;
        $grandTotal = null;

        foreach ($grid as $number => $cells) {
            if ($number <= $headerRow) {
                continue;
            }

            $row = $this->row($cells, $columns);
            $text = $row['description'];

            if ($text === '') {
                continue;
            }

            if (preg_match('/^(grand\s+total|total\s+estimate|say\b)/i', $text)) {
                if (! preg_match('/^say\b/i', $text)) {
                    $grandTotal = $row['amount'];
                }

                break;
            }

            if (preg_match('/^(sub\s*-?\s*)?total\b/i', $text)) {
                $this->closeHead();
                $fileTotal ??= $row['amount'];

                continue;
            }

            if (preg_match('/^add\s+[\d.]+\s*%/i', $text)) {
                $this->closeHead();
                $this->addOn($number, $row);

                continue;
            }

            if ($this->open !== null && $row['code'] === '' && $row['unit'] === '' && preg_match('/^([a-z])[.)]?\s+(.+)$/iu', $text, $match)) {
                $this->lettered($number, $row, mb_strtolower($match[1]), $match[2]);

                continue;
            }

            $this->closeHead();
            $this->numbered($number, $row);
        }

        $this->closeHead();
        $this->compareWithFile($fileTotal);
        ksort($this->lines);

        return [$this->output(), self::OUTPUT, $this->notes, [
            'file_total' => $fileTotal,
            'grand_total' => $grandTotal,
            'lines_total' => (string) $this->linesTotal(),
        ]];
    }

    /** @return array{code:string,description:string,unit:string,qty:?string,rate:?string,amount:?string,remark:string} */
    private function row(array $cells, array $columns): array
    {
        $get = fn (string $field) => isset($columns[$field]) ? ($cells[$columns[$field]] ?? null) : null;

        return [
            'code' => $this->text($get('code')),
            'description' => $this->text($get('description')),
            'unit' => $this->text($get('unit')),
            'qty' => $this->number($get('qty')),
            'rate' => $this->number($get('rate')),
            'amount' => $this->number($get('amount')),
            'remark' => $this->text($get('remark')),
        ];
    }

    private function numbered(int $number, array $row): void
    {
        ['qty' => $qty, 'rate' => $rate, 'amount' => $amount, 'description' => $text] = $row;

        if ($qty === null && $rate === null && $amount === null && $row['unit'] === '') {
            $this->note($number, __('This row has no quantity, rate or amount, so it was left out.'));

            return;
        }

        $code = $this->code($row['code']);
        $unit = $this->unit($row['unit']);
        $category = $this->category($text);

        if ($qty === null && $rate === null) {
            $this->open = ['head' => true, 'row' => $number, 'code' => $code, 'description' => $text, 'unit' => $unit, 'category' => $category, 'amount' => $amount, 'remark' => $row['remark'], 'children' => 0, 'sum' => BigDecimal::zero()];

            return;
        }

        if ($qty === null && $amount !== null && $rate !== null && ! Dec::of($rate)->isZero()) {
            $qty = (string) Dec::of($amount)->dividedBy(Dec::of($rate), 3, RoundingMode::HalfUp);
            $this->note($number, __('No quantity in the file. It was worked out as amount divided by rate.'));
        }

        if ($qty !== null && $rate !== null && $amount !== null && ! Dec::of($qty)->isZero() && $this->differs(Dec::of($qty)->multipliedBy(Dec::of($rate)), Dec::of($amount))) {
            $rate = (string) Dec::of($amount)->dividedBy(Dec::of($qty), 2, RoundingMode::HalfUp);
            $this->note($number, __('Quantity times rate does not match the amount in the file. The amount was kept, so the rate became :rate.', ['rate' => inr($rate)]));
        }

        if ($unit === '') {
            $unit = 'nos';
            $this->note($number, __('No unit in the file. It was taken as nos.'));
        }

        $this->lines[$number] = $this->line(__('Civil works'), $code, $text, $unit, $qty, $rate, $category, $row['remark']);
        $this->open = ['head' => false, 'row' => $number, 'code' => $code, 'description' => $text, 'unit' => $unit, 'category' => $category];
    }

    /** A lettered row is its own line when it has a rate. Without one it only explains the line above. */
    private function lettered(int $number, array $row, string $letter, string $rest): void
    {
        $open = $this->open;

        if ($row['rate'] === null && ! $open['head']) {
            $this->lines[$open['row']]['parts'][] = $this->part($row);

            if ($row['remark'] !== '') {
                $this->lines[$open['row']]['remarks'][] = $row['remark'];
            }

            return;
        }

        $unit = preg_match('/\b(litres?|liters?|ltrs?)\b/i', $rest) ? 'ltr' : $open['unit'];
        $description = trim((string) preg_replace('/\s+[\d.,]+\s*(litres?|liters?|ltrs?\.?)?\s*$/i', '', $rest)).' - '.$open['description'];

        $this->lines[$number] = $this->line(__('Civil works'), $this->unique($open['code'].$letter), $description, $unit, $row['qty'], $row['rate'], $open['category'], $row['remark']);

        if ($open['head']) {
            $this->open['children']++;

            if ($row['qty'] !== null && $row['rate'] !== null) {
                $this->open['sum'] = $open['sum']->plus(Dec::of($row['qty'])->multipliedBy(Dec::of($row['rate'])));
            }
        }
    }

    /** "Add 6% paint work" has only an amount, so it comes in as one lump sum. */
    private function addOn(int $number, array $row): void
    {
        if (array_filter($this->lines, fn (array $line) => $line['add_on']) === []) {
            $this->note(0, __('Each "Add %" row was imported as one lump-sum line at the amount in the file.'));
        }

        $line = $this->line(__('Add-ons'), $this->code($row['code']), $row['description'], 'LS', '1', $row['amount'], $this->category($row['description']), $row['remark']);
        $this->lines[$number] = ['add_on' => true] + $line;
    }

    /** Finish the head that lettered rows were being added to, and start looking for a new one. */
    private function closeHead(): void
    {
        $head = $this->open;
        $this->open = null;

        if ($head === null || ! $head['head']) {
            return;
        }

        if ($head['children'] === 0) {
            if ($head['amount'] !== null) {
                $this->lines[$head['row']] = $this->line(__('Civil works'), $head['code'], $head['description'], 'LS', '1', $head['amount'], $head['category'], $head['remark']);
            }

            return;
        }

        if ($head['amount'] !== null && $this->differs($head['sum'], Dec::of($head['amount']))) {
            $this->note($head['row'], __('The parts add up to :parts but the file shows :file for this item. The parts were imported.', ['parts' => inr($head['sum']), 'file' => inr($head['amount'])]));
        }
    }

    private function compareWithFile(?string $fileTotal): void
    {
        if ($fileTotal === null) {
            return;
        }

        $sum = $this->linesTotal();

        if ($this->differs($sum, Dec::of($fileTotal))) {
            $this->note(0, __('The lines add up to :lines but the total written in the file is :file. The lines were imported as they are, so please check that total.', ['lines' => inr($sum), 'file' => inr($fileTotal)]));
        }
    }

    /** What the lines before the add-ons come to: the figure a "Total" row in the file states. */
    private function linesTotal(): BigDecimal
    {
        $sum = BigDecimal::zero();

        foreach ($this->lines as $line) {
            if (! $line['add_on'] && $line['qty'] !== null && $line['rate'] !== null) {
                $sum = $sum->plus(Dec::of($line['qty'])->multipliedBy(Dec::of($line['rate'])));
            }
        }

        return $sum;
    }

    private function line(string $section, string $code, string $description, string $unit, ?string $qty, ?string $rate, string $category, string $remark): array
    {
        return [
            'add_on' => false, 'section' => $section, 'code' => $code, 'description' => $description, 'unit' => $unit, 'qty' => $qty, 'rate' => $rate, 'category' => $category,
            'parts' => [], 'remarks' => $remark === '' ? [] : [$remark],
        ];
    }

    /** @return array<int, array<int, ?string>> */
    private function output(): array
    {
        $rows = [];

        foreach ($this->lines as $number => $line) {
            $details = array_filter([
                $line['parts'] === [] ? null : __('Break-up: :parts', ['parts' => implode('; ', $line['parts'])]),
                $line['remarks'] === [] ? null : __('Remark: :text', ['text' => implode('; ', $line['remarks'])]),
            ]);

            $rows[$number] = [
                self::OUTPUT['section'] => $line['section'],
                self::OUTPUT['code'] => $line['code'],
                self::OUTPUT['description'] => $line['description'],
                self::OUTPUT['unit'] => $line['unit'],
                self::OUTPUT['qty'] => $line['qty'],
                self::OUTPUT['rate'] => $line['rate'],
                self::OUTPUT['category'] => $line['category'],
                self::OUTPUT['details'] => $details === [] ? null : implode("\n", $details),
            ];
        }

        return $rows;
    }

    /** "b. Slab 40" with 7 in the quantity column means 40 on each of 7 floors. */
    protected function part(array $row): string
    {
        if ($row['qty'] === null) {
            return $row['description'];
        }

        return preg_match('/\d\s*$/', $row['description']) ? $row['description'].' × '.$row['qty'] : $row['description'].' '.$row['qty'];
    }

    /** The serial number, or the one before it when the row has none. A repeat gets .1, .2 and so on. */
    private function code(string $serial): string
    {
        if ($serial !== '') {
            $this->lastSerial = $serial;
        }

        return $this->unique($this->lastSerial ?? '1');
    }

    protected function unique(string $code): string
    {
        $candidate = $code;
        $repeat = 0;

        while (isset($this->used[$candidate])) {
            $candidate = $code.'.'.++$repeat;
        }

        $this->used[$candidate] = true;

        return $candidate;
    }

    /** "Rs/cu.m" and "Rs/sq.m" are how a rate is written. The BOQ wants the unit alone. */
    private function unit(string $text): string
    {
        $unit = trim((string) preg_replace('/^(rs\.?|inr|₹)\s*\/\s*/iu', '', $text));
        $key = (string) preg_replace('/[^a-z0-9]/', '', mb_strtolower($unit));

        return match (true) {
            in_array($key, ['cum', 'cumt', 'cumtr', 'cubm', 'm3'], true) => 'cum',
            in_array($key, ['sqm', 'sqmt', 'sqmtr', 'm2'], true) => 'sqm',
            in_array($key, ['sqft', 'sft'], true) => 'sqft',
            in_array($key, ['rm', 'rmt', 'rmtr', 'lm'], true) => 'rmt',
            in_array($key, ['m', 'mtr', 'metre', 'meter'], true) => 'm',
            in_array($key, ['mt', 'ton', 'tons', 'tonne'], true) => 'MT',
            default => $unit,
        };
    }

    protected function category(string $text): string
    {
        foreach (self::CATEGORY_PATTERNS as $category => $pattern) {
            if (preg_match($pattern, $text)) {
                return $category;
            }
        }

        return 'other';
    }

    protected function differs(BigDecimal $first, BigDecimal $second): bool
    {
        return $first->minus($second)->abs()->isGreaterThan(1);
    }

    protected function note(int $row, string $message): void
    {
        $this->notes[] = ['row' => $row, 'message' => $message];
    }

    protected function text(mixed $value): string
    {
        if (is_int($value) || is_float($value)) {
            return rtrim(rtrim(number_format((float) $value, 6, '.', ''), '0'), '.');
        }

        return trim((string) preg_replace('/\s+/u', ' ', (string) $value));
    }

    /** An exact decimal string, or null when the cell holds no number. */
    protected function number(mixed $value): ?string
    {
        if (is_int($value) || (is_float($value) && is_finite($value))) {
            return $this->text($value);
        }

        $text = is_string($value) ? str_replace([',', ' ', '₹'], '', $value) : '';

        return preg_match('/^\d+(\.\d+)?$/', $text) ? $text : null;
    }
}
