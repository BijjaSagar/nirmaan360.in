<?php

namespace App\Services\Reports;

use Carbon\CarbonImmutable;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Spreadsheet as Workbook;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/** Small helpers shared by the Excel exports. Cells are written with explicit types, so text is never run as a formula. */
final class Spreadsheet
{
    /** Lakh and crore grouping, as Indian accounts are written. */
    public const MONEY_FORMAT = '[>=10000000]##\,##\,##\,##0.00;[>=100000]##\,##\,##0.00;##,##0.00';

    public const QTY_FORMAT = '#,##0.000';

    public const DEC1_FORMAT = '#,##0.0';

    public const DATE_FORMAT = 'dd mmm yyyy';

    public static function text(Worksheet $sheet, int $column, int $row, ?string $value): void
    {
        if ($value === null || $value === '') {
            return;
        }

        $sheet->setCellValueExplicit(Coordinate::stringFromColumnIndex($column).$row, $value, DataType::TYPE_STRING);
    }

    /** A number kept from an exact decimal string. Blank stays blank. */
    public static function number(Worksheet $sheet, int $column, int $row, mixed $value, string $format): void
    {
        if ($value === null || $value === '') {
            return;
        }

        $coordinate = Coordinate::stringFromColumnIndex($column).$row;
        $sheet->setCellValueExplicit($coordinate, (string) $value, DataType::TYPE_NUMERIC);
        $sheet->getStyle($coordinate)->getNumberFormat()->setFormatCode($format);
    }

    public static function date(Worksheet $sheet, int $column, int $row, ?string $value): void
    {
        if ($value === null || $value === '') {
            return;
        }

        $coordinate = Coordinate::stringFromColumnIndex($column).$row;
        $sheet->setCellValueExplicit($coordinate, (string) Date::PHPToExcel(CarbonImmutable::parse($value)), DataType::TYPE_NUMERIC);
        $sheet->getStyle($coordinate)->getNumberFormat()->setFormatCode(self::DATE_FORMAT);
        $sheet->getStyle($coordinate)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
    }

    /** Bold header cells with a fill and a rule under them. */
    public static function header(Worksheet $sheet, int $row, int $columns): void
    {
        $range = 'A'.$row.':'.Coordinate::stringFromColumnIndex($columns).$row;
        $style = $sheet->getStyle($range);
        $style->getFont()->setBold(true);
        $style->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('EBE5D7');
        $style->getBorders()->getBottom()->setBorderStyle(Border::BORDER_THIN);
        $style->getAlignment()->setVertical(Alignment::VERTICAL_CENTER)->setWrapText(true);
    }

    /** Width in characters from the longest of the first values, within limits. */
    public static function width(Worksheet $sheet, int $column, string $label, array $values, int $max = 50): void
    {
        $longest = mb_strlen($label);

        foreach (array_slice($values, 0, 200) as $value) {
            $longest = max($longest, mb_strlen((string) $value));
        }

        $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($column))->setWidth(min($max, max(8, $longest + 2)));
    }

    public static function landscape(Worksheet $sheet): void
    {
        $sheet->getPageSetup()->setOrientation('landscape')->setFitToPage(true)->setFitToWidth(1)->setFitToHeight(0);
    }

    /** A sheet title Excel accepts: 31 characters and none of : \ / ? * [ ]. */
    public static function sheetTitle(string $title): string
    {
        return mb_substr(trim((string) preg_replace('/[:\\\\\/?*\[\]]/', ' ', $title)) ?: 'Sheet', 0, 31);
    }

    /** The workbook as file contents. */
    public static function bytes(Workbook $book): string
    {
        $path = tempnam(sys_get_temp_dir(), 'nirmaan');

        try {
            $writer = IOFactory::createWriter($book, 'Xlsx');
            $writer->setPreCalculateFormulas(false);
            $writer->save($path);

            return (string) file_get_contents($path);
        } finally {
            @unlink($path);
            // A workbook holds reference cycles that PHP only frees when told to.
            $book->disconnectWorksheets();
        }
    }
}
