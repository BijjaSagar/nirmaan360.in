<?php

namespace App\Services\Reports;

use App\Models\BoqItem;
use App\Models\Concerns\OrganizationScope;
use App\Models\MeasurementEntry;
use App\Models\Project;
use App\Services\BoqService;
use App\Services\ProjectSetup\BoqCategories;
use App\Services\ProjectSetup\BoqRevisions;
use App\Support\Dec;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx as XlsxReader;
use PhpOffice\PhpSpreadsheet\Spreadsheet as Workbook;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Throwable;
use ZipArchive;

/**
 * Excel (.xlsx) BOQ export and import, next to the CSV in BoqController.
 *
 * The import reads values only. A formula cell is taken from the result Excel saved with it and is
 * never calculated here, so an uploaded file cannot make the server run a formula.
 */
class BoqExcelService
{
    public const MAX_ROWS = 5000;

    /** Columns of the export and the template. The import needs every one except section. */
    public const COLUMNS = ['section', 'code', 'description', 'unit', 'qty', 'rate'];

    private const REQUIRED = ['code', 'description', 'unit', 'qty', 'rate'];

    /** Read when the sheet has them. A sheet without them still imports, and leaves them as they are. */
    public const OPTIONAL = ['category', 'details'];

    /** Header text people commonly use, after lower-casing and dropping punctuation. */
    private const ALIASES = [
        'section' => ['section', 'chapter', 'section name', 'group'],
        'code' => ['code', 'item code', 'item no', 'item number', 'sr no', 'sl no', 's no'],
        'description' => ['description', 'item description', 'description of item', 'particulars'],
        'unit' => ['unit', 'units', 'uom'],
        'qty' => ['qty', 'quantity', 'boq qty', 'boq quantity'],
        'rate' => ['rate', 'unit rate', 'rate inr', 'rate rs', 'rate in inr'],
        'category' => ['category', 'work category', 'type of work', 'head of work'],
        'details' => ['details', 'detail', 'item details', 'specification', 'specifications'],
    ];

    /** Sheets bigger than this when unpacked are refused. A small file can unpack to a huge one. */
    private const MAX_UNPACKED_BYTES = 60 * 1024 * 1024;

    public function __construct(private BoqService $boq) {}

    /** The project's BOQ as an .xlsx file, in the same columns the import reads. */
    public function export(Project $project): string
    {
        $items = $project->boqItems()->with('section')->orderBy('sort')->orderBy('id')->get();

        $book = new Workbook;
        $sheet = $book->getActiveSheet();
        $sheet->setTitle('BOQ');

        $this->headerRow($sheet, [...self::COLUMNS, 'amount', ...self::OPTIONAL]);

        $row = 1;

        foreach ($items as $item) {
            $row++;
            Spreadsheet::text($sheet, 1, $row, $item->section?->name);
            Spreadsheet::text($sheet, 2, $row, $item->code);
            Spreadsheet::text($sheet, 3, $row, $item->description);
            Spreadsheet::text($sheet, 4, $row, $item->unit);
            Spreadsheet::number($sheet, 5, $row, Dec::qty($item->qty), Spreadsheet::QTY_FORMAT);
            Spreadsheet::number($sheet, 6, $row, Dec::money($item->rate), Spreadsheet::MONEY_FORMAT);
            Spreadsheet::number($sheet, 7, $row, $item->amount(), Spreadsheet::MONEY_FORMAT);
            Spreadsheet::text($sheet, 8, $row, $item->category);
            Spreadsheet::text($sheet, 9, $row, $item->details);
        }

        $this->widths($sheet, 9);

        return Spreadsheet::bytes($book);
    }

    /** An empty workbook with the right headings, two example rows and a notes sheet. */
    public function template(): string
    {
        $book = new Workbook;
        $sheet = $book->getActiveSheet();
        $sheet->setTitle('BOQ');
        $this->headerRow($sheet, [...self::COLUMNS, ...self::OPTIONAL]);

        foreach ([
            ['Substructure', '1.1', 'Earthwork in excavation in all soils', 'cum', '250.000', '185.00', 'excavation', ''],
            ['Superstructure', '2.1', 'RCC M25 in slab including shuttering', 'cum', '120.000', '7450.00', 'rcc', 'M25 grade, 150 mm thick, with centering and shuttering.'],
        ] as $index => $values) {
            $row = $index + 2;
            Spreadsheet::text($sheet, 1, $row, $values[0]);
            Spreadsheet::text($sheet, 2, $row, $values[1]);
            Spreadsheet::text($sheet, 3, $row, $values[2]);
            Spreadsheet::text($sheet, 4, $row, $values[3]);
            Spreadsheet::number($sheet, 5, $row, $values[4], Spreadsheet::QTY_FORMAT);
            Spreadsheet::number($sheet, 6, $row, $values[5], Spreadsheet::MONEY_FORMAT);
            Spreadsheet::text($sheet, 7, $row, $values[6]);
            Spreadsheet::text($sheet, 8, $row, $values[7]);
        }

        $this->widths($sheet, 8);

        $notes = $book->createSheet();
        $notes->setTitle('Notes');

        foreach ([
            __('Fill the BOQ sheet. Keep the headings in the first row.'),
            __('Section may be left blank. Item code, description, unit, quantity and rate are needed on every row.'),
            __('A row whose item code already exists in the project updates that item. Other rows are added.'),
            __('If any row has a problem, nothing is imported and each problem is listed with its row number.'),
            __('Use values, not formulas, in quantity and rate.'),
            __('Category and details are optional. Category is one of: :categories', ['categories' => implode(', ', BoqCategories::keys())]),
        ] as $index => $line) {
            Spreadsheet::text($notes, 1, $index + 1, $line);
        }

        $notes->getColumnDimension('A')->setWidth(110);
        $book->setActiveSheetIndex(0);

        return Spreadsheet::bytes($book);
    }

    /** Check the file against the project and, only if it is clean, add and update BOQ lines. */
    public function import(Project $project, string $path): BoqImportResult
    {
        return DB::transaction(function () use ($project, $path) {
            // The same lock BoqService::create takes, so an import and a hand-added line cannot race for sort order.
            $project = Project::lockForUpdate()->findOrFail($project->id);

            [$rows, $errors] = $this->check($project, $path);

            if ($errors !== []) {
                return new BoqImportResult($errors);
            }

            $existing = $this->existingByCode($project);
            $sort = (int) $project->boqItems()->max('sort');
            $created = $updated = $unchanged = 0;

            BoqRevisions::with(__('Imported from an Excel file'), 'excel_import', function () use ($rows, $project, $existing, &$sort, &$created, &$updated, &$unchanged) {
                foreach ($rows as $row) {
                    $values = [
                        'boq_section_id' => $this->boq->sectionId($project, $row['section']),
                        'description' => $row['description'],
                        'unit' => $row['unit'],
                        'qty' => Dec::qty($row['qty']),
                        'rate' => Dec::money($row['rate']),
                        ...Arr::only($row, self::OPTIONAL),
                    ];

                    if ($item = $existing->get($row['code'])) {
                        $item->update($values);
                        $item->wasChanged() ? $updated++ : $unchanged++;

                        continue;
                    }

                    BoqItem::create([
                        ...$values,
                        'organization_id' => $project->organization_id,
                        'project_id' => $project->id,
                        'code' => $row['code'],
                        'sort' => ++$sort,
                    ]);
                    $created++;
                }
            }, batch: true);

            return new BoqImportResult([], $created, $updated, $unchanged);
        });
    }

    /**
     * Read the file and list every problem. Returns the clean rows and the errors.
     *
     * @return array{0: list<array<string,string>>, 1: list<array{row:int,field:?string,message:string}>}
     */
    public function check(Project $project, string $path): array
    {
        [$grid, $missingValue, $fileError] = $this->read($path);

        if ($fileError !== null) {
            return [[], [['row' => 0, 'field' => null, 'message' => $fileError]]];
        }

        [$headerRow, $columns] = $this->findHeader($grid);

        if ($headerRow === null) {
            return [[], [['row' => 0, 'field' => null, 'message' => __('Could not find the heading row. The sheet needs these columns: :columns', ['columns' => implode(', ', self::REQUIRED)])]]];
        }

        $existing = $this->existingByCode($project);
        $approved = MeasurementEntry::withoutGlobalScope(OrganizationScope::class)
            ->where('organization_id', $project->organization_id)->where('project_id', $project->id)->where('status', 'APPROVED')
            ->selectRaw('boq_item_id, sum(qty) as total')->groupBy('boq_item_id')->pluck('total', 'boq_item_id');

        $rows = [];
        $errors = [];
        $seen = [];

        foreach ($grid as $number => $cells) {
            if ($number <= $headerRow) {
                continue;
            }

            $get = fn (string $field) => isset($columns[$field]) ? ($cells[$columns[$field]] ?? null) : null;

            if (collect(array_keys($columns))->every(fn ($field) => $this->blank($get($field)) && ! isset($missingValue[$number][$columns[$field]]))) {
                continue;
            }

            if (count($rows) + 1 > self::MAX_ROWS) {
                $errors[] = ['row' => $number, 'field' => null, 'message' => __('The sheet has more than :max rows. Split it into smaller files.', ['max' => self::MAX_ROWS])];
                break;
            }

            $problems = [];
            $fail = function (string $field, string $message) use (&$problems, $number) {
                $problems[] = ['row' => $number, 'field' => $field, 'message' => $message];
            };

            $text = [];

            foreach (['section', 'code', 'description', 'unit'] as $field) {
                $text[$field] = $this->text($get($field));
            }

            foreach (['code' => 32, 'description' => 2000, 'unit' => 16, 'section' => 120] as $field => $max) {
                if (in_array($field, self::REQUIRED, true) && $text[$field] === '') {
                    $fail($field, __(':field is missing.', ['field' => $this->label($field)]));
                } elseif (mb_strlen($text[$field]) > $max) {
                    $fail($field, __(':field is longer than :max characters.', ['field' => $this->label($field), 'max' => $max]));
                }
            }

            $optional = [];

            foreach (self::OPTIONAL as $field) {
                if (! isset($columns[$field])) {
                    continue;
                }

                $value = $this->text($get($field));

                if ($field === 'category' && $value !== '') {
                    $key = BoqCategories::fromText($value);

                    if ($key === null) {
                        $fail($field, __('Category :value is not recognised. Use one of: :categories', ['value' => $value, 'categories' => implode(', ', BoqCategories::keys())]));
                    }

                    $value = (string) $key;
                }

                if ($field === 'details' && mb_strlen($value) > 10000) {
                    $fail($field, __(':field is longer than :max characters.', ['field' => $this->label($field), 'max' => 10000]));
                }

                $optional[$field] = $value === '' ? null : $value;
            }

            $numbers = [];

            foreach (['qty' => '99999999999', 'rate' => '9999999999999'] as $field => $max) {
                if (isset($missingValue[$number][$columns[$field]])) {
                    $fail($field, __(':field is a formula with no saved value. Paste it as a value and try again.', ['field' => $this->label($field)]));

                    continue;
                }

                $numbers[$field] = $this->number($get($field), $max, $field, $fail);
            }

            if ($text['code'] !== '') {
                if (isset($seen[$text['code']])) {
                    $fail('code', __('Item code :code is already used in row :row.', ['code' => $text['code'], 'row' => $seen[$text['code']]]));
                }

                $seen[$text['code']] ??= $number;
            }

            // The same rule as editing a line by hand: the BOQ cannot go below what is already approved.
            if (isset($numbers['qty']) && ($item = $existing->get($text['code']))) {
                $approvedQty = Dec::of($approved->get($item->id) ?? 0);

                if ($approvedQty->isGreaterThan(Dec::of($numbers['qty']))) {
                    $fail('qty', __('Quantity cannot be lower than the approved quantity (:qty) for item :code.', ['qty' => fmt_qty($approvedQty), 'code' => $text['code']]));
                }
            }

            if ($problems !== []) {
                array_push($errors, ...$problems);

                continue;
            }

            $rows[] = [...$text, 'qty' => $numbers['qty'], 'rate' => $numbers['rate'], ...$optional];
        }

        if ($errors === [] && $rows === []) {
            $errors[] = ['row' => 0, 'field' => null, 'message' => __('The file has no rows.')];
        }

        return [$rows, $errors];
    }

    /** @return Collection<string, BoqItem> The lowest id wins when a code repeats. */
    private function existingByCode(Project $project)
    {
        return $project->boqItems()->orderBy('id')->get()->unique('code')->keyBy('code');
    }

    /**
     * @return array{0: array<int, array<int, mixed>>, 1: array<int, array<int, true>>, 2: ?string} Row => column => value, formula cells without a saved value, and a problem with the file.
     */
    private function read(string $path): array
    {
        $reader = new XlsxReader;

        if (! $reader->canRead($path) || $this->unpackedSize($path) > self::MAX_UNPACKED_BYTES) {
            return [[], [], __('This is not a valid .xlsx workbook. Save it from Excel as "Excel Workbook (.xlsx)" and try again.')];
        }

        $reader->setReadFilter(new class implements IReadFilter
        {
            public function readCell(string $columnAddress, int $row, string $worksheetName = ''): bool
            {
                return $row <= BoqExcelService::MAX_ROWS + 20 && Coordinate::columnIndexFromString($columnAddress) <= 12;
            }
        });

        try {
            $book = $reader->load($path);
            $sheet = $book->sheetNameExists('BOQ') ? $book->getSheetByName('BOQ') : $book->getSheet(0);
        } catch (Throwable) {
            return [[], [], __('This is not a valid .xlsx workbook. Save it from Excel as "Excel Workbook (.xlsx)" and try again.')];
        }

        $grid = [];
        $missing = [];

        foreach ($sheet->getRowIterator(1, min($sheet->getHighestDataRow(), self::MAX_ROWS + 20)) as $row) {
            $cells = $row->getCellIterator('A', 'L');
            $cells->setIterateOnlyExistingCells(true);

            foreach ($cells as $cell) {
                $value = $cell->isFormula() ? $cell->getOldCalculatedValue() : $cell->getValue();

                if ($cell->isFormula() && $value === null) {
                    $missing[$row->getRowIndex()][Coordinate::columnIndexFromString($cell->getColumn())] = true;
                }

                $grid[$row->getRowIndex()][Coordinate::columnIndexFromString($cell->getColumn())] = $value;
            }
        }

        $book->disconnectWorksheets();

        return [$grid, $missing, null];
    }

    /** @return array{0: ?int, 1: array<string,int>} The heading row number and each field's column. */
    private function findHeader(array $grid): array
    {
        foreach (array_slice($grid, 0, 10, true) as $number => $cells) {
            $columns = [];

            foreach ($cells as $column => $value) {
                $name = trim((string) preg_replace('/\s+/', ' ', (string) preg_replace('/[^a-z0-9 ]/', '', mb_strtolower((string) $value))));

                foreach (self::ALIASES as $field => $aliases) {
                    if (in_array($name, $aliases, true)) {
                        $columns[$field] ??= $column;
                    }
                }
            }

            if (array_diff(self::REQUIRED, array_keys($columns)) === []) {
                return [$number, $columns];
            }
        }

        return [null, []];
    }

    private function blank(mixed $value): bool
    {
        return $value === null || trim((string) $value) === '';
    }

    private function text(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        // A code typed as a number, such as 12 or 1.5, must not pick up a trailing .0 or float noise.
        if (is_int($value) || is_float($value)) {
            return rtrim(rtrim(number_format((float) $value, 6, '.', ''), '0'), '.');
        }

        return trim((string) $value);
    }

    /** @return ?string An exact decimal string, or null when the cell is not an allowed number. */
    private function number(mixed $value, string $max, string $field, callable $fail): ?string
    {
        if ($this->blank($value)) {
            $fail($field, __(':field is missing.', ['field' => $this->label($field)]));

            return null;
        }

        if (is_int($value) || is_float($value)) {
            $string = is_float($value) && ! is_finite($value) ? 'x' : number_format((float) $value, 6, '.', '');
        } elseif (is_string($value)) {
            $string = str_replace([',', ' ', '₹'], '', $value);
        } else {
            $string = 'x';
        }

        if (! preg_match('/^-?\d+(\.\d+)?$/', $string)) {
            $fail($field, __(':field must be a number.', ['field' => $this->label($field)]));

            return null;
        }

        if (str_starts_with($string, '-') && ! Dec::of($string)->isZero()) {
            $fail($field, __(':field cannot be negative.', ['field' => $this->label($field)]));

            return null;
        }

        if (Dec::of($string)->isGreaterThan(Dec::of($max))) {
            $fail($field, __(':field is too large.', ['field' => $this->label($field)]));

            return null;
        }

        return ltrim($string, '-');
    }

    private function label(string $field): string
    {
        return match ($field) {
            'section' => __('Section'),
            'code' => __('Item code'),
            'description' => __('Description'),
            'unit' => __('Unit'),
            'qty' => __('Quantity'),
            'rate' => __('Rate'),
            'category' => __('Category'),
            'details' => __('Details'),
        };
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

    /** @param  list<string>  $headings */
    private function headerRow(Worksheet $sheet, array $headings): void
    {
        foreach ($headings as $index => $heading) {
            Spreadsheet::text($sheet, $index + 1, 1, $heading);
        }

        Spreadsheet::header($sheet, 1, count($headings));
        $sheet->freezePane('A2');
    }

    private function widths(Worksheet $sheet, int $columns): void
    {
        foreach (array_slice([1 => 22, 2 => 12, 3 => 60, 4 => 9, 5 => 14, 6 => 16, 7 => 18], 0, $columns, true) as $column => $width) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($column))->setWidth($width);
        }
    }
}
