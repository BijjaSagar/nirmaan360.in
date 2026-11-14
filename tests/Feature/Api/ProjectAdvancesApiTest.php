<?php

namespace Tests\Feature\Api;

use App\Models\AuditLog;
use App\Models\CustomRole;
use App\Models\Organization;
use App\Models\Project;
use App\Models\ProjectAdvance;
use App\Models\User;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/**
 * The advance lines and the worked-out recovery percentage through GET and PATCH /projects/{project}.
 * Additions only: the iPhone and Android apps already read these replies. Names and figures are made up.
 */
class ProjectAdvancesApiTest extends ApiTestCase
{
    /** Every key the detail had before advances came in lines, in order. */
    private const EARLIER_KEYS = [
        'id', 'code', 'name', 'location', 'status', 'start_date', 'end_date', 'measurement_tolerance_pct', 'image_url', 'updated_at',
        'client_id', 'state', 'plot_size', 'built_up_area', 'planned_days', 'deadline_date',
        'retention_pct', 'tds_pct', 'gst_pct', 'advance_recovery_pct', 'deduction_basis', 'deduction_order',
        'contract_value', 'advance_amount', 'phases', 'budget_amount', 'budget_margin_pct',
    ];

    private Organization $organization;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = $this->tenant();
        $this->project = $this->makeProject($this->organization, ['code' => 'GV-2', 'name' => 'Ganesh Vihar Phase 2', 'contract_value' => '3000000.00']);
    }

    private function as(string $role): User
    {
        return $this->signIn($this->member($this->organization, $role));
    }

    /** A custom role that may run projects but not see money. */
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

    private function show(): TestResponse
    {
        return $this->getJson(self::API.'/projects/'.$this->project->id);
    }

    private function saved(): array
    {
        return ProjectAdvance::withoutGlobalScopes()->where('project_id', $this->project->id)->orderBy('sort')->get()
            ->map(fn (ProjectAdvance $line) => [$line->amount, $line->received_on?->toDateString(), $line->note])->all();
    }

    // The reply

    public function test_the_detail_keeps_every_earlier_key_and_adds_two(): void
    {
        $this->as('owner');

        $data = $this->show()->assertOk()->json('data');

        $this->assertSame([...self::EARLIER_KEYS, 'advances', 'advance_recovery_auto'], array_keys($data));
        // Nothing earlier changes its type: money and percentages are still strings.
        $this->assertSame('3000000.00', $data['contract_value']);
        $this->assertSame('0.00', $data['advance_amount']);
        $this->assertSame('0.00', $data['advance_recovery_pct']);
        $this->assertSame([], $data['advances']);
        $this->assertFalse($data['advance_recovery_auto']);

        // The list row and the create reply are not touched.
        $this->assertSame(array_slice(self::EARLIER_KEYS, 0, 10), array_keys($this->getJson(self::API.'/projects')->assertOk()->json('data.0')));
        $this->assertSame(array_slice(self::EARLIER_KEYS, 0, 10), array_keys($this->postJson(self::API.'/projects', ['name' => 'Ganesh Vihar Phase 3'])->assertCreated()->json('data')));
    }

    public function test_the_lines_are_listed_in_order_and_null_without_money_view(): void
    {
        $this->as('owner');
        $this->edit(['advances' => [
            ['amount' => '60000', 'received_on' => '2026-11-02', 'note' => 'NEFT 30218'],
            ['amount' => '40000', 'received_on' => null, 'note' => null],
        ]])->assertOk();

        $advances = $this->show()->assertOk()->json('data.advances');
        $this->assertSame([['amount' => '60000.00', 'received_on' => '2026-11-02', 'note' => 'NEFT 30218'], ['amount' => '40000.00', 'received_on' => null, 'note' => null]],
            array_map(fn (array $line) => array_diff_key($line, ['id' => true]), $advances));
        $this->assertSame(['id', 'amount', 'received_on', 'note'], array_keys($advances[0]));
        $this->assertIsInt($advances[0]['id']);

        $this->asProjectClerk();
        $this->show()->assertOk()
            ->assertJsonPath('data.advances', null)
            ->assertJsonPath('data.advance_amount', null)
            ->assertJsonPath('data.advance_recovery_auto', false);
    }

    // PATCH

    public function test_lines_set_the_advance_to_their_sum(): void
    {
        $owner = $this->as('owner');

        $this->edit([
            'advances' => [
                ['amount' => '60000', 'received_on' => '2026-11-02', 'note' => 'NEFT 30218'],
                ['amount' => '40000.50'],
                ['amount' => null, 'note' => 'An empty line is left out'],
            ],
            'advance_amount' => '1',
        ])->assertOk()
            ->assertJsonPath('data.advance_amount', '100000.50')
            ->assertJsonPath('data.advances.1.amount', '40000.50')
            ->assertJsonCount(2, 'data.advances');

        $this->assertSame([['60000.00', '2026-11-02', 'NEFT 30218'], ['40000.50', null, null]], $this->saved());
        $this->assertSame([$this->organization->id], ProjectAdvance::withoutGlobalScopes()->pluck('organization_id')->unique()->values()->all());

        $audit = AuditLog::where('action', 'project.advances_changed')->sole();
        $this->assertSame($owner->id, $audit->user_id);
        $this->assertSame([['amount' => '60000.00', 'received_on' => '2026-11-02', 'has_note' => true], ['amount' => '40000.50', 'received_on' => null, 'has_note' => false]], $audit->new_values['advances']);
    }

    public function test_a_request_without_the_new_fields_behaves_as_before(): void
    {
        $this->as('owner');

        $this->edit(['advance_amount' => '250000', 'advance_recovery_pct' => '10'])->assertOk()
            ->assertJsonPath('data.advance_amount', '250000.00')
            ->assertJsonPath('data.advance_recovery_pct', '10.00')
            ->assertJsonPath('data.advances', [])
            ->assertJsonPath('data.advance_recovery_auto', false);

        $this->edit(['name' => 'Ganesh Vihar II'])->assertOk()->assertJsonPath('data.advance_recovery_pct', '10.00');

        $this->assertSame([], $this->saved());
        $this->assertSame(0, AuditLog::where('action', 'project.advances_changed')->count());
    }

    public function test_a_project_with_lines_keeps_its_figure_equal_to_them(): void
    {
        $this->as('owner');
        $this->edit(['advances' => [['amount' => '60000'], ['amount' => '40000']]])->assertOk();

        $this->edit(['advance_amount' => '250000'])->assertUnprocessable()->assertJsonValidationErrors('advance_amount');
        $this->assertSame('100000.00', $this->project->refresh()->advance_amount);

        // The same figure is no change.
        $this->edit(['advance_amount' => '100000'])->assertOk();
    }

    public function test_an_empty_list_takes_the_lines_away(): void
    {
        $this->as('owner');
        $this->edit(['advances' => [['amount' => '60000']]])->assertOk();

        $this->edit(['advances' => []])->assertOk()
            ->assertJsonPath('data.advances', [])
            ->assertJsonPath('data.advance_amount', '0.00');

        $this->assertSame([], $this->saved());
        $this->assertNull(AuditLog::where('action', 'project.advances_changed')->latest('id')->first()->new_values);

        // One figure again, so it can be set on its own.
        $this->edit(['advance_amount' => '75000'])->assertOk()->assertJsonPath('data.advance_amount', '75000.00');
    }

    public function test_lines_are_checked(): void
    {
        $this->as('owner');

        $this->edit(['advances' => [['amount' => '-1']]])->assertUnprocessable()->assertJsonValidationErrors(['advances.0.amount' => 'An advance must be more than zero.']);
        $this->edit(['advances' => [['amount' => '0']]])->assertUnprocessable()->assertJsonValidationErrors('advances.0.amount');
        $this->edit(['advances' => [['amount' => 'lots']]])->assertUnprocessable()->assertJsonValidationErrors('advances.0.amount');
        $this->edit(['advances' => [['amount' => '10', 'received_on' => 'yesterday-ish']]])->assertUnprocessable()->assertJsonValidationErrors('advances.0.received_on');
        $this->edit(['advances' => [['amount' => '10', 'note' => Str::repeat('a', 256)]]])->assertUnprocessable()->assertJsonValidationErrors('advances.0.note');
        $this->edit(['advances' => 'sixty thousand'])->assertUnprocessable()->assertJsonValidationErrors('advances');
        $this->edit(['advances' => array_fill(0, 21, ['amount' => '10'])])->assertUnprocessable()->assertJsonValidationErrors('advances');
        $this->edit(['advances' => [['amount' => '9999999999999'], ['amount' => '1']]])->assertUnprocessable()->assertJsonValidationErrors('advances');
        $this->edit(['advance_recovery_auto' => 'perhaps'])->assertUnprocessable()->assertJsonValidationErrors('advance_recovery_auto');

        $this->assertSame([], $this->saved());
        $this->assertSame('0.00', $this->project->refresh()->advance_amount);
    }

    // The worked-out percentage

    public function test_the_percentage_follows_the_advance_and_the_contract_value(): void
    {
        $this->as('owner');

        // 1,00,000 on 30,00,000.
        $this->edit(['advances' => [['amount' => '100000']], 'advance_recovery_auto' => true, 'advance_recovery_pct' => '40'])->assertOk()
            ->assertJsonPath('data.advance_recovery_auto', true)
            ->assertJsonPath('data.advance_recovery_pct', '3.33');

        // A typed percentage is ignored while it is worked out.
        $this->edit(['advance_recovery_pct' => '40'])->assertOk()->assertJsonPath('data.advance_recovery_pct', '3.33');

        // A line added later.
        $this->edit(['advances' => [['amount' => '100000'], ['amount' => '50000']]])->assertOk()->assertJsonPath('data.advance_recovery_pct', '5.00');

        // The contract value changed on its own.
        $this->edit(['contract_value' => '1000000'])->assertOk()->assertJsonPath('data.advance_recovery_pct', '15.00');

        // Turned off, the saved figure stays until one is typed.
        $this->edit(['advance_recovery_auto' => false])->assertOk()->assertJsonPath('data.advance_recovery_pct', '15.00');
        $this->edit(['advance_recovery_pct' => '12'])->assertOk()->assertJsonPath('data.advance_recovery_pct', '12.00');
        $this->edit(['contract_value' => '500000'])->assertOk()->assertJsonPath('data.advance_recovery_pct', '12.00');
    }

    public function test_the_percentage_follows_the_phases_the_app_sends(): void
    {
        $this->as('owner');
        $this->edit(['advances' => [['amount' => '100000']], 'advance_recovery_auto' => true])->assertOk()->assertJsonPath('data.advance_recovery_pct', '3.33');

        $this->edit(['phases' => [['name' => 'Foundation', 'amount' => '1500000'], ['name' => 'Slab', 'amount' => '2500000']]])->assertOk()
            ->assertJsonPath('data.contract_value', '4000000.00')
            ->assertJsonPath('data.advance_recovery_pct', '2.50');

        $this->edit(['phases' => [['name' => 'Foundation', 'amount' => '1500000']]])->assertOk()->assertJsonPath('data.advance_recovery_pct', '6.67');

        // No value, no recovery.
        $this->edit(['phases' => [['name' => 'To be agreed', 'amount' => '0']]])->assertOk()->assertJsonPath('data.advance_recovery_pct', '0.00');

        // An advance above the value holds back all of each bill and no more.
        $this->edit(['phases' => [['name' => 'Foundation', 'amount' => '50000']]])->assertOk()->assertJsonPath('data.advance_recovery_pct', '100.00');
    }

    public function test_a_project_that_existed_keeps_its_percentage_until_auto_is_turned_on(): void
    {
        $this->as('owner');
        $this->project->update(['advance_amount' => '250000.00', 'advance_recovery_pct' => '10.00']);

        $this->edit(['phases' => [['name' => 'Foundation', 'amount' => '1000000']]])->assertOk()->assertJsonPath('data.advance_recovery_pct', '10.00');
        $this->edit(['advance_recovery_auto' => true])->assertOk()->assertJsonPath('data.advance_recovery_pct', '25.00');
    }

    // Who may

    public function test_someone_who_cannot_see_money_leaves_the_advance_alone(): void
    {
        $this->as('owner');
        $this->edit(['advances' => [['amount' => '60000', 'note' => 'NEFT 30218']]])->assertOk();

        $this->asProjectClerk();
        $this->edit(['name' => 'Renamed by the clerk', 'advances' => [['amount' => '1']], 'advance_recovery_auto' => true, 'advance_amount' => '1'])->assertOk()
            ->assertJsonPath('data.name', 'Renamed by the clerk')
            ->assertJsonPath('data.advances', null)
            ->assertJsonPath('data.advance_recovery_auto', false);

        $project = $this->project->refresh();
        $this->assertSame('60000.00', $project->advance_amount);
        $this->assertFalse($project->advance_recovery_auto);
        $this->assertSame([['60000.00', null, 'NEFT 30218']], $this->saved());
    }

    public function test_changing_needs_project_manage(): void
    {
        $this->as('engineer');

        $this->edit(['advances' => [['amount' => '60000']]])->assertForbidden();
        $this->assertSame([], $this->saved());
    }

    public function test_another_company_cannot_reach_the_project(): void
    {
        $this->as('owner');
        $this->edit(['advances' => [['amount' => '60000']]])->assertOk();

        $this->signInElsewhere();
        $this->show()->assertNotFound();
        $this->edit(['advances' => [['amount' => '1']], 'advance_recovery_auto' => true])->assertNotFound();

        $this->assertSame([['60000.00', null, null]], $this->saved());
        $this->assertFalse($this->project->refresh()->advance_recovery_auto);
    }
}
