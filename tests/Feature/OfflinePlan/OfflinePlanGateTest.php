<?php

namespace Tests\Feature\OfflinePlan;

use App\Models\AuditLog;
use App\Models\BillingPayment;
use App\Models\DeviceLicence;
use App\Models\MeasurementEntry;
use App\Models\Organization;
use App\Models\Party;
use App\Models\Project;
use App\Models\User;
use App\Services\BillingService;
use Illuminate\Support\Str;
use Tests\Feature\Api\ApiTestCase;

/** A company on the offline plan never syncs, on the API or the web, until it moves to an online plan. */
class OfflinePlanGateTest extends ApiTestCase
{
    use BuildsOfflineCompany;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    /** The purchase flow's last step: the order is paid and the plan changes. */
    private function pay(Organization $organization, User $by, string $plan): void
    {
        $payment = BillingPayment::create([
            'organization_id' => $organization->id, 'user_id' => $by->id, 'plan' => $plan, 'cycle' => 'monthly',
            'base_amount' => '100.00', 'gst_amount' => '18.00', 'total_amount' => '118.00', 'gateway_order_id' => 'order_'.Str::random(8),
        ]);

        app(BillingService::class)->markPaid($payment, 'pay_'.Str::random(8));
        $by->refresh();
    }

    public function test_sync_and_every_write_are_refused_with_a_clear_code(): void
    {
        $organization = $this->offlineTenant();
        $owner = $this->signIn($this->member($organization, 'owner'));
        $project = $this->makeProject($organization);
        $item = $this->makeBoqItem($project);
        $refused = fn ($response) => $response->assertForbidden()->assertJsonPath('code', 'offline_plan')->assertJsonPath('plan', 'offline')->assertJsonPath('upgrade_url', route('billing.index'));

        $refused($this->postJson(self::API.'/sync/push', ['items' => [$this->item('measurement', $project, $this->measurementData($item))]]));
        $refused($this->getJson(self::API.'/sync/pull'));
        $refused($this->postJson(self::API.'/sync/upload', ['type' => 'dpr_photo']));
        $refused($this->postJson(self::API.'/projects', ['code' => 'N1', 'name' => 'New site']));
        $refused($this->postJson(self::API."/projects/{$project->id}/measurements", $this->measurementData($item)));
        $refused($this->postJson(self::API."/projects/{$project->id}/dprs", $this->dprData()));
        $this->assertSame(0, MeasurementEntry::count());
        $this->assertSame(1, Project::count());

        // Signing in, the profile, the licence and the signing key still work.
        $this->getJson(self::API.'/me')->assertOk()->assertJsonPath('user.id', $owner->id);
        $this->getJson(self::OFFLINE_API.'/status')->assertOk()->assertJsonPath('syncs', false);
        $this->activatePhone()->assertCreated();
        $this->getJson(self::OFFLINE_API.'/public-key')->assertOk();
        $this->postJson(self::API.'/auth/logout')->assertOk();

        $this->app['auth']->forgetGuards();
        $this->assertNotEmpty($this->postJson(self::API.'/auth/login', ['email' => $owner->email, 'password' => 'password', 'device_name' => 'phone'])->assertOk()->json('token'));
    }

    public function test_a_real_phone_token_is_gated_the_same_way(): void
    {
        // No actingAs here: the phone sends the bearer token it got from /auth/login, as in production.
        $organization = $this->offlineTenant();
        $owner = $this->member($organization, 'owner');
        $headers = ['Authorization' => 'Bearer '.$owner->createToken('phone')->plainTextToken];

        $this->getJson(self::API.'/sync/pull', $headers)->assertForbidden()->assertJsonPath('code', 'offline_plan');
        $this->postJson(self::API.'/sync/push', ['items' => []], $headers)->assertForbidden()->assertJsonPath('code', 'offline_plan');
        $this->postJson(self::OFFLINE_API.'/devices/activate', $this->phone(), $headers)->assertCreated();
        $this->getJson(self::API.'/me', $headers)->assertOk();

        // With no token at all it is sign-in that refuses, not the plan.
        $this->app['auth']->forgetGuards();
        $this->getJson(self::API.'/sync/pull')->assertUnauthorized();
    }

    public function test_the_block_lifts_after_an_upgrade_and_the_phone_data_syncs_up_once(): void
    {
        $organization = $this->offlineTenant();
        $owner = $this->signIn($this->member($organization, 'owner'));
        $project = $this->makeProject($organization);
        $outbox = $this->item('measurement', $project, $this->measurementData($this->makeBoqItem($project)));

        $this->postJson(self::API.'/sync/push', ['items' => [$outbox]])->assertForbidden()->assertJsonPath('code', 'offline_plan');

        $this->pay($organization, $owner, 'contractor');
        $this->assertSame('contractor', $organization->fresh()->effectivePlan());

        // The existing sync takes the phone's outbox, and client_uuid keeps a retry from doubling anything.
        $this->postJson(self::API.'/sync/push', ['items' => [$outbox]])->assertOk()->assertJsonPath('results.0.status', 'created');
        $this->postJson(self::API.'/sync/push', ['items' => [$outbox]])->assertOk()->assertJsonPath('results.0.status', 'duplicate');
        $this->assertSame(1, MeasurementEntry::count());
        $this->getJson(self::API.'/sync/pull')->assertOk()->assertJsonCount(1, 'projects');
        $this->getJson(self::OFFLINE_API.'/status')->assertOk()->assertJsonPath('syncs', true);
    }

    public function test_moving_down_to_the_offline_plan_keeps_every_server_record(): void
    {
        $organization = $this->tenant('Big Co', 'business');
        $owner = $this->signIn($this->member($organization, 'owner'));
        $project = $this->makeProject($organization);
        $this->draftEntry($project, $this->makeBoqItem($project), $owner);
        $before = [Project::withoutGlobalScopes()->count(), MeasurementEntry::withoutGlobalScopes()->count(), AuditLog::count()];

        $this->pay($organization, $owner, 'offline');

        $this->assertSame('offline', $organization->fresh()->effectivePlan());
        $this->assertSame($before[0], Project::withoutGlobalScopes()->count());
        $this->assertSame($before[1], MeasurementEntry::withoutGlobalScopes()->count());
        $this->assertSame(0, AuditLog::where('action', 'deleted')->count());
        $this->getJson(self::API.'/sync/pull')->assertForbidden()->assertJsonPath('code', 'offline_plan');

        // And back up again: everything is still there to pull.
        $this->pay($organization, $owner, 'business');
        $this->getJson(self::API.'/sync/pull')->assertOk()->assertJsonCount(1, 'projects')->assertJsonCount(1, 'measurements');
    }

    public function test_the_web_app_shows_only_account_billing_and_devices(): void
    {
        $organization = $this->offlineTenant();
        $this->actingAs($this->member($organization, 'owner'));
        $this->makeProject($organization);

        foreach (['/dashboard', '/projects', '/approvals', '/team', '/workers', '/audit'] as $page) {
            $this->get($page)->assertRedirect(route('offline.devices.index'));
        }

        $this->post('/projects', ['code' => 'X1', 'name' => 'Blocked'])->assertForbidden();
        $this->assertSame(1, Project::count());

        $this->get('/offline/devices')->assertOk()->assertSee('Offline plan')->assertSee('There is no server backup')->assertSee('Upgrade in Billing')->assertSee(route('billing.index'));
        $this->get('/billing')->assertOk()->assertSee('Offline');
        $this->get('/settings')->assertOk();
        $this->get('/devices')->assertOk();
        $this->get('/pricing')->assertOk();
        $this->post('/logout')->assertRedirect('/');
    }

    public function test_someone_without_billing_rights_lands_on_the_same_page_without_the_controls(): void
    {
        $organization = $this->offlineTenant();
        $this->licenceFor($this->member($organization, 'owner'));
        $this->actingAs($this->member($organization, 'engineer'));

        $this->get('/dashboard')->assertRedirect(route('offline.devices.index'));
        $this->get('/offline/devices')->assertOk()->assertSee('Ask your company owner')->assertDontSee('Licensed phones')->assertDontSee('Free phone')->assertDontSee('Ravi Pixel');
        $this->post('/offline/devices/'.DeviceLicence::withoutGlobalScopes()->value('id').'/free')->assertForbidden();
    }

    public function test_online_companies_are_untouched_and_do_not_see_the_menu_item(): void
    {
        $this->actingAs($this->member($this->tenant('Online Co', 'business'), 'owner'));

        $this->get('/dashboard')->assertOk()->assertDontSee('Offline devices');
        $this->get('/projects')->assertOk();
        $this->get('/offline/devices')->assertOk()->assertSee('phones need no licence');
    }

    public function test_the_menu_shows_offline_devices_on_the_offline_plan(): void
    {
        $this->actingAs($this->member($this->offlineTenant(), 'owner'));

        $this->get('/billing')->assertOk()->assertSee('Offline devices')->assertSee(route('offline.devices.index'));
    }

    /** The menu offers only the pages the gate leaves open, instead of links that all land on the devices page. */
    public function test_the_menu_on_the_offline_plan_offers_only_the_pages_that_open(): void
    {
        $organization = $this->offlineTenant();
        $this->actingAs($this->member($organization, 'owner'));
        $this->makeProject($organization);

        $page = $this->get('/offline/devices')->assertOk();

        foreach (['offline.devices.index', 'devices.index', 'billing.index', 'settings.edit'] as $open) {
            $page->assertSee(route($open), false);
        }

        foreach (['dashboard', 'projects.index', 'approvals.index', 'workers.index', 'team.index', 'audit.index'] as $closed) {
            $page->assertDontSee(route($closed), false);
        }

        $page->assertDontSee('>'.__('Dashboard').'<', false)->assertDontSee(__('Approvals'))->assertDontSee(__('Audit log'));

        // Someone without billing rights is left with their own devices page and nothing that would bounce them.
        $this->actingAs($this->member($organization, 'engineer'));
        $engineer = $this->get('/offline/devices')->assertOk();
        $engineer->assertSee(route('devices.index'), false)->assertDontSee(route('dashboard'), false)->assertDontSee(route('projects.index'), false)->assertDontSee(route('workers.index'), false);
    }

    /** A contractor login has no page on the offline plan, so it is refused outright rather than bounced between the two gates for ever. */
    public function test_a_contractor_login_on_the_offline_plan_is_refused_rather_than_sent_round_in_circles(): void
    {
        $organization = $this->offlineTenant();
        $party = Party::create(['organization_id' => $organization->id, 'type' => 'subcontractor', 'name' => 'Shree Formwork']);
        $contractor = User::factory()->create(['organization_id' => $organization->id, 'role' => User::CONTRACTOR_ROLE, 'contractor_party_id' => $party->id, 'is_active' => true]);

        $this->actingAs($contractor);
        $this->get('/contractor-portal')->assertForbidden();
        $this->get('/contractor-portal/payments')->assertForbidden();
        $this->get('/offline/devices')->assertRedirect(route('contractor-portal.home'));
        $this->get('/dashboard')->assertRedirect(route('contractor-portal.home'));
        $this->post('/logout')->assertRedirect('/');

        $this->signIn($contractor);
        $this->getJson(self::API.'/contractor-portal')->assertForbidden()->assertJsonPath('code', 'offline_plan');
        $this->getJson(self::API.'/me')->assertOk()->assertJsonPath('user.kind', 'contractor');
    }

    public function test_the_owner_frees_a_phone_from_the_web_and_never_another_companys(): void
    {
        $organization = $this->offlineTenant();
        $owner = $this->member($organization, 'owner');
        $licence = $this->licenceFor($owner);

        $this->actingAs($owner);
        $this->get('/offline/devices')->assertOk()->assertSee('Ravi Pixel')->assertSee('1 of 1 phone slots in use')->assertSee('Free phone');

        $this->post("/offline/devices/{$licence->id}/free")->assertRedirect()->assertSessionHas('success');
        $licence->refresh();
        $this->assertSame('revoked', $licence->status);
        $this->assertNull($licence->slot);
        $this->assertSame($owner->id, $licence->revoked_by);
        $this->assertSame(1, AuditLog::where('auditable_type', $licence->getMorphClass())->where('action', 'updated')->count());
        $this->get('/offline/devices')->assertOk()->assertSee('Freed')->assertSee('0 of 1 phone slots in use')->assertDontSee('Free phone');

        // Freeing twice changes nothing more.
        $this->post("/offline/devices/{$licence->id}/free")->assertRedirect();
        $this->assertSame(1, AuditLog::where('auditable_type', $licence->getMorphClass())->where('action', 'updated')->count());

        // Another company's owner cannot even see it.
        $other = $this->licenceFor($this->member($this->offlineTenant('Other Co'), 'owner'), 'phone-0002');
        $this->actingAs($this->member($this->offlineTenant('Third Co'), 'owner'));
        $this->post("/offline/devices/{$other->id}/free")->assertNotFound();
        $this->assertSame('active', $other->fresh()->status);
    }
}
