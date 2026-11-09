<?php

namespace Tests\Feature\Reports;

use App\Models\Attendance;
use App\Models\BoqItem;
use App\Models\PettyCashEntry;
use App\Models\PettyCashWallet;
use App\Models\Project;
use App\Models\RaBill;
use App\Models\Worker;
use App\Services\Reports\ReportBuilder;
use App\Services\Reports\ReportExporter;
use App\Services\Reports\ReportResult;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;

class ReportsScreenTest extends ReportsTestCase
{
    private Project $project;

    private BoqItem $item;

    protected function setUp(): void
    {
        parent::setUp();

        $this->project = $this->makeProject($this->org, ['code' => 'RT1', 'name' => 'Riverside Tower']);
        $this->item = $this->makeBoqItem($this->project, '100.000', '5000.00');
        $this->draftEntry($this->project, $this->item, $this->pm, ['measured_on' => now()->toDateString()]);

        $worker = Worker::create(['organization_id' => $this->org->id, 'name' => 'Ramesh', 'trade' => 'Mason', 'type' => 'own', 'daily_wage' => '650.50']);
        Attendance::create(['organization_id' => $this->org->id, 'project_id' => $this->project->id, 'worker_id' => $worker->id, 'attendance_date' => now()->toDateString(), 'status' => 'present']);

        $wallet = PettyCashWallet::create(['organization_id' => $this->org->id, 'project_id' => $this->project->id, 'name' => 'Site cash']);
        PettyCashEntry::create(['organization_id' => $this->org->id, 'petty_cash_wallet_id' => $wallet->id, 'type' => 'topup', 'amount' => '5000', 'entry_date' => now()->toDateString()]);

        RaBill::create(['organization_id' => $this->org->id, 'project_id' => $this->project->id, 'type' => 'client', 'bill_no' => 1, 'number' => 'RT1/RA/1', 'period_from' => now()->startOfMonth(), 'period_to' => now()->endOfMonth(), 'status' => 'DRAFT', 'gross_amount' => '250000', 'net_payable' => '250000']);
    }

    /** Every report with the query that runs it. */
    private function allReports(): array
    {
        return [
            'boq_ledger' => ['report' => 'boq_ledger', 'project_id' => $this->project->id],
            'measurement_register' => ['report' => 'measurement_register', 'period' => 'this_month'],
            'ra_bill_register' => ['report' => 'ra_bill_register', 'period' => 'this_month'],
            'attendance_summary' => ['report' => 'attendance_summary', 'period' => 'this_month'],
            'petty_cash' => ['report' => 'petty_cash', 'period' => 'this_month'],
        ];
    }

    public function test_the_reports_screen_offers_the_reports_a_person_may_run(): void
    {
        $this->actingAs($this->pm)->get(route('reports.index'))->assertOk()
            ->assertSee('Reports')
            ->assertSee('BOQ ledger')->assertSee('Measurement register')->assertSee('RA bill register')->assertSee('Attendance summary')->assertSee('Petty cash')
            ->assertSee(route('reports.schedules.index'), false);

        // Accounts see money reports but not attendance.
        $this->actingAs($this->member($this->org, 'accounts'))->get(route('reports.index'))->assertOk()
            ->assertSee('RA bill register')->assertSee('Petty cash')->assertDontSee('Attendance summary');
    }

    public function test_the_menu_links_to_reports_for_people_with_access_on_paid_plans(): void
    {
        $this->actingAs($this->pm)->get(route('dashboard'))->assertOk()->assertSee(route('reports.index'), false)->assertSee(route('ratelib.index'), false);
        $this->actingAs($this->member($this->org, 'engineer'))->get(route('dashboard'))->assertOk()->assertDontSee(route('reports.index'), false);

        $free = $this->tenant('Small Co', 'free');
        $this->actingAs($this->member($free, 'owner'))->get(route('dashboard'))->assertOk()->assertDontSee(route('reports.index'), false);
    }

    public function test_a_report_shows_on_screen_with_totals_and_export_links(): void
    {
        $response = $this->actingAs($this->pm)->get(route('reports.index', $this->allReports()['boq_ledger']));

        $response->assertOk()
            ->assertSee('RT1 · Riverside Tower')
            ->assertSee($this->item->code)
            ->assertSee('5,00,000.00')   // BOQ amount, grouped the Indian way
            ->assertSee('Total')
            ->assertSee(e(route('reports.xlsx', $this->allReports()['boq_ledger'])), false)
            ->assertSee(e(route('reports.pdf', $this->allReports()['boq_ledger'])), false);
    }

    public function test_each_report_opens_on_screen(): void
    {
        foreach ($this->allReports() as $key => $query) {
            $this->actingAs($this->pm)->get(route('reports.index', $query))->assertOk()->assertSee(__(ReportBuilder::DEFINITIONS[$key]['label']), false);
        }

        $this->actingAs($this->pm)->get(route('reports.index', ['report' => 'attendance_summary', 'period' => 'this_month']))->assertSee('Ramesh')->assertSee('650.50');
        $this->actingAs($this->pm)->get(route('reports.index', ['report' => 'petty_cash', 'period' => 'this_month']))->assertSee('Site cash')->assertSee('5,000.00');
        $this->actingAs($this->pm)->get(route('reports.index', ['report' => 'ra_bill_register', 'period' => 'this_month']))->assertSee('RT1/RA/1')->assertSee('2,50,000.00');
    }

    public function test_a_report_with_no_data_says_so(): void
    {
        $this->actingAs($this->pm)->get(route('reports.index', ['report' => 'petty_cash', 'period' => 'custom', 'from' => '2020-01-01', 'to' => '2020-01-31']))
            ->assertOk()->assertSee('No data for these choices.');
    }

    public function test_choices_are_checked(): void
    {
        $this->actingAs($this->pm)->get(route('reports.index', ['report' => 'boq_ledger']))->assertSessionHasErrors('project_id');
        $this->actingAs($this->pm)->get(route('reports.index', ['report' => 'made_up']))->assertSessionHasErrors('report');
        $this->actingAs($this->pm)->get(route('reports.index', ['report' => 'petty_cash', 'period' => 'next_decade']))->assertSessionHasErrors('period');
        $this->actingAs($this->pm)->get(route('reports.index', ['report' => 'petty_cash', 'period' => 'custom']))->assertSessionHasErrors(['from', 'to']);
        $this->actingAs($this->pm)->get(route('reports.index', ['report' => 'petty_cash', 'period' => 'custom', 'from' => '2026-11-30', 'to' => '2026-11-01']))->assertSessionHasErrors('to');
        $this->actingAs($this->pm)->get(route('reports.xlsx', ['report' => 'boq_ledger']))->assertSessionHasErrors('project_id');
    }

    public function test_another_companys_project_cannot_be_chosen(): void
    {
        $theirs = $this->makeProject($this->tenant('Other Co', 'business'));

        $this->actingAs($this->pm)->get(route('reports.index', ['report' => 'boq_ledger', 'project_id' => $theirs->id]))->assertSessionHasErrors('project_id');
        $this->actingAs($this->pm)->get(route('reports.xlsx', ['report' => 'boq_ledger', 'project_id' => $theirs->id]))->assertSessionHasErrors('project_id');
        $this->actingAs($this->pm)->get(route('reports.pdf', ['report' => 'boq_ledger', 'project_id' => $theirs->id]))->assertSessionHasErrors('project_id');
    }

    public function test_every_report_exports_to_excel(): void
    {
        foreach ($this->allReports() as $key => $query) {
            $response = $this->actingAs($this->pm)->get(route('reports.xlsx', $query));

            $this->assertWorkbook($response);
            $this->assertStringContainsString('report-'.str_replace('_', '-', $key).'-'.now()->format('Y-m-d').'.xlsx', $response->headers->get('Content-Disposition'));

            $rows = $this->readWorkbook($response);
            $this->assertSame('Acme Infra', $rows[0][0], $key);
            $this->assertSame(__(ReportBuilder::DEFINITIONS[$key]['label']), $rows[1][0], $key);
            $this->assertNotEmpty($rows[2][0], $key);          // the scope line
            $this->assertNotEmpty($rows[5][0], $key);          // headings
            $this->assertNotEmpty(array_filter($rows[6], fn ($cell) => $cell !== null), $key);   // first data row
        }
    }

    public function test_excel_numbers_are_numbers_with_indian_grouping_and_text_stays_text(): void
    {
        $this->item->update(['description' => '=1+1']);

        $response = $this->actingAs($this->pm)->get(route('reports.xlsx', $this->allReports()['boq_ledger']));
        $path = tempnam(sys_get_temp_dir(), 'x').'.xlsx';
        file_put_contents($path, $response->getContent());
        $sheet = IOFactory::load($path)->getSheet(0);

        // Headings are on row 6 and the first line on row 7. Column C is the description, L the BOQ amount.
        $this->assertSame('Description', $sheet->getCell('C6')->getValue());
        $this->assertFalse($sheet->getCell('C7')->isFormula());
        $this->assertSame('=1+1', $sheet->getCell('C7')->getValue());
        $this->assertSame(500000.0, (float) $sheet->getCell('L7')->getValue());
        $this->assertStringContainsString('##\,##\,##', $sheet->getStyle('L7')->getNumberFormat()->getFormatCode());
        $this->assertSame('Total', $sheet->getCell('A8')->getValue());
        $this->assertSame(500000.0, (float) $sheet->getCell('L8')->getValue());
    }

    public function test_every_report_exports_to_pdf(): void
    {
        foreach ($this->allReports() as $key => $query) {
            $response = $this->actingAs($this->pm)->get(route('reports.pdf', $query));

            $this->assertPdf($response);
            $this->assertStringContainsString('.pdf', $response->headers->get('Content-Disposition'), $key);
        }
    }

    public function test_the_pdf_page_carries_the_company_title_totals_and_notes(): void
    {
        $report = new ReportResult(
            'petty_cash', 'Petty cash', 'All projects · 01 Nov 2026 to 30 Nov 2026',
            [['key' => 'wallet', 'label' => 'Wallet', 'type' => 'text'], ['key' => 'closing', 'label' => 'Closing', 'type' => 'money']],
            [['wallet' => 'Site cash', 'closing' => '1234567.50']],
            ['closing' => '1234567.50'],
            ['A note about the figures.'],
            true,
        );

        $html = view('modules.reports.pdf.report', ['report' => $report, 'organization' => $this->org, 'totalRows' => 1500, 'numeric' => ['money', 'qty', 'int', 'dec1']])->render();

        $this->assertStringContainsString('Acme Infra', $html);
        $this->assertStringContainsString('Petty cash', $html);
        $this->assertStringContainsString('Site cash', $html);
        $this->assertStringContainsString('12,34,567.50', $html);
        $this->assertStringContainsString('Total', $html);
        $this->assertStringContainsString('A note about the figures.', $html);
        $this->assertStringContainsString('first 1 of 1500 rows', $html);
    }

    public function test_a_long_report_is_cut_on_screen_but_whole_in_excel(): void
    {
        $rows = [];

        foreach (range(1, 205) as $number) {
            $rows[] = ['organization_id' => $this->org->id, 'project_id' => $this->project->id, 'code' => "X{$number}", 'description' => "Line {$number}", 'unit' => 'nos', 'qty' => 1, 'rate' => 1, 'sort' => $number + 10, 'created_at' => now(), 'updated_at' => now()];
        }

        BoqItem::insert($rows);

        $this->actingAs($this->pm)->get(route('reports.index', $this->allReports()['boq_ledger']))->assertOk()
            ->assertSee('Showing the first 200 of 206 rows')->assertSee('Line 100')->assertDontSee('Line 205');

        $sheet = $this->readWorkbook($this->actingAs($this->pm)->get(route('reports.xlsx', $this->allReports()['boq_ledger'])));
        $descriptions = array_column($sheet, 2);
        $this->assertContains('Line 205', $descriptions);
    }

    // The largest PDF needs a lot of memory, so it runs in its own process.
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_the_pdf_is_capped_by_how_many_cells_it_holds_and_the_largest_one_still_renders(): void
    {
        $exporter = app(ReportExporter::class);
        $column = fn (int $n) => ['key' => "c{$n}", 'label' => "Column {$n}", 'type' => $n % 2 ? 'money' : 'text'];
        $make = function (int $columns, int $rows) use ($column) {
            $cols = array_map($column, range(1, $columns));
            $data = [];

            foreach (range(1, $rows) as $r) {
                $data[] = collect($cols)->mapWithKeys(fn ($c) => [$c['key'] => $c['type'] === 'money' ? '12345.67' : "Some text {$r}"])->all();
            }

            return new ReportResult('boq_ledger', 'BOQ ledger', 'scope', $cols, $data);
        };

        $narrow = $make(1, 1200);
        $wide = $make(14, 500);

        $this->assertSame(1000, $exporter->pdfRowLimit($narrow));
        $this->assertSame(428, $exporter->pdfRowLimit($wide));
        $this->assertCount(428, $wide->limited(428)->rows);
        $this->assertTrue($wide->limited(428)->truncated);

        // The worst case, a wide report over the limit, must still come out within memory.
        $this->assertStringStartsWith('%PDF', $exporter->pdf($wide, $this->org));
    }

    public function test_the_measurement_register_status_filter_works_on_screen(): void
    {
        $query = ['report' => 'measurement_register', 'period' => 'this_month'];

        $this->actingAs($this->pm)->get(route('reports.index', $query))->assertOk()->assertSee('Draft');
        $this->actingAs($this->pm)->get(route('reports.index', [...$query, 'status' => 'APPROVED']))->assertOk()->assertSee('No data for these choices.');
    }

    public function test_access_by_role_plan_and_sign_in(): void
    {
        $query = $this->allReports()['attendance_summary'];

        // No Reports permission at all.
        foreach (['engineer', 'store', 'viewer'] as $role) {
            $user = $this->member($this->org, $role);
            $this->actingAs($user)->get(route('reports.index'))->assertForbidden();
            $this->actingAs($user)->get(route('reports.xlsx', $query))->assertForbidden();
            $this->actingAs($user)->get(route('reports.pdf', $query))->assertForbidden();
        }

        // Reports permission, but not the data behind this report: the estimator has no attendance access.
        $qs = $this->member($this->org, 'qs');
        $this->actingAs($qs)->get(route('reports.index'))->assertOk()->assertDontSee('Attendance summary');
        $this->actingAs($qs)->get(route('reports.index', $query))->assertForbidden();
        $this->actingAs($qs)->get(route('reports.xlsx', $query))->assertForbidden();
        $this->actingAs($qs)->get(route('reports.pdf', $query))->assertForbidden();
        $this->assertWorkbook($this->actingAs($qs)->get(route('reports.xlsx', $this->allReports()['ra_bill_register'])));

        // A plan without the module.
        $free = $this->tenant('Small Co', 'free');
        $freeOwner = $this->member($free, 'owner');
        $this->actingAs($freeOwner)->get(route('reports.index'))->assertForbidden();
        $this->actingAs($freeOwner)->get(route('reports.xlsx', $query))->assertForbidden();
        $this->actingAs($freeOwner)->get(route('reports.schedules.index'))->assertForbidden();

        auth()->logout();
        $this->get(route('reports.index'))->assertRedirect(route('login'));
        $this->get(route('reports.xlsx', $query))->assertRedirect(route('login'));
    }

    public function test_one_company_never_sees_anothers_rows_in_a_report(): void
    {
        $other = $this->tenant('Other Co', 'business');
        $theirs = $this->makeProject($other, ['code' => 'THEIRS']);
        $theirItem = $this->makeBoqItem($theirs);
        $worker = Worker::create(['organization_id' => $other->id, 'name' => 'Their worker', 'trade' => 'Mason', 'type' => 'own', 'daily_wage' => '500']);
        Attendance::create(['organization_id' => $other->id, 'project_id' => $theirs->id, 'worker_id' => $worker->id, 'attendance_date' => now()->toDateString(), 'status' => 'present']);

        foreach ($this->allReports() as $key => $query) {
            $this->actingAs($this->pm)->get(route('reports.index', $query))->assertOk()->assertDontSee('THEIRS')->assertDontSee('Their worker')->assertDontSee($theirItem->code);
            $rows = json_encode($this->readWorkbook($this->actingAs($this->pm)->get(route('reports.xlsx', $query))));
            $this->assertStringNotContainsString('THEIRS', $rows, $key);
            $this->assertStringNotContainsString('Their worker', $rows, $key);
        }

        // The form only offers this company's projects.
        $this->actingAs($this->pm)->get(route('reports.index'))->assertOk()->assertSee('RT1 · Riverside Tower', false)->assertDontSee('THEIRS');
    }
}
