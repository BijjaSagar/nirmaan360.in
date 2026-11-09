<?php

namespace App\Services\PriceWatch;

use App\Models\MaterialPrice;
use App\Models\PriceWatchMaterial;
use App\Models\User;
use App\Services\Reports\Spreadsheet;
use App\Support\Dec;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx as XlsxReader;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet as Workbook;
use Throwable;
use ZipArchive;

/**
 * Prices in bulk from an Excel sheet: material, rate, unit, date, source. The template is the
 * board itself with the price cells blank. A sheet is checked whole and imported whole: one bad
 * row and nothing is saved, so a half-loaded list never passes for a complete one.
 *
 * Values only are read. A formula cell gives the result Excel saved with it; nothing is calculated here.
 */
class PriceSheet
{
    public const COLUMNS = ['material', 'rate', 'unit', 'date', 'source'];

    /** Header text people write, lower-cased with punctuation dropped. */
    private const ALIASES = [
        'material' => ['material', 'material name', 'item', 'name'],
        'rate' => ['rate', 'price', 'current rate', 'current price', 'rate inr', 'rate rs', 'price inr', 'price rs'],
        'unit' => ['unit', 'units', 'uom', 'per'],
        'date' => ['date', 'as on', 'as on date', 'priced on', 'price date'],
        'source' => ['source', 'from', 'where from', 'supplier', 'remark', 'remarks'],
    ];

    private const DATE_FORMATS = ['!Y-m-d', '!d/m/Y', '!d-m-Y', '!d.m.Y', '!d M Y', '!d F Y', '!Y/m/d'];

    private const MAX_UNPACKED_BYTES = 20 * 1024 * 1024;

    /** What the board's columns hold: name varchar(160), unit varchar(20), rate decimal(15,2). The web form has the same limits. */
    private const MAX_NAME = 160;

    private const MAX_UNIT = 20;

    private const MAX_RATE = '9999999999999';

    public function __construct(private PriceRecorder $recorder) {}

    /**
     * The board as a workbook with blank price cells, today's date filled in, as .xlsx bytes.
     *
     * @param  Collection<int, array<string, mixed>>  $rows  Board rows from PriceBoard::rows()
     */
    public function template(Collection $rows, ?CarbonInterface $today = null): string
    {
        $today ??= today();
        $book = new Workbook;
        $sheet = $book->getActiveSheet();
        $sheet->setTitle('Prices');

        foreach ([__('Material'), __('Rate (₹)'), __('Unit'), __('Date'), __('Source')] as $index => $heading) {
            Spreadsheet::text($sheet, $index + 1, 1, $heading);
        }

        Spreadsheet::header($sheet, 1, count(self::COLUMNS));

        foreach ($rows->values() as $index => $row) {
            Spreadsheet::text($sheet, 1, $index + 2, $row['name']);
            Spreadsheet::text($sheet, 3, $index + 2, $row['unit']);
            Spreadsheet::text($sheet, 4, $index + 2, $today->toDateString());
        }

        foreach ([1 => 28, 2 => 14, 3 => 10, 4 => 14, 5 => 30] as $column => $width) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($column))->setWidth($width);
        }

        $handle = fopen('php://memory', 'w+');
        IOFactory::createWriter($book, 'Xlsx')->save($handle);
        rewind($handle);
        $bytes = (string) stream_get_contents($handle);
        fclose($handle);
        $book->disconnectWorksheets();

        return $bytes;
    }

    /**
     * Check the whole sheet, then save it whole.
     *
     * @return array{errors: list<string>, added: int, unchanged: int, created: int}
     */
    public function import(int $organizationId, string $path, bool $addMissing, ?User $by = null, ?CarbonInterface $today = null): array
    {
        $today ??= today();
        $nothing = ['added' => 0, 'unchanged' => 0, 'created' => 0];
        [$grid, $fileError] = $this->read($path);

        if ($fileError !== null) {
            return ['errors' => [$fileError], ...$nothing];
        }

        [$headerRow, $columns] = $this->findHeader($grid);

        if ($headerRow === null) {
            return ['errors' => [__('The sheet needs a heading row with material, rate, unit, date and source. Download the template to see the layout.')], ...$nothing];
        }

        $materials = PriceWatchMaterial::withoutGlobalScopes()->where('organization_id', $organizationId)->get();
        $rows = [];
        $errors = [];
        $max = (int) config('modules.price_watch.upload_max_rows', 500);

        foreach ($grid as $number => $cells) {
            if ($number <= $headerRow) {
                continue;
            }

            $read = fn (string $column) => isset($columns[$column]) ? $this->cellText($cells[$columns[$column]] ?? null) : '';

            if ($read('material') === '' && $read('rate') === '' && $read('date') === '') {
                continue;
            }

            if (count($rows) >= $max) {
                $errors[] = __('A sheet may hold up to :max price rows.', ['max' => $max]);
                break;
            }

            $name = $read('material');
            $material = $this->match($materials, $name);

            if ($name === '') {
                $errors[] = __('Row :row: the material is missing.', ['row' => $number]);
            } elseif (mb_strlen($name) > self::MAX_NAME) {
                $errors[] = __('Row :row: the material name is longer than :max characters.', ['row' => $number, 'max' => self::MAX_NAME]);
            } elseif ($material === null && ! $addMissing) {
                $errors[] = __('Row :row: ":name" is not on the board. Add it first, or tick "add new materials from the sheet".', ['row' => $number, 'name' => $name]);
            }

            $unit = $read('unit');

            if ($material !== null && $unit !== '' && strcasecmp($unit, $material->unit) !== 0) {
                $errors[] = __('Row :row: :name is priced per :unit on the board, not per :given.', ['row' => $number, 'name' => $material->label(), 'unit' => $material->unit, 'given' => $unit]);
            } elseif ($material === null && $unit === '') {
                $errors[] = __('Row :row: give a unit for ":name", for example bag or kg.', ['row' => $number, 'name' => $name]);
            } elseif ($material === null && mb_strlen($unit) > self::MAX_UNIT) {
                $errors[] = __('Row :row: the unit is longer than :max characters.', ['row' => $number, 'max' => self::MAX_UNIT]);
            }

            $rate = $this->rate($cells[$columns['rate']] ?? null);

            if ($rate === null) {
                $errors[] = __('Row :row: the rate must be a number of rupees, zero or more.', ['row' => $number]);
            } elseif (Dec::of($rate)->isGreaterThan(self::MAX_RATE)) {
                $errors[] = __('Row :row: the rate is larger than :max.', ['row' => $number, 'max' => inr(self::MAX_RATE)]);
            }

            $date = $this->date($cells[$columns['date']] ?? null);

            if ($date === null) {
                $errors[] = __('Row :row: the date is missing or unreadable. Write it as 2026-11-05 or 05/11/2026.', ['row' => $number]);
            } elseif ($date > $today->toDateString()) {
                $errors[] = __('Row :row: the date is in the future.', ['row' => $number]);
            }

            $rows[] = ['name' => $name, 'material' => $material, 'unit' => $unit, 'rate' => $rate, 'date' => $date, 'source' => mb_substr($read('source'), 0, 160)];
        }

        if ($rows === [] && $errors === []) {
            $errors[] = __('The sheet has no price rows under the heading row.');
        }

        if ($errors !== []) {
            return ['errors' => $errors, ...$nothing];
        }

        return DB::transaction(function () use ($organizationId, $rows, $by) {
            $result = ['errors' => [], 'added' => 0, 'unchanged' => 0, 'created' => 0];
            $created = [];

            foreach ($rows as $row) {
                $material = $row['material'] ?? ($created[mb_strtolower($row['name'])] ?? null);

                if ($material === null) {
                    $material = $this->recorder->addMaterial($organizationId, ['name' => $row['name'], 'unit' => $row['unit']], $by);
                    $created[mb_strtolower($row['name'])] = $material;
                    $result['created']++;
                }

                $saved = $this->recorder->record($material, ['rate' => $row['rate'], 'priced_on' => $row['date'], 'source' => $row['source']], $by, MaterialPrice::EXCEL);
                $saved === null ? $result['unchanged']++ : $result['added']++;
            }

            return $result;
        });
    }

    /** @return array{0: array<int, array<int, mixed>>, 1: ?string} The sheet as rows of cells by column index, or a file error. */
    private function read(string $path): array
    {
        $reader = new XlsxReader;

        if (! $reader->canRead($path) || $this->unpackedSize($path) > self::MAX_UNPACKED_BYTES) {
            return [[], __('This is not a valid .xlsx workbook. Save it from Excel as "Excel Workbook (.xlsx)" and try again.')];
        }

        $limit = (int) config('modules.price_watch.upload_max_rows', 500) + 20;

        $reader->setReadFilter(new class($limit) implements IReadFilter
        {
            public function __construct(private int $limit) {}

            public function readCell(string $columnAddress, int $row, string $worksheetName = ''): bool
            {
                return $row <= $this->limit && Coordinate::columnIndexFromString($columnAddress) <= 8;
            }
        });

        try {
            $book = $reader->load($path);
            $sheet = $book->getSheet(0);
        } catch (Throwable) {
            return [[], __('This is not a valid .xlsx workbook. Save it from Excel as "Excel Workbook (.xlsx)" and try again.')];
        }

        $grid = [];

        foreach ($sheet->getRowIterator(1, min($sheet->getHighestDataRow(), $limit)) as $row) {
            $cells = $row->getCellIterator('A', 'H');
            $cells->setIterateOnlyExistingCells(true);

            foreach ($cells as $cell) {
                $grid[$row->getRowIndex()][Coordinate::columnIndexFromString($cell->getColumn())] = $cell->isFormula() ? $cell->getOldCalculatedValue() : $cell->getValue();
            }
        }

        $book->disconnectWorksheets();

        return [$grid, null];
    }

    private function unpackedSize(string $path): int
    {
        $zip = new ZipArchive;

        if ($zip->open($path) !== true) {
            return PHP_INT_MAX;
        }

        $size = 0;

        for ($index = 0; $index < $zip->numFiles; $index++) {
            $size += (int) ($zip->statIndex($index)['size'] ?? 0);
        }

        $zip->close();

        return $size;
    }

    /** @return array{0: ?int, 1: array<string, int>} The heading row number and column index of each known heading. */
    private function findHeader(array $grid): array
    {
        foreach (array_slice($grid, 0, 10, true) as $number => $cells) {
            $columns = [];

            foreach ($cells as $index => $value) {
                $text = $this->normalise($this->cellText($value));

                foreach (self::ALIASES as $column => $aliases) {
                    if (! isset($columns[$column]) && in_array($text, $aliases, true)) {
                        $columns[$column] = $index;
                    }
                }
            }

            if (isset($columns['material'], $columns['rate'], $columns['date'])) {
                return [$number, $columns];
            }
        }

        return [null, []];
    }

    private function match(Collection $materials, string $name): ?PriceWatchMaterial
    {
        $wanted = mb_strtolower($name);

        if ($wanted === '') {
            return null;
        }

        return $materials->first(fn (PriceWatchMaterial $material) => mb_strtolower($material->name) === $wanted
            || mb_strtolower($material->label()) === $wanted
            || ($material->key !== null && $material->key === $this->normalise($name, '_')));
    }

    private function normalise(string $text, string $space = ' '): string
    {
        return trim((string) preg_replace('/\s+/', $space, (string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', mb_strtolower($text))));
    }

    private function cellText(mixed $value): string
    {
        if ($value === null || is_bool($value)) {
            return '';
        }

        return trim((string) $value);
    }

    /** A rupee figure rounded to the paisa, or null when it is not a number at or above zero. */
    private function rate(mixed $value): ?string
    {
        $text = str_replace([',', '₹', 'Rs', 'rs', ' '], '', $this->cellText($value));

        if ($text === '' || ! is_numeric($text) || str_starts_with($text, '-')) {
            return null;
        }

        return Dec::money($text);
    }

    /** Y-m-d from an Excel date number or any of the usual written forms, or null. */
    private function date(mixed $value): ?string
    {
        $text = $this->cellText($value);

        if ($text === '') {
            return null;
        }

        if (is_numeric($text) && ! str_contains($text, '-') && ! str_contains($text, '/') && (float) $text > 20000 && (float) $text < 80000) {
            try {
                return ExcelDate::excelToDateTimeObject((float) $text)->format('Y-m-d');
            } catch (Throwable) {
                return null;
            }
        }

        foreach (self::DATE_FORMATS as $format) {
            try {
                $parsed = Carbon::createFromFormat($format, $text);
            } catch (Throwable) {
                continue;
            }

            if ($parsed !== false && $parsed->format(ltrim($format, '!')) === $text) {
                return $parsed->toDateString();
            }
        }

        return null;
    }
}
