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
     * Add or update lines from a BOQ file. A line whose code already exists is updated.
     * Nothing is saved unless every row is valid. The file's content decides how it is read, not its name.
     */
    public function import(CrmTender $tender, string $path, string $extension): int
    {
        $rows = $this->readRows($tender, $path);

        return DB::transaction(function () use ($tender, $rows) {
            $tender = $this->lockEditable($tender);
            $sort = (int) $tender->boqItems()->max('sort');
            $byCode = $tender->boqItems()->get()->keyBy('code');

            foreach ($rows as $row) {
                $values = $this->values($row);

                if ($existing = $byCode->get($row['code'])) {
                    $existing->update($values);

                    continue;
                }

                $byCode->put($row['code'], CrmTenderBoqItem::create([
                    ...$values,
                    'organization_id' => $tender->organization_id,
                    'tender_id' => $tender->id,
                    'uuid' => (string) Str::uuid(),
                    'sort' => ++$sort,
                ]));
            }

            return count($rows);
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

    /** @return list<array<string,string>> */
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

                $candidates[$index + 2] = array_map(fn ($cell) => trim((string) $cell), array_combine($header, $cells));
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
            $row['qty'] = str_replace([',', ' '], '', (string) $row['qty']);
            $row['rate'] = str_replace([',', ' '], '', (string) $row['rate']);

            if ($row['code'] === '' || $row['description'] === '' || $row['unit'] === ''
                || ! is_numeric($row['qty']) || ! is_numeric($row['rate']) || $row['qty'] < 0 || $row['rate'] < 0
                || $row['qty'] > 99999999999 || $row['rate'] > 9999999999999 || mb_strlen($row['description']) > 2000
                || mb_strlen($row['code']) > 32 || mb_strlen($row['unit']) > 16 || mb_strlen((string) $row['section']) > 120) {
                $errors[] = __('Line :line is incomplete or has an invalid number.', ['line' => $line]);

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
