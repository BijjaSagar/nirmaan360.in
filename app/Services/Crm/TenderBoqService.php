<?php

namespace App\Services\Crm;

use App\Models\CrmTender;
use App\Models\CrmTenderBoqItem;
use App\Services\Imports\BoqSheetImport;
use App\Services\Imports\SheetFile;
use App\Support\Dec;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;

/**
 * The BOQ a tender is priced on. When the tender is won and converted, these lines become
 * the project's BOQ through BoqService. Same columns as the project BOQ template.
 *
 * An import in the template's columns is read as it always was. Any other sheet (the department's or
 * the client's own BOQ, .xlsx, .xls, .ods or .csv) is read by its own headings through BoqSheetImport,
 * with the same checks on every row.
 */
class TenderBoqService
{
    public const COLUMNS = ['section', 'code', 'description', 'unit', 'qty', 'rate'];

    public const MAX_IMPORT_ROWS = 3000;

    /** The most rows one import may carry. Tests lower it instead of building a huge file. */
    public int $maxImportRows = self::MAX_IMPORT_ROWS;

    public function rules(): array
    {
        return [
            'section' => ['nullable', 'string', 'max:120'],
            'code' => ['required', 'string', 'max:32'],
            'description' => ['required', 'string', 'max:2000'],
            'unit' => ['required', 'string', 'max:16'],
            'qty' => ['required', 'numeric', 'min:0', 'max:99999999999'],
            'rate' => ['required', 'numeric', 'min:0', 'max:9999999999999'],
        ];
    }

    public function add(CrmTender $tender, array $data): CrmTenderBoqItem
    {
        return DB::transaction(function () use ($tender, $data) {
            $tender = $this->lockEditable($tender);

            return CrmTenderBoqItem::create([
                ...$this->values($data),
                'organization_id' => $tender->organization_id,
                'tender_id' => $tender->id,
                'uuid' => (string) Str::uuid(),
                'sort' => (int) $tender->boqItems()->max('sort') + 1,
            ]);
        });
    }

    public function update(CrmTenderBoqItem $item, array $data): CrmTenderBoqItem
    {
        $this->lockEditable($item->tender);
        $item->update($this->values($data));

        return $item;
    }

    public function delete(CrmTenderBoqItem $item): void
    {
        $this->lockEditable($item->tender);
        $item->delete();
    }

    /**
     * Add or update lines from a BOQ file. Nothing is saved unless every row is valid. The file's content decides how
     * it is read, not its name. There is no preview on this page, so a line updates one already on the tender only
     * when it plainly is that line:
     *
     * - its code is the sheet's own item code (every code of a file in the template's columns), as the import always
     *   took it, or
     * - its code, read from the sheet's serial numbers or made up from the order of the rows, belongs to a line with
     *   the same description. A serial number is only where the line stands: when a corrigendum adds or drops an item,
     *   the numbers after it move to other items. Such a line is added under the next free code (2 becomes 2-2) and
     *   the line already there is left as it was, as the column check of a project's BOQ does; the result names them.
     *
     * A sheet in its own layout may not give a line's rate or quantity (no such column, a blank cell, a rate-only
     * line); it is read as 0. A line that updates one already there keeps that line's own figure then, so a
     * department's revised schedule or corrigendum, which has no rates, updates the quantities and never wipes the
     * rates quoted on the tender, nor gives a line the rate quoted for another. So does a figure the reader works out
     * from the amount (quantity as amount over rate, rate as amount over quantity, 1 lump sum at the amount): the
     * department's estimated amount over the quantity is its own rate, not the one quoted. A line that is added takes
     * such figures, as on the column check. The result names the lines that kept their own.
     *
     * @return array{count: int, renumbered: list<array{from: string, to: string}>, kept: list<string>} The rows imported, the lines added under a new code, and the codes of the lines already there that kept their own quantity or rate.
     */
    public function import(CrmTender $tender, string $path, string $extension): array
    {
        $rows = $this->readRows($tender, $path);

        return DB::transaction(function () use ($tender, $rows) {
            $tender = $this->lockEditable($tender);
            $sort = (int) $tender->boqItems()->max('sort');
            $byCode = $tender->boqItems()->get()->keyBy(fn (CrmTenderBoqItem $item) => (string) $item->code);
            $used = array_flip(array_map(fn (array $row) => (string) $row['code'], $rows));
            $sameLine = fn (?CrmTenderBoqItem $line, array $row) => $line !== null && BoqSheetImport::sameWording((string) $line->description, (string) $row['description']);
            $renumbered = [];
            $kept = [];

            foreach ($rows as $row) {
                $existing = $byCode->get((string) $row['code']);

                if ($existing !== null && $row['origin'] !== 'code' && ! $sameLine($existing, $row)) {
                    $code = BoqSheetImport::nextCode((string) $row['code'], $used, fn (string $code) => ! $byCode->has($code) || $sameLine($byCode->get($code), $row));
                    $used[$code] = true;
                    $renumbered[] = ['from' => (string) $row['code'], 'to' => $code];
                    $row['code'] = $code;
                    $existing = $byCode->get($code);
                }

                $values = $this->values($row);

                if ($existing !== null) {
                    // What the file does not give, and what was worked out from its amount, stays as the line has it.
                    $own = [...$row['filled'], ...$row['derived']];
                    $existing->update(array_diff_key($values, array_flip($own)));

                    if (array_intersect($own, ['qty', 'rate']) !== []) {
                        $kept[] = (string) $row['code'];
                    }

                    continue;
                }

                $byCode->put((string) $row['code'], CrmTenderBoqItem::create([
                    ...$values,
                    'organization_id' => $tender->organization_id,
                    'tender_id' => $tender->id,
                    'uuid' => (string) Str::uuid(),
                    'sort' => ++$sort,
                ]));
            }

            return ['count' => count($rows), 'renumbered' => $renumbered, 'kept' => $kept];
        });
    }

    public function total(CrmTender $tender): string
    {
        return Dec::money($tender->boqItems()->get()->reduce(
            fn ($sum, CrmTenderBoqItem $item) => $sum->plus(Dec::of($item->amount())),
            Dec::of(0),
        ));
    }

    /** The tender row, locked, and only while its BOQ can still change. */
    private function lockEditable(CrmTender $tender): CrmTender
    {
        $tender = CrmTender::query()->where('organization_id', $tender->organization_id)->lockForUpdate()->findOrFail($tender->id);

        if ($tender->isConverted()) {
            throw ValidationException::withMessages(['boq' => __('This tender has become a project. Change the BOQ in the project.')]);
        }

        return $tender;
    }

    private function values(array $data): array
    {
        return [
            'section' => filled($data['section'] ?? null) ? $data['section'] : null,
            'code' => $data['code'],
            'description' => $data['description'],
            'unit' => $data['unit'],
            'qty' => Dec::qty($data['qty']),
            'rate' => Dec::money($data['rate']),
        ];
    }

    /** @return list<array<string, mixed>> Each row's fields, in "filled" the figures (qty, rate) the file does not give, in "derived" those worked out from its amount (and a unit put in with them), and in "origin" where its code came from (BoqSheetImport::rowsFor). */
    private function readRows(CrmTender $tender, string $path): array
    {
        $matrix = match (SheetFile::sniff($path)) {
            SheetFile::XLSX => $this->readSpreadsheet($path),
            SheetFile::CSV => $this->readCsv($path),
            null => throw ValidationException::withMessages(['file' => __('That spreadsheet could not be read. Save it as .xlsx or CSV and try again.')]),
            default => null,
        };
        $header = $matrix === null ? [] : array_map(fn ($cell) => strtolower(trim((string) preg_replace('/^\xEF\xBB\xBF/', '', (string) $cell))), array_shift($matrix) ?? []);
        $candidates = [];

        if ($matrix !== null && array_diff(self::COLUMNS, $header) === []) {
            foreach ($matrix as $index => $cells) {
                $cells = array_pad(array_slice($cells, 0, count($header)), count($header), '');

                if (collect($cells)->every(fn ($cell) => trim((string) $cell) === '')) {
                    continue;
                }

                // The template's code column holds the item codes.
                $candidates[$index + 2] = [...array_map(fn ($cell) => trim((string) $cell), array_combine($header, $cells)), 'origin' => 'code'];
            }
        } else {
            // Not the template: the sheet is read by its own headings.
            $read = app(BoqSheetImport::class)->rowsFor($tender->organization_id, $path, $this->maxImportRows);

            if ($read['error'] !== null) {
                throw ValidationException::withMessages(['file' => $read['error']]);
            }

            $candidates = $read['rows'];
        }

        $rows = [];
        $errors = [];

        foreach ($candidates as $line => $row) {
            $row['section'] ??= '';
            // Only the sheet reader says which figures the file left out or it worked out; a template row gives every figure it has.
            $row['filled'] = array_values(array_intersect(is_array($row['filled'] ?? null) ? $row['filled'] : [], ['qty', 'rate']));
            $row['derived'] = array_values(array_intersect(is_array($row['derived'] ?? null) ? $row['derived'] : [], ['qty', 'rate', 'unit']));
            // A code no reader vouches for is not taken as an item code.
            $row['origin'] = in_array($row['origin'] ?? null, ['code', 'serial'], true) ? $row['origin'] : 'made';
            $row['qty'] = str_replace([',', ' '], '', (string) $row['qty']);
            $row['rate'] = str_replace([',', ' '], '', (string) $row['rate']);

            // Name what is wrong with the line, so the person knows what to change in the file.
            $problem = match (true) {
                $row['code'] === '' => __('Line :line has no item code.', ['line' => $line]),
                $row['description'] === '' => __('Line :line has no description.', ['line' => $line]),
                $row['unit'] === '' => __('Line :line has no unit. Add the unit to that row and upload the file again.', ['line' => $line]),
                ! is_numeric($row['qty']) || ! is_numeric($row['rate']) || $row['qty'] < 0 || $row['rate'] < 0
                    || $row['qty'] > 99999999999 || $row['rate'] > 9999999999999 || mb_strlen($row['description']) > 2000
                    || mb_strlen($row['code']) > 32 || mb_strlen($row['unit']) > 16 || mb_strlen((string) $row['section']) > 120 => __('Line :line is incomplete or has an invalid number.', ['line' => $line]),
                default => null,
            };

            if ($problem !== null) {
                $errors[] = $problem;

                continue;
            }

            $rows[] = $row;
        }

        if ($errors !== [] || $rows === []) {
            throw ValidationException::withMessages(['file' => $errors ? array_slice($errors, 0, 10) : [__('The file has no rows.')]]);
        }

        if (count($rows) > $this->maxImportRows) {
            throw ValidationException::withMessages(['file' => __('Import at most :count rows at a time.', ['count' => $this->maxImportRows])]);
        }

        return $rows;
    }

    /** @return list<list<mixed>> */
    private function readCsv(string $path): array
    {
        $handle = fopen($path, 'r');
        $matrix = [];

        while (($cells = fgetcsv($handle, 0, ',', '"', '')) !== false) {
            $matrix[] = $cells;
        }

        fclose($handle);

        return $matrix;
    }

    /** @return list<list<mixed>> */
    private function readSpreadsheet(string $path): array
    {
        $limit = $this->maxImportRows + 1;

        try {
            $reader = IOFactory::createReader('Xlsx');
            $reader->setReadDataOnly(true);
            // Read one row past the limit, so a huge sheet is refused without loading all of it.
            $reader->setReadFilter(new class($limit) implements IReadFilter
            {
                public function __construct(private int $limit) {}

                public function readCell(string $columnAddress, int $row, string $worksheetName = ''): bool
                {
                    return $row <= $this->limit + 1;
                }
            });

            $sheet = $reader->load($path)->getActiveSheet();

            if ($sheet->getHighestDataRow() > $limit) {
                throw ValidationException::withMessages(['file' => __('Import at most :count rows at a time.', ['count' => $this->maxImportRows])]);
            }

            return array_values($sheet->toArray(null, true, false, false));
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (\Throwable) {
            throw ValidationException::withMessages(['file' => __('That spreadsheet could not be read. Save it as .xlsx or CSV and try again.')]);
        }
    }
}
