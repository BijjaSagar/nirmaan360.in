<?php

namespace Tests\Feature\Agents;

use App\Models\AgentConversation;
use App\Models\AgentRun;
use App\Models\AgentStep;
use App\Models\AgentToolCall;
use App\Models\AgentVersion;
use App\Models\Attendance;
use App\Models\Dpr;
use App\Models\Issue;
use App\Models\Party;
use App\Models\PettyCashWallet;
use App\Models\RaBill;
use App\Models\Worker;
use App\Models\WorkOrder;
use App\Services\Agents\AgentRegistry;
use App\Services\Agents\ToolRegistry;
use App\Services\RaBillService;

/** E6-01, E6-03, E6-04 and E6-05, through the same loop a real run uses. */
class AgentsWorkTest extends AgentTestCase
{
    public function test_site_reporter_turns_the_marathi_example_into_a_draft_dpr(): void
    {
        $organization = $this->tenant();
        $project = $this->makeProject($organization);
        $this->site($project);
        $slab = $this->makeBoqItem($project, '500.000');
        $this->connect();
        $engineer = $this->member($organization, 'engineer');

        $this->fakeClaude([
            $this->toolUse('boq_search', ['project_id' => $project->id, 'query' => 'slab'], 'toolu_1'),
            $this->toolUse('dpr_create_draft', [
                'project_id' => $project->id,
                'report_date' => now()->toDateString(),
                'manpower' => [['trade' => 'Mason', 'count' => 12], ['trade' => 'Labour', 'count' => 30]],
                'work' => [['boq_item_id' => $slab->id, 'qty' => '22.000']],
            ], 'toolu_2'),
            $this->finalAnswer('आजचा अहवाल मसुदा म्हणून जतन केला: गवंडी 12, कामगार 30, स्लॅब कास्टिंग 22.000 cum. कृपया तपासा.'),
        ]);

        $run = $this->runAgent('site_reporter', $engineer, 'आज १२ गवंडी, ३० कामगार, स्लॅब कास्टिंग २२ घनमीटर', $project);

        $this->assertSame('COMPLETED', $run->status);

        $dpr = Dpr::with('manpower', 'workItems')->sole();
        $this->assertSame('DRAFT', $dpr->status);
        $this->assertSame($engineer->id, $dpr->created_by);
        $this->assertSame($project->sites->first()->id, $dpr->site_id, 'A project with one site needs no question.');
        $this->assertSame(12, (int) $dpr->manpower->firstWhere('trade', 'Mason')->count);
        $this->assertSame(30, (int) $dpr->manpower->firstWhere('trade', 'Labour')->count);
        $this->assertSame($slab->id, $dpr->workItems->first()->boq_item_id);
        $this->assertSame('22.000', $dpr->workItems->first()->qty);

        $this->assertSame('dpr', $run->drafts()[0]['type']);
        $this->assertSame($dpr->id, $run->drafts()[0]['id']);

        $this->assertStringContainsString('आज १२ गवंडी', json_encode($this->sentToModel[0]['messages'], JSON_UNESCAPED_UNICODE));
    }

    public function test_every_read_tool_works_on_real_records_and_parallel_results_return_together(): void
    {
        $organization = $this->tenant();
        $project = $this->makeProject($organization);
        $this->site($project);
        $item = $this->makeBoqItem($project);
        $owner = $this->member($organization, 'owner');
        $engineer = $this->member($organization, 'engineer');
        $this->approveEntry($this->draftEntry($project, $item, $engineer), $engineer, $owner);
        Dpr::create(['organization_id' => $organization->id, 'project_id' => $project->id, 'report_date' => now()->toDateString(), 'status' => 'DRAFT', 'summary' => 'Slab work']);
        Issue::create(['organization_id' => $organization->id, 'project_id' => $project->id, 'title' => 'Leak', 'status' => 'open', 'priority' => 'high']);
        app(RaBillService::class)->generate($project, 'client', '2026-10-01', now()->toDateString(), $owner);
        $party = Party::create(['organization_id' => $organization->id, 'type' => 'subcontractor', 'name' => 'Patil Formwork']);
        WorkOrder::create(['organization_id' => $organization->id, 'project_id' => $project->id, 'party_id' => $party->id, 'number' => 'WO-1', 'title' => 'Shuttering']);
        $worker = Worker::create(['organization_id' => $organization->id, 'name' => 'Ramesh Secretname', 'phone' => '9876543210', 'trade' => 'Mason', 'daily_wage' => '800.00']);
        Attendance::create(['organization_id' => $organization->id, 'project_id' => $project->id, 'worker_id' => $worker->id, 'attendance_date' => now()->toDateString(), 'status' => 'present']);
        $wallet = PettyCashWallet::create(['organization_id' => $organization->id, 'project_id' => $project->id, 'name' => 'Site cash']);
        $wallet->entries()->create(['organization_id' => $organization->id, 'type' => 'topup', 'amount' => '1500.50', 'entry_date' => now()->toDateString()]);
        $this->connect();

        $scope = ['project_id' => $project->id];
        $calls = [
            'projects_list' => [],
            'project_summary' => $scope,
            'boq_search' => [...$scope, 'query' => 'slab'],
            'dprs_search' => $scope,
            'issues_search' => $scope,
            'measurements_search' => $scope,
            'ra_bills_search' => $scope,
            'work_orders_search' => $scope,
            'attendance_summary' => [...$scope, 'from' => now()->subDay()->toDateString(), 'to' => now()->toDateString()],
            'petty_cash_summary' => $scope,
        ];

        $blocks = [];

        foreach ($calls as $name => $input) {
            $blocks[] = ['type' => 'tool_use', 'id' => 'toolu_'.$name, 'name' => $name, 'input' => $input];
        }

        $this->fakeClaude([$this->message($blocks, 'tool_use'), $this->finalAnswer('Done.')]);

        $run = $this->runAgent('project_brain', $owner, 'everything', $project);

        $this->assertSame('COMPLETED', $run->status);

        $results = AgentToolCall::all()->keyBy('tool');
        $this->assertCount(10, $results);

        foreach ($results as $tool => $call) {
            $this->assertSame('OK', $call->status, $tool.': '.$call->error);
        }

        $this->assertSame('50.000', $results['boq_search']->output['items'][0]['approved_qty']);
        $this->assertSame('Slab work', $results['dprs_search']->output['reports'][0]['summary']);
        $this->assertSame('Leak', $results['issues_search']->output['issues'][0]['title']);
        $this->assertSame('Patil Formwork', $results['work_orders_search']->output['work_orders'][0]['party']);
        $this->assertSame('1500.50', $results['petty_cash_summary']->output['wallets'][0]['balance']);
        $this->assertSame(1, $results['attendance_summary']->output['days'][0]['counts']['present']);

        // All ten answers travel back in one message, as the Messages API requires.
        $this->assertCount(10, $this->sentToModel[1]['messages'][2]['content']);

        // Worker names, phones and wages stay out of what the model reads.
        $this->assertStringNotContainsString('Secretname', json_encode($this->sentToModel));
        $this->assertStringNotContainsString('800.00', json_encode($results['attendance_summary']->output));
    }

    public function test_chat_shows_confirm_and_edit_for_a_draft_and_they_work(): void
    {
        $organization = $this->tenant();
        $project = $this->makeProject($organization);
        $this->connect();
        $engineer = $this->member($organization, 'engineer');
        $this->fakeClaude([
            $this->toolUse('dpr_create_draft', ['project_id' => $project->id, 'report_date' => now()->toDateString(), 'manpower' => [['trade' => 'Mason', 'count' => 3]], 'work' => []]),
            $this->finalAnswer('Saved as a draft.'),
        ]);

        $this->actingAs($engineer)->post('/agents/site_reporter/chat', ['message' => '3 masons today', 'project_id' => $project->id])->assertRedirect();

        $conversation = AgentConversation::sole();
        $dpr = Dpr::sole();

        $this->get("/agent-chats/{$conversation->id}")
            ->assertOk()
            ->assertSee('Saved as a draft.')
            ->assertSee('Confirm')
            ->assertSee(route('agents.drafts.dpr.edit', $dpr), false);

        $this->get(route('agents.drafts.dpr.edit', $dpr))->assertOk()->assertSee('Mason');

        $this->patch(route('agents.drafts.dpr.update', $dpr), [
            'report_date' => now()->toDateString(),
            'manpower' => [['trade' => 'Mason', 'count' => 5]],
        ])->assertRedirect(route('dprs.show', $dpr));
        $this->assertSame(5, (int) $dpr->manpower()->sole()->count);

        $this->post(route('dprs.submit', $dpr))->assertRedirect();
        $this->assertSame('IN_REVIEW', $dpr->refresh()->status);

        $this->get(route('agents.drafts.dpr.edit', $dpr))->assertStatus(409);
    }

    public function test_site_reporter_is_stopped_from_guessing_the_site(): void
    {
        $organization = $this->tenant();
        $project = $this->makeProject($organization);
        $this->site($project, 'Tower A');
        $this->site($project, 'Tower B');
        $this->connect();

        $this->fakeClaude([
            $this->toolUse('dpr_create_draft', ['project_id' => $project->id, 'report_date' => now()->toDateString(), 'manpower' => [['trade' => 'Mason', 'count' => 3]], 'work' => []]),
            $this->finalAnswer('Which site, Tower A or Tower B?', [], 'Which site, Tower A or Tower B?'),
        ]);

        $run = $this->runAgent('site_reporter', $this->member($organization, 'engineer'), '3 masons today', $project);

        $this->assertSame(0, Dpr::count());
        $this->assertSame('ERROR', AgentToolCall::sole()->status);
        $this->assertStringContainsString('Tower B', AgentToolCall::sole()->error);
        $this->assertSame('Which site, Tower A or Tower B?', $run->output['clarifying_question']);
    }

    public function test_answering_a_question_keeps_the_earlier_conversation(): void
    {
        $organization = $this->tenant();
        $project = $this->makeProject($organization);
        $this->connect();
        $engineer = $this->member($organization, 'engineer');

        $this->fakeClaude([$this->finalAnswer('Which activity?', [], 'Which activity?'), $this->finalAnswer('Thanks.')]);

        $this->actingAs($engineer)->post('/agents/site_reporter/chat', ['message' => 'casting done', 'project_id' => $project->id]);
        $conversation = AgentConversation::sole();

        $this->post('/agents/site_reporter/chat', ['message' => 'slab, 22 cum', 'conversation_id' => $conversation->id])->assertRedirect("/agent-chats/{$conversation->id}");

        $messages = $this->sentToModel[1]['messages'];
        $this->assertSame('casting done', $messages[0]['content']);
        $this->assertSame('Which activity?', $messages[1]['content']);
        $this->assertSame('slab, 22 cum', $messages[2]['content']);
        $this->assertSame(1, AgentConversation::count());
    }

    public function test_sending_the_same_message_twice_starts_one_run(): void
    {
        $organization = $this->tenant();
        $this->connect();
        $this->fakeClaude([$this->finalAnswer('ok')]);
        $engineer = $this->member($organization, 'engineer');
        $uuid = '7f1f8f3a-6c3e-4a50-9a9e-2f7e6c1b0a11';

        $this->actingAs($engineer)->post('/agents/site_reporter/chat', ['message' => 'hello', 'client_uuid' => $uuid]);
        $this->post('/agents/site_reporter/chat', ['message' => 'hello', 'client_uuid' => $uuid])->assertRedirect();

        $this->assertSame(1, AgentRun::count());
        $this->assertCount(1, $this->sentToModel);
    }

    public function test_ask_my_project_answers_with_links_to_records(): void
    {
        $organization = $this->tenant();
        $project = $this->makeProject($organization);
        $item = $this->makeBoqItem($project);
        $this->connect();
        $owner = $this->member($organization, 'owner');

        $this->fakeClaude([
            $this->toolUse('boq_search', ['project_id' => $project->id, 'query' => 'slab']),
            $this->finalAnswer('BOQ quantity is 100.000 cum.', [['type' => 'boq_item', 'id' => $item->id]]),
        ]);

        $this->actingAs($owner)->get("/projects/{$project->id}/ask")->assertOk()->assertSee('Ask my project')->assertSee($project->name);

        $this->post('/agents/project_brain/chat', ['message' => 'how much slab?', 'project_id' => $project->id])->assertRedirect();

        $conversation = AgentConversation::sole();
        $this->assertSame($project->id, $conversation->project_id);

        $this->get("/agent-chats/{$conversation->id}")
            ->assertOk()
            ->assertSee('BOQ quantity is 100.000 cum.')
            ->assertSee(route('boq.index', $project, false), false)
            ->assertSee($item->code);

        $this->assertStringContainsString('Project in scope: '.$project->name, $this->sentToModel[0]['system'][1]['text']);
    }

    public function test_project_brain_never_writes(): void
    {
        $version = AgentVersion::where('agent_id', app(AgentRegistry::class)->find('project_brain')->id)->latest('version')->first();

        foreach ($version->tools as $tool) {
            $this->assertSame('read', app(ToolRegistry::class)->find($tool)->effect(), $tool);
        }

        $this->assertSame('L0', $version->max_autonomy);
    }

    public function test_owners_copilot_builds_the_brief_from_company_data(): void
    {
        $organization = $this->tenant();
        $project = $this->makeProject($organization, ['end_date' => now()->subDays(5)->toDateString(), 'status' => 'active']);
        Issue::create(['organization_id' => $organization->id, 'project_id' => $project->id, 'title' => 'Crack in column', 'status' => 'open', 'priority' => 'critical']);
        $this->connect();
        $owner = $this->member($organization, 'owner');

        $this->fakeClaude([
            $this->toolUse('company_overview', []),
            $this->finalAnswer('One project is late.', [['type' => 'project', 'id' => $project->id]], null, ['decisions' => [['title' => 'Extend the deadline', 'why' => 'It is 5 days late.']]]),
        ]);

        $this->actingAs($owner)->post('/agents/owners_copilot/chat', ['message' => "Give me today's brief."])->assertRedirect();

        $overview = AgentToolCall::sole()->output;
        $this->assertSame($project->id, $overview['late_projects'][0]['id']);
        $this->assertSame('Crack in column', $overview['urgent_issues'][0]['title']);

        $run = AgentRun::sole();
        $this->get("/agent-chats/{$run->agent_conversation_id}")->assertOk()->assertSee('One project is late.')->assertSee('Extend the deadline');
    }

    public function test_owners_copilot_is_only_for_owners_and_admins(): void
    {
        $organization = $this->tenant();
        $this->connect();
        $this->fakeClaude([$this->finalAnswer('hi')]);

        $this->actingAs($this->member($organization, 'pm'))->get('/agents/owners_copilot/chat')->assertForbidden();
        $this->post('/agents/owners_copilot/chat', ['message' => 'hello'])->assertSessionHasErrors('message');
        $this->assertSame(0, AgentRun::count());
    }

    public function test_daily_brief_runs_only_when_switched_on_and_only_once_a_day(): void
    {
        $on = $this->tenant('Early Bird');
        $off = $this->tenant('Not Today');
        $this->member($on, 'owner');
        $this->member($off, 'owner');
        $this->connect();
        $this->settings('owners_copilot', $on, ['daily_brief' => true]);
        $this->fakeClaude([$this->toolUse('company_overview', []), $this->finalAnswer('Brief.', extra: ['decisions' => []])]);

        $this->artisan('agents:daily-briefs')->assertSuccessful();
        $this->artisan('agents:daily-briefs')->assertSuccessful();

        $this->assertSame(1, AgentRun::withoutGlobalScopes()->count());
        $this->assertSame($on->id, AgentRun::withoutGlobalScopes()->sole()->organization_id);
        $this->assertSame('schedule', AgentRun::withoutGlobalScopes()->sole()->channel);
    }

    public function test_billing_agent_drafts_a_bill_from_approved_measurements(): void
    {
        $organization = $this->tenant();
        $project = $this->makeProject($organization);
        $item = $this->makeBoqItem($project);
        $this->connect();
        $owner = $this->member($organization, 'owner');
        $engineer = $this->member($organization, 'engineer');
        $this->approveEntry($this->draftEntry($project, $item, $engineer), $engineer, $owner);

        $this->fakeClaude([
            $this->toolUse('measurements_search', ['project_id' => $project->id, 'status' => 'APPROVED', 'unbilled_only' => true], 'toolu_1'),
            $this->toolUse('ra_bill_create_draft', ['project_id' => $project->id, 'type' => 'client', 'period_from' => '2026-10-01', 'period_to' => now()->toDateString()], 'toolu_2'),
            $this->finalAnswer('Drafted RA bill.'),
        ]);

        $run = $this->runAgent('measurement_billing', $owner, 'bill up to today', $project);

        $bill = RaBill::sole();
        $this->assertSame('DRAFT', $bill->status);
        $this->assertSame('250000.00', $bill->gross_amount);
        $this->assertSame('claude-opus-5-5', $run->model);
        $this->assertSame('ra_bill', $run->drafts()[0]['type']);

        $output = AgentToolCall::where('tool', 'ra_bill_create_draft')->sole()->output;
        $this->assertSame($bill->net_payable, $output['net_payable']);
        $this->assertSame('50.000', $output['lines'][0]['current_qty']);
    }

    public function test_billing_agent_reports_when_nothing_is_ready_to_bill(): void
    {
        $organization = $this->tenant();
        $project = $this->makeProject($organization);
        $this->connect();
        $this->fakeClaude([
            $this->toolUse('ra_bill_create_draft', ['project_id' => $project->id, 'type' => 'client', 'period_from' => '2026-10-01', 'period_to' => now()->toDateString()]),
            $this->finalAnswer('Nothing is ready to bill.'),
        ]);

        $this->runAgent('measurement_billing', $this->member($organization, 'owner'), 'bill', $project);

        $this->assertSame(0, RaBill::count());
        $this->assertSame('ERROR', AgentToolCall::sole()->status);
        $this->assertStringContainsString('No approved, unbilled', AgentToolCall::sole()->error);
    }

    public function test_definitions_become_versions_and_a_change_makes_a_new_one(): void
    {
        $registry = app(AgentRegistry::class);
        $agent = $registry->find('project_brain');
        $version = $agent->currentVersion;
        $this->assertSame(1, $version->version);
        $this->assertSame(1, AgentVersion::where('agent_id', $agent->id)->count());

        $registry->sync();
        $this->assertSame(1, AgentVersion::where('agent_id', $agent->id)->count(), 'An unchanged definition makes no new version.');

        // Simulate the definition file having changed since this version was saved.
        $version->update(['content_hash' => str_repeat('0', 64)]);
        $registry->sync();

        $agent->refresh();
        $this->assertSame(2, $agent->currentVersion->version);
        $this->assertSame(2, AgentVersion::where('agent_id', $agent->id)->count());
    }

    public function test_each_run_keeps_the_version_it_used(): void
    {
        $organization = $this->tenant();
        $this->connect();
        $this->fakeClaude([$this->finalAnswer('ok')]);

        $registry = app(AgentRegistry::class);
        $run = $this->runAgent('project_brain', $this->member($organization, 'owner'));

        $registry->find('project_brain')->currentVersion->update(['content_hash' => str_repeat('0', 64)]);
        $registry->sync();

        $this->assertSame(1, $run->refresh()->agentVersion->version);
    }

    public function test_new_chats_are_titled_with_the_cheap_model_and_metered(): void
    {
        $organization = $this->tenant();
        $this->connect();
        $this->fakeClaude([$this->finalAnswer('ok')]);

        $run = $this->runAgent('project_brain', $this->member($organization, 'owner'), 'how is the slab work going');

        $this->assertSame('Slab work today', AgentConversation::sole()->title);

        $step = AgentStep::where('purpose', 'title')->sole();
        $this->assertSame('claude-haiku-5-5', $step->model);
        $this->assertSame(1, $run->refresh()->steps_count, 'A title does not use up one of the run\'s steps.');
    }
}
