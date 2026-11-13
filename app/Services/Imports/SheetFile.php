<?php

namespace App\Services\Imports;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Reader\BaseReader;
use PhpOffice\PhpSpreadsheet\Reader\IReadFilter;
use PhpOffice\PhpSpreadsheet\Reader\Ods as OdsReader;
use PhpOffice\PhpSpreadsheet\Reader\Xls as XlsReader;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx as XlsxReader;
use PhpOffice\PhpSpreadsheet\RichText\RichText;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Throwable;
use ZipArchive;

/**
 * Reads the sheets of an uploaded BOQ file into grids of values, whatever its name says.
 *
 * The kind of file is decided by its content: a zip with a workbook in it is .xlsx, a zip with an
 * OpenDocument spreadsheet is .ods, an OLE file is a BIFF .xls, and plain text is CSV. So a ".xls"
 * that Excel really saved as .xlsx imports like any other.
 *
 * Values only are read. A formula cell gives the result saved with it and is never calculated, so a
 * file cannot make the server run a formula. Rows and columns past the limits are not read at all,
 * and a zip that unpacks to more than MAX_UNPACKED_BYTES is refused.
 */
final class SheetFile
{
    public const XLSX = 'xlsx';

    public const XLS = 'xls';

    public const ODS = 'ods';

    public const CSV = 'csv';

    /** Columns read from each sheet: A to Z. */
    public const MAX_COLUMNS = 26;

    /** At most this many sheets of a workbook are read. */
    public const MAX_SHEETS = 12;

    /** The same guard as the plain Excel import: a small zip can unpack to a huge file. */
    public const MAX_UNPACKED_BYTES = 60 * 1024 * 1024;

    /** CSV files bigger than this are refused; the upload limit is lower anyway. */
    private const MAX_CSV_BYTES = 8 * 1024 * 1024;

    /** What kind of file this is, by its content, or null when it is none of the kinds read here. */
    public static function sniff(string $path): ?string
    {
        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            return null;
        }

        $head = (string) fread($handle, 8);
        fclose($handle);

        if (str_starts_with($head, "PK\x03\x04")) {
            $zip = new ZipArchive;

            if ($zip->open($path) !== true) {
                return null;
            }

            $kind = match (true) {
                $zip->locateName('xl/workbook.xml') !== false => self::XLSX,
                str_contains((string) $zip->getFromName('mimetype'), 'opendocument.spreadsheet') => self::ODS,
                default => null,
            };
            $zip->close();

            return $kind;
        }

        if ($head === "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1") {
            return (new XlsReader)->canRead($path) ? self::XLS : null;
        }

        return self::looksLikeText($path) ? self::CSV : null;
    }

    /**
     * Every sheet of the file as a grid, or an error to show the person.
     *
     * @return array{format: ?string, sheets: list<array{name: string, grid: array<int, array<int, mixed>>, missing: array<int, array<int, true>>, merges: list<array{0:int,1:int,2:int,3:int}>, truncated: bool, hidden: bool}>, error: ?string}
     */
    public static function read(string $path, int $maxRows): array
    {
        $format = self::sniff($path);

        if ($format === null) {
            return ['format' => null, 'sheets' => [], 'error' => __('This file is not a spreadsheet that can be read. Upload an Excel workbook (.xlsx or .xls), an OpenDocument sheet (.ods) or a CSV file.')];
        }

        if (in_array($format, [self::XLSX, self::ODS], true) && self::unpackedSize($path) > self::MAX_UNPACKED_BYTES) {
            return ['format' => $format, 'sheets' => [], 'error' => __('This workbook is too large when unpacked. Remove other sheets, pictures or formatting and try again.')];
        }

        try {
            $sheets = $format === self::CSV ? self::readCsv($path, $maxRows) : self::readWorkbook($path, $format, $maxRows);
        } catch (Throwable) {
            return ['format' => $format, 'sheets' => [], 'error' => __('This file could not be read. Open it in Excel, save it again as .xlsx and upload it again.')];
        }

        if ($sheets === []) {
            return ['format' => $format, 'sheets' => [], 'error' => __('This file has no sheet with anything in it.')];
        }

        return ['format' => $format, 'sheets' => $sheets, 'error' => null];
    }

    private static function readWorkbook(string $path, string $format, int $maxRows): array
    {
        /** @var BaseReader $reader */
        $reader = match ($format) {
            self::XLSX => new XlsxReader,
            self::XLS => new XlsReader,
            self::ODS => new OdsReader,
        };

        $limit = $maxRows + 20;
        $reader->setReadFilter(new class($limit) implements IReadFilter
        {
            public function __construct(private int $limit) {}

            public function readCell(string $columnAddress, int $row, string $worksheetName = ''): bool
            {
                return $row <= $this->limit + 1 && Coordinate::columnIndexFromString($columnAddress) <= SheetFile::MAX_COLUMNS;
            }
        });

        $book = $reader->load($path);
        $sheets = [];

        try {
            foreach ($book->getWorksheetIterator() as $index => $sheet) {
                if ($index >= self::MAX_SHEETS) {
                    break;
                }

                $read = self::readSheet($sheet, $limit);

                if ($read['grid'] !== []) {
                    $sheets[] = $read;
                }
            }
        } finally {
            $book->disconnectWorksheets();
        }

        return $sheets;
    }

    private static function readSheet(Worksheet $sheet, int $limit): array
    {
        $grid = [];
        $missing = [];
        $highest = $sheet->getHighestDataRow();

        foreach ($sheet->getRowIterator(1, max(1, min($highest, $limit))) as $row) {
            $cells = $row->getCellIterator('A', Coordinate::stringFromColumnIndex(self::MAX_COLUMNS));
            $cells->setIterateOnlyExistingCells(true);

            foreach ($cells as $cell) {
                $value = $cell->isFormula() ? $cell->getOldCalculatedValue() : $cell->getValue();
                $column = Coordinate::columnIndexFromString($cell->getColumn());

                if ($value instanceof RichText) {
                    $value = $value->getPlainText();
                }

                if ($cell->isFormula() && ($value === null || $value === '')) {
                    $missing[$row->getRowIndex()][$column] = true;
                }

                if ($value === null || (is_string($value) && trim($value) === '')) {
                    continue;
                }

                $grid[$row->getRowIndex()][$column] = is_bool($value) ? ($value ? 'TRUE' : 'FALSE') : $value;
            }
        }

        $merges = [];

        foreach ($sheet->getMergeCells() as $range) {
            [$start, $end] = array_pad(Coordinate::rangeBoundaries($range), 2, null);

            if ($start !== null && $end !== null && $start[1] <= $limit) {
                $merges[] = [(int) $start[1], (int) $start[0], (int) $end[1], (int) $end[0]];
            }
        }

        ksort($grid);

        return [
            'name' => mb_substr($sheet->getTitle(), 0, 60),
            'grid' => $grid,
            'missing' => $missing,
            'merges' => array_slice($merges, 0, 500),
            'truncated' => $highest > $limit,
            'hidden' => $sheet->getSheetState() !== Worksheet::SHEETSTATE_VISIBLE,
        ];
    }

    /** One sheet from a CSV file. The separator is a comma, a semicolon or a tab, whichever the first lines use. */
    private static function readCsv(string $path, int $maxRows): array
    {
        $content = (string) file_get_contents($path, length: self::MAX_CSV_BYTES);
        $content = (string) preg_replace('/^\xEF\xBB\xBF/', '', $content);

        if (str_starts_with($content, "\xFF\xFE") || str_starts_with($content, "\xFE\xFF")) {
            $content = (string) mb_convert_encoding(substr($content, 2), 'UTF-8', str_starts_with($content, "\xFF\xFE") ? 'UTF-16LE' : 'UTF-16BE');
        } elseif (! mb_check_encoding($content, 'UTF-8')) {
            $content = (string) mb_convert_encoding($content, 'UTF-8', 'Windows-1252');
        }

        $sample = implode("\n", array_slice(preg_split('/\r\n|\r|\n/', $content, 12) ?: [], 0, 10));
        $separator = ',';
        $most = substr_count($sample, ',');

        foreach ([';', "\t"] as $candidate) {
            if (substr_count($sample, $candidate) > $most) {
                $separator = $candidate;
                $most = substr_count($sample, $candidate);
            }
        }

        $handle = fopen('php://temp', 'r+');
        fwrite($handle, $content);
        rewind($handle);

        $grid = [];
        $number = 0;
        $truncated = false;

        while (($cells = fgetcsv($handle, 0, $separator, '"', '')) !== false) {
            $number++;

            if ($number > $maxRows + 20) {
                $truncated = true;
                break;
            }

            foreach (array_slice($cells, 0, self::MAX_COLUMNS) as $index => $value) {
                if ($value !== null && trim($value) !== '') {
                    $grid[$number][$index + 1] = trim($value);
                }
            }
        }

        fclose($handle);

        return $grid === [] ? [] : [['name' => 'CSV', 'grid' => $grid, 'missing' => [], 'merges' => [], 'truncated' => $truncated, 'hidden' => false]];
    }

    /** Text with no control bytes in its first part: what a CSV file is. */
    private static function looksLikeText(string $path): bool
    {
        $sample = (string) file_get_contents($path, length: 65536);

        if ($sample === '' || str_contains($sample, "\0") && ! str_starts_with($sample, "\xFF\xFE") && ! str_starts_with($sample, "\xFE\xFF")) {
            return false;
        }

        $controls = preg_match_all('/[\x01-\x08\x0B\x0C\x0E-\x1A\x1C-\x1F]/', $sample);

        return $controls === 0 || $controls * 100 < strlen($sample);
    }

    private static function unpackedSize(string $path): int
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
}
