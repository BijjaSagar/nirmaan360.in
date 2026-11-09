<?php

namespace Tests\Feature\Reports;

use App\Models\BoqItem;
use App\Models\MeasurementEntry;
use App\Models\Project;
use App\Services\Reports\MeasurementSheetService;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;

class MeasurementSheetTest extends ReportsTestCase
{
    private function entry(Project $project, BoqItem $item, string $date, string $status, array $dims, bool $deduction = false, array $extra = []): MeasurementEntry
    {
        return MeasurementEntry::create([
            'organization_id' => $project->organization_id,
            'project_id' => $project->id,
            'boq_item_id' => $item->id,
            'measured_on' => $date,
            'description' => 'Slab',
            'location' => 'Level 2',
            'nos' => $dims[0], 'length' => $dims[1] ?? null, 'breadth' => $dims[2] ?? null, 'depth' => $dims[3] ?? null,
            'is_deduction' => $deduction,
            'status' => $status,
            ...$extra,
        ]);
    }

    /** @return array{0: Project, 1: BoqItem, 2: BoqItem} */
    private function measuredProject(): array
    {
        $project = $this->makeProject($this->org, ['code' => 'RT1']);
        $a = BoqItem::create(['organization_id' => $this->org->id, 'project_id' => $project->id, 'code' => 'A', 'description' => 'RCC slab', 'unit' => 'cum', 'qty' => '500', 'rate' => '100.00', 'sort' => 1]);
        $b = BoqItem::create(['organization_id' => $this->org->id, 'project_id' => $project->id, 'code' => 'B', 'description' => 'Plaster', 'unit' => 'sqm', 'qty' => '10', 'rate' => '33.33', 'sort' => 2]);

        $this->entry($project, $a, '2026-10-15', 'APPROVED', [1, 10, 2, 1]);       // 20, before the period
        $this->entry($project, $a, '2026-11-02', 'APPROVED', [2, 5, 1, 1]);        // 10
        $this->entry($project, $a, '2026-11-05', 'APPROVED', [1, 1, 1, 1], true);  // -1, a deduction
        $this->entry($project, $a, '2026-11-06', 'DRAFT', [7, 1, 1, 1]);           // not approved
        $this->entry($project, $a, '2026-11-07', 'REJECTED', [8, 1, 1, 1]);        // not approved
        $this->entry($project, $a, '2026-12-01', 'APPROVED', [9, 1, 1, 1]);        // after the period
        $this->entry($project, $b, '2026-11-10', 'APPROVED', [3, 1.5]);            // 4.5

        return [$project, $a, $b];
    }

    public function test_the_sheet_groups_approved_entries_and_works_out_period_previous_and_cumulative(): void
    {
        [$project] = $this->measuredProject();

        $sheet = app(MeasurementSheetService::class)->build($project, CarbonImmutable::parse('2026-11-01'), CarbonImmutable::parse('2026-11-30'));

        $this->assertSame(3, $sheet['entry_count']);
        $this->assertCount(2, $sheet['items']);

        [$a, $b] = $sheet['items'];
        $this->assertSame('A', $a['code']);
        $this->assertSame(['10.000', '-1.000'], array_column($a['rows'], 'qty'));
        $this->assertSame('9.000', $a['period_qty']);
        $this->assertSame('20.000', $a['previous_qty']);
        $this->assertSame('29.000', $a['cumulative_qty']);
        $this->assertSame('500.000', $a['boq_qty']);
        $this->assertSame('900.00', $a['amount']);

        $this->assertSame('B', $b['code']);
        $this->assertSame('4.500', $b['period_qty']);
        $this->assertSame('0.000', $b['previous_qty']);
        // 4.5 x 33.33 = 149.985, rounded half up to money.
        $this->assertSame('149.99', $b['amount']);

        $this->assertSame('1049.99', $sheet['total_amount']);
    }

    public function test_the_period_includes_both_end_dates_and_a_quiet_item_is_left_out(): void
    {
        [$project] = $this->measuredProject();
        $service = app(MeasurementSheetService::class);

        $sheet = $service->build($project, CarbonImmutable::parse('2026-11-10'), CarbonImmutable::parse('2026-11-10'));
        $this->assertSame(['B'], array_column($sheet['items'], 'code'));

        $sheet = $service->build($project, CarbonImmutable::parse('2026-12-02'), CarbonImmutable::parse('2026-12-31'));
        $this->assertSame([], $sheet['items']);
        $this->assertSame('0.00', $sheet['total_amount']);
    }

    public function test_other_projects_and_companies_never_appear(): void
    {
        [$project] = $this->measuredProject();
        $otherProject = $this->makeProject($this->org, ['code' => 'RT2']);
        $otherItem = $this->makeBoqItem($otherProject);
        $this->entry($otherProject, $otherItem, '2026-11-03', 'APPROVED', [100, 1, 1, 1]);

        $otherOrg = $this->tenant('Other Co', 'business');
        $theirProject = $this->makeProject($otherOrg);
        $this->entry($theirProject, $this->makeBoqItem($theirProject), '2026-11-03', 'APPROVED', [100, 1, 1, 1]);

        $sheet = app(MeasurementSheetService::class)->build($project, CarbonImmutable::parse('2026-11-01'), CarbonImmutable::parse('2026-11-30'));

        $this->assertSame(3, $sheet['entry_count']);
        $this->assertSame(['A', 'B'], array_column($sheet['items'], 'code'));
    }

    public function test_a_reversal_is_a_negative_entry_in_the_sheet(): void
    {
        [$project, $a] = $this->measuredProject();
        $original = $this->entry($project, $a, '2026-11-12', 'APPROVED', [1, 4, 1, 1]);   // 4
        $this->entry($project, $a, '2026-11-13', 'APPROVED', [1, 4, 1, 1], true, ['reversal_of_id' => $original->id]); // -4

        $sheet = app(MeasurementSheetService::class)->build($project, CarbonImmutable::parse('2026-11-12'), CarbonImmutable::parse('2026-11-13'));

        $this->assertSame(['4.000', '-4.000'], array_column($sheet['items'][0]['rows'], 'qty'));
        $this->assertSame('0.000', $sheet['items'][0]['period_qty']);
    }

    public function test_the_sheet_refuses_a_period_with_too_many_entries(): void
    {
        [$project, $a] = $this->measuredProject();
        $rows = [];

        foreach (range(1, MeasurementSheetService::MAX_ENTRIES + 1) as $n) {
            $rows[] = ['organization_id' => $this->org->id, 'project_id' => $project->id, 'boq_item_id' => $a->id, 'measured_on' => '2026-11-20', 'nos' => 1, 'qty' => 1, 'status' => 'APPROVED', 'created_at' => now(), 'updated_at' => now()];
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            MeasurementEntry::insert($chunk);
        }

        $this->expectException(ValidationException::class);
        app(MeasurementSheetService::class)->build($project, CarbonImmutable::parse('2026-11-01'), CarbonImmutable::parse('2026-11-30'));
    }

    // A full sheet needs well over 100 MB to render. It runs in its own process so the rest of the suite stays small.
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_the_largest_allowed_sheet_still_renders(): void
    {
        [$project, $a] = $this->measuredProject();
        $rows = [];

        foreach (range(1, MeasurementSheetService::MAX_ENTRIES) as $n) {
            $rows[] = ['organization_id' => $this->org->id, 'project_id' => $project->id, 'boq_item_id' => $a->id, 'measured_on' => '2026-11-20', 'description' => "Slab portion {$n}", 'location' => 'Level 2', 'nos' => 2, 'length' => 5.5, 'breadth' => 1.25, 'depth' => 0.2, 'qty' => '2.750', 'status' => 'APPROVED', 'remarks' => 'Checked', 'created_at' => now(), 'updated_at' => now()];
        }

        foreach (array_chunk($rows, 200) as $chunk) {
            MeasurementEntry::insert($chunk);
        }

        $response = $this->actingAs($this->pm)->get(route('measurements.sheet', [$project, 'from' => '2026-11-20', 'to' => '2026-11-20']));

        $this->assertPdf($response);
    }

    public function test_the_pdf_downloads_for_a_project_and_period(): void
    {
        [$project] = $this->measuredProject();

        $response = $this->actingAs($this->pm)->get(route('measurements.sheet', [$project, 'from' => '2026-11-01', 'to' => '2026-11-30']));

        $this->assertPdf($response);
        $this->assertStringContainsString('measurement-sheet-RT1-20261101-20261130.pdf', $response->headers->get('Content-Disposition'));
    }

    public function test_an_empty_period_still_gives_a_sheet_that_says_so(): void
    {
        [$project] = $this->measuredProject();

        $this->assertPdf($this->actingAs($this->pm)->get(route('measurements.sheet', [$project, 'from' => '2027-01-01', 'to' => '2027-01-31'])));
    }

    public function test_the_period_is_checked(): void
    {
        [$project] = $this->measuredProject();

        $this->actingAs($this->pm)->get(route('measurements.sheet', $project))->assertSessionHasErrors(['from', 'to']);
        $this->actingAs($this->pm)->get(route('measurements.sheet', [$project, 'from' => '2026-11-30', 'to' => '2026-11-01']))->assertSessionHasErrors('to');
        $this->actingAs($this->pm)->get(route('measurements.sheet', [$project, 'from' => 'soon', 'to' => '2026-11-01']))->assertSessionHasErrors('from');
    }

    public function test_access_by_role_plan_and_company(): void
    {
        [$project] = $this->measuredProject();
        $query = ['from' => '2026-11-01', 'to' => '2026-11-30'];

        foreach (['engineer', 'accounts', 'viewer', 'qs'] as $role) {
            $this->assertPdf($this->actingAs($this->member($this->org, $role))->get(route('measurements.sheet', [$project, ...$query])));
        }

        // The store keeper has no measurement access.
        $this->actingAs($this->member($this->org, 'store'))->get(route('measurements.sheet', [$project, ...$query]))->assertForbidden();

        $other = $this->member($this->tenant('Other Co', 'business'), 'owner');
        $this->actingAs($other)->get(route('measurements.sheet', [$project, ...$query]))->assertNotFound();

        $free = $this->tenant('Small Co', 'free');
        $freeProject = $this->makeProject($free);
        $this->actingAs($this->member($free, 'owner'))->get(route('measurements.sheet', [$freeProject, ...$query]))->assertForbidden();

        auth()->logout();
        $this->get(route('measurements.sheet', [$project, ...$query]))->assertRedirect(route('login'));
    }

    public function test_the_measurement_book_shows_the_sheet_button_only_with_the_module(): void
    {
        $project = $this->makeProject($this->org);
        $this->actingAs($this->pm)->get(route('measurements.index', $project))->assertOk()->assertSee('Measurement sheet PDF')->assertSee(route('measurements.sheet', $project), false);

        $free = $this->tenant('Small Co', 'free');
        $this->actingAs($this->member($free, 'owner'))->get(route('measurements.index', $this->makeProject($free)))->assertOk()->assertDontSee('Measurement sheet PDF');
    }
}
