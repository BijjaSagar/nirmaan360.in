<?php

namespace Tests\Feature\Notify;

use App\Jobs\Notify\DeliverNotification;
use App\Models\Alert;
use App\Models\DeliveryChallan;
use App\Models\Material;
use App\Models\User;
use App\Services\MaterialControl\DeliveryChallanService;
use App\Services\MaterialControl\MaterialCheckService;
use App\Services\MaterialControl\ShortDeliveryService;
use App\Services\Notify\Notifier;
use App\Services\SiteAlerts\AlertEngine;
use App\Support\PlatformSettings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\MaterialControl\BuildsMaterialControl;

/**
 * A delivery challan that came short, and a gap the material check found, both of which stay open
 * until someone writes a reason. The people who can write it on that project are pushed, with
 * quantities only, whether or not they manage alerts.
 */
class PushMaterialTest extends NotifyTestCase
{
    use BuildsMaterialControl;

    private User $pm;

    private User $qs;

    private User $engineer;

    /** The store keeper. The trait already keeps the material store in $store. */
    private User $keeper;

    private User $viewer;

    private User $accounts;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake();
        $this->setUpControl();

        foreach (['pm', 'qs', 'engineer', 'viewer', 'accounts'] as $role) {
            $this->{$role} = $this->member($this->organization, $role);
        }

        $this->keeper = $this->member($this->organization, 'store');

        Queue::fake();
        $this->connectFcm();
        $this->at('2026-11-10 11:00');

        foreach ([$this->owner, $this->pm, $this->qs, $this->engineer, $this->keeper, $this->viewer, $this->accounts] as $user) {
            $this->phone($user, 'phone-'.$user->id);
        }
    }

    /** Four brass of sand on the challan, three counted at the gate by the store keeper. */
    private function shortAtTheGate(?User $by = null): DeliveryChallan
    {
        $by ??= $this->keeper;
        $this->actingAs($by);
        $sand = $this->bulk('SAND', 'River sand', 'brass');

        return app(DeliveryChallanService::class)->create($this->project, [
            'party_id' => $this->makeVendor('Birla Traders')->id,
            'material_id' => $sand->id,
            'qty' => '3',
            'challan_qty' => '4',
            'challan_date' => '2026-11-03',
        ], $by);
    }

    /** Scanned three days after the deliveries, before any challan's bill is overdue, so the gap is the only alert. */
    private function scan(): array
    {
        return app(AlertEngine::class)->scanProject($this->project->fresh(), Carbon::parse('2026-11-06'));
    }

    /** 105 bags of cement used against 100 in the BOQ for the work done: an over-BOQ gap of 5 bags. */
    private function cementOverUsed(): Material
    {
        $this->actingAs($this->owner);
        $cement = $this->bulk('CEM', 'Cement', 'bag');
        $slab = $this->boqFor($cement, '100', '1');
        $this->workDone($slab, '100');
        $this->deliver($cement, '105');
        $this->useOn($slab, $cement, '105');

        return $cement;
    }

    public function test_a_challan_that_came_short_is_pushed_to_the_people_who_can_write_the_reason(): void
    {
        $challan = $this->shortAtTheGate();

        // Manager, estimator and engineer may write a reason, and so may the owner. The store keeper counted it and already knows.
        foreach ([$this->owner, $this->pm, $this->qs, $this->engineer] as $told) {
            $this->assertCount(1, $this->pushesFor($told), $told->role.' should be pushed once');
        }

        foreach ([$this->keeper, $this->viewer, $this->accounts] as $notTold) {
            $this->assertCount(0, $this->pushesFor($notTold), $notTold->role.' should not be pushed');
        }

        $push = $this->pushesFor($this->engineer)->sole();
        $this->assertSame('Short delivery needs a reason · Riverside Tower', $push->message->title);
        $this->assertSame('River sand: 1 brass short on challan '.$challan->number.' from Birla Traders.', $push->message->body);
        $this->assertSame([
            'material_id' => (string) $challan->material_id,
            'flag' => 'short_delivery',
            'type' => 'delivery_challan',
            'id' => (string) $challan->id,
            'category' => 'material',
            'project_id' => (string) $this->project->id,
            'event' => 'material.short_delivery',
            'critical' => '0',
            'url' => route('material_control.index', $this->project),
        ], $this->dataOf($push));

        // Push only: nothing new in the bell or the inbox of people who were never told about this before.
        $this->assertSame(0, $this->engineer->notifications()->count());
        $this->assertCount(0, Queue::pushed(DeliverNotification::class, fn (DeliverNotification $job) => $job->channel !== 'push'));
    }

    public function test_a_shortage_found_later_is_pushed_as_well_and_each_shortage_once(): void
    {
        $this->actingAs($this->owner);
        $cement = $this->bulk('CEM', 'Cement', 'bag');
        $challan = $this->deliver($cement, '50');
        $uuid = '4f3f6f0e-7a51-4a55-9d6f-2f1f6d2b8c11';

        app(ShortDeliveryService::class)->report($challan, ['short_qty' => '2', 'found_on' => '2026-11-04', 'client_uuid' => $uuid], $this->keeper);
        // The phone retries the same report after a dropped connection.
        app(ShortDeliveryService::class)->report($challan, ['short_qty' => '2', 'found_on' => '2026-11-04', 'client_uuid' => $uuid], $this->keeper);

        $this->assertSame('Cement: 2 bag short on challan '.$challan->number.' from Birla Traders.', $this->pushesFor($this->pm)->sole()->message->body);
        $this->assertCount(0, $this->pushesFor($this->keeper));
    }

    public function test_material_pushes_never_carry_money_even_when_the_challan_has_a_bill(): void
    {
        $challan = $this->shortAtTheGate();
        $challan->update(['bill_status' => 'attached', 'bill_amount' => '73105001.00', 'bill_no' => 'INV-9']);
        $this->cementOverUsed();
        $this->scan();

        foreach ([$this->engineer, $this->qs, $this->pm, $this->owner, $this->keeper] as $user) {
            foreach ($this->pushesFor($user) as $job) {
                $words = $this->wordsOf($job).json_encode($this->dataOf($job));

                $this->assertDoesNotMatchRegularExpression('/(₹|\bRs\.?|\bINR)\s*-?\d/iu', $words);
                $this->assertStringNotContainsString('73105', $words);
            }
        }

        $this->assertGreaterThanOrEqual(2, $this->pushesFor($this->engineer)->count());
    }

    public function test_someone_limited_to_another_project_or_in_another_company_is_not_pushed_about_a_shortage(): void
    {
        $elsewhere = $this->makeProject($this->organization, ['name' => 'Hilltop Villas']);
        $this->limitTo($this->engineer, $elsewhere);
        $this->limitTo($this->qs, $this->project);
        $theirEngineer = $this->member($this->tenant('Other Infra'), 'engineer');
        $this->phone($theirEngineer, 'their-phone');

        $this->shortAtTheGate();

        $this->assertCount(0, $this->pushesFor($this->engineer));
        $this->assertCount(1, $this->pushesFor($this->qs));
        $this->assertCount(0, $this->pushesFor($theirEngineer));
    }

    public function test_a_material_gap_is_pushed_to_whoever_can_explain_it_and_each_person_only_once(): void
    {
        $cement = $this->cementOverUsed();

        $this->scan();

        $alert = Alert::where('type', 'material.over_boq')->sole();

        // Whoever manages alerts hears it as the alert it is.
        foreach ([$this->owner, $this->pm] as $manager) {
            $push = $this->pushesFor($manager)->sole();
            $this->assertSame('Material used beyond the BOQ · Riverside Tower', $push->message->title);
            $this->assertSame(['alert', (string) $alert->id, 'alerts'], [$this->dataOf($push)['type'], $this->dataOf($push)['id'], $this->dataOf($push)['category']]);
        }

        // Site staff who can write the reason are asked for it, and taken to the material check.
        foreach ([$this->qs, $this->engineer, $this->keeper] as $writer) {
            $push = $this->pushesFor($writer)->sole();
            $this->assertSame('Material gap needs a reason · Riverside Tower', $push->message->title);
            $this->assertSame('Cement: 105 bag used against 100 bag in the BOQ for the work done, 5 bag over (5% wastage).', $push->message->body);
            $this->assertSame([
                'alert_id' => (string) $alert->id,
                'flag' => MaterialCheckService::OVER_USED,
                'severity' => 'warning',
                'type' => 'material_flag',
                'id' => (string) $cement->id,
                'category' => 'material',
                'project_id' => (string) $this->project->id,
                'event' => 'material.reason_needed',
                'critical' => '0',
                'url' => route('material_control.index', $this->project),
            ], $this->dataOf($push));
            $this->assertSame(0, $writer->notifications()->count());
        }

        // The rest can see the alert but cannot answer it, so they get it as an alert on the app and nothing else.
        foreach ([$this->viewer, $this->accounts] as $onlooker) {
            $push = $this->pushesFor($onlooker)->sole();
            $this->assertSame(['alert', 'alerts'], [$this->dataOf($push)['type'], $this->dataOf($push)['category']]);
            $this->assertSame(0, $onlooker->notifications()->count());
        }

        // Scanning again tells nobody again.
        $this->scan();

        foreach ([$this->owner, $this->pm, $this->qs, $this->engineer, $this->keeper, $this->viewer, $this->accounts] as $anyone) {
            $this->assertCount(1, $this->pushesFor($anyone));
        }
    }

    public function test_each_person_gets_the_gap_under_whichever_switch_they_left_on(): void
    {
        // The manager has site alerts off, the engineer has material checks off, the store keeper has both off.
        $this->prefer($this->pm, ['push_categories' => ['alerts' => false]]);
        $this->prefer($this->engineer, ['push_categories' => ['material' => false]]);
        $this->prefer($this->keeper, ['push_categories' => ['alerts' => false, 'material' => false]]);
        $this->cementOverUsed();

        $this->scan();

        $this->assertSame('material', $this->dataOf($this->pushesFor($this->pm)->sole())['category']);
        $this->assertSame('alerts', $this->dataOf($this->pushesFor($this->engineer)->sole())['category']);
        $this->assertCount(0, $this->pushesFor($this->keeper));
        $this->assertSame('alerts', $this->dataOf($this->pushesFor($this->owner)->sole())['category']);
    }

    public function test_once_the_reason_is_written_a_held_push_about_the_gap_is_not_sent(): void
    {
        $cement = $this->cementOverUsed();
        $this->at('2026-11-10 23:15');
        $this->scan();
        $held = $this->pushesFor($this->engineer)->sole();
        $this->assertNotNull($held->delay);

        // The manager writes the reason that night, and the morning scan closes the alert.
        app(MaterialCheckService::class)->addReason($this->project, ['material_id' => $cement->id, 'flag_type' => MaterialCheckService::OVER_USED, 'reason' => 'Patching after the slab'], $this->pm);
        $this->assertSame(1, $this->scan()['resolved']);

        $this->fakeFcm();
        $this->at('2026-11-11 07:00');
        $held->handle(app(Notifier::class), app(PlatformSettings::class));

        Http::assertNothingSent();
    }
}
