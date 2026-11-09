<?php

namespace Tests\Feature\Drawings;

use App\Models\BoqItem;
use App\Models\DrawingSheet;
use App\Models\Project;
use App\Models\TakeoffLayer;
use App\Models\TakeoffMeasurement;
use App\Models\User;
use App\Services\Drawings\TakeoffService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\BuildsTenant;
use Tests\TestCase;

class TakeoffTest extends TestCase
{
    use BuildsDrawings, BuildsTenant, RefreshDatabase;

    private const RECTANGLE = [[0, 0], [200, 0], [200, 100], [0, 100]];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function measure($user, DrawingSheet $sheet, array $data): TestResponse
    {
        return $this->actingAs($user)->postJson(route('drawings.takeoff.measurements.store', $sheet), $data);
    }

    /** @return array{0:Project,1:DrawingSheet,2:TakeoffLayer,3:User} */
    private function setUpSheet(string $role = 'qs'): array
    {
        $organization = $this->tenant();
        $project = $this->makeProject($organization);

        return [$project, $this->scaledSheet($project), $this->makeLayer($project), $this->member($organization, $role)];
    }

    public function test_length_is_worked_out_from_the_scale(): void
    {
        [, $sheet, $layer, $user] = $this->setUpSheet();

        // Segments of 50 and 60 units at 0.1 m per unit.
        $this->measure($user, $sheet, ['type' => 'length', 'takeoff_layer_id' => $layer->id, 'points' => [[0, 0], [30, 40], [30, 100]]])
            ->assertCreated()->assertJsonPath('measurement.qty', '11.000')->assertJsonPath('measurement.unit', 'rmt');
    }

    public function test_area_is_worked_out_from_the_scale(): void
    {
        [, $sheet, $layer, $user] = $this->setUpSheet();

        // 200 x 100 units = 20000 square units, each 0.01 sqm.
        $this->measure($user, $sheet, ['type' => 'area', 'takeoff_layer_id' => $layer->id, 'points' => self::RECTANGLE])
            ->assertCreated()->assertJsonPath('measurement.qty', '200.000')->assertJsonPath('measurement.unit', 'sqm');
    }

    public function test_volume_is_area_times_depth(): void
    {
        [, $sheet, $layer, $user] = $this->setUpSheet();

        $this->measure($user, $sheet, ['type' => 'volume', 'takeoff_layer_id' => $layer->id, 'points' => self::RECTANGLE, 'depth' => '0.150'])
            ->assertCreated()->assertJsonPath('measurement.qty', '30.000')->assertJsonPath('measurement.unit', 'cum');
    }

    public function test_volume_needs_a_depth(): void
    {
        [, $sheet, $layer, $user] = $this->setUpSheet();

        $this->measure($user, $sheet, ['type' => 'volume', 'takeoff_layer_id' => $layer->id, 'points' => self::RECTANGLE])
            ->assertUnprocessable()->assertJsonValidationErrors('depth');
    }

    public function test_count_needs_no_scale(): void
    {
        $organization = $this->tenant();
        $project = $this->makeProject($organization);
        $sheet = $this->makeSheet($this->makeDrawing($project)->currentVersion);

        $this->measure($this->member($organization, 'qs'), $sheet, ['type' => 'count', 'takeoff_layer_id' => $this->makeLayer($project)->id, 'points' => [[10, 10], [20, 20], [30, 30], [10, 10]]])
            ->assertCreated()->assertJsonPath('measurement.qty', '4.000');
    }

    public function test_length_needs_a_scale(): void
    {
        $organization = $this->tenant();
        $project = $this->makeProject($organization);
        $sheet = $this->makeSheet($this->makeDrawing($project)->currentVersion);

        $this->measure($this->member($organization, 'qs'), $sheet, ['type' => 'length', 'takeoff_layer_id' => $this->makeLayer($project)->id, 'points' => [[0, 0], [10, 0]]])
            ->assertUnprocessable()->assertJsonValidationErrors('points');
    }

    public function test_a_browser_supplied_quantity_is_ignored(): void
    {
        [, $sheet, $layer, $user] = $this->setUpSheet();

        $this->measure($user, $sheet, ['type' => 'area', 'takeoff_layer_id' => $layer->id, 'points' => self::RECTANGLE, 'qty' => '999999', 'unit' => 'MT'])
            ->assertJsonPath('measurement.qty', '200.000')->assertJsonPath('measurement.unit', 'sqm');
    }

    public function test_an_outline_that_crosses_itself_is_refused(): void
    {
        [, $sheet, $layer, $user] = $this->setUpSheet();

        $this->measure($user, $sheet, ['type' => 'area', 'takeoff_layer_id' => $layer->id, 'points' => [[0, 0], [100, 100], [100, 0], [0, 100]]])
            ->assertUnprocessable()->assertJsonValidationErrors('points');
    }

    public function test_an_outline_with_no_area_is_refused(): void
    {
        [, $sheet, $layer, $user] = $this->setUpSheet();

        $this->measure($user, $sheet, ['type' => 'area', 'takeoff_layer_id' => $layer->id, 'points' => [[0, 0], [50, 0], [100, 0]]])
            ->assertUnprocessable()->assertJsonValidationErrors('points');
    }

    public function test_a_repeated_click_and_a_closing_click_change_nothing(): void
    {
        [, $sheet, $layer, $user] = $this->setUpSheet();

        $this->measure($user, $sheet, ['type' => 'area', 'takeoff_layer_id' => $layer->id, 'points' => [[0, 0], [200, 0], [200, 0], [200, 100], [0, 100], [0, 0]]])
            ->assertCreated()->assertJsonPath('measurement.qty', '200.000');
    }

    public function test_points_must_be_on_the_sheet(): void
    {
        [, $sheet, $layer, $user] = $this->setUpSheet();

        $this->measure($user, $sheet, ['type' => 'length', 'takeoff_layer_id' => $layer->id, 'points' => [[0, 0], [5000, 0]]])
            ->assertUnprocessable()->assertJsonValidationErrors('points');
    }

    public function test_repeating_a_save_returns_the_original(): void
    {
        [, $sheet, $layer, $user] = $this->setUpSheet();
        $data = ['type' => 'area', 'takeoff_layer_id' => $layer->id, 'points' => self::RECTANGLE, 'client_uuid' => (string) Str::uuid()];

        $first = $this->measure($user, $sheet, $data)->assertCreated()->json('measurement.id');
        $second = $this->measure($user, $sheet, $data)->assertOk()->json('measurement.id');

        $this->assertSame($first, $second);
        $this->assertSame(1, TakeoffMeasurement::count());
    }

    public function test_calibration_works_out_the_scale(): void
    {
        $organization = $this->tenant();
        $project = $this->makeProject($organization);
        $sheet = $this->makeSheet($this->makeDrawing($project)->currentVersion);

        // A 3-4-5 triangle: 500 units between the points represent 25 m, so 0.05 m per unit.
        $this->actingAs($this->member($organization, 'qs'))
            ->postJson(route('drawings.register.sheets.calibrate', $sheet), ['points' => [[0, 0], [300, 400]], 'length_m' => 25])
            ->assertOk()->assertJsonPath('calibLength', '25.000');

        $this->assertSame('0.050000000000', $sheet->fresh()->metres_per_unit);
        $this->assertDatabaseHas('audit_logs', ['auditable_type' => DrawingSheet::class, 'auditable_id' => $sheet->id, 'action' => 'updated']);
    }

    public function test_changing_the_scale_recalculates_lengths_areas_and_volumes_but_not_counts(): void
    {
        [, $sheet, $layer, $user] = $this->setUpSheet();
        $this->measure($user, $sheet, ['type' => 'area', 'takeoff_layer_id' => $layer->id, 'points' => self::RECTANGLE])->assertCreated();
        $this->measure($user, $sheet, ['type' => 'volume', 'takeoff_layer_id' => $layer->id, 'points' => self::RECTANGLE, 'depth' => 2])->assertCreated();
        $this->measure($user, $sheet, ['type' => 'count', 'takeoff_layer_id' => $layer->id, 'points' => [[1, 1], [2, 2]]])->assertCreated();

        // 100 units for 20 m is 0.2 m per unit: four times the area.
        $this->actingAs($user)->postJson(route('drawings.register.sheets.calibrate', $sheet), ['points' => [[0, 0], [100, 0]], 'length_m' => 20])
            ->assertOk();

        $this->assertSame(['800.000', '1600.000', '2.000'], TakeoffMeasurement::orderBy('id')->pluck('qty')->all());
    }

    public function test_calibration_points_must_be_apart(): void
    {
        [, $sheet, , $user] = $this->setUpSheet();

        $this->actingAs($user)->postJson(route('drawings.register.sheets.calibrate', $sheet), ['points' => [[10, 10], [10.5, 10]], 'length_m' => 5])
            ->assertUnprocessable()->assertJsonValidationErrors('points');
    }

    public function test_changing_the_depth_recalculates_the_volume(): void
    {
        [, $sheet, $layer, $user] = $this->setUpSheet();
        $id = $this->measure($user, $sheet, ['type' => 'volume', 'takeoff_layer_id' => $layer->id, 'points' => self::RECTANGLE, 'depth' => 1])->json('measurement.id');

        $this->actingAs($user)->patchJson(route('drawings.takeoff.measurements.update', $id), ['depth' => '0.5'])
            ->assertOk()->assertJsonPath('measurement.qty', '100.000');
    }

    public function test_a_measurement_links_only_to_a_boq_item_in_a_matching_unit(): void
    {
        [$project, $sheet, $layer, $user] = $this->setUpSheet();
        $cum = $this->makeBoqItem($project);

        $this->measure($user, $sheet, ['type' => 'area', 'takeoff_layer_id' => $layer->id, 'boq_item_id' => $cum->id, 'points' => self::RECTANGLE])
            ->assertUnprocessable()->assertJsonValidationErrors('boq_item_id');

        $this->measure($user, $sheet, ['type' => 'volume', 'takeoff_layer_id' => $layer->id, 'boq_item_id' => $cum->id, 'points' => self::RECTANGLE, 'depth' => 1])
            ->assertCreated()->assertJsonPath('measurement.boq_item_id', $cum->id);
    }

    public function test_unit_spellings_are_recognised(): void
    {
        $this->assertSame('volume', TakeoffService::dimensionOfUnit('Cu.M'));
        $this->assertSame('area', TakeoffService::dimensionOfUnit('sq. m'));
        $this->assertSame('length', TakeoffService::dimensionOfUnit('RMT'));
        $this->assertSame('count', TakeoffService::dimensionOfUnit('No.'));
        $this->assertNull(TakeoffService::dimensionOfUnit('MT'));
    }

    public function test_a_boq_item_or_layer_from_another_project_is_refused(): void
    {
        [, $sheet, $layer, $user] = $this->setUpSheet();
        $other = $this->makeProject($user->organization);

        $this->measure($user, $sheet, ['type' => 'volume', 'takeoff_layer_id' => $layer->id, 'boq_item_id' => $this->makeBoqItem($other)->id, 'points' => self::RECTANGLE, 'depth' => 1])
            ->assertUnprocessable()->assertJsonValidationErrors('boq_item_id');
        $this->measure($user, $sheet, ['type' => 'count', 'takeoff_layer_id' => $this->makeLayer($other)->id, 'points' => [[1, 1]]])
            ->assertUnprocessable()->assertJsonValidationErrors('takeoff_layer_id');
    }

    public function test_totals_are_copied_to_the_boq_on_request(): void
    {
        [$project, $sheet, $layer, $user] = $this->setUpSheet();
        $item = $this->makeBoqItem($project, '1.000');
        $this->measure($user, $sheet, ['type' => 'volume', 'takeoff_layer_id' => $layer->id, 'boq_item_id' => $item->id, 'points' => self::RECTANGLE, 'depth' => '0.150'])->assertCreated();
        $this->measure($user, $sheet, ['type' => 'volume', 'takeoff_layer_id' => $layer->id, 'boq_item_id' => $item->id, 'points' => self::RECTANGLE, 'depth' => '0.100'])->assertCreated();

        $this->assertSame('1.000', $item->fresh()->qty, 'Saving a measurement must not change the BOQ by itself.');

        $this->actingAs($user)->post(route('drawings.takeoff.apply', $item))->assertRedirect();

        $this->assertSame('50.000', $item->fresh()->qty);
    }

    public function test_the_boq_cannot_drop_below_the_approved_quantity(): void
    {
        [$project, $sheet, $layer, $user] = $this->setUpSheet();
        $owner = $this->member($project->organization, 'owner');
        $item = $this->makeBoqItem($project, '500.000');
        $this->approveEntry($this->draftEntry($project, $item, $user), $user, $owner);

        $this->measure($user, $sheet, ['type' => 'volume', 'takeoff_layer_id' => $layer->id, 'boq_item_id' => $item->id, 'points' => [[0, 0], [10, 0], [10, 10], [0, 10]], 'depth' => 1])->assertCreated();

        $this->actingAs($user)->post(route('drawings.takeoff.apply', $item))->assertSessionHasErrors('boq');

        $this->assertSame('500.000', $item->fresh()->qty);
    }

    public function test_old_revisions_are_left_out_of_the_totals(): void
    {
        [$project, $sheet, $layer, $user] = $this->setUpSheet();
        $item = $this->makeBoqItem($project, '1.000');
        $this->measure($user, $sheet, ['type' => 'volume', 'takeoff_layer_id' => $layer->id, 'boq_item_id' => $item->id, 'points' => self::RECTANGLE, 'depth' => 1])->assertCreated();

        $drawing = $sheet->version->drawing;
        $newSheet = $this->makeSheet($this->makeVersion($drawing, 'R1'), metresPerUnit: '0.100000000000');
        $this->measure($user, $newSheet, ['type' => 'volume', 'takeoff_layer_id' => $layer->id, 'boq_item_id' => $item->id, 'points' => self::RECTANGLE, 'depth' => 2])->assertCreated();

        $service = app(TakeoffService::class);
        $this->assertSame('400.000', $service->boqTotals($project)->first()['takeoff']);
        $this->assertSame(1, $service->supersededCount($project));

        $this->actingAs($user)->get(route('drawings.takeoff.index', $project))->assertOk()->assertSee('400.000');
    }

    public function test_totals_are_grouped_by_layer_and_unit(): void
    {
        [$project, $sheet, $layer, $user] = $this->setUpSheet();
        $this->measure($user, $sheet, ['type' => 'area', 'takeoff_layer_id' => $layer->id, 'points' => self::RECTANGLE])->assertCreated();
        $this->measure($user, $sheet, ['type' => 'area', 'takeoff_layer_id' => $layer->id, 'points' => self::RECTANGLE])->assertCreated();
        $this->measure($user, $sheet, ['type' => 'count', 'takeoff_layer_id' => $layer->id, 'points' => [[1, 1]]])->assertCreated();

        $totals = app(TakeoffService::class)->layerTotals($project);

        $this->assertSame('400.000', $totals->firstWhere('unit', 'sqm')['qty']);
        $this->assertSame('1.000', $totals->firstWhere('unit', 'nos')['qty']);
    }

    public function test_a_layer_in_use_cannot_be_removed(): void
    {
        [, $sheet, $layer, $user] = $this->setUpSheet();
        $this->measure($user, $sheet, ['type' => 'count', 'takeoff_layer_id' => $layer->id, 'points' => [[1, 1]]])->assertCreated();

        $this->actingAs($user)->delete(route('drawings.takeoff.layers.destroy', $layer))->assertSessionHasErrors('layer');

        $this->assertDatabaseHas('takeoff_layers', ['id' => $layer->id]);
    }

    public function test_adding_a_layer_twice_returns_the_same_layer(): void
    {
        [$project, , , $user] = $this->setUpSheet();
        $data = ['name' => 'Plaster', 'colour' => '#1B4A66'];

        $first = $this->actingAs($user)->postJson(route('drawings.takeoff.layers.store', $project), $data)->assertCreated()->json('layer.id');
        $second = $this->actingAs($user)->postJson(route('drawings.takeoff.layers.store', $project), $data)->json('layer.id');

        $this->assertSame($first, $second);
    }

    public function test_a_measurement_can_be_removed(): void
    {
        [, $sheet, $layer, $user] = $this->setUpSheet();
        $id = $this->measure($user, $sheet, ['type' => 'count', 'takeoff_layer_id' => $layer->id, 'points' => [[1, 1]]])->json('measurement.id');

        $this->actingAs($user)->deleteJson(route('drawings.takeoff.measurements.destroy', $id))->assertOk();

        $this->assertSoftDeleted('takeoff_measurements', ['id' => $id]);
    }

    public function test_roles_without_takeoff_cannot_measure_or_set_the_scale(): void
    {
        [$project, $sheet, $layer] = $this->setUpSheet();
        $engineer = $this->member($project->organization, 'engineer');
        $item = $this->makeBoqItem($project);

        $this->measure($engineer, $sheet, ['type' => 'count', 'takeoff_layer_id' => $layer->id, 'points' => [[1, 1]]])->assertForbidden();
        $this->actingAs($engineer)->postJson(route('drawings.register.sheets.calibrate', $sheet), ['points' => [[0, 0], [100, 0]], 'length_m' => 5])->assertForbidden();
        $this->actingAs($engineer)->post(route('drawings.takeoff.apply', $item))->assertForbidden();
    }

    public function test_applying_to_the_boq_needs_the_boq_permission_too(): void
    {
        config(['buildos.roles.qs.permissions' => array_values(array_diff(config('buildos.roles.qs.permissions'), ['boq.*']))]);
        [$project, , , $user] = $this->setUpSheet();

        $this->actingAs($user)->post(route('drawings.takeoff.apply', $this->makeBoqItem($project)))->assertForbidden();
    }

    public function test_other_companies_cannot_reach_a_sheet_or_measurement(): void
    {
        [$project, $sheet, $layer, $user] = $this->setUpSheet();
        $id = $this->measure($user, $sheet, ['type' => 'count', 'takeoff_layer_id' => $layer->id, 'points' => [[1, 1]]])->json('measurement.id');
        $outsider = $this->member($this->tenant('Other Co'), 'owner');

        $this->measure($outsider, $sheet, ['type' => 'count', 'takeoff_layer_id' => $layer->id, 'points' => [[1, 1]]])->assertNotFound();
        $this->actingAs($outsider)->patchJson(route('drawings.takeoff.measurements.update', $id), ['label' => 'x'])->assertNotFound();
        $this->actingAs($outsider)->deleteJson(route('drawings.takeoff.measurements.destroy', $id))->assertNotFound();
        $this->actingAs($outsider)->postJson(route('drawings.register.sheets.calibrate', $sheet), ['points' => [[0, 0], [100, 0]], 'length_m' => 5])->assertNotFound();
        $this->actingAs($outsider)->post(route('drawings.takeoff.layers.store', $project), ['name' => 'x', 'colour' => '#000000'])->assertNotFound();
        $this->actingAs($outsider)->post(route('drawings.takeoff.apply', BoqItem::create(['organization_id' => $project->organization_id, 'project_id' => $project->id, 'code' => 'X', 'description' => 'x', 'unit' => 'cum', 'qty' => 1, 'rate' => 1])))->assertNotFound();
    }
}
