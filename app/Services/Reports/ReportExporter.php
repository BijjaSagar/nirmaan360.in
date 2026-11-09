<?php

namespace App\Services\Reports;

use App\Models\Organization;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Spreadsheet as Workbook;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Turns a finished report into an Excel file or a PDF.
 *
 * Both are capped by how many cells they hold, because that is what uses the memory: about 1 KB a cell
 * in Excel and 30 KB in the PDF. A cut file says so, and its totals still cover every row.
 */
class ReportExporter
{
    /** Excel holds up to this many cells: about 8,500 rows of the 14-column BOQ ledger, or 10,000 rows of a short report. */
    public const XLSX_MAX_CELLS = 120000;

    /** A PDF holds up to this many cells: 428 rows of the 14-column BOQ ledger, or 1,000 rows of a short report. */
    public const PDF_MAX_CELLS = 6000;

    public const PDF_MAX_ROWS = 1000;

    private const NUMERIC = ['money', 'qty', 'int', 'dec1'];

    public function __construct(private int $xlsxMaxCells = self::XLSX_MAX_CELLS, private int $pdfMaxCells = self::PDF_MAX_CELLS) {}

    public function xlsx(ReportResult $full, Organization $organization): string
    {
        Memory::ensure();

        $report = $full->limited($this->xlsxRowLimit($full));
        $book = new Workbook;
        $sheet = $book->getActiveSheet();
        $sheet->setTitle(Spreadsheet::sheetTitle($report->title));

        Spreadsheet::text($sheet, 1, 1, $organization->name);
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        Spreadsheet::text($sheet, 1, 2, $report->title);
        $sheet->getStyle('A2')->getFont()->setBold(true)->setSize(12);
        Spreadsheet::text($sheet, 1, 3, $report->subtitle);
        Spreadsheet::text($sheet, 1, 4, __('Generated').' '.now()->format('d M Y H:i'));

        $headerRow = 6;

        foreach ($report->columns as $index => $column) {
            $position = $index + 1;
            Spreadsheet::text($sheet, $position, $headerRow, $column['label']);
            Spreadsheet::width($sheet, $position, $column['label'], array_column($report->rows, $column['key']), in_array($column['key'], ['description', 'item'], true) ? 60 : 36);
        }

        Spreadsheet::header($sheet, $headerRow, count($report->columns));

        $row = $headerRow;

        foreach ($report->rows as $data) {
            $row++;

            foreach ($report->columns as $index => $column) {
                $this->put($sheet, $index + 1, $row, $column['type'], $data[$column['key']] ?? null);
            }
        }

        if ($report->totals !== null) {
            $row++;
            $first = true;

            foreach ($report->columns as $index => $column) {
                if ($first) {
                    Spreadsheet::text($sheet, $index + 1, $row, __('Total'));
                    $first = false;
                } elseif (array_key_exists($column['key'], $report->totals)) {
                    $this->put($sheet, $index + 1, $row, $column['type'], $report->totals[$column['key']]);
                }
            }

            $style = $sheet->getStyle('A'.$row.':'.Coordinate::stringFromColumnIndex(count($report->columns)).$row);
            $style->getFont()->setBold(true);
            $style->getBorders()->getTop()->setBorderStyle(Border::BORDER_THIN);
        }

        $notes = $report->notes;

        if ($report->rowCount() < $full->rowCount()) {
            $notes[] = __('This file holds the first :shown of :total rows. The totals cover all :total rows. Narrow the dates or the project to see the rest.', ['shown' => $report->rowCount(), 'total' => $full->rowCount()]);
        }

        foreach ($notes as $note) {
            $row += 2;
            Spreadsheet::text($sheet, 1, $row, $note);
            $sheet->getStyle('A'.$row)->getFont()->setItalic(true);
        }

        $sheet->freezePane('A'.($headerRow + 1));
        Spreadsheet::landscape($sheet);

        return Spreadsheet::bytes($book);
    }

    public function pdf(ReportResult $full, Organization $organization): string
    {
        $report = $full->limited($this->pdfRowLimit($full));

        return PdfRenderer::render('modules.reports.pdf.report', [
            'report' => $report,
            'organization' => $organization,
            'totalRows' => $full->rowCount(),
            'numeric' => self::NUMERIC,
        ], count($report->columns) > 8 ? 'landscape' : 'portrait');
    }

    public function xlsxRowLimit(ReportResult $report): int
    {
        return max(1, min(ReportBuilder::MAX_ROWS, intdiv($this->xlsxMaxCells, max(1, count($report->columns)))));
    }

    public function pdfRowLimit(ReportResult $report): int
    {
        return max(1, min(self::PDF_MAX_ROWS, intdiv($this->pdfMaxCells, max(1, count($report->columns)))));
    }

    /** report-boq-ledger-2026-11-09.xlsx */
    public function filename(ReportResult $report, string $extension): string
    {
        return 'report-'.Str::slug(str_replace('_', '-', $report->key)).'-'.now()->format('Y-m-d').'.'.$extension;
    }

    private function put(Worksheet $sheet, int $column, int $row, string $type, mixed $value): void
    {
        match ($type) {
            'money' => Spreadsheet::number($sheet, $column, $row, $value, Spreadsheet::MONEY_FORMAT),
            'qty' => Spreadsheet::number($sheet, $column, $row, $value, Spreadsheet::QTY_FORMAT),
            'dec1' => Spreadsheet::number($sheet, $column, $row, $value, Spreadsheet::DEC1_FORMAT),
            'int' => Spreadsheet::number($sheet, $column, $row, $value, '0'),
            'date' => Spreadsheet::date($sheet, $column, $row, $value),
            default => Spreadsheet::text($sheet, $column, $row, $value === null ? null : (string) $value),
        };
    }
}
