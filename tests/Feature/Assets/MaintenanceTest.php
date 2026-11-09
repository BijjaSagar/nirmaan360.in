<?php

namespace Tests\Feature\Assets;

use App\Models\AssetServiceRecord;
use App\Services\Assets\AssetService;
use App\Services\Assets\MaintenanceService;
use App\Services\Assets\PlantLogService;
use Illuminate\Validation\ValidationException;
use Tests\Feature\FieldOps\FieldOpsTestCase;

class MaintenanceTest extends FieldOpsTestCase
{
    private object $w;

    private MaintenanceService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-11-09 10:00:00');
        $this->w = $this->world();
        $this->actingAs($this->w->owner);
        $this->service = app(MaintenanceService::class);
    }

    private function work(object $asset, string $date, string $hours): void
    {
        app(PlantLogService::class)->record(['asset_id' => $asset->id, 'log_date' => $date, 'hours' => $hours], $this->w->owner);
    }

    public function test_a_service_due_by_date_is_overdue_due_soon_or_on_schedule(): void
    {
        $asset = $this->ownedAsset($this->w->owner);

        foreach (['2026-11-08' => 'overdue', '2026-11-09' => 'due_soon', '2026-11-16' => 'due_soon', '2026-11-17' => 'ok', '2027-01-01' => 'ok'] as $due => $state) {
            $item = $this->service->add(['asset_id' => $asset->id, 'title' => "Due $due", 'interval_days' => 90, 'next_due_on' => $due], $this->w->owner);

            $this->assertSame($state, $this->service->status($item, $asset)['state'], "due $due");
        }
    }

    public function test_hours_since_the_last_service_drive_the_state(): void
    {
        $asset = $this->ownedAsset($this->w->owner);
        $item = $this->service->add(['asset_id' => $asset->id, 'title' => 'Engine oil', 'interval_usage' => 250, 'last_done_on' => '2026-10-01'], $this->w->owner);

        // 10 days at 22 hours = 220 h, which is 88% of 250.
        foreach (range(2, 11) as $day) {
            $this->work($asset, sprintf('2026-10-%02d', $day), '22');
        }

        $status = $this->service->status($item, $asset);
        $this->assertSame('ok', $status['state']);
        $this->assertSame('220.000', $status['usage_since']);
        $this->assertSame('30.000', $status['usage_left']);

        $this->work($asset, '2026-10-12', '6'); // 226 h, past 90%
        $this->assertSame('due_soon', $this->service->status($item, $asset)['state']);

        $this->work($asset, '2026-10-13', '24'); // 250 h, the interval is reached
        $status = $this->service->status($item, $asset);
        $this->assertSame('overdue', $status['state']);
        $this->assertSame('0.000', $status['usage_left']);

        $this->work($asset, '2026-10-14', '2');
        $this->assertSame('-2.000', $this->service->status($item, $asset)['usage_left']);
    }

    public function test_work_before_the_last_service_does_not_count(): void
    {
        $asset = $this->ownedAsset($this->w->owner);
        $this->work($asset, '2026-09-10', '20');
        $this->work($asset, '2026-10-01', '10'); // the service day itself counts as before the service
        $this->work($asset, '2026-10-02', '5');

        $item = $this->service->add(['asset_id' => $asset->id, 'title' => 'Grease', 'interval_usage' => 100, 'last_done_on' => '2026-10-01'], $this->w->owner);

        $this->assertSame('5.000', $this->service->status($item, $asset)['usage_since']);
    }

    public function test_usage_scheduling_needs_a_meter(): void
    {
        $asset = $this->ownedAsset($this->w->owner, ['meter_unit' => null]);

        $this->expectException(ValidationException::class);
        $this->service->add(['asset_id' => $asset->id, 'title' => 'Oil', 'interval_usage' => 250], $this->w->owner);
    }

    public function test_a_schedule_needs_an_interval_by_date_or_usage(): void
    {
        $asset = $this->ownedAsset($this->w->owner);

        $this->post(route('asset-maintenance.store'), ['asset_id' => $asset->id, 'title' => 'Oil'])->assertSessionHasErrors(['interval_usage', 'interval_days']);
    }

    public function test_the_next_due_date_is_worked_out_from_the_interval(): void
    {
        $asset = $this->ownedAsset($this->w->owner);

        $fromLast = $this->service->add(['asset_id' => $asset->id, 'title' => 'A', 'interval_days' => 30, 'last_done_on' => '2026-10-20'], $this->w->owner);
        $fromToday = $this->service->add(['asset_id' => $asset->id, 'title' => 'B', 'interval_days' => 30], $this->w->owner);
        $given = $this->service->add(['asset_id' => $asset->id, 'title' => 'C', 'interval_days' => 30, 'next_due_on' => '2026-12-25'], $this->w->owner);

        $this->assertSame('2026-11-19', $fromLast->next_due_on->toDateString());
        $this->assertSame('2026-12-09', $fromToday->next_due_on->toDateString());
        $this->assertSame('2026-12-25', $given->next_due_on->toDateString());
    }

    public function test_doing_a_service_keeps_the_history_and_moves_the_next_date_out(): void
    {
        $asset = $this->ownedAsset($this->w->owner);
        $item = $this->service->add(['asset_id' => $asset->id, 'title' => 'Engine oil', 'interval_days' => 90, 'interval_usage' => 250, 'next_due_on' => '2026-11-01'], $this->w->owner);
        $this->work($asset, '2026-11-02', '8');

        $record = $this->service->markDone($item, ['done_on' => '2026-11-05', 'cost' => '4200.50', 'done_by' => 'Dealer workshop'], $this->w->owner);

        $item->refresh();
        $this->assertSame('2026-11-05', $item->last_done_on->toDateString());
        $this->assertSame('2027-02-03', $item->next_due_on->toDateString());
        $this->assertSame('4200.50', $record->cost);
        $this->assertSame('Engine oil', $record->description);
        $this->assertSame('ok', $this->service->status($item, $asset)['state']);
        $this->assertSame('0.000', $this->service->status($item, $asset)['usage_since'], 'The 8 hours were before the service.');

        // Entering an older service afterwards keeps the record but not the dates.
        $this->service->markDone($item, ['done_on' => '2026-10-01'], $this->w->owner);
        $this->assertSame('2026-11-05', $item->refresh()->last_done_on->toDateString());
        $this->assertSame(2, AssetServiceRecord::where('maintenance_id', $item->id)->count());
    }

    public function test_the_schedule_lists_the_most_urgent_first_and_skips_paused_and_retired(): void
    {
        $asset = $this->ownedAsset($this->w->owner);
        $retired = $this->ownedAsset($this->w->owner, ['name' => 'Old roller']);
        $ok = $this->service->add(['asset_id' => $asset->id, 'title' => 'Later', 'interval_days' => 90, 'next_due_on' => '2027-03-01'], $this->w->owner);
        $this->service->add(['asset_id' => $asset->id, 'title' => 'Late', 'interval_days' => 90, 'next_due_on' => '2026-10-01'], $this->w->owner);
        $this->service->add(['asset_id' => $asset->id, 'title' => 'Soon', 'interval_days' => 90, 'next_due_on' => '2026-11-12'], $this->w->owner);
        $paused = $this->service->add(['asset_id' => $asset->id, 'title' => 'Paused', 'interval_days' => 90, 'next_due_on' => '2026-01-01'], $this->w->owner);
        $this->service->add(['asset_id' => $retired->id, 'title' => 'On retired', 'interval_days' => 90, 'next_due_on' => '2026-01-01'], $this->w->owner);
        $this->service->toggle($paused);
        app(AssetService::class)->update($retired, ['status' => 'retired']);

        $this->assertSame(['Late', 'Soon', 'Later'], $this->service->schedule()->pluck('item.title')->all());
        $this->assertSame($ok->id, $this->service->schedule()->last()['item']->id);

        $this->get(route('asset-maintenance.index'))->assertOk()->assertSee('Late')->assertSee('Overdue')->assertDontSee('Paused')->assertDontSee('On retired');
    }
}
