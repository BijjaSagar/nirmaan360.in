<?php

namespace Tests\Feature\Api;

use App\Models\AuditLog;
use App\Models\CustomRole;
use App\Models\Organization;
use App\Models\Project;
use App\Models\ProjectValuePhase;
use App\Models\User;
use App\Services\Access\TeamAccessService;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

class ProjectEditApiTest extends ApiTestCase
{
    private Organization $organization;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = $this->tenant();
        $this->project = $this->makeProject($this->organization, [
            'code' => 'KT-B', 'name' => 'Kalyani Tower B', 'location' => 'Baner', 'start_date' => '2026-11-01', 'end_date' => '2027-12-31',
        ]);
    }

    private function as(string $role): User
    {
        return $this->signIn($this->member($this->organization, $role));
    }

    /** A custom role that may run projects but not see money. No built-in role is like that. */
    private function asProjectClerk(): User
    {
        $owner = $this->member($this->organization, 'owner');
        $role = CustomRole::create(['organization_id' => $this->organization->id, 'name' => 'Project clerk', 'permissions' => ['project.view', 'project.manage'], 'created_by' => $owner->id]);

        return $this->signIn(User::factory()->create(['organization_id' => $this->organization->id, 'role' => 'viewer', 'custom_role_id' => $role->id, 'is_active' => true]));
    }

    private function edit(array $body, ?Project $project = null): TestResponse
    {
        return $this->patchJson(self::API.'/projects/'.($project ?? $this->project)->id, $body);
    }

    private function phases(): array
    {
        return [
            ['name' => 'Foundation', 'amount' => '4000000'],
            ['name' => 'Slab', 'amount' => '6000000.50'],
            ['name' => 'Finishing', 'amount' => '10000000'],
        ];
    }

    private function savedPhases(?Project $project = null): array
    {
        return ProjectValuePhase::where('project_id', ($project ?? $this->project)->id)->orderBy('sort')->get(['name', 'amount', 'sort'])->toArray();
    }

    // GET /projects/{project}

    public function test_the_detail_carries_the_list_row_the_form_fields_and_the_phases(): void
    {
        $this->as('owner');
        $this->edit(['phases' => $this->phases()])->assertOk();

        $data = $this->getJson(self::API.'/projects/'.$this->project->id)->assertOk()
            ->assertJsonPath('data.code', 'KT-B')
            ->assertJsonPath('data.name', 'Kalyani Tower B')
            ->assertJsonPath('data.start_date', '2026-11-01')
            ->assertJsonPath('data.contract_value', '20000000.50')
            ->assertJsonPath('data.deduction_order', 'retention,advance,material,tds')
            ->json('data');

        $this->assertSame([
            ['name' => 'Foundation', 'amount' => '4000000.00', 'share_pct' => '20.00'],
            ['name' => 'Slab', 'amount' => '6000000.50', 'share_pct' => '30.00'],
            ['name' => 'Finishing', 'amount' => '10000000.00', 'share_pct' => '50.00'],
        ], array_map(fn (array $phase) => array_diff_key($phase, ['id' => true]), $data['phases']));

        foreach (['id', 'code', 'name', 'location', 'status', 'start_date', 'end_date', 'measurement_tolerance_pct', 'image_url', 'updated_at'] as $key) {
            $this->assertArrayHasKey($key, $data, "The detail is a superset of the list row: {$key}");
        }
    }

    public function test_a_project_with_one_figure_has_no_phases(): void
    {
        $this->as('owner');

        $this->getJson(self::API.'/projects/'.$this->project->id)->assertOk()
            ->assertJsonPath('data.contract_value', '10000000.00')
            ->assertJsonPath('data.phases', []);
    }

    public function test_money_is_null_to_someone_who_may_not_see_it(): void
    {
        $this->as('owner');
        $this->edit(['phases' => $this->phases(), 'budget_amount' => '18000000'])->assertOk();

        $this->asProjectClerk();
        $this->getJson(self::API.'/projects/'.$this->project->id)->assertOk()
            ->assertJsonPath('data.name', 'Kalyani Tower B')
            ->assertJsonPath('data.contract_value', null)
            ->assertJsonPath('data.advance_amount', null)
            ->assertJsonPath('data.phases', null)
            ->assertJsonPath('data.budget_amount', null);
    }

    public function test_reading_needs_project_view_and_stays_in_the_company(): void
    {
        $this->as('engineer');
        $this->getJson(self::API.'/projects/'.$this->project->id)->assertOk();

        $this->signInElsewhere();
        $this->getJson(self::API.'/projects/'.$this->project->id)->assertNotFound();
    }

    // PATCH /projects/{project}

    public function test_only_the_fields_sent_change_and_the_change_is_audited(): void
    {
        $owner = $this->as('owner');

        $this->edit(['name' => 'Kalyani Tower B (Wing 2)', 'location' => 'Balewadi'])->assertOk()
            ->assertJsonPath('data.name', 'Kalyani Tower B (Wing 2)')
            ->assertJsonPath('data.location', 'Balewadi')
            ->assertJsonPath('data.code', 'KT-B')
            ->assertJsonPath('data.start_date', '2026-11-01')
            ->assertJsonPath('data.contract_value', '10000000.00');

        $audit = AuditLog::where('action', 'updated')->where('auditable_id', $this->project->id)->latest('id')->firstOrFail();
        $this->assertSame($owner->id, $audit->user_id);
        $this->assertSame(['name' => 'Kalyani Tower B (Wing 2)', 'location' => 'Balewadi'], $audit->new_values);
        $this->assertSame(['name' => 'Kalyani Tower B', 'location' => 'Baner'], $audit->old_values);
    }

    public function test_the_website_form_fields_can_all_be_changed(): void
    {
        $this->as('owner');

        $this->edit([
            'code' => 'KTB-2', 'state' => 'Goa', 'status' => 'on_hold', 'start_date' => '2026-12-01', 'end_date' => null,
            'retention_pct' => '7.5', 'tds_pct' => '1', 'gst_pct' => '12', 'advance_amount' => '250000', 'advance_recovery_pct' => '10',
            'measurement_tolerance_pct' => '3', 'deduction_basis' => 'running', 'deduction_order' => 'tds,retention,advance,material',
            'plot_size' => '30 × 80 ft', 'planned_days' => 240, 'budget_amount' => '9000000', 'budget_margin_pct' => '4',
        ])->assertOk()->assertJsonPath('data.status', 'on_hold')->assertJsonPath('data.end_date', null);

        $project = $this->project->refresh();
        $this->assertSame('KTB-2', $project->code);
        $this->assertSame('Goa', $project->state);
        $this->assertSame('2026-12-01', $project->start_date->toDateString());
        $this->assertSame('7.50', $project->retention_pct);
        $this->assertSame('250000.00', $project->advance_amount);
        $this->assertSame('running', $project->deduction_basis);
        $this->assertSame(['tds', 'retention', 'advance', 'material'], $project->deduction_order);
        $this->assertSame(240, $project->planned_days);
        $this->assertSame('9000000.00', $project->budget_amount);
    }

    public function test_fields_are_checked(): void
    {
        $this->as('owner');
        $this->makeProject($this->organization, ['code' => 'TAKEN']);

        $this->edit(['name' => ''])->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->edit(['code' => 'TAKEN'])->assertUnprocessable()->assertJsonValidationErrors('code');
        $this->edit(['status' => 'demolished'])->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->edit(['deduction_order' => 'tds,tds'])->assertUnprocessable()->assertJsonValidationErrors('deduction_order');
        $this->edit(['contract_value' => '-1'])->assertUnprocessable()->assertJsonValidationErrors('contract_value');

        // Its own code is not a clash.
        $this->edit(['code' => 'KT-B'])->assertOk();
    }

    public function test_an_end_date_is_checked_against_the_saved_start(): void
    {
        $this->as('owner');

        $this->edit(['end_date' => '2026-10-31'])->assertUnprocessable()->assertJsonValidationErrors('end_date');
        $this->edit(['start_date' => '2028-01-01'])->assertUnprocessable()->assertJsonValidationErrors('end_date');
        $this->edit(['start_date' => '2028-01-01', 'end_date' => '2028-06-30'])->assertOk();
        $this->edit(['start_date' => null])->assertOk()->assertJsonPath('data.start_date', null);
        $this->edit(['end_date' => '2020-01-01'])->assertOk();
    }

    public function test_changing_needs_project_manage(): void
    {
        $this->as('engineer');

        $this->edit(['name' => 'Taken over'])->assertForbidden();
        $this->assertSame('Kalyani Tower B', $this->project->refresh()->name);
    }

    public function test_another_company_cannot_reach_the_project(): void
    {
        $this->signInElsewhere();

        $this->edit(['name' => 'Taken over', 'phases' => $this->phases()])->assertNotFound();
        $this->assertSame('Kalyani Tower B', $this->project->refresh()->name);
        $this->assertSame([], $this->savedPhases());
    }

    public function test_someone_limited_to_other_projects_cannot_reach_this_one(): void
    {
        $mine = $this->makeProject($this->organization, ['code' => 'MINE']);
        $user = $this->member($this->organization, 'pm');
        app(TeamAccessService::class)->applyProjects($user, true, [$mine->id]);
        $this->signIn($user->refresh());

        $this->getJson(self::API.'/projects/'.$this->project->id)->assertNotFound();
        $this->edit(['name' => 'Taken over'])->assertNotFound();
        $this->edit(['name' => 'Mine, renamed'], $mine)->assertOk();
    }

    // Phases

    public function test_phases_set_the_contract_value_to_their_total(): void
    {
        $owner = $this->as('owner');

        $this->edit(['phases' => $this->phases()])->assertOk()
            ->assertJsonPath('data.contract_value', '20000000.50')
            ->assertJsonPath('data.phases.1.name', 'Slab')
            ->assertJsonPath('data.phases.1.share_pct', '30.00');

        $this->assertSame('20000000.50', $this->project->refresh()->contract_value);
        $this->assertSame([
            ['name' => 'Foundation', 'amount' => '4000000.00', 'sort' => 0],
            ['name' => 'Slab', 'amount' => '6000000.50', 'sort' => 1],
            ['name' => 'Finishing', 'amount' => '10000000.00', 'sort' => 2],
        ], $this->savedPhases());
        $this->assertSame([$this->organization->id], ProjectValuePhase::pluck('organization_id')->unique()->values()->all());

        $audit = AuditLog::where('action', 'project.phases_changed')->sole();
        $this->assertSame($owner->id, $audit->user_id);
        $this->assertNull($audit->old_values);
        $this->assertSame('Finishing', $audit->new_values['phases'][2]['name']);

        // The value's own change is in the project's audit row.
        $this->assertSame('20000000.50', AuditLog::where('action', 'updated')->latest('id')->first()->new_values['contract_value']);
    }

    public function test_new_phases_replace_the_saved_ones_in_the_order_sent(): void
    {
        $this->as('owner');
        $this->edit(['phases' => $this->phases()])->assertOk();

        $this->edit(['phases' => [['name' => '  Finishing  ', 'amount' => '5000000'], ['name' => 'Plinth', 'amount' => '2500000.25']]])->assertOk()
            ->assertJsonPath('data.contract_value', '7500000.25')
            ->assertJsonPath('data.phases.0.share_pct', '66.67')
            ->assertJsonPath('data.phases.1.share_pct', '33.33');

        $this->assertSame([
            ['name' => 'Finishing', 'amount' => '5000000.00', 'sort' => 0],
            ['name' => 'Plinth', 'amount' => '2500000.25', 'sort' => 1],
        ], $this->savedPhases());

        $audit = AuditLog::where('action', 'project.phases_changed')->latest('id')->first();
        $this->assertCount(3, $audit->old_values['phases']);
        $this->assertCount(2, $audit->new_values['phases']);
    }

    public function test_sending_the_same_phases_again_changes_and_audits_nothing(): void
    {
        $this->as('owner');
        $this->edit(['phases' => $this->phases()])->assertOk();
        $ids = ProjectValuePhase::pluck('id')->all();

        $this->edit(['phases' => $this->phases()])->assertOk();

        $this->assertSame($ids, ProjectValuePhase::pluck('id')->all());
        $this->assertSame(1, AuditLog::where('action', 'project.phases_changed')->count());
    }

    public function test_an_empty_list_takes_the_phases_away_and_keeps_the_value(): void
    {
        $this->as('owner');
        $this->edit(['phases' => $this->phases()])->assertOk();

        $this->edit(['phases' => []])->assertOk()
            ->assertJsonPath('data.phases', [])
            ->assertJsonPath('data.contract_value', '20000000.50');

        $this->assertSame([], $this->savedPhases());
        $this->assertNull(AuditLog::where('action', 'project.phases_changed')->latest('id')->first()->new_values);

        // One figure again, so it can be changed on its own.
        $this->edit(['contract_value' => '15000000'])->assertOk()->assertJsonPath('data.contract_value', '15000000.00');
    }

    public function test_a_phased_value_changes_only_through_its_phases(): void
    {
        $this->as('owner');
        $this->edit(['phases' => $this->phases()])->assertOk();

        $this->edit(['contract_value' => '25000000'])->assertUnprocessable()->assertJsonValidationErrors('contract_value');
        $this->assertSame('20000000.50', $this->project->refresh()->contract_value);

        // The same figure is no change, as the website's form sends it back.
        $this->edit(['contract_value' => '20000000.5'])->assertOk();

        // Taking the phases away and setting one figure in the same call is allowed.
        $this->edit(['phases' => [], 'contract_value' => '25000000'])->assertOk()->assertJsonPath('data.contract_value', '25000000.00');
    }

    public function test_a_value_sent_beside_phases_must_be_their_total(): void
    {
        $this->as('owner');

        $this->edit(['phases' => $this->phases(), 'contract_value' => '20000000'])->assertUnprocessable()->assertJsonValidationErrors('contract_value');
        $this->assertSame([], $this->savedPhases());

        $this->edit(['phases' => $this->phases(), 'contract_value' => '20000000.50'])->assertOk();
    }

    public function test_phases_are_checked(): void
    {
        $this->as('owner');

        $this->edit(['phases' => [['name' => '', 'amount' => '100']]])->assertUnprocessable()->assertJsonValidationErrors('phases.0.name');
        $this->edit(['phases' => [['name' => 'Slab']]])->assertUnprocessable()->assertJsonValidationErrors('phases.0.amount');
        $this->edit(['phases' => [['name' => 'Slab', 'amount' => '-5']]])->assertUnprocessable()->assertJsonValidationErrors('phases.0.amount');
        $this->edit(['phases' => [['name' => 'Slab', 'amount' => 'lots']]])->assertUnprocessable()->assertJsonValidationErrors('phases.0.amount');
        $this->edit(['phases' => [['name' => Str::repeat('a', 121), 'amount' => '1']]])->assertUnprocessable()->assertJsonValidationErrors('phases.0.name');
        $this->edit(['phases' => 'Slab'])->assertUnprocessable()->assertJsonValidationErrors('phases');
        $this->edit(['phases' => array_fill(0, 51, ['name' => 'Floor', 'amount' => '1'])])->assertUnprocessable()->assertJsonValidationErrors('phases');

        // Each can hold the most a project can, but not all together.
        $this->edit(['phases' => [['name' => 'A', 'amount' => '9999999999999'], ['name' => 'B', 'amount' => '1']]])
            ->assertUnprocessable()->assertJsonValidationErrors('phases');

        $this->assertSame([], $this->savedPhases());
        $this->assertSame('10000000.00', $this->project->refresh()->contract_value);
    }

    public function test_phases_of_nothing_have_no_shares(): void
    {
        $this->as('owner');

        $this->edit(['phases' => [['name' => 'To be agreed', 'amount' => '0']]])->assertOk()
            ->assertJsonPath('data.contract_value', '0.00')
            ->assertJsonPath('data.phases.0.share_pct', null);
    }

    public function test_someone_who_cannot_see_money_changes_the_rest_and_leaves_money_alone(): void
    {
        $this->as('owner');
        $this->edit(['phases' => $this->phases()])->assertOk();

        $this->asProjectClerk();
        $this->edit(['name' => 'Renamed by the PM', 'contract_value' => '1', 'advance_amount' => '1', 'phases' => []])->assertOk()
            ->assertJsonPath('data.name', 'Renamed by the PM')
            ->assertJsonPath('data.contract_value', null)
            ->assertJsonPath('data.phases', null);

        $project = $this->project->refresh();
        $this->assertSame('20000000.50', $project->contract_value);
        $this->assertSame('0.00', $project->advance_amount);
        $this->assertCount(3, $this->savedPhases());
    }

    // POST /projects with phases

    public function test_a_new_project_can_be_made_with_phases(): void
    {
        $this->as('owner');

        $response = $this->postJson(self::API.'/projects', ['name' => 'Sargam Hotel', 'phases' => $this->phases(), 'client_uuid' => (string) Str::uuid()])->assertCreated();

        // Still the list row, as the app stores it.
        $this->assertSame(['id', 'code', 'name', 'location', 'status', 'start_date', 'end_date', 'measurement_tolerance_pct', 'image_url', 'updated_at'], array_keys($response->json('data')));

        $project = Project::findOrFail($response->json('data.id'));
        $this->assertSame('20000000.50', $project->contract_value);
        $this->assertSame(['Foundation', 'Slab', 'Finishing'], array_column($this->savedPhases($project), 'name'));
        $this->assertSame(1, AuditLog::where('action', 'project.phases_changed')->where('auditable_id', $project->id)->count());
    }

    public function test_a_repeated_create_with_phases_makes_one_project_and_one_set_of_phases(): void
    {
        $this->as('owner');
        $body = ['name' => 'Sargam Hotel', 'phases' => $this->phases(), 'client_uuid' => (string) Str::uuid()];

        $id = $this->postJson(self::API.'/projects', $body)->assertCreated()->json('data.id');
        $this->postJson(self::API.'/projects', $body)->assertOk()->assertJsonPath('data.id', $id);

        $this->assertSame(3, ProjectValuePhase::where('project_id', $id)->count());
    }

    public function test_a_new_project_checks_its_phases_before_saving_anything(): void
    {
        $this->as('owner');

        $this->postJson(self::API.'/projects', ['name' => 'Sargam Hotel', 'phases' => [['name' => 'Slab', 'amount' => 'x']]])
            ->assertUnprocessable()->assertJsonValidationErrors('phases.0.amount');
        $this->postJson(self::API.'/projects', ['name' => 'Sargam Hotel', 'phases' => $this->phases(), 'contract_value' => '1'])
            ->assertUnprocessable()->assertJsonValidationErrors('contract_value');

        $this->assertSame(0, Project::where('name', 'Sargam Hotel')->count());
        $this->assertSame(0, ProjectValuePhase::count());
    }

    public function test_an_empty_list_on_create_is_one_figure(): void
    {
        $this->as('owner');

        $id = $this->postJson(self::API.'/projects', ['name' => 'Sargam Hotel', 'contract_value' => '500000', 'phases' => []])->assertCreated()->json('data.id');

        $this->assertSame('500000.00', Project::findOrFail($id)->contract_value);
        $this->assertSame(0, ProjectValuePhase::count());
    }

    // Schema

    public function test_the_migration_can_be_rolled_back_and_run_again(): void
    {
        $migration = require database_path('migrations/2027_01_08_200001_create_project_value_phases_table.php');

        $migration->down();
        $this->assertFalse(Schema::hasTable('project_value_phases'));

        $migration->up();
        $this->assertSame(
            ['id', 'organization_id', 'project_id', 'name', 'amount', 'sort', 'created_at', 'updated_at'],
            Schema::getColumnListing('project_value_phases'),
        );
    }
}
