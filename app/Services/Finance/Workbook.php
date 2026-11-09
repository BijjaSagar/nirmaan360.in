<?php

namespace App\Services\Finance;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Writes report rows to an Excel file, keeping GSTINs as text and amounts as numbers. */
final class Workbook
{
    /** Each sheet has a title, headers and rows. "top" rows sit above the headers, and "numeric" lists the zero-based columns holding numbers. */
    public static function build(array $sheets): Spreadsheet
    {
        $book = new Spreadsheet;
        $book->removeSheetByIndex(0);

        foreach ($sheets as $definition) {
            $sheet = $book->createSheet();
            $sheet->setTitle($definition['title']);
            $row = 1;

            foreach ($definition['top'] ?? [] as $line) {
                self::writeRow($sheet, $row++, $line, [], true);
            }

            self::writeRow($sheet, $row++, $definition['headers'], [], false);

            foreach ($definition['rows'] as $line) {
                self::writeRow($sheet, $row++, $line, $definition['numeric'] ?? [], false);
            }

            foreach (range(1, max(1, count($definition['headers']))) as $column) {
                $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($column))->setAutoSize(true);
            }
        }

        $book->setActiveSheetIndex(0);

        return $book;
    }

    public static function download(Spreadsheet $book, string $filename): StreamedResponse
    {
        return response()->streamDownload(function () use ($book) {
            (new Xlsx($book))->save('php://output');
        }, $filename, ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }

    /** @param list<int> $numeric */
    private static function writeRow($sheet, int $row, array $values, array $numeric, bool $autoNumbers): void
    {
        foreach (array_values($values) as $index => $value) {
            if ($value === null || $value === '') {
                continue;
            }

            $isNumber = in_array($index, $numeric, true) || ($autoNumbers && is_numeric($value));
            $coordinate = Coordinate::stringFromColumnIndex($index + 1).$row;
            $sheet->setCellValueExplicit($coordinate, $isNumber ? 0 + $value : (string) $value, $isNumber ? DataType::TYPE_NUMERIC : DataType::TYPE_STRING);
        }
    }
}
