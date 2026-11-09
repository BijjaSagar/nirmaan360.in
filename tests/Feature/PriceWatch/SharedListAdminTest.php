<?php

namespace Tests\Feature\PriceWatch;

use App\Models\AuditLog;
use App\Models\PriceWatchSharedPrice;
use App\Services\PriceWatch\PriceWatchSettings;
use Illuminate\Support\Carbon;

class SharedListAdminTest extends PriceWatchTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-10 10:00:00');
    }

    public function test_only_platform_staff_reach_the_shared_list(): void
    {
        $row = PriceWatchSharedPrice::create(['key' => 'cement', 'name' => 'Cement', 'unit' => 'bag', 'rate' => '395.00', 'priced_on' => '2026-11-05']);

        foreach ([$this->owner, $this->pm, $this->engineer] as $user) {
            $this->actingAs($user);
            $this->get(route('price-watch.admin.edit'))->assertNotFound();
            $this->post(route('price-watch.admin.prices.save'), ['name' => 'Sand', 'unit' => 'brass', 'rate' => '1', 'priced_on' => '2026-11-05'])->assertNotFound();
            $this->patch(route('price-watch.admin.settings'), ['stale_days' => 5, 'rise_pct' => '1', 'impact_pct' => '1'])->assertNotFound();
            $this->delete(route('price-watch.admin.prices.destroy', $row))->assertNotFound();
        }

        $this->assertSame(1, PriceWatchSharedPrice::count());
    }

    public function test_the_admin_keeps_the_list_and_every_change_is_audited(): void
    {
        $admin = $this->platformAdmin();
        $this->actingAs($admin);

        $this->get(route('price-watch.admin.edit'))->assertOk()->assertSee('The shared list is empty')->assertSee('Save to the shared list');
        $this->get(route('admin.plans'))->assertOk()->assertSee(route('price-watch.admin.edit'), false);

        $this->post(route('price-watch.admin.prices.save'), ['name' => 'Cement', 'unit' => 'bag', 'rate' => '395', 'priced_on' => '2026-11-05', 'source' => 'Pune market'])
            ->assertRedirect()->assertSessionHas('success', 'Shared price saved for Cement.');
        $this->post(route('price-watch.admin.prices.save'), ['key' => 'tiles', 'name' => 'Vitrified tiles', 'unit' => 'sqm', 'rate' => '55', 'priced_on' => '2026-11-05'])->assertSessionHasNoErrors();

        $cement = PriceWatchSharedPrice::where('key', 'cement')->sole();
        $this->assertSame(['395.00', 'bag', '2026-11-05', 'Pune market', $admin->id], [$cement->rate, $cement->unit, $cement->priced_on->toDateString(), $cement->source, $cement->updated_by]);
        $this->assertTrue(AuditLog::where('action', 'platform.shared_price_added')->where('auditable_id', $cement->id)->exists());

        // The same key moves the price on rather than adding a second row.
        $this->post(route('price-watch.admin.prices.save'), ['key' => 'cement', 'name' => 'Cement', 'unit' => 'bag', 'rate' => '405', 'priced_on' => '2026-11-09'])->assertSessionHasNoErrors();
        $this->assertSame(2, PriceWatchSharedPrice::count());
        $this->assertSame('405.00', $cement->fresh()->rate);
        $this->assertTrue(AuditLog::where('action', 'platform.shared_price_changed')->where('auditable_id', $cement->id)->exists());

        $this->get(route('price-watch.admin.edit'))->assertOk()->assertSee('Vitrified tiles')->assertSee('₹405.00', false);

        $this->post(route('price-watch.admin.prices.save'), ['key' => 'Bad Key', 'name' => 'X', 'unit' => 'kg', 'rate' => '1', 'priced_on' => '2026-11-05'])->assertSessionHasErrors('key');
        $this->post(route('price-watch.admin.prices.save'), ['name' => 'X', 'unit' => 'kg', 'rate' => '-1', 'priced_on' => '2026-11-05'])->assertSessionHasErrors('rate');
        $this->post(route('price-watch.admin.prices.save'), ['name' => 'X', 'unit' => 'kg', 'rate' => '1', 'priced_on' => '2999-01-01'])->assertSessionHasErrors('priced_on');

        $this->delete(route('price-watch.admin.prices.destroy', $cement))->assertSessionHas('success', 'Removed from the shared list.');
        $this->assertSame(1, PriceWatchSharedPrice::count());
        $this->assertTrue(AuditLog::where('action', 'platform.shared_price_removed')->exists());
    }

    public function test_the_limits_are_saved_and_read_back(): void
    {
        $settings = app(PriceWatchSettings::class);
        $this->assertSame([30, '5.00', '1.00'], [$settings->staleDays(), $settings->risePct(), $settings->impactPct()]);

        $this->actingAs($this->platformAdmin());
        $this->patch(route('price-watch.admin.settings'), ['stale_days' => 45, 'rise_pct' => '7.5', 'impact_pct' => '0.5'])->assertSessionHas('success', 'Price watch settings saved.');
        $this->assertSame([45, '7.50', '0.50'], [$settings->staleDays(), $settings->risePct(), $settings->impactPct()]);
        $this->get(route('price-watch.admin.edit'))->assertOk()->assertSee('value="45"', false)->assertSee('value="7.50"', false);

        $this->patch(route('price-watch.admin.settings'), ['stale_days' => 0, 'rise_pct' => '7.5', 'impact_pct' => '0.5'])->assertSessionHasErrors('stale_days');
        $this->patch(route('price-watch.admin.settings'), ['stale_days' => 45, 'rise_pct' => '0', 'impact_pct' => '0.5'])->assertSessionHasErrors('rise_pct');
        $this->patch(route('price-watch.admin.settings'), ['stale_days' => 45, 'rise_pct' => '5', 'impact_pct' => '101'])->assertSessionHasErrors('impact_pct');
        $this->assertSame([45, '7.50', '0.50'], [$settings->staleDays(), $settings->risePct(), $settings->impactPct()]);
    }
}
