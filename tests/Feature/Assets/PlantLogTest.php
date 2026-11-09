<?php

namespace Tests\Feature\Assets;

use App\Models\FuelIssue;
use App\Models\MachineLog;
use App\Services\Assets\AssetService;
use App\Services\Assets\PlantLogService;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Feature\FieldOps\FieldOpsTestCase;

class PlantLogTest extends FieldOpsTestCase
{
    private object $w;

    private PlantLogService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->w = $this->world();
        $this->actingAs($this->w->owner);
        $this->service = app(PlantLogService::class);
    }

    private function record(object $asset, array $data = [])
    {
        return $this->service->record(['asset_id' => $asset->id, 'log_date' => '2026-11-01', ...$data], $this->w->owner);
    }

    public function test_hours_are_taken_from_the_meter_when_not_typed(): void
    {
        $crane = $this->ownedAsset($this->w->owner);

        $log = $this->record($crane, ['opening_reading' => 1204.5, 'closing_reading' => 1212.0]);

        $this->assertSame('7.500', $log->hours);
        $this->assertSame('0.000', $log->km);
        $this->assertSame('1204.500', $log->opening_reading);
    }

    public function test_a_km_meter_gives_km(): void
    {
        $truck = $this->ownedAsset($this->w->owner, ['meter_unit' => 'km']);

        $log = $this->record($truck, ['opening_reading' => 50200, 'closing_reading' => 50325.5]);

        $this->assertSame('125.500', $log->km);
        $this->assertSame('0.000', $log->hours);
    }

    public function test_typed_hours_win_over_the_meter(): void
    {
        $this->assertSame('6.000', $this->record($this->ownedAsset($this->w->owner), ['hours' => 6, 'opening_reading' => 10, 'closing_reading' => 19])->hours);
    }

    public function test_a_closing_reading_below_the_opening_one_is_refused(): void
    {
        $this->expectException(ValidationException::class);

        $this->record($this->ownedAsset($this->w->owner), ['opening_reading' => 20, 'closing_reading' => 10]);
    }

    public function test_a_log_with_nothing_in_it_is_refused_but_a_breakdown_only_day_is_not(): void
    {
        $asset = $this->ownedAsset($this->w->owner);

        try {
            $this->record($asset);
            $this->fail('An empty log must be refused.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('hours', $e->errors());
        }

        $log = $this->record($asset, ['breakdown_hours' => 8]);
        $this->assertSame('8.000', $log->breakdown_hours);
        $this->assertSame('0.000', $log->hours);

        $this->assertSame('0.000', $this->record($asset, ['log_date' => '2026-11-02', 'hours' => 0])->hours, 'An explicit idle day is a valid log.');
    }

    public function test_a_machine_cannot_work_more_than_24_hours_in_a_day(): void
    {
        $asset = $this->ownedAsset($this->w->owner);
        $this->record($asset, ['hours' => 16]);
        $this->record($asset, ['hours' => 8]); // exactly 24 is allowed

        $this->expectException(ValidationException::class);
        $this->record($asset, ['hours' => 0.5]);
    }

    public function test_the_same_client_uuid_logs_once(): void
    {
        $asset = $this->ownedAsset($this->w->owner);
        $uuid = (string) Str::uuid();

        $a = $this->record($asset, ['hours' => 5, 'client_uuid' => $uuid]);
        $b = $this->record($asset, ['hours' => 5, 'client_uuid' => $uuid]);

        $this->assertSame($a->id, $b->id);
        $this->assertSame(1, MachineLog::count());
    }

    public function test_the_log_defaults_to_the_projects_the_machine_is_on(): void
    {
        $asset = $this->ownedAsset($this->w->owner, ['project_id' => $this->w->project->id]);

        $this->assertSame($this->w->project->id, $this->record($asset, ['hours' => 1])->project_id);
    }

    public function test_a_retired_machine_takes_no_entries(): void
    {
        $asset = $this->ownedAsset($this->w->owner);
        app(AssetService::class)->update($asset, ['status' => 'retired']);

        foreach ([
            fn () => $this->record($asset->refresh(), ['hours' => 1]),
            fn () => $this->service->issueFuel(['asset_id' => $asset->id, 'issue_date' => '2026-11-01', 'litres' => 5, 'rate' => 90], $this->w->owner),
            fn () => $this->service->reportBreakdown(['asset_id' => $asset->id, 'reported_at' => '2026-11-01 08:00', 'description' => 'x'], $this->w->owner),
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('A retired asset must refuse entries.');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('asset_id', $e->errors());
            }
        }
    }

    public function test_fuel_amount_is_litres_times_rate_to_the_paisa(): void
    {
        $asset = $this->ownedAsset($this->w->owner);

        $a = $this->service->issueFuel(['asset_id' => $asset->id, 'issue_date' => '2026-11-01', 'litres' => 40, 'rate' => 94.75], $this->w->owner);
        $b = $this->service->issueFuel(['asset_id' => $asset->id, 'issue_date' => '2026-11-01', 'litres' => '33.333', 'rate' => '92.45'], $this->w->owner);

        $this->assertSame('3790.00', $a->amount);
        $this->assertSame('3081.64', $b->amount); // 33.333 x 92.45 = 3081.64
        $this->assertSame('40.000', $a->litres);
        $this->assertSame($this->w->owner->id, $a->issued_by);
    }

    public function test_fuel_issue_is_idempotent(): void
    {
        $asset = $this->ownedAsset($this->w->owner);
        $data = ['asset_id' => $asset->id, 'issue_date' => '2026-11-01', 'litres' => 10, 'rate' => 90, 'client_uuid' => (string) Str::uuid()];

        $this->service->issueFuel($data, $this->w->owner);
        $this->service->issueFuel($data, $this->w->owner);

        $this->assertSame(1, FuelIssue::count());
    }

    public function test_breakdowns_put_the_machine_under_repair_until_the_last_one_is_fixed(): void
    {
        $asset = $this->ownedAsset($this->w->owner);

        $first = $this->service->reportBreakdown(['asset_id' => $asset->id, 'reported_at' => '2026-11-01 08:00', 'description' => 'Hose burst'], $this->w->owner);
        $second = $this->service->reportBreakdown(['asset_id' => $asset->id, 'reported_at' => '2026-11-01 09:00', 'description' => 'Bucket tooth lost'], $this->w->owner);
        $this->assertSame('under_repair', $asset->refresh()->status);

        $this->service->resolveBreakdown($first, ['resolved_at' => '2026-11-01 17:30', 'repair_cost' => 3500.5, 'resolution' => 'New hose']);
        $this->assertSame('under_repair', $asset->refresh()->status, 'One is still open.');
        $this->assertSame('9.5', $first->refresh()->downtimeHours());
        $this->assertSame('3500.50', $first->repair_cost);

        $this->service->resolveBreakdown($second, ['resolved_at' => '2026-11-02 10:00']);
        $this->assertSame('active', $asset->refresh()->status);
    }

    public function test_a_breakdown_cannot_be_fixed_twice_or_before_it_happened(): void
    {
        $asset = $this->ownedAsset($this->w->owner);
        $breakdown = $this->service->reportBreakdown(['asset_id' => $asset->id, 'reported_at' => '2026-11-01 08:00', 'description' => 'Hose burst'], $this->w->owner);

        try {
            $this->service->resolveBreakdown($breakdown, ['resolved_at' => '2026-11-01 07:00']);
            $this->fail('Resolution before the report must be refused.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('resolved_at', $e->errors());
        }

        $this->service->resolveBreakdown($breakdown, ['resolved_at' => '2026-11-01 09:00']);

        try {
            $this->service->resolveBreakdown($breakdown->refresh(), ['resolved_at' => '2026-11-01 10:00']);
            $this->fail('Expected a 409.');
        } catch (HttpException $e) {
            $this->assertSame(409, $e->getStatusCode());
        }
    }

    public function test_the_asset_summary_adds_up_work_fuel_and_repairs(): void
    {
        $asset = $this->ownedAsset($this->w->owner);
        $this->record($asset, ['log_date' => '2026-11-01', 'hours' => 8]);
        $this->record($asset, ['log_date' => '2026-11-02', 'hours' => 7.5]);
        $this->service->issueFuel(['asset_id' => $asset->id, 'issue_date' => '2026-11-01', 'litres' => 93, 'rate' => 90], $this->w->owner);
        $breakdown = $this->service->reportBreakdown(['asset_id' => $asset->id, 'reported_at' => '2026-11-02 08:00', 'description' => 'x'], $this->w->owner);
        $this->service->resolveBreakdown($breakdown, ['resolved_at' => '2026-11-02 12:00', 'repair_cost' => 1250]);

        $summary = app(AssetService::class)->summary($asset);

        $this->assertSame('15.500', $summary['hours']);
        $this->assertSame('93.000', $summary['fuel_litres']);
        $this->assertSame('8370.00', $summary['fuel_cost']);
        $this->assertSame('1250.00', $summary['repair_cost']);
        $this->assertSame('6.00', $summary['consumption']); // 93 L over 15.5 h
        $this->assertSame('L/h', $summary['consumption_unit']);
    }

    public function test_the_log_form_saves_through_the_web_and_returns_to_the_tab(): void
    {
        $asset = $this->ownedAsset($this->w->owner);

        $this->post(route('machine-logs.store'), ['asset_id' => $asset->id, 'log_date' => '2026-11-03', 'hours' => 6.5, 'operator' => 'Ramesh', '_tab' => 'log'])
            ->assertRedirect(route('assets.show', ['asset' => $asset->id, 'tab' => 'log']))
            ->assertSessionHas('success');

        $this->assertSame('Ramesh', MachineLog::firstOrFail()->operator);
        $this->post(route('machine-logs.store'), ['asset_id' => $asset->id, 'log_date' => '2999-01-01', 'hours' => 1])->assertSessionHasErrors('log_date');
    }
}
