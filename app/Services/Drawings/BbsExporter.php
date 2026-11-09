<?php

namespace App\Services\Drawings;

use App\Models\BbsSchedule;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/** Writes a schedule in the column order of IS 2502 Table I, with cutting length and weight added. */
class BbsExporter
{
    public const DISCLAIMER = 'Calculated from IS 2502:1963 allowances and d²/162 kg/m. A qualified engineer must check every bar against the drawings and the design before cutting. Laps and development lengths are not added.';

    private const HEADINGS = ['Location', 'Mark', 'Steel', 'Size (mm)', 'No. of sets', 'No. per set', 'Total no.', 'Shape ref', 'Shape', 'Dimensions (mm)', 'Cutting length (mm)', 'Total length (m)', 'Unit weight (kg/m)', 'Weight (kg)'];

    public function __construct(private BbsService $service) {}

    public function spreadsheet(BbsSchedule $schedule): Spreadsheet
    {
        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet();
        $sheet->setTitle('Bar bending schedule');
        $shapes = BbsCalculator::shapes();
        $steels = BbsCalculator::steelTypes();

        $this->text($sheet, 'A1', $schedule->project->name.' ('.$schedule->project->code.')');
        $this->text($sheet, 'A2', 'Bar bending schedule '.$schedule->number.': '.$schedule->title);
        $this->text($sheet, 'A3', self::DISCLAIMER);
        $sheet->getStyle('A1:A2')->getFont()->setBold(true);
        $sheet->getStyle('A3')->getFont()->setBold(true)->getColor()->setARGB('FFB4451F');

        $row = 5;

        foreach (self::HEADINGS as $index => $heading) {
            $this->text($sheet, [$index + 1, $row], $heading);
        }

        $sheet->getStyle('A5:N5')->getFont()->setBold(true);

        foreach ($schedule->bars()->get() as $bar) {
            $row++;
            $this->text($sheet, [1, $row], (string) $bar->location);
            $this->text($sheet, [2, $row], $bar->mark);
            $this->text($sheet, [3, $row], $steels[$bar->steel_type] ?? $bar->steel_type);
            $this->number($sheet, [4, $row], $bar->dia_mm);
            $this->number($sheet, [5, $row], $bar->sets);
            $this->number($sheet, [6, $row], $bar->per_set);
            $this->number($sheet, [7, $row], $bar->total_bars);
            $this->text($sheet, [8, $row], $bar->shape_code);
            $this->text($sheet, [9, $row], $shapes[$bar->shape_code]['name'] ?? '');
            $this->text($sheet, [10, $row], BbsService::describeDims($bar->dims));
            $this->number($sheet, [11, $row], $bar->cutting_length_mm);
            $this->number($sheet, [12, $row], $bar->total_length_m);
            $this->number($sheet, [13, $row], $bar->unit_weight_kg_m);
            $this->number($sheet, [14, $row], $bar->total_weight_kg);
        }

        $summary = $this->service->summary($schedule);
        $row += 2;
        $this->text($sheet, [1, $row], 'Summary by bar size');
        $sheet->getStyle([1, $row])->getFont()->setBold(true);
        $row++;

        foreach (['Size (mm)', 'Total no.', 'Total length (m)', 'Weight (kg)'] as $index => $heading) {
            $this->text($sheet, [$index + 1, $row], $heading);
        }

        $sheet->getStyle([1, $row, 4, $row])->getFont()->setBold(true);

        foreach ($summary['sizes'] as $size) {
            $row++;
            $this->number($sheet, [1, $row], $size['dia']);
            $this->number($sheet, [2, $row], $size['bars']);
            $this->number($sheet, [3, $row], $size['length_m']);
            $this->number($sheet, [4, $row], $size['weight_kg']);
        }

        $row++;
        $this->text($sheet, [1, $row], 'Total weight (kg)');
        $this->number($sheet, [4, $row], $summary['total_weight_kg']);
        $sheet->getStyle([1, $row, 4, $row])->getFont()->setBold(true);

        foreach (range('A', 'N') as $column) {
            $sheet->getColumnDimension($column)->setWidth(in_array($column, ['I', 'J', 'C'], true) ? 26 : 14);
        }

        return $book;
    }

    /** Always a plain string: a mark such as "=1+1" must never run as a formula. */
    private function text(Worksheet $sheet, string|array $cell, string $value): void
    {
        $sheet->setCellValueExplicit($cell, $value, DataType::TYPE_STRING);
    }

    private function number(Worksheet $sheet, array $cell, mixed $value): void
    {
        $sheet->setCellValueExplicit($cell, $value, DataType::TYPE_NUMERIC);
    }
}
