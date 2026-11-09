<?php

namespace Tests\Feature\Api;

use App\Models\AuditLog;
use App\Models\BoqItem;
use App\Models\BoqSection;
use App\Models\PettyCashWallet;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

class SetupApiTest extends ApiTestCase
{
    private const LIMIT_MESSAGE = 'Your plan project limit has been reached. Upgrade to add more.';

    private function owner(): User
    {
        return $this->signIn($this->member($this->tenant(), 'owner'));
    }

    private function createProject(array $body): TestResponse
    {
        return $this->postJson(self::API.'/projects', $body);
    }

    private function createLine(Project $project, array $body = []): TestResponse
    {
        return $this->postJson(self::API."/projects/{$project->id}/boq-items", [
            'description' => 'RCC M25 in slab', 'unit' => 'cum', 'qty' => '10.5', 'rate' => '7450', ...$body,
        ]);
    }

    private function generatedCode(string $name): ?string
    {
        return $this->createProject(['name' => $name])->assertCreated()->json('data.code');
    }

    // The can list

    public function test_can_lists_the_setup_permissions(): void
    {
        $this->owner();

        $can = $this->getJson(self::API.'/me')->assertOk()->json('user')['can'];

        $this->assertTrue($can['project.manage']);
        $this->assertTrue($can['boq.manage']);

        $this->site('engineer');
        $can = $this->getJson(self::API.'/me')->assertOk()->json('user')['can'];

        $this->assertFalse($can['project.manage']);
        $this->assertFalse($can['boq.manage']);
    }

    // POST /projects

    public function test_a_name_alone_creates_a_project_on_the_schema_defaults(): void
    {
        $owner = $this->owner();

        $response = $this->createProject(['name' => 'Kalyani Tower B'])->assertCreated()
            ->assertJsonPath('data.code', 'KTB')
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.measurement_tolerance_pct', '5.00');

        $this->assertSame(['id', 'code', 'name', 'location', 'status', 'start_date', 'end_date', 'measurement_tolerance_pct', 'updated_at'], array_keys($response->json('data')));

        $project = Project::sole();
        $this->assertSame('0.00', $project->contract_value);
        $this->assertSame('5.00', $project->retention_pct);
        $this->assertSame('18.00', $project->gst_pct);
        $this->assertSame('gross', $project->deduction_basis);
        $this->assertSame($owner->id, $project->created_by);
    }

    public function test_optional_fields_are_saved(): void
    {
        $this->owner();

        $this->createProject(['name' => 'Tower', 'code' => 'T-1', 'location' => 'Baner', 'start_date' => '2026-11-01', 'end_date' => '2027-03-31', 'contract_value' => '1250000.50'])->assertCreated()
            ->assertJsonPath('data.code', 'T-1')
            ->assertJsonPath('data.location', 'Baner')
            ->assertJsonPath('data.start_date', '2026-11-01')
            ->assertJsonPath('data.end_date', '2027-03-31');

        $this->assertSame('1250000.50', Project::sole()->contract_value);
    }

    public function test_blank_optional_fields_take_the_defaults_instead_of_failing(): void
    {
        $this->owner();

        $this->createProject(['name' => 'Tower', 'code' => '', 'contract_value' => null, 'location' => null])->assertCreated()
            ->assertJsonPath('data.code', 'T');

        $this->assertSame('0.00', Project::sole()->contract_value);
    }

    public function test_an_end_date_needs_no_start_date_but_cannot_precede_one(): void
    {
        $this->owner();

        $this->createProject(['name' => 'Tower', 'end_date' => '2027-03-31'])->assertCreated();
        $this->createProject(['name' => 'Tower', 'start_date' => '2027-04-01', 'end_date' => '2027-03-31'])
            ->assertUnprocessable()->assertJsonValidationErrors('end_date');
    }

    public function test_a_project_needs_a_name(): void
    {
        $this->owner();

        $this->createProject([])->assertUnprocessable()->assertJsonValidationErrors('name');
    }

    public function test_a_repeated_client_uuid_is_200_and_one_row(): void
    {
        $this->owner();
        $body = ['name' => 'Tower', 'client_uuid' => (string) Str::uuid()];

        $id = $this->createProject($body)->assertCreated()->json('data.id');
        $this->createProject($body)->assertOk()->assertJsonPath('data.id', $id)->assertJsonPath('data.code', 'T');

        $this->assertSame(1, Project::count());
    }

    public function test_a_repeat_with_an_explicit_code_does_not_trip_the_unique_rule(): void
    {
        $this->owner();
        $body = ['name' => 'Tower', 'code' => 'KT-B', 'client_uuid' => (string) Str::uuid()];

        $id = $this->createProject($body)->assertCreated()->json('data.id');
        $this->createProject($body)->assertOk()->assertJsonPath('data.id', $id);

        $this->assertSame(1, Project::count());
    }

    public function test_a_repeat_still_succeeds_once_the_plan_is_full(): void
    {
        $this->owner();
        config(['buildos.plans.business.max_projects' => 1]);
        $body = ['name' => 'Tower', 'client_uuid' => (string) Str::uuid()];

        $this->createProject($body)->assertCreated();
        $this->createProject($body)->assertOk();

        $this->assertSame(1, Project::count());
    }

    public function test_a_repeat_returns_a_soft_deleted_original_instead_of_failing(): void
    {
        $this->owner();
        $body = ['name' => 'Tower', 'client_uuid' => (string) Str::uuid()];
        $id = $this->createProject($body)->assertCreated()->json('data.id');
        Project::find($id)->delete();

        $this->createProject($body)->assertOk()->assertJsonPath('data.id', $id);

        $this->assertSame(1, Project::withTrashed()->count());
    }

    public function test_a_malformed_client_uuid_is_a_422(): void
    {
        $this->owner();

        $this->createProject(['name' => 'Tower', 'client_uuid' => 'nope'])->assertUnprocessable()->assertJsonValidationErrors('client_uuid');
    }

    public function test_a_client_uuid_is_scoped_to_the_organisation(): void
    {
        $this->owner();
        $body = ['name' => 'Tower', 'client_uuid' => (string) Str::uuid()];
        $this->createProject($body)->assertCreated();

        $this->signInElsewhere();
        $this->createProject($body)->assertCreated();

        $this->assertSame(2, Project::withoutGlobalScopes()->count());
    }

    // Generated project codes

    public function test_generated_codes_are_uppercase_initials_up_to_four(): void
    {
        $this->owner();

        $this->assertSame('RT', $this->generatedCode('riverside tower'));
        $this->assertSame('ABGD', $this->generatedCode('Alpha Beta Gamma Delta Epsilon'));
        $this->assertSame('SMS', $this->generatedCode("St. Mary's School"));
    }

    public function test_generated_codes_get_a_number_when_taken(): void
    {
        $this->owner();

        $this->assertSame('KTB', $this->generatedCode('Kalyani Tower B'));
        $this->assertSame('KTB2', $this->generatedCode('Kalyani Tower B'));
        $this->assertSame('KTB3', $this->generatedCode('Kothrud Trade Bhavan'));
        $this->assertSame(3, Project::pluck('code')->unique()->count());
    }

    public function test_a_generated_code_avoids_one_held_by_a_deleted_project(): void
    {
        $this->owner();
        $id = $this->createProject(['name' => 'Kalyani Tower B'])->assertCreated()->json('data.id');
        Project::find($id)->delete();

        $this->assertSame('KTB2', $this->generatedCode('Kalyani Tower B'));
    }

    public function test_generated_codes_are_unique_per_organisation_only(): void
    {
        $this->owner();
        $this->assertSame('KTB', $this->generatedCode('Kalyani Tower B'));

        $this->signInElsewhere();
        $this->assertSame('KTB', $this->generatedCode('Kalyani Tower B'));
    }

    public function test_generated_codes_handle_devanagari_and_symbol_only_names(): void
    {
        $this->owner();

        $this->assertSame('कट', $this->generatedCode('कल्याणी टॉवर'));
        $this->assertSame('P', $this->generatedCode('###'));
        $this->assertSame('P2', $this->generatedCode('***'));
    }

    public function test_a_taken_code_supplied_by_the_user_is_a_422(): void
    {
        $this->owner();
        $this->createProject(['name' => 'One', 'code' => 'KT-B'])->assertCreated();

        $this->createProject(['name' => 'Two', 'code' => 'KT-B'])->assertUnprocessable()->assertJsonValidationErrors('code');
    }

    // Plan limit

    public function test_the_plan_limit_is_a_422_on_errors_plan_with_the_web_message(): void
    {
        $this->owner();
        config(['buildos.plans.business.max_projects' => 1]);

        $this->createProject(['name' => 'One'])->assertCreated();

        $this->createProject(['name' => 'Two'])->assertUnprocessable()->assertJsonPath('errors.plan.0', self::LIMIT_MESSAGE);
        $this->assertSame(1, Project::count());
    }

    public function test_the_plan_message_follows_the_users_locale(): void
    {
        $this->owner()->update(['locale' => 'hi']);
        config(['buildos.plans.business.max_projects' => 0]);

        $this->createProject(['name' => 'One'])->assertUnprocessable()
            ->assertJsonPath('errors.plan.0', 'आपके प्लान की प्रोजेक्ट सीमा पूरी हो गई है। और जोड़ने के लिए अपग्रेड करें।');
    }

    public function test_the_limit_counts_only_this_organisation(): void
    {
        $this->owner();
        config(['buildos.plans.business.max_projects' => 1]);
        $this->createProject(['name' => 'One'])->assertCreated();

        $this->signInElsewhere();

        $this->createProject(['name' => 'Mine'])->assertCreated();
    }

    // POST /projects/{project}/boq-items

    public function test_a_line_is_created_in_the_pull_shape(): void
    {
        $site = $this->site('owner');
        $project = $this->makeProject($site->organization);

        $response = $this->createLine($project, ['section' => 'Superstructure', 'code' => '2.1'])->assertCreated()
            ->assertJsonPath('data.project_id', $project->id)
            ->assertJsonPath('data.section', 'Superstructure')
            ->assertJsonPath('data.code', '2.1')
            ->assertJsonPath('data.qty', '10.500')
            ->assertJsonPath('data.rate', '7450.00')
            ->assertJsonPath('data.sort', 1)
            ->assertJsonPath('data.measured_qty', '0.000')
            ->assertJsonPath('data.approved_qty', '0.000')
            ->assertJsonPath('data.balance_qty', '10.500');

        $this->assertSame(['id', 'project_id', 'section', 'code', 'description', 'unit', 'qty', 'rate', 'sort', 'measured_qty', 'approved_qty', 'balance_qty', 'updated_at'], array_keys($response->json('data')));
    }

    public function test_a_line_needs_description_unit_qty_and_rate(): void
    {
        $site = $this->site('owner');

        $this->postJson(self::API."/projects/{$site->project->id}/boq-items", [])
            ->assertUnprocessable()->assertJsonValidationErrors(['description', 'unit', 'qty', 'rate']);
    }

    public function test_a_repeated_client_uuid_on_a_line_is_200_and_one_row(): void
    {
        $site = $this->site('owner');
        $project = $this->makeProject($site->organization);
        $body = ['section' => 'Superstructure', 'client_uuid' => (string) Str::uuid()];

        $id = $this->createLine($project, $body)->assertCreated()->json('data.id');
        $this->createLine($project, $body)->assertOk()->assertJsonPath('data.id', $id)->assertJsonPath('data.sort', 1)->assertJsonPath('data.code', '1');

        $this->assertSame(1, BoqItem::where('project_id', $project->id)->count());
        $this->assertSame(1, BoqSection::where('project_id', $project->id)->count());
    }

    public function test_a_repeat_reports_the_current_ledger(): void
    {
        $site = $this->site('owner');
        $body = ['client_uuid' => (string) Str::uuid(), 'qty' => '100'];
        $id = $this->createLine($site->project, $body)->assertCreated()->json('data.id');
        $this->draftEntry($site->project, BoqItem::find($id), $site->user);

        $this->createLine($site->project, $body)->assertOk()
            ->assertJsonPath('data.measured_qty', '50.000')
            ->assertJsonPath('data.balance_qty', '50.000');
    }

    public function test_a_blank_code_is_the_next_number_and_sort_follows_the_web_rule(): void
    {
        $site = $this->site('owner');
        $project = $this->makeProject($site->organization);

        $this->createLine($project)->assertCreated()->assertJsonPath('data.code', '1')->assertJsonPath('data.sort', 1);
        $this->createLine($project, ['code' => null])->assertCreated()->assertJsonPath('data.code', '2')->assertJsonPath('data.sort', 2);
        $this->createLine($project, ['code' => ''])->assertCreated()->assertJsonPath('data.code', '3')->assertJsonPath('data.sort', 3);
    }

    public function test_the_next_number_clears_higher_numeric_codes(): void
    {
        $site = $this->site('owner');
        $project = $this->makeProject($site->organization);
        $this->createLine($project, ['code' => '7'])->assertCreated();

        $this->createLine($project)->assertCreated()->assertJsonPath('data.code', '8')->assertJsonPath('data.sort', 2);
    }

    public function test_the_next_number_follows_the_line_count_when_codes_are_not_plain_numbers(): void
    {
        $site = $this->site('owner');
        $project = $this->makeProject($site->organization);

        foreach (['1.1', '1.2', '2.1'] as $code) {
            $this->createLine($project, ['code' => $code])->assertCreated();
        }

        $this->createLine($project)->assertCreated()->assertJsonPath('data.code', '4');
    }

    public function test_a_deleted_lines_number_is_not_reused(): void
    {
        $site = $this->site('owner');
        $project = $this->makeProject($site->organization);
        $id = $this->createLine($project)->assertCreated()->json('data.id');
        BoqItem::find($id)->delete();

        $this->createLine($project)->assertCreated()->assertJsonPath('data.code', '2');
    }

    public function test_generated_numbers_are_per_project(): void
    {
        $site = $this->site('owner');
        $first = $this->makeProject($site->organization);
        $second = $this->makeProject($site->organization);
        $this->createLine($first)->assertCreated();

        $this->createLine($second)->assertCreated()->assertJsonPath('data.code', '1');
    }

    public function test_a_section_is_found_or_created_in_that_project(): void
    {
        $site = $this->site('owner');
        $first = $this->makeProject($site->organization);
        $second = $this->makeProject($site->organization);

        $this->createLine($first, ['section' => 'Superstructure'])->assertCreated();
        $this->createLine($first, ['section' => '  Superstructure '])->assertCreated()->assertJsonPath('data.section', 'Superstructure');
        $this->createLine($second, ['section' => 'Superstructure'])->assertCreated();
        $this->createLine($first, ['section' => 'Substructure'])->assertCreated();

        $this->assertSame(2, BoqSection::where('project_id', $first->id)->count());
        $this->assertSame(1, BoqSection::where('project_id', $second->id)->count());
    }

    public function test_no_section_leaves_the_line_unsectioned(): void
    {
        $site = $this->site('owner');
        $project = $this->makeProject($site->organization);

        $this->createLine($project, ['section' => ''])->assertCreated()->assertJsonPath('data.section', null);

        $this->assertSame(0, BoqSection::where('project_id', $project->id)->count());
    }

    // POST /projects/{project}/wallets

    public function test_a_wallet_is_created_in_the_pull_shape_and_audited(): void
    {
        $site = $this->site();

        $response = $this->postJson(self::API."/projects/{$site->project->id}/wallets", ['name' => 'Site imprest', 'user_id' => $site->user->id])->assertCreated()
            ->assertJsonPath('data.project_id', $site->project->id)
            ->assertJsonPath('data.name', 'Site imprest')
            ->assertJsonPath('data.user_id', $site->user->id)
            ->assertJsonPath('data.balance', '0.00');

        $this->assertSame(['id', 'project_id', 'name', 'user_id', 'balance', 'updated_at'], array_keys($response->json('data')));
        $this->assertSame(1, AuditLog::where('action', 'created')->where('auditable_type', PettyCashWallet::class)->count());
    }

    public function test_a_repeated_client_uuid_on_a_wallet_is_200_and_one_row(): void
    {
        $site = $this->site();
        $body = ['name' => 'Site imprest', 'client_uuid' => (string) Str::uuid()];
        $url = self::API."/projects/{$site->project->id}/wallets";

        $id = $this->postJson($url, $body)->assertCreated()->json('data.id');
        $this->postJson($url, $body)->assertOk()->assertJsonPath('data.id', $id);

        $this->assertSame(1, PettyCashWallet::count());
    }

    public function test_a_wallet_holder_from_another_company_is_rejected(): void
    {
        $site = $this->site();
        $outsider = $this->member($this->tenant('Other Co'));

        $this->postJson(self::API."/projects/{$site->project->id}/wallets", ['name' => 'Site imprest', 'user_id' => $outsider->id])
            ->assertUnprocessable()->assertJsonValidationErrors('user_id');
    }

    public function test_a_new_wallet_can_be_topped_up_with_a_push_item(): void
    {
        $site = $this->site();
        $wallet = $this->postJson(self::API."/projects/{$site->project->id}/wallets", ['name' => 'Site imprest'])->assertCreated()->json('data.id');

        $this->push($this->item('petty_cash', $site->project, ['wallet_id' => $wallet, 'type' => 'topup', 'amount' => '5000', 'entry_date' => '2026-11-01']))
            ->assertJsonPath('results.0.status', 'created');

        $this->getJson(self::API.'/sync/pull')->assertOk()->assertJsonPath('wallets.0.balance', '5000.00');
    }

    public function test_the_wallet_route_respects_the_plan_feature(): void
    {
        $site = $this->site('owner');
        config(['buildos.plans.business.features' => ['site']]);

        $this->postJson(self::API."/projects/{$site->project->id}/wallets", ['name' => 'Site imprest'])->assertForbidden();
    }

    // Permissions

    public function test_an_engineer_is_denied_projects_and_boq_but_allowed_wallets(): void
    {
        $site = $this->site('engineer');

        $this->createProject(['name' => 'Tower'])->assertForbidden();
        $this->createLine($site->project)->assertForbidden();
        $this->postJson(self::API."/projects/{$site->project->id}/wallets", ['name' => 'Site imprest'])->assertCreated();

        $this->assertSame(1, Project::count());
        $this->assertSame(1, BoqItem::count());
    }

    public function test_each_route_follows_its_own_permission(): void
    {
        $site = $this->site();

        $this->signIn($this->member($site->organization, 'qs'));
        $this->createProject(['name' => 'Tower'])->assertForbidden();
        $this->createLine($site->project)->assertCreated();
        $this->postJson(self::API."/projects/{$site->project->id}/wallets", ['name' => 'Imprest'])->assertForbidden();

        $this->signIn($this->member($site->organization, 'store'));
        $this->createLine($site->project)->assertForbidden();
        $this->postJson(self::API."/projects/{$site->project->id}/wallets", ['name' => 'Imprest'])->assertForbidden();

        $this->signIn($this->member($site->organization, 'pm'));
        $this->createProject(['name' => 'Tower'])->assertCreated();
    }

    public function test_setup_routes_need_a_token(): void
    {
        $this->createProject(['name' => 'Tower'])->assertUnauthorized();
    }

    // Tenant isolation

    public function test_another_companys_project_is_a_404_on_nested_routes(): void
    {
        $site = $this->site('owner');
        $this->signInElsewhere();

        $this->createLine($site->project)->assertNotFound();
        $this->postJson(self::API."/projects/{$site->project->id}/wallets", ['name' => 'Imprest'])->assertNotFound();

        $this->assertSame(1, BoqItem::withoutGlobalScopes()->count());
        $this->assertSame(0, PettyCashWallet::withoutGlobalScopes()->count());
    }

    public function test_a_new_project_stays_in_the_creators_organisation(): void
    {
        $owner = $this->owner();

        $id = $this->createProject(['name' => 'Tower'])->assertCreated()->json('data.id');
        $this->assertSame($owner->organization_id, Project::find($id)->organization_id);

        $this->signInElsewhere();

        $this->getJson(self::API.'/projects')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson(self::API."/projects/{$id}/boq")->assertNotFound();
    }

    // Pull

    public function test_new_records_appear_in_the_next_pull_in_the_same_shape(): void
    {
        $this->travelTo('2026-11-09 10:00:00');
        $site = $this->site('owner');
        $this->travelTo('2026-11-09 11:00:00');
        $since = $this->getJson(self::API.'/sync/pull')->json('server_time');

        $project = $this->createProject(['name' => 'Tower'])->assertCreated()->json('data');
        $line = $this->createLine(Project::find($project['id']), ['section' => 'Superstructure'])->assertCreated()->json('data');
        $wallet = $this->postJson(self::API."/projects/{$project['id']}/wallets", ['name' => 'Imprest'])->assertCreated()->json('data');

        $pulled = $this->getJson(self::API.'/sync/pull?since='.urlencode($since))->assertOk()->json();

        $this->assertSame([$project], $pulled['projects']);
        $this->assertSame([$line], $pulled['boq_items']);
        $this->assertSame([$wallet], $pulled['wallets']);
        $this->assertSame($site->organization->id, $pulled['user']['organization']['id']);
    }

    // The web keeps its own rules

    public function test_the_web_project_form_still_requires_its_fields(): void
    {
        $this->actingAs($this->owner());

        $this->post('/projects', ['name' => 'Tower'])->assertSessionHasErrors([
            'code', 'contract_value', 'retention_pct', 'tds_pct', 'gst_pct', 'advance_amount',
            'advance_recovery_pct', 'measurement_tolerance_pct', 'deduction_basis', 'deduction_order',
        ]);

        $this->assertSame(0, Project::count());
    }

    public function test_the_web_project_form_still_creates_through_the_service(): void
    {
        $owner = $this->owner();
        $this->actingAs($owner);

        $this->post('/projects', [
            'code' => 'X2', 'name' => 'Mine', 'contract_value' => 1, 'retention_pct' => 7, 'tds_pct' => 2, 'gst_pct' => 18,
            'advance_amount' => 0, 'advance_recovery_pct' => 0, 'measurement_tolerance_pct' => 5, 'deduction_basis' => 'running',
            'deduction_order' => 'tds,retention,advance,material',
        ])->assertRedirect();

        $project = Project::sole();
        $this->assertSame('X2', $project->code);
        $this->assertSame('active', $project->status);
        $this->assertSame('7.00', $project->retention_pct);
        $this->assertSame($owner->id, $project->created_by);
        $this->assertSame(['tds', 'retention', 'advance', 'material'], $project->deduction_order);
    }

    public function test_the_web_still_answers_the_plan_limit_with_403_before_validation(): void
    {
        $this->actingAs($this->owner());
        config(['buildos.plans.business.max_projects' => 0]);

        $this->post('/projects', [])->assertForbidden()->assertSee(self::LIMIT_MESSAGE);
    }

    public function test_the_web_boq_form_still_requires_a_code(): void
    {
        $site = $this->site('owner');
        $project = $this->makeProject($site->organization);
        $this->actingAs($site->user);

        $this->post("/projects/{$project->id}/boq/items", ['description' => 'RCC', 'unit' => 'cum', 'qty' => 1, 'rate' => 1])->assertSessionHasErrors('code');
        $this->assertSame(0, BoqItem::where('project_id', $project->id)->count());
    }

    public function test_the_web_boq_form_keeps_the_section_and_sort_rules(): void
    {
        $site = $this->site('owner');
        $project = $this->makeProject($site->organization);
        $this->actingAs($site->user);
        $row = ['section' => 'Superstructure', 'description' => 'RCC', 'unit' => 'cum', 'qty' => '1.5', 'rate' => '10'];

        $this->post("/projects/{$project->id}/boq/items", [...$row, 'code' => 'A'])->assertSessionHasNoErrors();
        $this->post("/projects/{$project->id}/boq/items", [...$row, 'code' => 'B'])->assertSessionHasNoErrors();

        $items = BoqItem::where('project_id', $project->id)->orderBy('sort')->get();
        $this->assertSame([1, 2], $items->pluck('sort')->all());
        $this->assertSame(['A', 'B'], $items->pluck('code')->all());
        $this->assertSame(1, BoqSection::where('project_id', $project->id)->count());
    }

    public function test_the_web_wallet_form_still_requires_a_name_and_saves(): void
    {
        $site = $this->site('owner');
        $this->actingAs($site->user);

        $this->post("/projects/{$site->project->id}/petty-cash/wallets", [])->assertSessionHasErrors('name');
        $this->post("/projects/{$site->project->id}/petty-cash/wallets", ['name' => 'Imprest', 'user_id' => ''])->assertSessionHasNoErrors();

        $this->assertSame('Imprest', PettyCashWallet::sole()->name);
    }
}
