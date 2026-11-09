<?php

namespace Tests\Feature\Drawings;

use App\Models\BbsBar;
use App\Models\BbsSchedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\BuildsTenant;
use Tests\TestCase;

class BbsTest extends TestCase
{
    use BuildsDrawings, BuildsTenant, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function barData(array $overrides = []): array
    {
        return [
            'location' => 'Column C4',
            'mark' => 'C4-1',
            'steel_type' => 'mild',
            'dia_mm' => 25,
            'sets' => 5,
            'per_set' => 4,
            'shape_code' => 'III-A',
            'dims' => ['l' => 3000],
            ...$overrides,
        ];
    }

    private function schedule($project, $user): BbsSchedule
    {
        $this->actingAs($user)->post(route('drawings.bbs.store', $project), ['title' => 'Column C4'])->assertRedirect();

        return BbsSchedule::latest('id')->firstOrFail();
    }

    public function test_a_bar_is_worked_out_on_the_server_and_totalled(): void
    {
        $organization = $this->tenant();
        $project = $this->makeProject($organization);
        $user = $this->member($organization, 'engineer');
        $schedule = $this->schedule($project, $user);

        // Values sent by a client for the calculated columns are ignored.
        $this->actingAs($user)->post(route('drawings.bbs.bars.store', $schedule), $this->barData(['total_weight_kg' => '1', 'cutting_length_mm' => '1']))->assertRedirect();

        $bar = BbsBar::firstOrFail();
        $this->assertSame('3000.000', $bar->cutting_length_mm);
        $this->assertSame('231.481', $bar->total_weight_kg);
        $this->assertSame('BBS-001', $schedule->number);

        $this->actingAs($user)->get(route('drawings.bbs.show', $schedule))
            ->assertOk()
            ->assertSee('231.481')
            ->assertSee('An engineer must check every bar before cutting.');
    }

    public function test_editing_a_bar_recalculates_it(): void
    {
        $organization = $this->tenant();
        $project = $this->makeProject($organization);
        $user = $this->member($organization, 'qs');
        $schedule = $this->schedule($project, $user);
        $this->actingAs($user)->post(route('drawings.bbs.bars.store', $schedule), $this->barData())->assertRedirect();
        $bar = BbsBar::firstOrFail();

        $this->actingAs($user)->patch(route('drawings.bbs.bars.update', $bar), $this->barData(['sets' => 10]))->assertRedirect();

        $this->assertSame('462.963', $bar->fresh()->total_weight_kg);
    }

    public function test_repeating_a_bar_submission_adds_one_row(): void
    {
        $organization = $this->tenant();
        $project = $this->makeProject($organization);
        $user = $this->member($organization, 'qs');
        $schedule = $this->schedule($project, $user);
        $data = $this->barData(['client_uuid' => (string) Str::uuid()]);

        $this->actingAs($user)->post(route('drawings.bbs.bars.store', $schedule), $data)->assertRedirect();
        $this->actingAs($user)->post(route('drawings.bbs.bars.store', $schedule), $data)->assertRedirect();

        $this->assertSame(1, BbsBar::count());
    }

    public function test_excel_export_matches_the_schedule_and_carries_the_warning(): void
    {
        $organization = $this->tenant();
        $project = $this->makeProject($organization);
        $user = $this->member($organization, 'viewer');
        $writer = $this->member($organization, 'qs');
        $schedule = $this->schedule($project, $writer);

        $this->actingAs($writer)->post(route('drawings.bbs.bars.store', $schedule), $this->barData())->assertRedirect();
        $this->actingAs($writer)->post(route('drawings.bbs.bars.store', $schedule), $this->barData([
            'mark' => '=1+1', 'steel_type' => 'deformed', 'dia_mm' => 8, 'sets' => 10, 'per_set' => 20, 'shape_code' => 'VIII-A', 'dims' => ['A' => 400, 'E' => 250],
        ]))->assertRedirect();

        $response = $this->actingAs($user)->get(route('drawings.bbs.export', $schedule))->assertOk();
        $this->assertStringContainsString('spreadsheetml', (string) $response->headers->get('Content-Type'));

        $path = tempnam(sys_get_temp_dir(), 'bbs').'.xlsx';
        file_put_contents($path, $response->streamedContent());
        $sheet = IOFactory::load($path)->getActiveSheet();
        unlink($path);

        $this->assertStringContainsString('engineer must check', $sheet->getCell('A3')->getValue());
        $this->assertSame('C4-1', $sheet->getCell('B6')->getValue());
        $this->assertEquals(3000, $sheet->getCell('K6')->getValue());
        $this->assertEquals(231.481, $sheet->getCell('N6')->getValue());
        $this->assertSame('=1+1', $sheet->getCell('B7')->getValue());
        $this->assertSame('s', $sheet->getCell('B7')->getDataType(), 'A mark must never become a formula.');
        $this->assertEquals(118.519, $sheet->getCell('N7')->getValue());

        $rows = $sheet->toArray(null, false, false);
        $totalRow = collect($rows)->first(fn (array $row) => $row[0] === 'Total weight (kg)');
        $this->assertEquals(350.0, $totalRow[3]);
    }

    public function test_viewers_can_read_but_not_write(): void
    {
        $organization = $this->tenant();
        $project = $this->makeProject($organization);
        $schedule = $this->schedule($project, $this->member($organization, 'qs'));
        $viewer = $this->member($organization, 'viewer');

        $this->actingAs($viewer)->get(route('drawings.bbs.index', $project))->assertOk();
        $this->actingAs($viewer)->get(route('drawings.bbs.show', $schedule))->assertOk();
        $this->actingAs($viewer)->post(route('drawings.bbs.store', $project), ['title' => 'No'])->assertForbidden();
        $this->actingAs($viewer)->post(route('drawings.bbs.bars.store', $schedule), $this->barData())->assertForbidden();
    }

    public function test_other_companies_cannot_reach_a_schedule(): void
    {
        $organization = $this->tenant();
        $schedule = $this->schedule($this->makeProject($organization), $this->member($organization, 'qs'));
        $outsider = $this->member($this->tenant('Other Co'), 'owner');

        $this->actingAs($outsider)->get(route('drawings.bbs.show', $schedule))->assertNotFound();
        $this->actingAs($outsider)->get(route('drawings.bbs.export', $schedule))->assertNotFound();
        $this->actingAs($outsider)->post(route('drawings.bbs.bars.store', $schedule), $this->barData())->assertNotFound();
    }

    public function test_a_schedule_can_be_renamed_and_removed_with_a_number_never_reused(): void
    {
        $organization = $this->tenant();
        $project = $this->makeProject($organization);
        $user = $this->member($organization, 'qs');
        $schedule = $this->schedule($project, $user);

        $this->actingAs($user)->patch(route('drawings.bbs.update', $schedule), ['title' => 'Column C5', 'notes' => 'Revised'])->assertRedirect();
        $this->assertSame('Column C5', $schedule->fresh()->title);

        $this->actingAs($user)->delete(route('drawings.bbs.destroy', $schedule))->assertRedirect();
        $this->assertSoftDeleted($schedule);

        $this->assertSame('BBS-002', $this->schedule($project, $user)->number);
    }

    public function test_a_bar_can_be_removed_and_the_totals_follow(): void
    {
        $organization = $this->tenant();
        $project = $this->makeProject($organization);
        $user = $this->member($organization, 'qs');
        $schedule = $this->schedule($project, $user);
        $this->actingAs($user)->post(route('drawings.bbs.bars.store', $schedule), $this->barData())->assertRedirect();

        $this->actingAs($user)->delete(route('drawings.bbs.bars.destroy', BbsBar::firstOrFail()))->assertRedirect();

        $this->assertSame(0, BbsBar::count());
        $this->actingAs($user)->get(route('drawings.bbs.show', $schedule))->assertOk()->assertSee('No bars yet');
    }

    public function test_an_invalid_shape_size_or_steel_is_refused(): void
    {
        $organization = $this->tenant();
        $project = $this->makeProject($organization);
        $user = $this->member($organization, 'qs');
        $schedule = $this->schedule($project, $user);
        $store = route('drawings.bbs.bars.store', $schedule);

        $this->actingAs($user)->post($store, $this->barData(['shape_code' => 'VI-A']))->assertSessionHasErrors('shape_code');
        $this->actingAs($user)->post($store, $this->barData(['dia_mm' => 11]))->assertSessionHasErrors('dia_mm');
        $this->actingAs($user)->post($store, $this->barData(['steel_type' => 'gold']))->assertSessionHasErrors('steel_type');
        $this->actingAs($user)->post($store, $this->barData(['dims' => ['l' => -5]]))->assertSessionHasErrors('dims.l');
        $this->actingAs($user)->post($store, $this->barData(['shape_code' => 'V-A', 'dims' => ['A' => 100]]))->assertSessionHasErrors('dims.E');

        $this->assertSame(0, BbsBar::count());
    }

    public function test_a_drawing_from_another_project_cannot_be_linked(): void
    {
        $organization = $this->tenant();
        $project = $this->makeProject($organization);
        $elsewhere = $this->makeDrawing($this->makeProject($organization));

        $this->actingAs($this->member($organization, 'qs'))
            ->post(route('drawings.bbs.store', $project), ['title' => 'F1', 'drawing_id' => $elsewhere->id])
            ->assertSessionHasErrors('drawing_id');
    }
}
