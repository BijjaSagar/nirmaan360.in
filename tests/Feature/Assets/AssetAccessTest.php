<?php

namespace Tests\Feature\Assets;

use App\Models\Asset;
use App\Models\AssetBreakdown;
use App\Models\AssetHireBill;
use App\Models\AssetMaintenance;
use App\Models\MachineLog;
use App\Services\Assets\HireBillService;
use App\Services\Assets\MaintenanceService;
use App\Services\Assets\PlantLogService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Tests\Feature\FieldOps\FieldOpsTestCase;

class AssetAccessTest extends FieldOpsTestCase
{
    public function test_who_can_do_what(): void
    {
        // role => [view register, add asset, log work, move asset, hire bills]
        $expected = [
            'owner' => [200, 200, 302, 302, 200],
            'pm' => [200, 200, 302, 302, 200],
            'engineer' => [200, 403, 302, 403, 403],
            'store' => [200, 403, 302, 403, 403],
            'accounts' => [200, 403, 403, 403, 200],
            'qs' => [200, 403, 403, 403, 403],
            'viewer' => [200, 403, 403, 403, 403],
        ];

        foreach ($expected as $role => [$view, $add, $log, $move, $bills]) {
            Auth::forgetUser();
            $w = $this->world($role, 'business', "Co $role");
            $this->actingAs($w->user);
            $asset = $this->ownedAsset($w->owner);

            $this->assertSame($view, $this->get(route('assets.index'))->getStatusCode(), "$role views the register");
            $this->assertSame($add, $this->get(route('assets.create'))->getStatusCode(), "$role adds an asset");
            $this->assertSame($log, $this->post(route('machine-logs.store'), ['asset_id' => $asset->id, 'log_date' => '2026-11-01', 'hours' => 4])->getStatusCode(), "$role logs work");
            $this->assertSame($move, $this->post(route('assets.transfers.store'), ['asset_id' => $asset->id, 'to_location' => 'Yard', 'moved_on' => '2026-11-01'])->getStatusCode(), "$role moves an asset");
            $this->assertSame($bills, $this->get(route('hire-bills.index'))->getStatusCode(), "$role opens hire bills");
        }
    }

    public function test_only_managers_can_delete_a_log_or_resolve_a_breakdown(): void
    {
        $w = $this->world('engineer');
        $this->actingAs($w->owner);
        $asset = $this->ownedAsset($w->owner);
        $log = app(PlantLogService::class)->record(['asset_id' => $asset->id, 'log_date' => '2026-11-01', 'hours' => 4], $w->owner);
        $breakdown = app(PlantLogService::class)->reportBreakdown(['asset_id' => $asset->id, 'reported_at' => '2026-11-01 08:00', 'description' => 'Overheating'], $w->owner);

        Auth::forgetUser();
        $this->actingAs($w->user);
        $this->delete(route('machine-logs.destroy', $log))->assertForbidden();
        $this->patch(route('assets.breakdowns.update', $breakdown), ['resolved_at' => '2026-11-02 08:00'])->assertForbidden();
        $this->assertSame(1, MachineLog::count());

        Auth::forgetUser();
        $this->actingAs($w->owner);
        $this->delete(route('machine-logs.destroy', $log))->assertRedirect();
        $this->assertSame(0, MachineLog::count());
    }

    public function test_the_module_is_only_on_business_and_enterprise_plans(): void
    {
        foreach (['free' => 403, 'contractor' => 403, 'business' => 200, 'enterprise' => 200] as $plan => $expected) {
            Auth::forgetUser();
            $w = $this->world('owner', $plan, "Co $plan");
            $this->actingAs($w->owner);

            foreach ([route('assets.index'), route('machine-logs.index'), route('asset-maintenance.index'), route('hire-bills.index')] as $url) {
                $this->get($url)->assertStatus($expected);
            }

            $this->get(route('dashboard'))->assertOk()->{$expected === 200 ? 'assertSee' : 'assertDontSee'}('Plant &amp; equipment', false);
        }
    }

    public function test_the_menu_shows_only_what_a_role_may_open(): void
    {
        $w = $this->world('viewer');
        $this->actingAs($w->user);

        $this->get(route('dashboard'))->assertOk()->assertSee('Assets')->assertSee('Machine log')->assertDontSee('Hire bills');

        Auth::forgetUser();
        $this->actingAs($this->member($w->organization, 'accounts'))->get(route('dashboard'))->assertSee('Hire bills');
    }

    public function test_another_companys_records_cannot_be_read_or_changed(): void
    {
        $mine = $this->world();
        $theirs = $this->world('owner', 'business', 'Rival Builders');

        $this->actingAs($theirs->owner);
        $theirAsset = $this->hiredAsset($theirs->owner, ['hire_basis' => 'hour', 'hire_rate' => 100]);
        $theirLog = app(PlantLogService::class)->record(['asset_id' => $theirAsset->id, 'log_date' => '2026-09-01', 'hours' => 5], $theirs->owner);
        $theirBreakdown = app(PlantLogService::class)->reportBreakdown(['asset_id' => $theirAsset->id, 'reported_at' => '2026-09-02 08:00', 'description' => 'Leak'], $theirs->owner);
        $theirItem = app(MaintenanceService::class)->add(['asset_id' => $theirAsset->id, 'title' => 'Oil', 'interval_days' => 30], $theirs->owner);
        $theirBill = app(HireBillService::class)->generate(['asset_id' => $theirAsset->id, 'period_from' => '2026-09-01', 'period_to' => '2026-09-30'], $theirs->owner);

        Auth::forgetUser();
        $this->actingAs($mine->owner);
        $myAsset = $this->ownedAsset($mine->owner);

        // Routes that name a record.
        $this->delete(route('machine-logs.destroy', $theirLog))->assertNotFound();
        $this->patch(route('assets.breakdowns.update', $theirBreakdown), ['resolved_at' => '2026-09-03 08:00'])->assertNotFound();
        $this->post(route('asset-maintenance.done', $theirItem), ['done_on' => '2026-09-05'])->assertNotFound();
        $this->post(route('asset-maintenance.toggle', $theirItem))->assertNotFound();
        $this->get(route('hire-bills.show', $theirBill))->assertNotFound();
        $this->post(route('hire-bills.finalize', $theirBill))->assertNotFound();
        $this->post(route('hire-bills.paid', $theirBill), ['paid_on' => '2026-10-01'])->assertNotFound();
        $this->post(route('hire-bills.cancel', $theirBill), ['reason' => 'x'])->assertNotFound();

        // Forms that carry an asset id.
        $this->post(route('machine-logs.store'), ['asset_id' => $theirAsset->id, 'log_date' => '2026-11-01', 'hours' => 1])->assertSessionHasErrors('asset_id');
        $this->post(route('assets.fuel.store'), ['asset_id' => $theirAsset->id, 'issue_date' => '2026-11-01', 'litres' => 1, 'rate' => 1])->assertSessionHasErrors('asset_id');
        $this->post(route('assets.breakdowns.store'), ['asset_id' => $theirAsset->id, 'reported_at' => '2026-11-01 08:00', 'description' => 'x'])->assertSessionHasErrors('asset_id');
        $this->post(route('asset-maintenance.store'), ['asset_id' => $theirAsset->id, 'title' => 'x', 'interval_days' => 1])->assertSessionHasErrors('asset_id');
        $this->post(route('assets.transfers.store'), ['asset_id' => $theirAsset->id, 'to_location' => 'x', 'moved_on' => '2026-11-01'])->assertSessionHasErrors('asset_id');
        $this->post(route('hire-bills.store'), ['asset_id' => $theirAsset->id, 'period_from' => '2026-10-01', 'period_to' => '2026-10-31'])->assertSessionHasErrors('asset_id');
        $this->post(route('hire-bills.preview'), ['asset_id' => $theirAsset->id, 'period_from' => '2026-10-01', 'period_to' => '2026-10-31'])->assertSessionHasErrors('asset_id');

        // Lists never include them.
        $this->get(route('machine-logs.index'))->assertOk()->assertDontSee($theirAsset->name);
        $this->get(route('hire-bills.index'))->assertOk()->assertDontSee($theirBill->number);
        $this->get(route('asset-maintenance.index'))->assertOk()->assertDontSee('Oil');

        $this->assertSame(1, MachineLog::withoutGlobalScopes()->where('asset_id', $theirAsset->id)->count());
        $this->assertSame(0, MachineLog::where('asset_id', $myAsset->id)->count());
        $this->assertSame('open', AssetBreakdown::withoutGlobalScopes()->find($theirBreakdown->id)->status);
        $this->assertTrue(AssetMaintenance::withoutGlobalScopes()->find($theirItem->id)->is_active);
        $this->assertSame('DRAFT', AssetHireBill::withoutGlobalScopes()->find($theirBill->id)->status);
        $this->assertSame(1, Asset::count());
    }

    public function test_an_asset_tag_cannot_be_reused_across_companies(): void
    {
        $a = $this->world();
        $b = $this->world('owner', 'business', 'Other Co');
        $this->actingAs($a->owner);
        $one = $this->ownedAsset($a->owner);
        Auth::forgetUser();
        $this->actingAs($b->owner);
        $two = $this->ownedAsset($b->owner, ['client_uuid' => (string) Str::uuid()]);

        $this->assertNotSame($one->tag, $two->tag);
        $this->assertSame($one->code, $two->code, 'Codes are per company.');
    }
}
