<?php

namespace Tests\Feature\Reports;

use App\Mail\ScheduledReportMail;
use App\Models\ReportSchedule;
use App\Services\Reports\ReportBuilder;
use App\Services\Reports\ReportExporter;
use App\Services\Reports\ReportResult;
use App\Services\Reports\ScheduleService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Mail;
use PhpOffice\PhpSpreadsheet\IOFactory;

class ReportLimitsAndRacesTest extends ReportsTestCase
{
    private function report(int $rows): ReportResult
    {
        return new ReportResult(
            'petty_cash', 'Petty cash', 'scope',
            [['key' => 'wallet', 'label' => 'Wallet', 'type' => 'text'], ['key' => 'amount', 'label' => 'Amount', 'type' => 'money']],
            array_map(fn ($n) => ['wallet' => "Wallet {$n}", 'amount' => '10.00'], range(1, $rows)),
            ['amount' => number_format($rows * 10, 2, '.', '')],
        );
    }

    public function test_excel_is_cut_by_cell_count_and_says_so_while_the_totals_stay_whole(): void
    {
        // Ten cells and two columns allow five rows.
        $exporter = new ReportExporter(xlsxMaxCells: 10, pdfMaxCells: 10);
        $this->assertSame(5, $exporter->xlsxRowLimit($this->report(12)));

        $path = tempnam(sys_get_temp_dir(), 'l').'.xlsx';
        file_put_contents($path, $exporter->xlsx($this->report(12), $this->org));
        $sheet = IOFactory::load($path)->getSheet(0);
        $text = collect($sheet->toArray(null, false, false, false))->flatten()->filter()->values();

        // Rows 7 to 11 are the five wallets, then the total row.
        $this->assertSame('Wallet 5', $sheet->getCell('A11')->getValue());
        $this->assertSame('Total', $sheet->getCell('A12')->getValue());
        $this->assertEquals(120, $sheet->getCell('B12')->getValue());
        $this->assertNull($sheet->getCell('A13')->getValue());
        $this->assertTrue($text->contains(fn ($cell) => str_contains((string) $cell, 'first 5 of 12 rows. The totals cover all 12 rows')));
        $this->assertNotContains('Wallet 6', $text->all());
    }

    public function test_excel_that_fits_has_no_cut_note(): void
    {
        $exporter = new ReportExporter(xlsxMaxCells: 100, pdfMaxCells: 100);
        $path = tempnam(sys_get_temp_dir(), 'l').'.xlsx';
        file_put_contents($path, $exporter->xlsx($this->report(12), $this->org));
        $text = collect(IOFactory::load($path)->getSheet(0)->toArray(null, false, false, false))->flatten()->filter()->implode(' ');

        $this->assertStringContainsString('Wallet 12', $text);
        $this->assertStringNotContainsString('The totals cover all', $text);
    }

    public function test_the_pdf_row_limit_follows_the_cell_cap(): void
    {
        $exporter = new ReportExporter(xlsxMaxCells: 10, pdfMaxCells: 10);

        $this->assertSame(5, $exporter->pdfRowLimit($this->report(12)));
        $this->assertStringStartsWith('%PDF', $exporter->pdf($this->report(12), $this->org));
        // The real limits keep a PDF of the widest report inside 256 MB and an Excel file inside it too.
        $real = new ReportExporter;
        $this->assertSame(8571, $real->xlsxRowLimit(new ReportResult('x', 'X', 's', array_fill(0, 14, ['key' => 'a', 'label' => 'A', 'type' => 'text']), [])));
        $this->assertSame(ReportBuilder::MAX_ROWS, $real->xlsxRowLimit($this->report(1)));
    }

    public function test_a_report_reads_at_most_its_row_limit_and_says_so(): void
    {
        $project = $this->makeProject($this->org);

        foreach (range(1, 3) as $n) {
            $this->makeBoqItem($project, '1.000', '100.00');
        }

        $builder = new class extends ReportBuilder
        {
            public const MAX_ROWS = 2;
        };

        $report = $builder->build('boq_ledger', $this->org, $project->id);

        $this->assertCount(2, $report->rows);
        $this->assertTrue($report->truncated);
        $this->assertStringContainsString('Only the first 2 rows were read', implode(' ', $report->notes));
        // The totals cover only the rows that were read, and the note says so.
        $this->assertSame('200.00', $report->totals['amount']);

        $full = (new ReportBuilder)->build('boq_ledger', $this->org, $project->id);
        $this->assertCount(3, $full->rows);
        $this->assertFalse($full->truncated);
        $this->assertStringNotContainsString('Only the first', implode(' ', $full->notes));
    }

    public function test_two_scheduler_processes_never_send_the_same_report_twice(): void
    {
        Mail::fake();
        config(['mail.default' => 'smtp']);
        $this->travelTo(Carbon::parse('2026-11-09 06:00:00'));
        $project = $this->makeProject($this->org);
        $this->makeBoqItem($project);

        $schedule = app(ScheduleService::class)->create($this->pm, [
            'report' => 'boq_ledger', 'project_id' => $project->id, 'period' => 'last_30_days', 'format' => 'xlsx',
            'frequency' => 'daily', 'hour' => 7, 'recipients' => ['a@acme.test'],
        ]);

        $this->travelTo(Carbon::parse('2026-11-09 07:05:00'));

        // Both processes loaded the schedule before either had sent it.
        $first = ReportSchedule::find($schedule->id);
        $second = ReportSchedule::find($schedule->id);

        $service = app(ScheduleService::class);
        $this->assertSame('sent', $service->run($first, now()));
        $this->assertSame('skipped', $service->run($second, now()));

        $this->assertCount(1, Mail::sent(ScheduledReportMail::class)->all());
    }

    public function test_a_schedule_for_a_deleted_project_is_skipped_not_failed(): void
    {
        Mail::fake();
        config(['mail.default' => 'smtp']);
        $this->travelTo(Carbon::parse('2026-11-09 06:00:00'));
        $project = $this->makeProject($this->org);

        $schedule = app(ScheduleService::class)->create($this->pm, [
            'report' => 'boq_ledger', 'project_id' => $project->id, 'period' => 'last_30_days', 'format' => 'xlsx',
            'frequency' => 'daily', 'hour' => 7, 'recipients' => ['a@acme.test'],
        ]);
        $project->delete();

        $this->travelTo(Carbon::parse('2026-11-09 07:05:00'));
        $counts = app(ScheduleService::class)->runDue();

        Mail::assertNothingSent();
        $this->assertSame(1, $counts['skipped']);
        $this->assertSame('no_project', $schedule->fresh()->last_status);

        $this->actingAs($this->pm)->get(route('reports.schedules.index'))->assertOk()->assertSee('Skipped: the project no longer exists.');
    }
}
