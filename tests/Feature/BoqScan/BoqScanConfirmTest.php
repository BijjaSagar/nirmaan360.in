<?php

namespace Tests\Feature\BoqScan;

use App\Models\AuditLog;
use App\Models\BoqItem;
use App\Models\BoqRevision;
use App\Models\BoqScanRow;
use App\Models\DocumentRead;
use App\Models\Project;
use App\Services\BoqScan\BoqScanConfirm;
use App\Services\BoqScan\BoqScanReview;
use App\Services\DocumentReading\ReadRefused;
use App\Services\Reports\BoqExcelService;
use App\Services\Reports\Spreadsheet;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Spreadsheet as Workbook;

/** Writing a checked scan into the BOQ: through the Excel import only, once, and never with anything open. */
class BoqScanConfirmTest extends BoqScanTestCase
{
    private function confirmer(): BoqScanConfirm
    {
        return app(BoqScanConfirm::class);
    }

    /** @return list<array<string, ?string>> */
    private function lines(Project $project): array
    {
        return BoqItem::withoutGlobalScopes()->with('section')->where('project_id', $project->id)->orderBy('sort')->get()
            ->map(fn (BoqItem $item) => ['code' => $item->code, 'description' => $item->description, 'unit' => $item->unit, 'qty' => $item->qty, 'rate' => $item->rate, 'section' => $item->section?->name])
            ->all();
    }

    /** The same lines typed into the Excel template, the way a person would without the reader. */
    private function excelOf(array $rows): string
    {
        $book = new Workbook;
        $sheet = $book->getActiveSheet();
        $sheet->setTitle('BOQ');

        foreach (BoqExcelService::COLUMNS as $index => $heading) {
            Spreadsheet::text($sheet, $index + 1, 1, $heading);
        }

        foreach ($rows as $number => $row) {
            foreach ($row as $index => $value) {
                Spreadsheet::text($sheet, $index + 1, $number + 2, $value);
            }
        }

        $path = tempnam(sys_get_temp_dir(), 'boq').'.xlsx';
        file_put_contents($path, Spreadsheet::bytes($book));

        return $path;
    }

    public function test_a_confirmed_scan_writes_the_same_lines_as_the_excel_import_of_the_same_rows(): void
    {
        $read = $this->scan();
        $this->cleanPage($read);
        $this->confirmAll($read);

        $result = $this->actingAs($this->owner)->confirmer()->confirm($read, $this->owner);
        $this->assertSame(['lines' => 3, 'created' => 3, 'updated' => 0, 'unchanged' => 0], array_intersect_key($result, array_flip(['lines', 'created', 'updated', 'unchanged'])));

        $other = $this->makeProject($this->organization, ['code' => 'XL1', 'name' => 'Excel copy']);
        $excel = app(BoqExcelService::class)->import($other, $this->excelOf([
            ['EARTHWORK', '1', 'Excavation in ordinary soil', 'cum', '120.500', '450.00'],
            ['EARTHWORK', '2', 'Filling with murum in layers', 'cum', '80.000', '300.00'],
            ['EARTHWORK', '3', 'PCC 1:4:8 below footings', 'cum', '12.000', '5200.00'],
        ]));

        $this->assertTrue($excel->ok());
        $this->assertSame($this->lines($other), $this->lines($this->project));
        $this->assertSame(DocumentRead::CONFIRMED, $read->fresh()->status);
    }

    public function test_the_grid_has_the_template_headings_so_the_estimate_repairs_never_run(): void
    {
        $read = $this->scan();
        $this->cleanPage($read);
        $this->stage($read, 2, [['', 'Add 6 % for contingencies', '', '', '', 'addon']], [['', '8,437.50']]);
        $this->review()->update($read, BoqScanRow::withoutGlobalScopes()->where('kind', 'addon')->firstOrFail(), ['code' => 'A1', 'amount' => '8437.50'], $this->owner);
        $this->confirmAll($read);

        [$grid] = $this->confirmer()->grid($this->state($read));
        $this->assertSame(BoqExcelService::COLUMNS, array_values($grid[1]));
        // An add-on is one lump-sum line at its confirmed amount.
        $this->assertSame(['Add-ons', 'A1', 'Add 6 % for contingencies', 'LS', '1', '8437.50'], array_values(end($grid)));

        [$rows, $errors, $notes, $facts] = app(BoqExcelService::class)->checkGrid($this->project, $grid);
        $this->assertSame([], $errors);
        $this->assertSame([], $notes, 'Nothing is repaired or derived on this path.');
        $this->assertFalse($facts['estimate']);
        $this->assertCount(4, $rows);

        // The printed total on page 1 does not include the add-on, so the value after the import needs a reason.
        $this->assertTrue($this->state($read)->summary['needs_value_reason']);
        $this->actingAs($this->owner)->confirmer()->confirm($read, $this->owner, 'The add-on is printed after the total');
        $addon = BoqItem::withoutGlobalScopes()->where('project_id', $this->project->id)->where('code', 'A1')->with('section')->firstOrFail();
        $this->assertSame(['LS', '1.000', '8437.50', 'Add-ons'], [$addon->unit, $addon->qty, $addon->rate, $addon->section->name]);
    }

    public function test_nothing_is_written_while_a_line_is_unconfirmed_or_a_check_is_open(): void
    {
        $read = $this->scan();
        $this->cleanPage($read);

        try {
            $this->confirmer()->confirm($read, $this->owner);
            $this->fail('Unconfirmed lines stop the import.');
        } catch (ReadRefused $refused) {
            $this->assertSame(BoqScanReview::FLAGS_OPEN, $refused->reason);
            $this->assertSame(422, $refused->status);
            $this->assertSame(['blocking' => 0, 'unticked' => 0, 'unconfirmed' => 3, 'pages_unresolved' => 0], $refused->details);
        }

        // A confirmation does not outlive a check that comes up afterwards.
        $this->confirmAll($read);
        $line = $this->row($read, '2');
        $line->forceFill(['qty' => '81.000'])->save();

        $this->expectException(ReadRefused::class);

        try {
            $this->confirmer()->confirm($read, $this->owner);
        } finally {
            $this->assertSame(0, BoqItem::withoutGlobalScopes()->where('project_id', $this->project->id)->count());
            $this->assertSame(DocumentRead::READY, $read->fresh()->status);
        }
    }

    public function test_a_difference_between_the_value_and_the_printed_total_needs_a_reason(): void
    {
        $read = $this->scan();
        $this->stage($read, 1, [
            ['1', 'Excavation in ordinary soil', 'cum', '10.000', '45.00'],
            ['2', 'Filling with murum in layers', 'cum', '10.000', '30.00'],
            ['3', 'PCC 1:4:8 below footings', 'cum', '10.000', '52.00'],
            ['', 'Total', '', '', '', 'total'],
        ], [['1', '450.60'], ['2', '300.60'], ['3', '520.60'], ['', '1,271.80', 'read', 3]]);
        $this->confirmAll($read);

        try {
            $this->confirmer()->confirm($read, $this->owner);
            $this->fail('A value that differs from the printed total needs a reason.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('value_reason', $exception->errors());
        }

        $this->actingAs($this->owner)->confirmer()->confirm($read, $this->owner, 'The estimate rounds each line up');

        $revision = BoqRevision::withoutGlobalScopes()->where('project_id', $this->project->id)->firstOrFail();
        $this->assertSame('ai_scan_import', $revision->source);
        $this->assertStringContainsString('The estimate rounds each line up', (string) $revision->reason);
    }

    public function test_confirming_twice_writes_once_and_the_history_and_audit_name_the_scan(): void
    {
        $read = $this->scan();
        $this->cleanPage($read);
        $this->confirmAll($read);

        $first = $this->actingAs($this->owner)->confirmer()->confirm($read, $this->owner);
        $revisions = BoqRevision::withoutGlobalScopes()->where('project_id', $this->project->id)->count();
        $second = $this->confirmer()->confirm($read, $this->owner);

        $this->assertSame($first, $second);
        $this->assertSame($revisions, BoqRevision::withoutGlobalScopes()->where('project_id', $this->project->id)->count());
        $this->assertSame(3, BoqItem::withoutGlobalScopes()->where('project_id', $this->project->id)->count());

        $batch = BoqRevision::withoutGlobalScopes()->where('project_id', $this->project->id)->pluck('batch_uuid')->unique()->values()->all();
        $this->assertCount(1, $batch);
        $this->assertSame($batch[0], $first['batch_uuid']);
        $this->assertSame(['ai_scan_import'], BoqRevision::withoutGlobalScopes()->where('project_id', $this->project->id)->pluck('source')->unique()->values()->all());
        $this->assertSame('Read from a scan and confirmed', BoqRevision::withoutGlobalScopes()->where('project_id', $this->project->id)->value('reason'));

        $audit = AuditLog::withoutGlobalScopes()->where('action', 'ai.read_confirmed')->sole();
        $this->assertSame(3, $audit->new_values['confirmed_by_row']);
        $this->assertSame(0, $audit->new_values['cells_typed']);
        $logged = json_encode($audit->new_values, JSON_UNESCAPED_UNICODE);

        foreach (['Excavation', 'murum', '450', '54225', '140625', 'EARTHWORK'] as $printed) {
            $this->assertStringNotContainsString($printed, $logged);
        }
    }

    public function test_the_history_screen_names_the_scan_as_the_source(): void
    {
        $read = $this->scan();
        $this->cleanPage($read);
        $this->confirmAll($read);
        $this->actingAs($this->owner)->confirmer()->confirm($read, $this->owner);

        $this->actingAs($this->owner)->get(route('project-setup.history', $this->project))->assertOk()->assertSee('Read from a scan and confirmed');

        $this->owner->update(['locale' => 'mr']);
        $this->actingAs($this->owner)->get(route('project-setup.history', $this->project))->assertOk()->assertSee('स्कॅनमधून वाचले आणि पुष्टी करून इम्पोर्ट केले');
    }

    public function test_a_read_that_is_not_ready_cannot_be_confirmed(): void
    {
        $read = $this->scan(['status' => DocumentRead::RUNNING]);
        $this->cleanPage($read);
        $this->confirmAll($read);

        $this->expectException(ReadRefused::class);
        $this->confirmer()->confirm($read, $this->owner);
    }

    private function review(): BoqScanReview
    {
        return app(BoqScanReview::class);
    }
}
