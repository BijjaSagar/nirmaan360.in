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
use XMLReader;
use ZipArchive;

/**
 * Reads the sheets of an uploaded BOQ file into grids of values, whatever its name says.
 *
 * The kind of file is decided by its content: a zip with a workbook in it is .xlsx, a zip with an
 * OpenDocument spreadsheet is .ods, an OLE file is a BIFF .xls, and plain text is CSV (UTF-8, UTF-16
 * with or without its mark, or Windows-1252). So a ".xls" that Excel really saved as .xlsx imports
 * like any other.
 *
 * Values only are read. A formula cell gives the result saved with it and is never calculated, so a
 * file cannot make the server run a formula; one saved with no result at all is listed in "missing".
 * Rows and columns past the limits are not read, but a sheet with values past them is marked, so
 * nothing is dropped unseen. A zip that unpacks to more than MAX_UNPACKED_BYTES is refused.
 */
final class SheetFile
{
    public const XLSX = 'xlsx';

    public const XLS = 'xls';

    public const ODS = 'ods';

    public const CSV = 'csv';

    /** Columns read from each sheet: A to AZ, so a measurement sheet with a column per floor still has its rate. */
    public const MAX_COLUMNS = 52;

    /** At most this many sheets of a workbook are read. */
    public const MAX_SHEETS = 12;

    /** The same guard as the plain Excel import: a small zip can unpack to a huge file. */
    public const MAX_UNPACKED_BYTES = 60 * 1024 * 1024;

    /** Merged ranges kept per sheet: the heading's, and those holding an item's text over its rows. */
    private const MAX_MERGES = 5000;

    /** CSV files bigger than this are refused; the upload limit is lower anyway. */
    private const MAX_CSV_BYTES = 8 * 1024 * 1024;

    /** Rows past the limit looked at in one go for a value, so a sheet formatted far down is never loaded whole. */
    private const WINDOW_ROWS = 5000;

    /** Rows from the top whose text past the last column read is kept, so a heading out there can be named. */
    private const BEYOND_HEADING_ROWS = 60;

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
     * "truncated" is true when the sheet has a value in a row past $maxRows (and the heading rows allowed
     * above them), "wide" when it has one in a column past MAX_COLUMNS; "beyond" holds the text near the top of
     * such columns (column letter => text), so a heading cut off there can be named. "texts" holds number cells as
     * the sheet shows them where a zero format adds digits (21.3 shown as 21.30, 11010 as 011010), so an item code
     * keeps its zeros. Hidden sheets are read too; macros are never run.
     *
     * @return array{format: ?string, sheets: list<array{name: string, grid: array<int, array<int, mixed>>, missing: array<int, array<int, true>>, merges: list<array{0:int,1:int,2:int,3:int}>, truncated: bool, wide: bool, hidden: bool, texts: array<int, array<int, string>>, beyond: array<string, string>}>, error: ?string}
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
        $reader = self::reader($format);
        $limit = $maxRows + 20;
        // Cells past the limits are never loaded. The filter only notes which sheets were offered one.
        $filter = new class($limit) implements IReadFilter
        {
            /** @var array<string, array{rows: bool, columns: bool, last: int}> */
            public array $beyond = [];

            public function __construct(private int $limit) {}

            public function readCell(string $columnAddress, int $row, string $worksheetName = ''): bool
            {
                $rows = $row > $this->limit;
                $columns = Coordinate::columnIndexFromString($columnAddress) > SheetFile::MAX_COLUMNS;

                if ($rows || $columns) {
                    $this->beyond[$worksheetName]['rows'] = ($this->beyond[$worksheetName]['rows'] ?? false) || $rows;
                    $this->beyond[$worksheetName]['columns'] = ($this->beyond[$worksheetName]['columns'] ?? false) || $columns;
                    $this->beyond[$worksheetName]['last'] = max($this->beyond[$worksheetName]['last'] ?? 0, $row);

                    return false;
                }

                return true;
            }
        };
        $reader->setReadFilter($filter);
        // An empty cell holds nothing to read; with this a BIFF file offers the filter only cells with values.
        $reader->setReadEmptyCells(false);

        $book = $reader->load($path);
        $sheets = [];

        try {
            foreach ($book->getWorksheetIterator() as $index => $sheet) {
                if ($index >= self::MAX_SHEETS) {
                    break;
                }

                $read = self::readSheet($sheet, $limit);
                $offered = $filter->beyond[$sheet->getTitle()] ?? null;

                if ($offered !== null) {
                    // The filter is offered empty cells too, formatted ones, so whether they hold a value is looked up.
                    $values = self::valuesBeyond($path, $format, $sheet->getTitle(), $limit, $offered);
                    $read['truncated'] = $offered['rows'] && $values['rows'];
                    $read['wide'] = $offered['columns'] && $values['columns'];
                    $read['beyond'] = $read['wide'] ? $values['headings'] : [];
                }

                if ($read['grid'] !== []) {
                    $sheets[] = $read;
                }
            }
        } finally {
            $book->disconnectWorksheets();
        }

        return $sheets;
    }

    private static function reader(string $format): BaseReader
    {
        return match ($format) {
            self::XLSX => new XlsxReader,
            self::XLS => new XlsReader,
            self::ODS => new OdsReader,
        };
    }

    /**
     * Whether a sheet holds a value in a row past $limit, and in a column past MAX_COLUMNS. A cell with a
     * border, a space, or a formula whose saved result is empty holds none. An .xlsx sheet is streamed, so
     * nothing is loaded; a BIFF .xls or an OpenDocument sheet is loaded again with values only, only past
     * the limits, a window of rows at a time.
     *
     * @param  array{rows: bool, columns: bool, last: int}  $offered  What the first reading was offered past the limits.
     * @return array{rows: bool, columns: bool}
     */
    private static function valuesBeyond(string $path, string $format, string $sheetName, int $limit, array $offered): array
    {
        if ($format === self::XLSX) {
            return self::xlsxValuesBeyond($path, $sheetName, $limit);
        }

        $found = ['rows' => false, 'columns' => false, 'headings' => []];
        $windows = $offered['columns'] ? [[1, $limit]] : [];

        for ($from = $limit + 1; $offered['rows'] && $from <= $offered['last']; $from += self::WINDOW_ROWS) {
            $windows[] = [$from, min($offered['last'], $from + self::WINDOW_ROWS - 1)];
        }

        foreach ($windows as [$from, $to]) {
            if (($found['columns'] || ! $offered['columns']) && ($found['rows'] || $to <= $limit)) {
                continue;
            }

            $reader = self::reader($format);
            $reader->setReadDataOnly(true);
            $reader->setReadEmptyCells(false);
            $reader->setLoadSheetsOnly([$sheetName]);
            $reader->setReadFilter(new class($from, $to, $limit) implements IReadFilter
            {
                public function __construct(private int $from, private int $to, private int $limit) {}

                public function readCell(string $columnAddress, int $row, string $worksheetName = ''): bool
                {
                    return $row >= $this->from && $row <= $this->to
                        && ($row > $this->limit || Coordinate::columnIndexFromString($columnAddress) > SheetFile::MAX_COLUMNS);
                }
            });

            $book = $reader->load($path);

            try {
                foreach ($book->getSheetCount() > 0 ? $book->getSheet(0)->getCellCollection()->getCoordinates() : [] as $coordinate) {
                    $cell = $book->getSheet(0)->getCell($coordinate);
                    $value = $cell->isFormula() ? $cell->getOldCalculatedValue() : $cell->getValue();
                    $value = $value instanceof RichText ? $value->getPlainText() : $value;

                    if ($value === null || (is_string($value) && trim($value) === '')) {
                        continue;
                    }

                    [$column, $row] = Coordinate::coordinateFromString($coordinate);
                    $found['rows'] = $found['rows'] || (int) $row > $limit;
                    $found['columns'] = $found['columns'] || Coordinate::columnIndexFromString($column) > self::MAX_COLUMNS;

                    if (Coordinate::columnIndexFromString($column) > self::MAX_COLUMNS && (int) $row <= self::BEYOND_HEADING_ROWS && is_string($value)) {
                        $found['headings'][$column] = trim(($found['headings'][$column] ?? '').' '.mb_substr(trim($value), 0, 80));
                    }
                }
            } finally {
                $book->disconnectWorksheets();
            }

            if ($found['rows'] && ($found['columns'] || ! $offered['columns'])) {
                break;
            }
        }

        return $found;
    }

    /**
     * The xlsx sheet's XML read as a stream for a value past the limits. A shared string is looked up, since
     * the cell holds only its number in the list of strings: a space typed far down is not a value.
     *
     * @return array{rows: bool, columns: bool, headings: array<string, string>}
     */
    private static function xlsxValuesBeyond(string $path, string $sheetName, int $limit): array
    {
        $found = ['rows' => false, 'columns' => false, 'headings' => []];
        $entry = self::xlsxSheetEntry($path, $sheetName);
        $xml = new XMLReader;

        if ($entry === null || ! @$xml->open('zip://'.$path.'#'.$entry, null, LIBXML_NONET | LIBXML_COMPACT)) {
            // When the sheet cannot be looked into, what the filter was offered stands.
            return ['rows' => true, 'columns' => true, 'headings' => []];
        }

        $row = 0;
        $column = 0;
        $type = '';
        /** @var array<int, array{rows: bool, columns: bool}> Shared string number => where cells holding it are. */
        $shared = [];
        /** @var array<int, list<string>> Shared string number => columns past the last read, near the top, that hold it. */
        $headingStrings = [];

        try {
            while (@$xml->read()) {
                if ($xml->nodeType !== XMLReader::ELEMENT) {
                    continue;
                }

                if ($xml->localName === 'row') {
                    $row = (int) ($xml->getAttribute('r') ?? 0) ?: $row + 1;
                    $column = 0;
                } elseif ($xml->localName === 'c') {
                    $reference = (string) $xml->getAttribute('r');
                    $column = preg_match('/^([A-Z]{1,3})(\d+)$/', $reference, $match) === 1 ? Coordinate::columnIndexFromString($match[1]) : $column + 1;
                    $row = isset($match[2]) && $reference !== '' ? (int) $match[2] : $row;
                    $type = (string) $xml->getAttribute('t');
                } elseif (($xml->localName === 'v' || $xml->localName === 't') && ($row > $limit || $column > self::MAX_COLUMNS) && ($text = trim($xml->readString())) !== '') {
                    if ($xml->localName === 'v' && $type === 's' && ctype_digit($text)) {
                        $shared[(int) $text]['rows'] = ($shared[(int) $text]['rows'] ?? false) || $row > $limit;
                        $shared[(int) $text]['columns'] = ($shared[(int) $text]['columns'] ?? false) || $column > self::MAX_COLUMNS;

                        if ($column > self::MAX_COLUMNS && $row <= self::BEYOND_HEADING_ROWS) {
                            $headingStrings[(int) $text][] = Coordinate::stringFromColumnIndex($column);
                        }

                        continue;
                    }

                    $found['rows'] = $found['rows'] || $row > $limit;
                    $found['columns'] = $found['columns'] || $column > self::MAX_COLUMNS;

                    if ($column > self::MAX_COLUMNS && $row <= self::BEYOND_HEADING_ROWS && ($type === 'inlineStr' || $type === 'str')) {
                        $letter = Coordinate::stringFromColumnIndex($column);
                        $found['headings'][$letter] = trim(($found['headings'][$letter] ?? '').' '.mb_substr($text, 0, 80));
                    }

                    if ($found['rows'] && $found['columns'] && $row > self::BEYOND_HEADING_ROWS) {
                        break;
                    }
                }
            }
        } finally {
            $xml->close();
        }

        if ($shared === [] || ($found['rows'] && $found['columns'] && $headingStrings === [])) {
            return $found;
        }

        foreach (self::sharedStringsWithText($path, $shared) as $index => $text) {
            $found['rows'] = $found['rows'] || $shared[$index]['rows'];
            $found['columns'] = $found['columns'] || $shared[$index]['columns'];

            foreach ($headingStrings[$index] ?? [] as $letter) {
                $found['headings'][$letter] = trim(($found['headings'][$letter] ?? '').' '.mb_substr($text, 0, 80));
            }
        }

        ksort($found['headings']);

        return $found;
    }

    /**
     * Which of these shared strings of an xlsx hold more than spaces, with their text.
     *
     * @param  array<int, mixed>  $wanted  Shared string number => anything.
     * @return array<int, string>
     */
    private static function sharedStringsWithText(string $path, array $wanted): array
    {
        $xml = new XMLReader;

        if (! @$xml->open('zip://'.$path.'#xl/sharedStrings.xml', null, LIBXML_NONET | LIBXML_COMPACT)) {
            // When the strings cannot be looked into, every cell holding one counts as a value.
            return array_fill_keys(array_keys($wanted), '');
        }

        $last = max(array_keys($wanted));
        $index = -1;
        $withText = [];

        try {
            while (@$xml->read()) {
                if ($xml->nodeType !== XMLReader::ELEMENT || $xml->localName !== 'si') {
                    continue;
                }

                $index++;

                if (isset($wanted[$index]) && ($text = trim($xml->readString())) !== '') {
                    $withText[$index] = $text;
                }

                if ($index >= $last) {
                    break;
                }
            }
        } finally {
            $xml->close();
        }

        return $withText;
    }

    /** The path inside the xlsx zip of the sheet with this name. */
    private static function xlsxSheetEntry(string $path, string $sheetName): ?string
    {
        $zip = new ZipArchive;

        if ($zip->open($path) !== true) {
            return null;
        }

        try {
            $workbook = @simplexml_load_string((string) $zip->getFromName('xl/workbook.xml'), options: LIBXML_NONET);
            $rels = @simplexml_load_string((string) $zip->getFromName('xl/_rels/workbook.xml.rels'), options: LIBXML_NONET);

            if ($workbook === false || $rels === false) {
                return null;
            }

            $id = null;

            foreach ($workbook->sheets->sheet ?? [] as $sheet) {
                if ((string) $sheet['name'] === $sheetName) {
                    $id = (string) ($sheet->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'] ?? '');
                }
            }

            foreach ($rels->Relationship ?? [] as $relationship) {
                if ($id !== null && (string) $relationship['Id'] === $id) {
                    $target = (string) $relationship['Target'];

                    return str_starts_with($target, '/') ? ltrim($target, '/') : 'xl/'.$target;
                }
            }

            return null;
        } finally {
            $zip->close();
        }
    }

    private static function readSheet(Worksheet $sheet, int $limit): array
    {
        $grid = [];
        $missing = [];
        $texts = [];
        /** @var array<int, array{0: int, 1: int}|null> Cell style => the zero format it has, as [digits before the point, digits after]. */
        $formats = [];
        $book = $sheet->getParent();
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

                // No result saved with the formula at all. A result saved as empty text is an empty cell.
                if ($cell->isFormula() && $value === null) {
                    $missing[$row->getRowIndex()][$column] = true;
                }

                if ($value === null || (is_string($value) && trim($value) === '')) {
                    continue;
                }

                $grid[$row->getRowIndex()][$column] = is_bool($value) ? ($value ? 'TRUE' : 'FALSE') : $value;

                // A number shown with zeros its value does not keep, as an item code 21.30 or 011010.
                if ((is_int($value) || is_float($value)) && $book !== null) {
                    $xf = $cell->getXfIndex();

                    if (! array_key_exists($xf, $formats)) {
                        $formats[$xf] = self::zeroFormat((string) $book->getCellXfByIndex($xf)->getNumberFormat()->getFormatCode());
                    }

                    if ($formats[$xf] !== null && ($shown = self::shown($value, $formats[$xf])) !== null) {
                        $texts[$row->getRowIndex()][$column] = $shown;
                    }
                }
            }
        }

        $merges = [];

        foreach ($sheet->getMergeCells() as $range) {
            [$start, $end] = array_pad(Coordinate::rangeBoundaries($range), 2, null);

            if ($start !== null && $end !== null && $start[1] <= $limit && $start[0] <= self::MAX_COLUMNS) {
                $merges[] = [(int) $start[1], (int) $start[0], (int) min($end[1], $limit), (int) min($end[0], self::MAX_COLUMNS)];
            }
        }

        ksort($grid);
        usort($merges, fn (array $a, array $b) => $a[0] <=> $b[0] ?: $a[1] <=> $b[1]);

        return [
            'name' => mb_substr($sheet->getTitle(), 0, 60),
            'grid' => $grid,
            'missing' => $missing,
            'merges' => array_slice($merges, 0, self::MAX_MERGES),
            'truncated' => false,
            'wide' => false,
            'hidden' => $sheet->getSheetState() !== Worksheet::SHEETSTATE_VISIBLE,
            'texts' => $texts,
            'beyond' => [],
        ];
    }

    /** @return array{0: int, 1: int}|null A number format of zeros only ("0.00", "000000"): the digits before the point and after it. */
    private static function zeroFormat(string $code): ?array
    {
        return preg_match('/^(0+)(?:\.(0+))?$/', trim($code), $match) === 1 ? [strlen($match[1]), strlen($match[2] ?? '')] : null;
    }

    /** A number as such a format shows it, when that differs from the number itself and is not a whole number with zeros after the point. */
    private static function shown(int|float $value, array $format): ?string
    {
        if ($value < 0 || ! is_finite((float) $value)) {
            return null;
        }

        [$before, $after] = $format;
        $text = number_format((float) $value, $after, '.', '');
        [$whole] = explode('.', $text);
        $text = str_pad($whole, $before, '0', STR_PAD_LEFT).substr($text, strlen($whole));

        if ($text === CellValues::text($value) || preg_match('/^\d+\.0+$/', $text) === 1) {
            return null;
        }

        return $text;
    }

    /** One sheet from a CSV file. The separator is a comma, a semicolon or a tab, whichever the first lines use. */
    private static function readCsv(string $path, int $maxRows): array
    {
        $content = self::decode((string) file_get_contents($path, length: self::MAX_CSV_BYTES)) ?? '';
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
        $wide = false;

        while (($cells = fgetcsv($handle, 0, $separator, '"', '')) !== false) {
            $number++;
            $filled = array_filter($cells, fn ($value) => $value !== null && trim($value) !== '');

            // Past the limit only whether anything is there matters.
            if ($number > $maxRows + 20) {
                if ($filled !== []) {
                    $truncated = true;
                    break;
                }

                continue;
            }

            $wide = $wide || ($filled !== [] && max(array_keys($filled)) >= self::MAX_COLUMNS);

            foreach (array_slice($cells, 0, self::MAX_COLUMNS) as $index => $value) {
                if ($value !== null && trim($value) !== '') {
                    $grid[$number][$index + 1] = trim($value);
                }
            }
        }

        fclose($handle);

        return $grid === [] ? [] : [['name' => 'CSV', 'grid' => $grid, 'missing' => [], 'merges' => [], 'truncated' => $truncated, 'wide' => $wide, 'hidden' => false, 'texts' => [], 'beyond' => []]];
    }

    /** Text with no control bytes in its first part, in any of the encodings a CSV comes in: what a CSV file is. */
    private static function looksLikeText(string $path): bool
    {
        $text = self::decode((string) file_get_contents($path, length: 65536));

        if ($text === null || $text === '' || str_contains($text, "\0")) {
            return false;
        }

        $controls = preg_match_all('/[\x01-\x08\x0B\x0C\x0E-\x1A\x1C-\x1F]/', $text);

        return $controls === 0 || $controls * 100 < strlen($text);
    }

    /**
     * Text as UTF-8: UTF-8 with or without its mark, UTF-16 with or without its mark (Excel's "Unicode
     * Text"), or Windows-1252. Null for bytes that are none of these, such as a binary file.
     */
    private static function decode(string $content): ?string
    {
        if (str_starts_with($content, "\xEF\xBB\xBF")) {
            return substr($content, 3);
        }

        if (str_starts_with($content, "\xFF\xFE") || str_starts_with($content, "\xFE\xFF")) {
            return (string) mb_convert_encoding(substr($content, 2, (strlen($content) - 2) & ~1), 'UTF-8', str_starts_with($content, "\xFF\xFE") ? 'UTF-16LE' : 'UTF-16BE');
        }

        if (str_contains($content, "\0")) {
            // UTF-16 with no mark: the zero bytes of its ASCII characters fall on one side only. A binary file's do not.
            $odd = $even = 0;

            for ($index = 0, $length = strlen($content); $index < $length; $index++) {
                if ($content[$index] === "\0") {
                    $index % 2 === 1 ? $odd++ : $even++;
                }
            }

            if (max($odd, $even) < 0.9 * ($odd + $even)) {
                return null;
            }

            return (string) mb_convert_encoding(substr($content, 0, strlen($content) & ~1), 'UTF-8', $odd >= $even ? 'UTF-16LE' : 'UTF-16BE');
        }

        return mb_check_encoding($content, 'UTF-8') ? $content : (string) mb_convert_encoding($content, 'UTF-8', 'Windows-1252');
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
