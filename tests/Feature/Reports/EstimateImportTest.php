<?php

namespace Tests\Feature\Reports;

use App\Models\BoqItem;
use App\Models\Project;
use App\Models\ProjectValuePhase;
use App\Models\User;
use App\Services\ProjectService;
use App\Services\Reports\BoqExcelService;
use App\Services\SiteControl\WorkProgress;
use App\Services\SiteProgress\ProgressReport;
use App\Support\Dec;
use Illuminate\Support\Facades\Auth;

/** An engineer's estimate: invented figures, in the layout such sheets really come in. */
class EstimateImportTest extends ReportsTestCase
{
    private const HEADING = ['Sr. No.', 'Description of Items', 'Qty.', 'Rate', 'Unit', 'Amount in Rs.'];

    /** @return list<array<int, mixed>> */
    private function sheet(?string $civilTotal = '1055000', string $centeringAmount = '3500'): array
    {
        return [
            [null, 'Estimate for a sample bungalow'],
            [],
            self::HEADING,
            [1, 'Excavation for foundation in soft murum', 10, 350, 'Rs/cu.m', 3500],
            [2, 'Providing and casting in situ cement concrete M-20 for R.C.C. works (Excluding reinforcement)', null, 4000, 'Rs/cu.m', 40000, null, null, null, 'side working', 12.5],
            [null, 'a. Column footing 4', 1],
            [null, 'b. Slabs 1.5', 4],
            [3, 'Providing R.C.C. centering works as per drawing', null, null, 'Rs/sqm', $centeringAmount],
            [null, 'a. Plinth area 2', 2, 750],
            [null, 'b. Slab area 1', 1, 2000],
            [4, 'Providing and fixing Teakwood door', 2, 15000, 'Rs/door', 30000],
            [null, 'Providing and fixing flush door', 3, 4000, 'Rs/door', 12000],
            [5, 'Providing HYSD reinforcement bars of various diameters', 1, 66000, 'Rs/M.T', 66000],
            [6, 'Providing elevator', 1, 1, null, 900000],
            [null, 'Total Civil Works', null, null, null, $civilTotal],
            [7, 'Add 6% paint work on inner and external walls', null, null, null, 6000],
            [7, 'Add 6% towards supervision and work charge establishment.', null, null, null, 6000],
            [null, 'Total Estimate Amount', null, null, null, 1067000],
            [null, 'Say', null, null, null, 64020],
            ['IN WORDS (RS. SOME AMOUNT ONLY)'],
            [null, null, null, null, null, null, null, 'aluminium', 550],
        ];
    }

    private function import(Project $project, array $rows)
    {
        return app(BoqExcelService::class)->import($project, $this->workbook($rows, 'estimate.xlsx', 'Sheet1')->getRealPath());
    }

    private function line(Project $project, string $code): BoqItem
    {
        return BoqItem::with('section')->where('project_id', $project->id)->where('code', $code)->firstOrFail();
    }

    public function test_an_estimate_becomes_ordinary_boq_lines(): void
    {
        $this->actingAs($this->pm);
        $project = $this->makeProject($this->org);

        $result = $this->import($project, $this->sheet());

        $this->assertTrue($result->ok(), json_encode($result->errors));
        $this->assertSame(
            ['1', '2', '3a', '3b', '4', '4.1', '5', '6', '7', '7.1'],
            BoqItem::where('project_id', $project->id)->orderBy('sort')->pluck('code')->all(),
        );
    }

    public function test_a_lettered_row_with_a_rate_is_its_own_line_under_its_heads_unit(): void
    {
        $this->actingAs($this->pm);
        $project = $this->makeProject($this->org);
        $this->import($project, $this->sheet());

        $line = $this->line($project, '3a');

        $this->assertSame('sqm', $line->unit);
        $this->assertSame('2.000', $line->qty);
        $this->assertSame('750.00', $line->rate);
        $this->assertSame('Plinth area - Providing R.C.C. centering works as per drawing', $line->description);
    }

    public function test_a_lettered_row_without_a_rate_explains_its_line_and_the_quantity_comes_from_the_amount(): void
    {
        $this->actingAs($this->pm);
        $project = $this->makeProject($this->org);
        $result = $this->import($project, $this->sheet());

        $line = $this->line($project, '2');

        $this->assertSame('10.000', $line->qty);
        $this->assertSame('cum', $line->unit);
        $this->assertSame('rcc', $line->category);
        $this->assertStringContainsString('a. Column footing 4 × 1; b. Slabs 1.5 × 4', $line->details);
        $this->assertContains(8 - 3, array_column($result->notes, 'row'));
    }

    public function test_a_typed_amount_wins_over_a_placeholder_rate_and_a_missing_unit_is_noted(): void
    {
        $this->actingAs($this->pm);
        $project = $this->makeProject($this->org);
        $result = $this->import($project, $this->sheet());

        $line = $this->line($project, '6');

        $this->assertSame('900000.00', $line->rate);
        $this->assertSame('nos', $line->unit);
        $this->assertCount(2, array_filter($result->notes, fn (array $note) => $note['row'] === 14));
    }

    public function test_add_ons_are_lump_sums_in_their_own_section_and_totals_are_not_lines(): void
    {
        $this->actingAs($this->pm);
        $project = $this->makeProject($this->org);
        $this->import($project, $this->sheet());

        $paint = $this->line($project, '7');

        $this->assertSame('LS', $paint->unit);
        $this->assertSame('1.000', $paint->qty);
        $this->assertSame('6000.00', $paint->rate);
        $this->assertSame('Add-ons', $paint->section->name);
        $this->assertSame('finishing', $paint->category);
        $this->assertSame('overhead', $this->line($project, '7.1')->category);
        $this->assertSame('Civil works', $this->line($project, '1')->section->name);
        $this->assertSame(0, BoqItem::where('project_id', $project->id)->where('description', 'like', '%Total%')->orWhere('description', 'Say')->count());
    }

    public function test_the_whole_estimate_comes_to_the_same_total_as_the_file(): void
    {
        $this->actingAs($this->pm);
        $project = $this->makeProject($this->org);
        $result = $this->import($project, $this->sheet());

        $total = BoqItem::where('project_id', $project->id)->get()->reduce(fn ($sum, BoqItem $item) => $sum->plus($item->amount()), Dec::of(0));

        $this->assertSame('1067000.00', Dec::money($total));
        $this->assertEmpty(array_filter($result->notes, fn (array $note) => str_contains($note['message'], 'add up')));
    }

    public function test_a_total_or_head_that_does_not_match_the_lines_is_pointed_out_and_the_lines_still_come_in(): void
    {
        $this->actingAs($this->pm);
        $project = $this->makeProject($this->org);

        $result = $this->import($project, $this->sheet(civilTotal: '1000000', centeringAmount: '2000'));

        $this->assertTrue($result->ok());
        $this->assertCount(2, array_filter($result->notes, fn (array $note) => str_contains($note['message'], 'add up')));
        $this->assertSame('2000.00', $this->line($project, '3b')->rate);
    }

    public function test_loading_the_same_estimate_again_changes_nothing(): void
    {
        $this->actingAs($this->pm);
        $project = $this->makeProject($this->org);
        $this->import($project, $this->sheet());

        $second = $this->import($project, $this->sheet());

        $this->assertSame(0, $second->created);
        $this->assertSame(10, $second->unchanged);
    }

    public function test_a_row_that_cannot_be_read_stops_the_import_with_its_excel_row_number(): void
    {
        $this->actingAs($this->pm);
        $project = $this->makeProject($this->org);
        $rows = $this->sheet();
        $rows[8] = [null, 'a. Plinth area 2', null, 750];

        $result = $this->import($project, $rows);

        $this->assertFalse($result->ok());
        $this->assertSame(9, $result->errors[0]['row']);
        $this->assertSame('qty', $result->errors[0]['field']);
        $this->assertSame(0, BoqItem::where('project_id', $project->id)->count());
    }

    public function test_the_template_layout_is_still_held_to_its_strict_rules(): void
    {
        $this->actingAs($this->pm);
        $project = $this->makeProject($this->org);

        $result = $this->import($project, [
            ['section', 'code', 'description', 'unit', 'qty', 'rate', 'amount'],
            ['Works', null, 'A line with no code', 'cum', 1, 100, 100],
        ]);

        $this->assertFalse($result->ok());
        $this->assertSame('code', $result->errors[0]['field']);
    }

    public function test_the_upload_page_shows_what_to_check(): void
    {
        $project = $this->makeProject($this->org);

        $this->actingAs($this->pm)
            ->post(route('boq.excel.import', $project), ['file' => $this->workbook($this->sheet('1000000'), 'estimate.xlsx', 'Sheet1')])
            ->assertRedirect(route('boq.excel.index', $project));

        $this->get(route('boq.excel.index', $project))->assertOk()->assertSee('Please check these')->assertSee('add up to');
    }

    public function test_supervision_stays_out_of_the_percentage_of_work_and_the_site_list(): void
    {
        $this->actingAs($this->pm);
        $project = $this->makeProject($this->org);
        $this->import($project, $this->sheet());

        $codes = app(ProgressReport::class)->items($project)->pluck('code')->all();

        $this->assertNotContains('7.1', $codes);
        $this->assertContains('7', $codes);
        $this->assertSame('0.00', app(WorkProgress::class)->projectPercent($project));
    }

    private function pack(array $estimates): string
    {
        $path = tempnam(sys_get_temp_dir(), 'pack');
        file_put_contents($path, base64_encode(gzencode(json_encode($estimates))));

        return $path;
    }

    private function grid(): array
    {
        $grid = [];

        foreach ($this->sheet() as $index => $cells) {
            $grid[$index + 1] = array_combine(range(1, max(count($cells), 1)), $cells === [] ? [null] : $cells);
        }

        return $grid;
    }

    public function test_the_console_command_creates_each_project_once(): void
    {
        $owner = User::where('organization_id', $this->org->id)->where('role', 'owner')->first() ?? $this->member($this->org, 'owner');
        $path = $this->pack([['code' => 'EST-ONE', 'name' => 'Sample bungalow', 'client' => 'A Client', 'location' => 'Solapur', 'state' => 'Maharashtra', 'grid' => $this->grid()]]);

        $this->artisan('boq:load-estimates', ['email' => $owner->email, 'path' => $path])->assertSuccessful();
        $this->artisan('boq:load-estimates', ['email' => $owner->email, 'path' => $path])->assertSuccessful();
        Auth::setUser($owner);

        $project = Project::where('code', 'EST-ONE')->sole();

        $this->assertSame('Solapur', $project->location);
        $this->assertSame('A Client', $project->client->name);
        $this->assertSame('1067000.00', $project->contract_value);
        $this->assertSame(10, BoqItem::where('project_id', $project->id)->count());
    }

    /** A project the customer already made, its value split into phases. */
    private function phasedProject(array $amounts): Project
    {
        $project = $this->makeProject($this->org, ['code' => 'EST-ONE', 'name' => 'Sample bungalow']);
        $phases = array_map(fn (string $amount, int $index) => ['name' => 'Phase '.($index + 1), 'amount' => $amount], $amounts, array_keys($amounts));

        return app(ProjectService::class)->update($project, ['phases' => $phases], $this->owner);
    }

    public function test_the_console_command_leaves_a_phased_value_alone_when_the_boq_comes_to_something_else(): void
    {
        $project = $this->phasedProject(['5000000', '15000000']);
        $path = $this->pack([['code' => 'EST-ONE', 'name' => 'Sample bungalow', 'grid' => $this->grid()]]);

        $this->artisan('boq:load-estimates', ['email' => $this->owner->email, 'path' => $path])
            ->expectsOutputToContain('phases')
            ->assertFailed();

        $project->refresh();
        $this->assertSame('20000000.00', $project->contract_value);
        $this->assertSame(['5000000.00', '15000000.00'], ProjectValuePhase::where('project_id', $project->id)->orderBy('sort')->pluck('amount')->all());
        $this->assertSame(0, BoqItem::where('project_id', $project->id)->count());
    }

    public function test_the_console_command_loads_into_a_phased_project_whose_phases_come_to_the_boq_total(): void
    {
        $project = $this->phasedProject(['67000', '1000000']);
        $path = $this->pack([['code' => 'EST-ONE', 'name' => 'Sample bungalow', 'grid' => $this->grid()]]);

        $this->artisan('boq:load-estimates', ['email' => $this->owner->email, 'path' => $path])->assertSuccessful();

        $project->refresh();
        $this->assertSame('1067000.00', $project->contract_value);
        $this->assertSame(2, ProjectValuePhase::where('project_id', $project->id)->count());
        $this->assertSame(10, BoqItem::where('project_id', $project->id)->count());
    }

    public function test_the_console_command_refuses_a_non_owner_and_a_bad_file(): void
    {
        $owner = User::where('organization_id', $this->org->id)->where('role', 'owner')->first() ?? $this->member($this->org, 'owner');
        $path = tempnam(sys_get_temp_dir(), 'bad');
        file_put_contents($path, 'not a pack');

        $this->artisan('boq:load-estimates', ['email' => $this->pm->email, 'path' => $path])->assertFailed();
        $this->artisan('boq:load-estimates', ['email' => $owner->email, 'path' => $path])->assertFailed();
    }
}
