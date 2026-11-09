<?php

namespace Tests\Feature\Access;

use App\Models\Approval;
use App\Models\Attendance;
use App\Models\BillNote;
use App\Models\BoqItem;
use App\Models\CustomRole;
use App\Models\Dpr;
use App\Models\DprPhoto;
use App\Models\Issue;
use App\Models\IssuePhoto;
use App\Models\MeasurementAttachment;
use App\Models\MeasurementEntry;
use App\Models\Organization;
use App\Models\Party;
use App\Models\PettyCashEntry;
use App\Models\PettyCashWallet;
use App\Models\Project;
use App\Models\RaBill;
use App\Models\Site;
use App\Models\User;
use App\Models\Worker;
use App\Models\WorkOrder;
use App\Services\Access\TeamAccessService;
use App\Services\ApprovalService;
use App\Services\MeasurementService;
use App\Services\RaBillService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\BuildsTenant;
use Tests\TestCase;

/**
 * Two projects in one company and one in another. A person limited to the first
 * must not reach the second, or the other company's, by any route, list or sync.
 */
class ProjectScopingTest extends TestCase
{
    use BuildsTenant, RefreshDatabase;

    private Organization $organization;

    private User $owner;

    private Project $mine;

    private Project $hidden;

    /** @var array<string, int> Route parameter => a record inside the hidden project. */
    private array $hiddenRecords;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake();

        $this->organization = $this->tenant();
        $this->owner = $this->member($this->organization, 'owner');
        $this->mine = $this->makeProject($this->organization, ['name' => 'Visible Tower']);
        $this->hidden = $this->makeProject($this->organization, ['name' => 'Secret Plaza']);

        $this->hiddenRecords = $this->fill($this->hidden, 'Secret');
        $this->fill($this->mine, 'Visible');
    }

    /** One of everything in a project. @return array<string, int> */
    private function fill(Project $project, string $word): array
    {
        $this->actingAs($this->owner);

        $item = $this->makeBoqItem($project);
        $site = Site::create(['organization_id' => $project->organization_id, 'project_id' => $project->id, 'name' => $word.' block']);
        $party = Party::create(['organization_id' => $project->organization_id, 'type' => 'subcontractor', 'name' => $word.' contractor']);
        $workOrder = WorkOrder::create([
            'organization_id' => $project->organization_id, 'project_id' => $project->id, 'party_id' => $party->id,
            'number' => 'WO-'.Str::random(5), 'title' => $word.' order', 'rate_type' => 'item_rate', 'status' => 'ACTIVE',
        ]);
        $issue = Issue::create(['organization_id' => $project->organization_id, 'project_id' => $project->id, 'title' => $word.' crack', 'status' => 'open', 'priority' => 'high']);
        $dpr = Dpr::create(['organization_id' => $project->organization_id, 'project_id' => $project->id, 'report_date' => now()->toDateString(), 'summary' => $word.' pour', 'status' => 'DRAFT', 'created_by' => $this->owner->id]);
        Storage::put('projects/'.$word.'.jpg', 'x');
        $project->forceFill(['image_path' => 'projects/'.$word.'.jpg'])->save();
        Storage::put('dpr/'.$word.'.jpg', 'x');
        $photo = DprPhoto::create(['organization_id' => $project->organization_id, 'dpr_id' => $dpr->id, 'path' => 'dpr/'.$word.'.jpg']);
        $wallet = PettyCashWallet::create(['organization_id' => $project->organization_id, 'project_id' => $project->id, 'user_id' => $this->owner->id, 'name' => $word.' wallet']);
        Storage::put('bills/'.$word.'.jpg', 'x');
        $entry = PettyCashEntry::create(['organization_id' => $project->organization_id, 'petty_cash_wallet_id' => $wallet->id, 'type' => 'expense', 'amount' => '10.00', 'entry_date' => now()->toDateString(), 'description' => $word.' fuel', 'bill_path' => 'bills/'.$word.'.jpg', 'created_by' => $this->owner->id]);
        $worker = Worker::firstOrCreate(['organization_id' => $project->organization_id, 'name' => 'Shared Mason'], ['trade' => 'Mason']);
        Attendance::create(['organization_id' => $project->organization_id, 'project_id' => $project->id, 'worker_id' => $worker->id, 'attendance_date' => now()->toDateString(), 'status' => 'present']);

        $measurement = $this->draftEntry($project, $item, $this->owner, ['location' => $word.' slab']);
        $approved = $this->draftEntry($project, $item, $this->owner);
        $this->approveEntry($approved, $this->owner, $this->owner);

        // Left in review, so it shows in approval inboxes.
        $inReview = $this->draftEntry($project, $item, $this->owner);
        $approval = app(MeasurementService::class)->submit($inReview, $this->owner);

        $bill = app(RaBillService::class)->generate($project, 'client', '2026-01-01', '2026-12-31', $this->owner);

        Storage::put('issues/'.$word.'.jpg', 'x');
        $issuePhoto = IssuePhoto::create(['organization_id' => $project->organization_id, 'issue_id' => $issue->id, 'kind' => 'before', 'path' => 'issues/'.$word.'.jpg']);
        Storage::put('measurements/'.$word.'.jpg', 'x');
        $attachment = MeasurementAttachment::create(['organization_id' => $project->organization_id, 'measurement_entry_id' => $measurement->id, 'kind' => 'photo', 'path' => 'measurements/'.$word.'.jpg']);
        $note = BillNote::create([
            'organization_id' => $project->organization_id, 'project_id' => $project->id, 'ra_bill_id' => $bill->id,
            'kind' => 'credit', 'number' => 'CN-'.$word, 'note_date' => now()->toDateString(), 'reason' => 'Rate correction',
            'taxable_amount' => '100.00', 'total_amount' => '100.00', 'net_effect' => '-100.00',
        ]);

        Auth::forgetUser();
        $this->app['auth']->forgetGuards();

        return [
            'project' => $project->id, 'item' => $item->id, 'dpr' => $dpr->id, 'photo' => $photo->id,
            'issue' => $issue->id, 'wallet' => $wallet->id, 'entry' => $entry->id,
            'measurement' => $measurement->id, 'workOrder' => $workOrder->id, 'bill' => $bill->id,
            'approval' => $approval->id,
            'issuePhoto' => $issuePhoto->id, 'attachment' => $attachment->id, 'note' => $note->id,
        ];
    }

    /** A project manager, the role with the widest reach short of owner, limited to one project. */
    private function limited(string $role = 'pm'): User
    {
        $user = $this->member($this->organization, $role);
        app(TeamAccessService::class)->applyProjects($user, true, [$this->mine->id]);

        return $user->refresh();
    }

    private function fakeArgsFor(string $uri): string
    {
        return preg_replace_callback('/\{(\w+)\??\}/', fn ($match) => (string) ($this->hiddenRecords[$match[1]] ?? 0), $uri);
    }

    /** Every route, in every module, that takes one of these records. @return iterable<int, \Illuminate\Routing\Route> */
    private function routesReaching(): iterable
    {
        foreach (Route::getRoutes() as $route) {
            preg_match_all('/\{(\w+)\??\}/', $route->uri(), $matches);

            // Routes that also take another kind of record cannot be filled in from one fixture.
            if ($matches[1] !== [] && array_diff($matches[1], array_keys($this->hiddenRecords)) === []) {
                yield $route;
            }
        }
    }

    public function test_every_web_and_api_route_to_a_record_in_another_project_is_a_404(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        $user = $this->limited();
        $checked = 0;

        foreach ($this->routesReaching() as $route) {
            $isApi = str_starts_with($route->uri(), 'api/');

            if (str_contains(implode(',', $route->middleware()), 'signed')) {
                continue;
            }

            $isApi ? Sanctum::actingAs($user) : $this->actingAs($user);

            foreach (array_diff($route->methods(), ['HEAD']) as $method) {
                $response = $this->json($method, '/'.$this->fakeArgsFor($route->uri()));
                $this->assertSame(404, $response->getStatusCode(), $method.' '.$route->uri().' returned '.$response->getStatusCode());
                $checked++;
            }
        }

        $this->assertGreaterThan(50, $checked, 'The route walk found too few routes to prove anything.');
    }

    /** The control for the walk above: without it, a broken fixture would also give 404s. */
    public function test_an_owner_reaches_every_one_of_those_routes(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        $checked = 0;

        foreach ($this->routesReaching() as $route) {
            if (! in_array('GET', $route->methods(), true) || str_contains(implode(',', $route->middleware()), 'signed')) {
                continue;
            }

            // This form opens from a purchase order, passed in the query string, so it has no page of its own.
            if ($route->uri() === 'projects/{project}/grns/create') {
                continue;
            }

            str_starts_with($route->uri(), 'api/') ? Sanctum::actingAs($this->owner) : $this->actingAs($this->owner);

            $response = $this->json('GET', '/'.$this->fakeArgsFor($route->uri()));
            $this->assertNotSame(404, $response->getStatusCode(), 'GET '.$route->uri().' is missing even for the owner');
            $checked++;
        }

        $this->assertGreaterThan(20, $checked);
    }

    public function test_the_same_routes_still_work_inside_the_assigned_project(): void
    {
        $this->actingAs($this->limited());
        $id = $this->mine->id;

        foreach (['', '/boq', '/dprs', '/issues', '/attendance', '/petty-cash', '/measurements', '/work-orders', '/ra-bills'] as $path) {
            $this->get("/projects/{$id}{$path}")->assertOk();
        }
    }

    public function test_list_screens_show_only_assigned_projects(): void
    {
        $this->actingAs($this->limited());

        $this->get('/projects')->assertOk()->assertSee('Visible Tower')->assertDontSee('Secret Plaza');
        $this->get('/dashboard')->assertOk()->assertSee('Visible Tower')->assertDontSee('Secret Plaza')->assertDontSee('Secret pour');
        $this->get('/approvals')->assertOk()->assertDontSee('Secret Plaza');
    }

    public function test_dashboard_totals_leave_out_other_projects(): void
    {
        $this->actingAs($this->limited());

        $data = $this->get('/dashboard')->assertOk()->viewData('openIssues');
        $this->assertSame(1, $data);
        $this->assertSame(1, $this->get('/dashboard')->viewData('dprsToday'));
        $this->assertSame(1, $this->get('/dashboard')->viewData('activeProjects'));
        $this->assertSame(1, $this->get('/dashboard')->viewData('presentToday'));
    }

    public function test_approval_inbox_only_holds_assigned_projects(): void
    {
        $approver = $this->limited();
        $this->actingAs($approver);

        [$mine, $waiting] = app(ApprovalService::class)->inbox($approver);
        $projects = $mine->concat($waiting)->map(fn (Approval $approval) => $approval->approvable->project_id)->unique()->values()->all();

        $this->assertSame([$this->mine->id], $projects);
    }

    public function test_a_limited_approver_cannot_act_on_another_projects_approval(): void
    {
        $this->actingAs($this->limited());

        $this->post("/approvals/{$this->hiddenRecords['approval']}/act", ['action' => 'approve'])->assertNotFound();
        $this->assertSame('IN_REVIEW', Approval::withoutGlobalScopes()->find($this->hiddenRecords['approval'])->status);
    }

    public function test_every_project_model_is_limited(): void
    {
        $this->actingAs($this->limited());

        foreach ([Project::class, Site::class, BoqItem::class, Dpr::class, DprPhoto::class, Issue::class, Attendance::class, PettyCashWallet::class, PettyCashEntry::class, MeasurementEntry::class, WorkOrder::class, RaBill::class, Approval::class] as $model) {
            $all = $model::withoutGlobalScopes()->where('organization_id', $this->organization->id)->count();
            $visible = $model::count();

            $this->assertGreaterThan(0, $visible, $model.' shows nothing at all');
            $this->assertLessThan($all, $visible, $model.' is not limited to the assigned project');
        }
    }

    public function test_child_rows_are_limited_through_their_parent(): void
    {
        $this->actingAs($this->limited());

        $this->assertNull(DprPhoto::find($this->hiddenRecords['photo']));
        $this->assertNull(PettyCashEntry::find($this->hiddenRecords['entry']));
    }

    public function test_company_level_records_stay_visible(): void
    {
        $this->actingAs($this->limited());

        $this->get('/workers')->assertOk()->assertSee('Shared Mason');
    }

    public function test_sync_pull_holds_only_assigned_projects(): void
    {
        Sanctum::actingAs($this->limited());

        $body = $this->getJson('/api/v1/sync/pull')->assertOk()->json();

        $this->assertSame([$this->mine->id], array_column($body['projects'], 'id'));

        foreach (['sites', 'boq_items', 'wallets', 'dprs', 'issues', 'attendance', 'measurements'] as $key) {
            $this->assertNotEmpty($body[$key], $key.' should hold the assigned project');
            $this->assertSame([$this->mine->id], array_values(array_unique(array_column($body[$key], 'project_id'))), $key.' leaked another project');
        }
    }

    public function test_sync_pull_approvals_hold_only_assigned_projects(): void
    {
        // The first approval level on a measurement belongs to the QS.
        Sanctum::actingAs($this->limited('qs'));

        $approvals = $this->getJson('/api/v1/sync/pull')->assertOk()->json('approvals');

        $this->assertNotEmpty($approvals);
        $this->assertSame([$this->mine->id], array_values(array_unique(array_column(array_column($approvals, 'project'), 'id'))));
    }

    public function test_sync_pull_tells_the_device_to_drop_a_project_taken_away(): void
    {
        Sanctum::actingAs($this->limited());

        $body = $this->getJson('/api/v1/sync/pull?since='.urlencode(now()->subMinute()->toIso8601String()))->assertOk()->json();

        $this->assertContains($this->hidden->id, $body['deleted']['projects']);
        $this->assertNotContains($this->mine->id, $body['deleted']['projects']);
    }

    public function test_sync_pull_sends_a_newly_assigned_project_in_full(): void
    {
        $user = $this->limited();
        $this->actingAs($this->owner);
        $since = now()->addMinutes(5);

        $this->travelTo($since->copy()->addMinute());
        $this->patch("/team/{$user->id}", ['role' => 'pm', 'is_active' => 1, 'project_access' => 'chosen', 'project_ids' => [$this->mine->id, $this->hidden->id]])->assertRedirect();

        Auth::forgetUser();
        $this->app['auth']->forgetGuards();
        Sanctum::actingAs($user->refresh());

        $body = $this->getJson('/api/v1/sync/pull?since='.urlencode($since->toIso8601String()))->assertOk()->json();

        $this->assertContains($this->hidden->id, array_column($body['projects'], 'id'));
        $this->assertContains($this->hidden->id, array_column($body['issues'], 'project_id'));
    }

    public function test_removed_drafts_from_other_projects_are_not_reported(): void
    {
        $this->actingAs($this->owner);
        MeasurementEntry::findOrFail($this->hiddenRecords['measurement'])->delete();
        Auth::forgetUser();
        $this->app['auth']->forgetGuards();

        Sanctum::actingAs($this->limited());
        $body = $this->getJson('/api/v1/sync/pull?since='.urlencode(now()->subHour()->toIso8601String()))->assertOk()->json();

        $this->assertNotContains($this->hiddenRecords['measurement'], $body['deleted']['measurements']);
    }

    public function test_sync_push_cannot_write_to_another_project(): void
    {
        Sanctum::actingAs($this->limited());

        $results = $this->postJson('/api/v1/sync/push', ['items' => [[
            'type' => 'issue',
            'client_uuid' => (string) Str::uuid(),
            'project_id' => $this->hidden->id,
            'data' => ['title' => 'Planted issue', 'priority' => 'high'],
        ]]])->assertOk()->json('results');

        $this->assertSame('error', $results[0]['status']);
        $this->assertSame(0, Issue::withoutGlobalScopes()->where('title', 'Planted issue')->count());
    }

    public function test_sync_upload_cannot_attach_to_another_project(): void
    {
        Sanctum::actingAs($this->limited());

        $this->post('/api/v1/sync/upload', [
            'type' => 'dpr_photo',
            'client_uuid' => (string) Str::uuid(),
            'parent_id' => $this->hiddenRecords['dpr'],
            'file' => UploadedFile::fake()->image('x.jpg'),
        ], ['Accept' => 'application/json'])->assertNotFound();
    }

    public function test_mobile_project_list_is_limited(): void
    {
        Sanctum::actingAs($this->limited());

        $this->assertSame([$this->mine->id], array_column($this->getJson('/api/v1/projects')->assertOk()->json('data'), 'id'));
    }

    /** Sanctum::actingAs sets the guard differently from a real request, so this uses a real token. */
    public function test_a_real_bearer_token_is_limited_too(): void
    {
        $plain = $this->limited()->createToken('Phone')->plainTextToken;
        $headers = ['Authorization' => 'Bearer '.$plain];

        $this->assertSame([$this->mine->id], array_column($this->getJson('/api/v1/projects', $headers)->assertOk()->json('data'), 'id'));
        $this->getJson("/api/v1/projects/{$this->hidden->id}/boq", $headers)->assertNotFound();
        $this->getJson("/api/v1/projects/{$this->mine->id}/boq", $headers)->assertOk();
    }

    public function test_limited_person_who_creates_a_project_can_open_it(): void
    {
        $this->actingAs($this->limited());

        $this->post('/projects', [
            'code' => 'NEW1', 'name' => 'Fresh Site', 'contract_value' => 100, 'retention_pct' => 5, 'tds_pct' => 2, 'gst_pct' => 18,
            'advance_amount' => 0, 'advance_recovery_pct' => 0, 'measurement_tolerance_pct' => 5, 'deduction_basis' => 'gross',
            'deduction_order' => array_key_first(config('buildos.deduction_orders')),
        ])->assertRedirect();

        $created = Project::withoutGlobalScopes()->where('code', 'NEW1')->firstOrFail();
        $this->get("/projects/{$created->id}")->assertOk();
    }

    public function test_owners_and_admins_see_everything_even_if_a_limit_is_set(): void
    {
        foreach (['owner', 'admin'] as $role) {
            $user = $this->member($this->organization, $role);
            $user->forceFill(['limited_to_projects' => true])->save();
            $this->actingAs($user);

            $this->assertFalse($user->isProjectLimited());
            $this->get("/projects/{$this->hidden->id}")->assertOk();
        }
    }

    public function test_applying_a_limit_to_an_owner_is_ignored(): void
    {
        $owner = $this->member($this->organization, 'owner');

        app(TeamAccessService::class)->applyProjects($owner, true, [$this->mine->id]);

        $this->assertFalse($owner->refresh()->limited_to_projects);
        $this->assertSame(0, $owner->assignedProjectIds()->count());
    }

    public function test_existing_users_with_no_assignment_keep_their_access(): void
    {
        $this->actingAs($this->member($this->organization, 'pm'));

        $this->get('/projects')->assertSee('Secret Plaza')->assertSee('Visible Tower');
        $this->get("/projects/{$this->hidden->id}")->assertOk();
    }

    public function test_someone_limited_with_no_projects_left_sees_nothing_rather_than_everything(): void
    {
        $user = $this->member($this->organization, 'pm');
        app(TeamAccessService::class)->applyProjects($user, true, []);
        $this->actingAs($user->refresh());

        $this->assertSame(0, Project::count());
        $this->get("/projects/{$this->mine->id}")->assertNotFound();
    }

    public function test_company_wide_permissions_are_refused_while_limited(): void
    {
        $role = CustomRole::withoutGlobalScopes()->create([
            'organization_id' => $this->organization->id,
            'name' => 'Site boss',
            'permissions' => ['user.manage', 'audit.view', 'settings.manage', 'billing.manage', 'project.view'],
        ]);
        $limited = $this->member($this->organization, 'viewer');
        $limited->forceFill(['custom_role_id' => $role->id])->save();
        app(TeamAccessService::class)->applyProjects($limited, true, [$this->mine->id]);
        $this->actingAs($limited->refresh());

        foreach (['/team', '/audit', '/settings', '/billing'] as $path) {
            $this->get($path)->assertForbidden();
        }

        $this->assertTrue($limited->hasPermission('project.view'));
    }

    public function test_another_company_is_out_of_reach_for_everyone(): void
    {
        $other = $this->tenant('Other Co');
        $outsideOwner = $this->member($other, 'owner');
        $this->actingAs($outsideOwner);

        $this->get("/projects/{$this->mine->id}")->assertNotFound();
        $this->get("/projects/{$this->hidden->id}/boq")->assertNotFound();
        $this->get("/dprs/{$this->hiddenRecords['dpr']}")->assertNotFound();
        $this->get('/projects')->assertDontSee('Visible Tower')->assertDontSee('Secret Plaza');
    }

    public function test_a_limited_person_cannot_be_assigned_another_companys_project(): void
    {
        $other = $this->tenant('Other Co');
        $foreign = $this->makeProject($other, ['name' => 'Foreign Works']);
        $user = $this->member($this->organization, 'pm');

        $this->actingAs($this->owner);
        $this->from('/team')->patch("/team/{$user->id}", ['role' => 'pm', 'is_active' => 1, 'project_access' => 'chosen', 'project_ids' => [$foreign->id]])
            ->assertSessionHasErrors('project_ids.0');

        $this->assertSame(0, $user->assignedProjectIds()->count());
    }

    public function test_assignment_rows_from_another_company_grant_nothing(): void
    {
        $other = $this->tenant('Other Co');
        $foreign = $this->makeProject($other, ['name' => 'Foreign Works']);
        $user = $this->limited();

        DB::table('user_project_access')->insert([
            'organization_id' => $other->id, 'user_id' => $user->id, 'project_id' => $foreign->id,
        ]);

        $this->assertSame([$this->mine->id], $user->assignedProjectIds()->pluck('project_id')->all());
    }
}
