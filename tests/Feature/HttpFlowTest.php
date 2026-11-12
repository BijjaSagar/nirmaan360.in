<?php

namespace Tests\Feature;

use App\Models\ApprovalRule;
use App\Models\BoqItem;
use App\Models\MeasurementEntry;
use App\Models\Party;
use App\Models\Project;
use App\Models\RaBill;
use App\Models\User;
use App\Services\ApprovalService;
use App\Services\MeasurementService;
use App\Services\RaBillService;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Tests\BuildsTenant;
use Tests\TestCase;

class HttpFlowTest extends TestCase
{
    use BuildsTenant, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_public_pages_load(): void
    {
        $this->get('/')->assertOk()->assertSee('Material, contractor payment and time.');
        $this->get('/calculators')->assertOk()->assertSee('Site calculators');
        $this->get('/login')->assertOk();
        $this->get('/register')->assertOk();
        $this->get('/up')->assertOk();
    }

    public function test_home_page_can_switch_language(): void
    {
        $this->get('/locale/hi')->assertRedirect();
        $this->get('/')->assertOk()->assertSee('मटेरियल, ठेकेदार का पेमेंट और समय।');
    }

    public function test_registration_creates_a_company_on_trial_with_approval_rules(): void
    {
        $this->post('/register', [
            'company' => 'Deccan Builders',
            'name' => 'Priya Rane',
            'email' => 'priya@example.com',
            'password' => 'a-long-password',
            'password_confirmation' => 'a-long-password',
        ])->assertRedirect('/dashboard');

        $user = User::where('email', 'priya@example.com')->first();
        $this->assertSame('owner', $user->role);
        $this->assertSame('trial', $user->organization->plan_status);
        $this->assertTrue($user->organization->allowsFeature('billing'));
        $this->assertSame(3, ApprovalRule::withoutGlobalScopes()->where('organization_id', $user->organization_id)->count());
    }

    public function test_web_login_finds_the_email_in_any_letter_case(): void
    {
        $user = $this->member($this->tenant());
        $user->forceFill(['email' => 'Priya.Rane@Example.com'])->save();

        $this->post('/login', ['email' => 'priya.rane@EXAMPLE.com', 'password' => 'password'])->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($user);
        $this->assertNotNull($user->fresh()->last_login_at);
        $this->post('/logout');

        $this->post('/login', ['email' => 'PRIYA.RANE@example.com', 'password' => 'wrong-password'])
            ->assertSessionHasErrors(['email' => 'These details do not match our records.']);
        $this->assertGuest();

        $user->update(['is_active' => false]);
        $this->post('/login', ['email' => 'priya.rane@example.com', 'password' => 'password'])->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_web_login_with_two_old_accounts_that_differ_only_by_case_takes_the_exact_spelling_only(): void
    {
        $upper = $this->member($this->tenant('First Co'));
        $upper->forceFill(['email' => 'Twin@example.com'])->save();
        $lower = $this->member($this->tenant('Second Co'));
        $lower->forceFill(['email' => 'twin@example.com'])->save();

        $this->post('/login', ['email' => 'Twin@example.com', 'password' => 'password'])->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($upper);
        $this->post('/logout');

        $this->post('/login', ['email' => 'twin@example.com', 'password' => 'password'])->assertRedirect('/dashboard');
        $this->assertAuthenticatedAs($lower);
        $this->post('/logout');

        $this->post('/login', ['email' => 'TWIN@example.com', 'password' => 'password'])->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_every_app_page_renders_with_demo_data(): void
    {
        $this->seed(DemoSeeder::class);
        Auth::forgetUser();

        $owner = User::where('email', 'owner@demo.test')->first();
        $this->actingAs($owner);

        $project = Project::first();
        $bill = RaBill::first();
        $entry = MeasurementEntry::where('status', 'APPROVED')->first();
        $dpr = $project->dprs()->first();
        $workOrder = $project->workOrders()->first();

        foreach ([
            '/dashboard', '/projects', '/projects/create', '/approvals', '/workers', '/parties', '/team', '/settings', '/audit',
            "/projects/{$project->id}", "/projects/{$project->id}/edit", "/projects/{$project->id}/boq", "/projects/{$project->id}/boq/export",
            "/projects/{$project->id}/dprs", "/projects/{$project->id}/dprs/create", "/dprs/{$dpr->id}",
            "/projects/{$project->id}/issues", "/projects/{$project->id}/attendance", "/projects/{$project->id}/petty-cash",
            "/projects/{$project->id}/measurements", "/projects/{$project->id}/measurements/create", "/measurements/{$entry->id}",
            "/projects/{$project->id}/work-orders", "/projects/{$project->id}/work-orders/create", "/work-orders/{$workOrder->id}",
            "/projects/{$project->id}/ra-bills", "/projects/{$project->id}/ra-bills/create", "/ra-bills/{$bill->id}", "/ra-bills/{$bill->id}/pdf",
        ] as $url) {
            $this->get($url)->assertOk();
        }
    }

    public function test_patching_an_approved_measurement_returns_409(): void
    {
        $organization = $this->tenant();
        $engineer = $this->member($organization);
        $owner = $this->member($organization, 'owner');
        $this->actingAs($engineer);
        $project = $this->makeProject($organization);
        $item = $this->makeBoqItem($project);
        $entry = $this->approveEntry($this->draftEntry($project, $item, $engineer), $engineer, $owner);

        $this->patch("/measurements/{$entry->id}", [
            'boq_item_id' => $item->id, 'measured_on' => '2026-11-01', 'nos' => 3,
        ])->assertStatus(409);

        $this->assertSame('2.000', $entry->fresh()->nos);
    }

    public function test_one_company_cannot_reach_another_companys_records(): void
    {
        $first = $this->tenant('First Co');
        $firstUser = $this->member($first, 'owner');
        $this->actingAs($firstUser);
        $project = $this->makeProject($first);
        $item = $this->makeBoqItem($project);
        $entry = $this->draftEntry($project, $item, $firstUser);

        $second = $this->tenant('Second Co');
        $this->actingAs($this->member($second, 'owner'));

        $this->get("/projects/{$project->id}")->assertNotFound();
        $this->get("/projects/{$project->id}/boq")->assertNotFound();
        $this->get("/measurements/{$entry->id}")->assertNotFound();
        $this->post("/measurements/{$entry->id}/submit")->assertNotFound();
        $this->assertSame(0, Project::count());
    }

    public function test_cross_company_ids_are_rejected_in_forms(): void
    {
        $first = $this->tenant('First Co');
        $this->actingAs($this->member($first, 'owner'));
        $foreignClient = Party::create(['organization_id' => $first->id, 'type' => 'client', 'name' => 'Theirs']);

        $second = $this->tenant('Second Co');
        $this->actingAs($this->member($second, 'owner'));

        $this->post('/projects', [
            'code' => 'X1', 'name' => 'Mine', 'client_id' => $foreignClient->id, 'contract_value' => 1,
            'retention_pct' => 5, 'tds_pct' => 2, 'gst_pct' => 18, 'advance_amount' => 0, 'advance_recovery_pct' => 0,
            'measurement_tolerance_pct' => 5, 'deduction_basis' => 'gross', 'deduction_order' => 'retention,advance,material,tds',
        ])->assertSessionHasErrors('client_id');
    }

    public function test_project_form_saves_the_chosen_deduction_order(): void
    {
        $organization = $this->tenant();
        $this->actingAs($this->member($organization, 'owner'));

        $this->post('/projects', [
            'code' => 'X2', 'name' => 'Mine', 'contract_value' => 1,
            'retention_pct' => 5, 'tds_pct' => 2, 'gst_pct' => 18, 'advance_amount' => 0, 'advance_recovery_pct' => 0,
            'measurement_tolerance_pct' => 5, 'deduction_basis' => 'running', 'deduction_order' => 'tds,retention,advance,material',
        ])->assertRedirect();

        $this->assertSame(['tds', 'retention', 'advance', 'material'], Project::first()->billingTerms()['order']);
    }

    public function test_permissions_are_enforced_on_routes(): void
    {
        $organization = $this->tenant();
        $engineer = $this->member($organization);
        $this->actingAs($engineer);
        $project = $this->makeProject($organization);

        $this->get("/projects/{$project->id}/ra-bills")->assertForbidden();
        $this->get('/team')->assertForbidden();
        $this->get('/settings')->assertForbidden();
        $this->get("/projects/{$project->id}/measurements")->assertOk();
    }

    public function test_guests_are_sent_to_login(): void
    {
        $this->get('/dashboard')->assertRedirect('/login');
    }

    public function test_free_plan_cannot_open_billing_and_has_project_limit(): void
    {
        $organization = $this->tenant('Small Co', 'free');
        $owner = $this->member($organization, 'owner');
        $this->actingAs($owner);
        $project = $this->makeProject($organization);

        $this->get("/projects/{$project->id}/ra-bills")->assertForbidden();
        $this->post('/projects', [])->assertForbidden();
    }

    public function test_expired_trial_falls_back_to_free(): void
    {
        $organization = $this->tenant('Trial Co', 'contractor');
        $organization->update(['plan_status' => 'trial', 'trial_ends_at' => now()->subDay()]);

        $this->assertSame('free', $organization->fresh()->effectivePlan());
    }

    public function test_csv_import_is_all_or_nothing(): void
    {
        $organization = $this->tenant();
        $this->actingAs($this->member($organization, 'owner'));
        $project = $this->makeProject($organization);

        $bad = "section,code,description,unit,qty,rate\nA,1,Good row,cum,10,100\nA,2,Bad row,cum,abc,100\n";
        $this->post("/projects/{$project->id}/boq/import", ['file' => UploadedFile::fake()->createWithContent('boq.csv', $bad)])
            ->assertSessionHasErrors('file');
        $this->assertSame(0, BoqItem::count());

        $good = "section,code,description,unit,qty,rate\nA,1,First,cum,10.5,100\nA,2,Second,sqm,20,55.25\n";
        $this->post("/projects/{$project->id}/boq/import", ['file' => UploadedFile::fake()->createWithContent('boq.csv', $good)])
            ->assertSessionHasNoErrors();
        $this->assertSame(2, BoqItem::count());
        $this->assertSame('10.500', BoqItem::where('code', '1')->first()->qty);

        // Importing the same code again updates the row.
        $update = "section,code,description,unit,qty,rate\nA,1,First revised,cum,12,100\n";
        $this->post("/projects/{$project->id}/boq/import", ['file' => UploadedFile::fake()->createWithContent('boq.csv', $update)]);
        $this->assertSame(2, BoqItem::count());
        $this->assertSame('First revised', BoqItem::where('code', '1')->first()->description);
    }

    public function test_csv_export_neutralises_spreadsheet_formulas(): void
    {
        $organization = $this->tenant();
        $this->actingAs($this->member($organization, 'owner'));
        $project = $this->makeProject($organization);
        $item = $this->makeBoqItem($project);
        $item->update(['description' => '=HYPERLINK("http://evil.test")']);

        $this->assertStringContainsString("'=HYPERLINK", $this->get("/projects/{$project->id}/boq/export")->streamedContent());
    }

    public function test_client_can_sign_off_only_with_a_valid_signed_link(): void
    {
        $organization = $this->tenant();
        ApprovalRule::withoutGlobalScopes()->where('organization_id', $organization->id)->where('doc_type', 'ra_bill')->update(['levels' => json_encode(['client'])]);
        $engineer = $this->member($organization);
        $owner = $this->member($organization, 'owner');
        $this->actingAs($engineer);
        $project = $this->makeProject($organization);
        $this->approveEntry($this->draftEntry($project, $this->makeBoqItem($project), $engineer), $engineer, $owner);
        $bill = app(RaBillService::class)->generate($project, 'client', '2026-11-01', '2026-11-30', $owner);
        $approval = app(ApprovalService::class)->submit($bill, $owner);

        $this->assertFalse(app(ApprovalService::class)->canAct($approval, $owner), 'Staff must not sign for the client');

        Auth::forgetUser();
        $this->app['auth']->forgetGuards();

        $this->get("/client/bills/{$bill->id}")->assertForbidden();

        $url = URL::temporarySignedRoute('client.bill', now()->addDay(), ['bill' => $bill->id]);
        $this->get($url)->assertOk()->assertSee($bill->number);
        $this->post($url, ['name' => 'V. Shinde', 'action' => 'approve'])->assertRedirect();

        $this->assertSame('APPROVED', RaBill::withoutGlobalScopes()->find($bill->id)->status);
        $this->post($url, ['name' => 'V. Shinde', 'action' => 'approve'])->assertStatus(409);
    }

    public function test_client_link_works_when_signed_in_to_another_company(): void
    {
        $organization = $this->tenant();
        ApprovalRule::withoutGlobalScopes()->where('organization_id', $organization->id)->where('doc_type', 'ra_bill')->update(['levels' => json_encode(['client'])]);
        $engineer = $this->member($organization);
        $owner = $this->member($organization, 'owner');
        $this->actingAs($engineer);
        $project = $this->makeProject($organization);
        $this->approveEntry($this->draftEntry($project, $this->makeBoqItem($project), $engineer), $engineer, $owner);
        $bill = app(RaBillService::class)->generate($project, 'client', '2026-11-01', '2026-11-30', $owner);
        app(ApprovalService::class)->submit($bill, $owner);

        $this->actingAs($this->member($this->tenant('Consultant Co'), 'owner'));

        $url = URL::temporarySignedRoute('client.bill', now()->addDay(), ['bill' => $bill->id]);
        $this->get($url)->assertOk()->assertSee($bill->number);
        $this->post($url, ['name' => 'V. Shinde', 'action' => 'approve'])->assertRedirect();

        $this->assertSame('APPROVED', RaBill::withoutGlobalScopes()->find($bill->id)->status);
    }

    public function test_user_from_another_company_cannot_be_edited(): void
    {
        $first = $this->tenant('First Co');
        $target = $this->member($first, 'engineer');

        $second = $this->tenant('Second Co');
        $this->actingAs($this->member($second, 'owner'));

        $this->patch("/team/{$target->id}", ['role' => 'owner', 'is_active' => 1])->assertNotFound();
        $this->assertSame('engineer', $target->fresh()->role);
    }

    public function test_api_login_measurement_and_idempotent_sync(): void
    {
        $organization = $this->tenant();
        $engineer = $this->member($organization);
        $owner = $this->member($organization, 'owner');
        $this->actingAs($owner);
        $project = $this->makeProject($organization);
        $item = $this->makeBoqItem($project);
        Auth::forgetUser();
        $this->app['auth']->forgetGuards();

        $token = $this->postJson('/api/v1/auth/login', ['email' => $engineer->email, 'password' => 'password', 'device_name' => 'test'])
            ->assertOk()->json('token');
        $auth = ['Authorization' => 'Bearer '.$token];

        $this->getJson('/api/v1/me', $auth)->assertOk()->assertJsonPath('user.email', $engineer->email);
        $this->getJson("/api/v1/projects/{$project->id}/boq", $auth)->assertOk()->assertJsonPath('data.0.balance_qty', '100.000');

        $uuid = (string) Str::uuid();
        $payload = ['boq_item_id' => $item->id, 'measured_on' => '2026-11-01', 'nos' => 2, 'length' => 10, 'breadth' => 5, 'depth' => 0.5, 'client_uuid' => $uuid];

        $first = $this->postJson("/api/v1/projects/{$project->id}/measurements", $payload, $auth)->assertCreated();
        $first->assertJsonPath('data.qty', '50.000');
        $this->postJson("/api/v1/projects/{$project->id}/measurements", $payload, $auth)->assertOk();
        $this->assertSame(1, MeasurementEntry::withoutGlobalScopes()->count());

        $batchUuid = (string) Str::uuid();
        $batch = ['items' => [
            ['type' => 'measurement', 'client_uuid' => $batchUuid, 'project_id' => $project->id, 'data' => ['boq_item_id' => $item->id, 'measured_on' => '2026-11-02', 'nos' => 1, 'length' => 4]],
            ['type' => 'measurement', 'client_uuid' => $batchUuid, 'project_id' => $project->id, 'data' => ['boq_item_id' => $item->id, 'measured_on' => '2026-11-02', 'nos' => 1, 'length' => 4]],
            ['type' => 'measurement', 'client_uuid' => (string) Str::uuid(), 'project_id' => $project->id, 'data' => ['boq_item_id' => 999999, 'measured_on' => '2026-11-02', 'nos' => 1]],
        ]];

        $this->postJson('/api/v1/sync/push', $batch, $auth)->assertOk()
            ->assertJsonPath('results.0.status', 'created')
            ->assertJsonPath('results.1.status', 'duplicate')
            ->assertJsonPath('results.2.status', 'error');
        $this->assertSame(2, MeasurementEntry::withoutGlobalScopes()->count());

        $this->getJson('/api/v1/sync/pull', $auth)->assertOk()->assertJsonCount(2, 'measurements');
    }

    public function test_api_returns_409_for_approved_measurement(): void
    {
        $organization = $this->tenant();
        $engineer = $this->member($organization);
        $owner = $this->member($organization, 'owner');
        $this->actingAs($engineer);
        $project = $this->makeProject($organization);
        $item = $this->makeBoqItem($project);
        $entry = $this->approveEntry($this->draftEntry($project, $item, $engineer), $engineer, $owner);

        $this->patchJson("/api/v1/measurements/{$entry->id}", ['boq_item_id' => $item->id, 'measured_on' => '2026-11-01', 'nos' => 3])->assertStatus(409);
    }

    public function test_deactivated_user_is_signed_out(): void
    {
        $organization = $this->tenant();
        $user = $this->member($organization);
        $user->update(['is_active' => false]);

        $this->actingAs($user)->get('/dashboard')->assertRedirect('/login');
    }

    public function test_measurement_web_form_is_idempotent(): void
    {
        $organization = $this->tenant();
        $engineer = $this->member($organization);
        $this->actingAs($engineer);
        $project = $this->makeProject($organization);
        $item = $this->makeBoqItem($project);
        $payload = ['boq_item_id' => $item->id, 'measured_on' => '2026-11-01', 'nos' => 2, 'length' => 10, 'client_uuid' => (string) Str::uuid()];

        $this->post("/projects/{$project->id}/measurements", $payload)->assertRedirect();
        $this->post("/projects/{$project->id}/measurements", $payload)->assertRedirect();

        $this->assertSame(1, MeasurementEntry::count());
        $this->assertSame('20.000', MeasurementEntry::first()->qty);
    }

    public function test_approval_endpoint_rejects_the_wrong_role(): void
    {
        $organization = $this->tenant();
        $engineer = $this->member($organization);
        $pm = $this->member($organization, 'pm');
        $this->actingAs($engineer);
        $project = $this->makeProject($organization);
        $approval = app(MeasurementService::class)->submit($this->draftEntry($project, $this->makeBoqItem($project), $engineer), $engineer);

        $this->actingAs($pm);
        $this->post("/approvals/{$approval->id}/act", ['action' => 'approve'])->assertForbidden();
    }
}
