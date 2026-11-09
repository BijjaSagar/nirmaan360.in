<?php

namespace Tests\Feature\SiteAlerts;

use App\Models\Alert;
use App\Models\User;
use App\Notifications\ApprovalUpdate;
use App\Services\MaterialControl\DeliveryChallanService;
use App\Services\MaterialControl\MaterialCheckService;
use App\Services\SiteAlerts\AlertEngine;
use App\Services\SiteAlerts\SiteDashboard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\MaterialControl\BuildsMaterialControl;
use Tests\TestCase;

/** Section 8: cement over-use, cement missing, the bill that never came, and the supplier who is short again. */
class MaterialAlertsTest extends TestCase
{
    use BuildsMaterialControl, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Queue::fake();
        Storage::fake();
        $this->setUpControl();
    }

    private function scan(string $today = '2026-11-20'): array
    {
        return app(AlertEngine::class)->scanProject($this->project->fresh(), Carbon::parse($today));
    }

    private function noticesAbout(User $user, string $title): int
    {
        return $user->notifications()->where('type', ApprovalUpdate::class)->get()
            ->filter(fn ($notice) => str_starts_with((string) ($notice->data['title'] ?? ''), $title))->count();
    }

    public function test_105_bags_against_100_is_an_over_boq_alert_everyone_may_see(): void
    {
        $cement = $this->bulk('CEM', 'Cement', 'bag');
        $slab = $this->boqFor($cement, '100', '1');
        $this->workDone($slab, '100');
        $this->deliver($cement, '105');
        $this->useOn($slab, $cement, '105');

        $this->scan();

        $alert = Alert::where('type', 'material.over_boq')->sole();
        $this->assertSame('Material used beyond the BOQ', $alert->titleIn('en'));
        $this->assertSame('Cement: 105 bag used against 100 bag in the BOQ for the work done, 5 bag over (5% wastage).', $alert->messageIn('en'));
        $this->assertSame('warning', $alert->severity);
        $this->assertSame('material:'.$cement->id, $alert->reference);
        $this->assertNull($alert->needs_permission);
        $this->assertSame(['estimate' => '100.000', 'used' => '105.000', 'gap' => '5.000', 'wastage_pct' => '5.00'], ['estimate' => $alert->data['estimate'], 'used' => $alert->data['used'], 'gap' => $alert->data['gap'], 'wastage_pct' => $alert->data['wastage_pct']]);
        $this->assertTrue(Alert::visibleTo($this->member($this->organization, 'engineer'))->whereKey($alert->id)->exists());

        // A written reason answers the gap, so the alert closes.
        app(MaterialCheckService::class)->addReason($this->project, ['material_id' => $cement->id, 'flag_type' => MaterialCheckService::OVER_USED, 'reason' => 'Patching after the slab'], $this->owner);

        $this->assertSame(1, $this->scan()['resolved']);
        $this->assertSame(0, Alert::where('type', 'material.over_boq')->open()->count());
    }

    public function test_50_received_45_used_and_none_in_stock_is_5_bags_unaccounted(): void
    {
        $cement = $this->bulk('CEM', 'Cement', 'bag');
        $slab = $this->boqFor($cement, '100', '1');
        $this->workDone($slab, '45');
        $this->deliver($cement, '50');
        $this->useOn($slab, $cement, '45');
        $this->countStock($cement, '0');

        $this->scan();

        $alert = Alert::where('type', 'material.unaccounted')->sole();
        $this->assertSame('Material unaccounted', $alert->titleIn('en'));
        $this->assertSame('Cement: 5 bag received but neither used nor in stock (received 50, used 45, in stock 0).', $alert->messageIn('en'));
        $this->assertSame('5.000', $alert->data['gap']);
        $this->assertSame(0, Alert::where('type', 'material.over_boq')->count());
    }

    public function test_a_challan_with_no_bill_for_a_week_is_raised_for_whoever_handles_bills(): void
    {
        $cement = $this->bulk('CEM', 'Cement', 'bag');
        $challan = $this->deliver($cement, '50');
        $accounts = $this->member($this->organization, 'accounts');
        $store = $this->member($this->organization, 'store');

        $this->assertSame(0, Alert::where('type', 'material.bills_pending')->count());
        $this->scan('2026-11-05');
        $this->assertSame(0, Alert::where('type', 'material.bills_pending')->count());

        $this->scan('2026-11-20');

        $alert = Alert::where('type', 'material.bills_pending')->sole();
        $this->assertSame('Challans without bills', $alert->titleIn('en'));
        $this->assertSame('1 delivery challans have had no bill for more than 7 days; the oldest is from 03 Nov 2026.', $alert->messageIn('en'));
        $this->assertSame('info', $alert->severity);
        $this->assertSame('material_control.bills', $alert->notify_permission);
        $this->assertSame(1, $this->noticesAbout($accounts, 'Challans without bills'));
        $this->assertSame(0, $this->noticesAbout($store, 'Challans without bills'));

        app(DeliveryChallanService::class)->attachBill($challan, ['bill_no' => 'INV-77'], $this->owner);

        $this->assertSame(1, $this->scan('2026-11-21')['resolved']);
    }

    public function test_a_supplier_short_twice_is_shown_but_not_sent_again(): void
    {
        $supplier = $this->makeVendor('Patil Sand Supply');
        $sand = $this->bulk('SAND', 'Sand', 'brass');
        $this->deliver($sand, '3', $supplier, ['challan_qty' => '4']);
        $this->deliver($sand, '3', $supplier, ['challan_qty' => '4']);

        $this->scan('2026-11-05');

        $alert = Alert::where('type', 'supplier.repeat_short')->sole();
        $this->assertSame('Repeat short delivery', $alert->titleIn('en'));
        $this->assertSame('Patil Sand Supply has delivered short 2 times.', $alert->messageIn('en'));
        $this->assertSame('party:'.$supplier->id, $alert->reference);
        $this->assertNull($alert->notify_permission);
        $this->assertSame(2, $alert->data['short_deliveries']);
        $this->assertSame(0, $this->noticesAbout($this->owner, 'Repeat short delivery'));
    }

    public function test_the_material_card_shows_wastage_and_the_open_flags(): void
    {
        $cement = $this->bulk('CEM', 'Cement', 'bag');
        $slab = $this->boqFor($cement, '100', '1');
        $this->workDone($slab, '100');
        $this->deliver($cement, '105');
        $this->useOn($slab, $cement, '105');

        $card = collect(app(SiteDashboard::class)->cards($this->project->fresh(), $this->owner))->firstWhere('key', 'material');

        $this->assertSame('5%', $card->value);
        $this->assertSame('Wastage so far, 1 open material flags to explain', $card->note);
        $this->assertSame(['Cement', '105 / 100 bag'], [$card->rows[0]['label'], $card->rows[0]['value']]);
        $this->assertStringContainsString('5 bag more than the BOQ', $card->rows[0]['sub']);
        $this->assertSame(['Challans without bills', '1'], [$card->rows[1]['label'], $card->rows[1]['value']]);
        $this->assertStringNotContainsString('₹', json_encode($card));
    }
}
