<?php

namespace Tests\Feature\Agents;

use App\Jobs\RunAgentStep;
use App\Models\AgentCreditLedger;
use App\Models\AgentRun;
use App\Models\AgentToolCall;
use App\Models\Approval;
use App\Models\AuditLog;
use App\Models\Dpr;
use App\Models\MeasurementEntry;
use App\Models\RaBill;
use App\Services\Agents\ToolRegistry;
use App\Services\ApprovalService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

/** The E5-04 acceptance criteria from the SRS. */
class OrchestratorTest extends AgentTestCase
{
    public function test_run_stops_at_the_step_limit_and_reports_it(): void
    {
        $organization = $this->tenant();
        $this->connect();
        $this->settings('project_brain', $organization, ['max_steps' => 2]);
        $this->fakeClaude([
            $this->toolUse('projects_list', [], 'toolu_1'),
            $this->toolUse('projects_list', [], 'toolu_2'),
            $this->toolUse('projects_list', [], 'toolu_3'),
        ]);

        $run = $this->runAgent('project_brain', $this->member($organization, 'owner'));

        $this->assertSame('LIMIT_REACHED', $run->status);
        $this->assertSame('steps', $run->limit_reason);
        $this->assertSame(2, $run->steps_count);
        $this->assertCount(2, $this->sentToModel);
    }

    public function test_run_stops_at_the_time_limit_and_reports_it(): void
    {
        $organization = $this->tenant();
        $this->connect();
        $this->settings('project_brain', $organization, ['max_seconds' => 30]);

        $this->fakeClaude([function () {
            Carbon::setTestNow(now()->addSeconds(45));

            return $this->toolUse('projects_list', [], 'toolu_'.uniqid());
        }]);

        $run = $this->runAgent('project_brain', $this->member($organization, 'owner'));
        Carbon::setTestNow();

        $this->assertSame('LIMIT_REACHED', $run->status);
        $this->assertSame('time', $run->limit_reason);
        $this->assertCount(1, $this->sentToModel);
    }

    public function test_time_waiting_for_approval_does_not_count_against_the_limit(): void
    {
        $organization = $this->tenant();
        $project = $this->makeProject($organization);
        $this->connect();
        $this->settings('site_reporter', $organization, ['autonomy' => 'L2', 'max_seconds' => 30]);
        $engineer = $this->member($organization, 'engineer');
        $dpr = Dpr::create(['organization_id' => $organization->id, 'project_id' => $project->id, 'report_date' => now()->toDateString(), 'status' => 'DRAFT', 'created_by' => $engineer->id]);

        $this->fakeClaude([$this->toolUse('dpr_submit_for_approval', ['dpr_id' => $dpr->id]), $this->finalAnswer('Sent.')]);

        $run = $this->runAgent('site_reporter', $engineer, 'send it', $project);
        $this->assertSame('WAITING_APPROVAL', $run->status);

        Carbon::setTestNow(now()->addHours(5));
        app(ApprovalService::class)->act(Approval::where('doc_type', 'agent_action')->firstOrFail(), $this->member($organization, 'owner'), 'approve');
        Carbon::setTestNow();

        $this->assertSame('COMPLETED', $run->refresh()->status);
    }

    public function test_run_stops_at_the_credit_budget_and_never_charges_past_it(): void
    {
        $organization = $this->tenant();
        $this->connect();
        $this->settings('project_brain', $organization, ['max_credits' => '0.01']);
        $this->fakeClaude([$this->toolUse('projects_list', [], 'toolu_1', 100000, 10000)]);

        $run = $this->runAgent('project_brain', $this->member($organization, 'owner'));

        $this->assertSame('LIMIT_REACHED', $run->status);
        $this->assertSame('credits', $run->limit_reason);
        $this->assertSame('0.0100', $run->credits_used);
        $this->assertSame(0, AgentToolCall::count(), 'A step that could not be paid for must not run its tools.');
        $this->assertSame('-0.0100', AgentCreditLedger::where('type', 'usage')->value('credits'));
    }

    public function test_run_stops_when_the_company_runs_out_of_credits(): void
    {
        $organization = $this->tenant();
        $this->connect();
        $this->setCredits($organization, '0.5000');
        $this->fakeClaude([$this->toolUse('projects_list', [], 'toolu_1', 100000, 10000)]);

        $run = $this->runAgent('project_brain', $this->member($organization, 'owner'));

        $this->assertSame('LIMIT_REACHED', $run->status);
        $this->assertSame('wallet', $run->limit_reason);
        $this->assertSame('0.5000', $run->credits_used);
    }

    public function test_tool_outside_the_agents_list_is_refused_and_logged(): void
    {
        $organization = $this->tenant();
        $project = $this->makeProject($organization);
        $this->connect();
        $owner = $this->member($organization, 'owner');
        $this->fakeClaude([
            $this->toolUse('dpr_create_draft', ['project_id' => $project->id, 'report_date' => now()->toDateString(), 'manpower' => [], 'work' => []]),
            $this->finalAnswer('I cannot do that.'),
        ]);

        $run = $this->runAgent('project_brain', $owner, 'write a report', $project);

        $call = AgentToolCall::sole();
        $this->assertSame('REFUSED', $call->status);
        $this->assertSame('not_in_tool_list', $call->refusal_code);
        $this->assertSame(0, Dpr::count());
        $this->assertSame(1, AuditLog::where('action', 'agent.tool_refused')->count());
        $this->assertTrue($this->sentToModel[1]['messages'][2]['content'][0]['is_error']);
        $this->assertSame('COMPLETED', $run->status);
    }

    public function test_tool_the_user_lacks_permission_for_is_refused_and_logged(): void
    {
        $organization = $this->tenant();
        $project = $this->makeProject($organization);
        $this->connect();
        $store = $this->member($organization, 'store');
        $this->assertFalse($store->hasPermission('ra_bill.view'));

        $this->fakeClaude([$this->toolUse('ra_bills_search', ['project_id' => $project->id]), $this->finalAnswer('No access.')]);

        $this->runAgent('project_brain', $store, 'show bills', $project);

        $call = AgentToolCall::sole();
        $this->assertSame('REFUSED', $call->status);
        $this->assertSame('permission', $call->refusal_code);
        $this->assertNull($call->output);

        $audit = AuditLog::where('action', 'agent.tool_refused')->sole();
        $this->assertSame('ra_bills_search', $audit->new_values['tool']);
        $this->assertSame($store->id, $audit->user_id);
    }

    public function test_tools_the_user_cannot_use_are_not_offered(): void
    {
        $organization = $this->tenant();
        $this->connect();
        $this->fakeClaude([$this->finalAnswer('Hello')]);

        $this->runAgent('project_brain', $this->member($organization, 'store'));

        $offered = array_column($this->sentToModel[0]['tools'], 'name');
        $this->assertContains('boq_search', $offered);
        $this->assertNotContains('ra_bills_search', $offered);
    }

    public function test_l1_writes_all_land_as_drafts(): void
    {
        $organization = $this->tenant();
        $project = $this->makeProject($organization);
        $item = $this->makeBoqItem($project);
        $this->connect();
        $qs = $this->member($organization, 'owner');

        $this->fakeClaude([
            $this->toolUse('measurement_create_draft', ['project_id' => $project->id, 'boq_item_id' => $item->id, 'measured_on' => now()->toDateString(), 'nos' => '2', 'length' => '10', 'breadth' => '5', 'depth' => '0.5'], 'toolu_1'),
            $this->finalAnswer('Saved a draft.'),
        ]);

        $run = $this->runAgent('measurement_billing', $qs, 'measure', $project);

        $entry = MeasurementEntry::sole();
        $this->assertSame('DRAFT', $entry->status);
        $this->assertSame('50.000', $entry->qty);
        $this->assertSame('L1', $run->autonomy);
        $this->assertSame([['type' => 'measurement', 'id' => $entry->id]], array_map(fn ($draft) => ['type' => $draft['type'], 'id' => $draft['id']], $run->drafts()));
    }

    public function test_l1_agent_cannot_use_a_tool_that_needs_approval(): void
    {
        $organization = $this->tenant();
        $project = $this->makeProject($organization);
        $this->connect();
        $engineer = $this->member($organization, 'engineer');
        $dpr = Dpr::create(['organization_id' => $organization->id, 'project_id' => $project->id, 'report_date' => now()->toDateString(), 'status' => 'DRAFT']);

        $this->fakeClaude([$this->toolUse('dpr_submit_for_approval', ['dpr_id' => $dpr->id]), $this->finalAnswer('Could not send.')]);

        $run = $this->runAgent('site_reporter', $engineer, 'send it', $project);

        $this->assertNotContains('dpr_submit_for_approval', array_column($this->sentToModel[0]['tools'], 'name'));
        $this->assertSame('autonomy', AgentToolCall::sole()->refusal_code);
        $this->assertSame('COMPLETED', $run->status);
        $this->assertSame('DRAFT', $dpr->refresh()->status);
        $this->assertSame(0, Approval::count());
    }

    public function test_l2_tool_creates_an_approval_request_and_the_run_pauses_until_approved(): void
    {
        $organization = $this->tenant();
        $project = $this->makeProject($organization);
        $this->connect();
        $this->settings('site_reporter', $organization, ['autonomy' => 'L2']);
        $engineer = $this->member($organization, 'engineer');
        $pm = $this->member($organization, 'pm');
        $dpr = Dpr::create(['organization_id' => $organization->id, 'project_id' => $project->id, 'report_date' => now()->toDateString(), 'status' => 'DRAFT', 'created_by' => $engineer->id]);

        $this->fakeClaude([$this->toolUse('dpr_submit_for_approval', ['dpr_id' => $dpr->id]), $this->finalAnswer('Sent for approval.')]);

        $run = $this->runAgent('site_reporter', $engineer, 'send it', $project);

        $this->assertSame('WAITING_APPROVAL', $run->status);
        $this->assertSame('WAITING_APPROVAL', AgentToolCall::sole()->status);
        $this->assertCount(1, $this->sentToModel);
        $this->assertSame('DRAFT', $dpr->refresh()->status, 'Nothing may happen before a person approves.');

        $approval = Approval::where('doc_type', 'agent_action')->sole();
        $this->assertSame('IN_REVIEW', $approval->status);

        app(ApprovalService::class)->act($approval, $pm, 'approve');

        $this->assertSame('COMPLETED', $run->refresh()->status);
        $this->assertSame('IN_REVIEW', $dpr->refresh()->status);
        $this->assertSame('OK', AgentToolCall::sole()->status);
        $this->assertCount(2, $this->sentToModel);
    }

    public function test_person_who_started_the_run_cannot_approve_their_own_agents_request(): void
    {
        $organization = $this->tenant();
        $project = $this->makeProject($organization);
        $this->connect();
        $this->settings('site_reporter', $organization, ['autonomy' => 'L2']);
        $pm = $this->member($organization, 'pm');
        $dpr = Dpr::create(['organization_id' => $organization->id, 'project_id' => $project->id, 'report_date' => now()->toDateString(), 'status' => 'DRAFT']);

        $this->fakeClaude([$this->toolUse('dpr_submit_for_approval', ['dpr_id' => $dpr->id])]);

        $this->runAgent('site_reporter', $pm, 'send it', $project);

        $this->assertFalse(app(ApprovalService::class)->canAct(Approval::where('doc_type', 'agent_action')->sole(), $pm));
    }

    public function test_rejected_request_is_not_carried_out_and_the_model_is_told(): void
    {
        $organization = $this->tenant();
        $project = $this->makeProject($organization);
        $this->connect();
        $this->settings('site_reporter', $organization, ['autonomy' => 'L2']);
        $engineer = $this->member($organization, 'engineer');
        $dpr = Dpr::create(['organization_id' => $organization->id, 'project_id' => $project->id, 'report_date' => now()->toDateString(), 'status' => 'DRAFT']);

        $this->fakeClaude([$this->toolUse('dpr_submit_for_approval', ['dpr_id' => $dpr->id]), $this->finalAnswer('Not sent.')]);

        $run = $this->runAgent('site_reporter', $engineer, 'send it', $project);

        app(ApprovalService::class)->act(Approval::where('doc_type', 'agent_action')->sole(), $this->member($organization, 'owner'), 'reject', 'Wrong date on this one');

        $this->assertSame('COMPLETED', $run->refresh()->status);
        $this->assertSame('DRAFT', $dpr->refresh()->status);
        $this->assertSame('REJECTED', AgentToolCall::sole()->status);

        $result = $this->sentToModel[1]['messages'][2]['content'][0];
        $this->assertTrue($result['is_error']);
        $this->assertStringContainsString('Wrong date on this one', $result['content']);
    }

    public function test_money_moving_and_approving_tools_do_not_exist(): void
    {
        $names = array_keys(app(ToolRegistry::class)->all());

        foreach ($names as $name) {
            $this->assertDoesNotMatchRegularExpression('/(^|_)(approve|pay|paid|payment|release|transfer)($|_)/', $name);
        }
    }

    public function test_bill_stays_with_people_even_at_the_highest_level(): void
    {
        $organization = $this->tenant();
        $project = $this->makeProject($organization);
        $item = $this->makeBoqItem($project);
        $this->connect();
        $this->settings('measurement_billing', $organization, ['autonomy' => 'L2']);
        $owner = $this->member($organization, 'owner');
        $engineer = $this->member($organization, 'engineer');
        $this->approveEntry($this->draftEntry($project, $item, $engineer), $engineer, $owner);

        $this->fakeClaude([
            $this->toolUse('ra_bill_create_draft', ['project_id' => $project->id, 'type' => 'client', 'period_from' => '2026-10-01', 'period_to' => now()->toDateString()], 'toolu_1'),
            fn () => $this->toolUse('ra_bill_submit_for_approval', ['bill_id' => RaBill::sole()->id], 'toolu_2'),
            $this->finalAnswer('Bill sent to its approval chain.'),
        ]);

        $run = $this->runAgent('measurement_billing', $owner, 'bill this month', $project);

        $this->assertSame('WAITING_APPROVAL', $run->status);
        $this->assertSame('DRAFT', RaBill::sole()->status);

        app(ApprovalService::class)->act(Approval::where('doc_type', 'agent_action')->sole(), $owner, 'approve');

        $this->assertSame('COMPLETED', $run->refresh()->status);
        $this->assertSame('IN_REVIEW', RaBill::sole()->status, 'The bill itself still needs its own approvers.');
    }

    public function test_every_run_records_model_tokens_credits_and_tool_calls_at_its_own_address(): void
    {
        $organization = $this->tenant();
        $project = $this->makeProject($organization);
        $this->connect();
        $owner = $this->member($organization, 'owner');
        $this->fakeClaude([$this->toolUse('project_summary', ['project_id' => $project->id]), $this->finalAnswer('All fine.', [['type' => 'project', 'id' => $project->id]])]);

        $run = $this->runAgent('project_brain', $owner, 'status?', $project);

        // Two Sonnet steps of 1000 in and 100 out, plus the Haiku call that titles the chat (40 in, 8 out).
        $this->assertSame('claude-sonnet-5-5', $run->model);
        $this->assertSame(2040, $run->input_tokens);
        $this->assertSame(208, $run->output_tokens);
        $this->assertSame('0.006008', $run->cost_usd);
        $this->assertSame('0.7030', $run->credits_used);

        $call = AgentToolCall::sole();
        $this->assertSame(['project_id' => $project->id], $call->input);
        $this->assertSame($project->name, $call->output['name']);

        $this->actingAs($owner)->get("/agent-runs/{$run->id}")
            ->assertOk()
            ->assertSee('claude-sonnet-5-5')
            ->assertSee('project_summary')
            ->assertSee('All fine.')
            ->assertSee('Completed')
            ->assertSee('0.70');
    }

    public function test_a_step_delivered_twice_runs_once(): void
    {
        $organization = $this->tenant();
        $this->connect();
        $this->fakeClaude([$this->finalAnswer('Done')]);

        $run = $this->runAgent('project_brain', $this->member($organization, 'owner'));
        $calls = count($this->sentToModel);

        (new RunAgentStep($run->id))->handle();

        $this->assertCount($calls, $this->sentToModel);
        $this->assertSame(1, $run->refresh()->steps_count);
    }

    public function test_model_refusal_ends_the_run_cleanly(): void
    {
        $organization = $this->tenant();
        $this->connect();
        $this->fakeClaude([$this->message([], 'refusal')]);

        $run = $this->runAgent('project_brain', $this->member($organization, 'owner'));

        $this->assertSame('FAILED', $run->status);
        $this->assertStringContainsString('declined', $run->error);
    }

    public function test_service_errors_fail_the_run_without_charging(): void
    {
        $organization = $this->tenant();
        $this->connect();
        $this->setCredits($organization, '100.0000');
        Http::fake(['api.anthropic.com/*' => Http::response(['error' => 'nope'], 400)]);

        $run = $this->runAgent('project_brain', $this->member($organization, 'owner'));

        $this->assertSame('FAILED', $run->status);
        $this->assertSame('0.0000', $run->credits_used);
        $this->assertSame(0, AgentCreditLedger::where('type', 'usage')->count());
    }

    public function test_tool_input_that_breaks_the_schema_goes_back_to_the_model(): void
    {
        $organization = $this->tenant();
        $project = $this->makeProject($organization);
        $this->connect();
        $this->fakeClaude([$this->toolUse('boq_search', ['project_id' => 'not a number']), $this->finalAnswer('Sorry.')]);

        $this->runAgent('project_brain', $this->member($organization, 'owner'), 'boq', $project);

        $this->assertSame('ERROR', AgentToolCall::sole()->status);
        $this->assertTrue($this->sentToModel[1]['messages'][2]['content'][0]['is_error']);
    }

    public function test_a_chat_limited_to_one_project_cannot_read_another(): void
    {
        $organization = $this->tenant();
        $project = $this->makeProject($organization);
        $other = $this->makeProject($organization);
        $this->connect();
        $this->fakeClaude([$this->toolUse('project_summary', ['project_id' => $other->id]), $this->finalAnswer('No.')]);

        $this->runAgent('project_brain', $this->member($organization, 'owner'), 'other project?', $project);

        $this->assertSame('project_scope', AgentToolCall::sole()->refusal_code);
    }

    public function test_tools_cannot_reach_another_company(): void
    {
        $organization = $this->tenant();
        $foreign = $this->makeProject($this->tenant('Rival Infra'));
        $this->connect();
        $this->fakeClaude([$this->toolUse('project_summary', ['project_id' => $foreign->id]), $this->finalAnswer('Nothing.')]);

        $this->runAgent('project_brain', $this->member($organization, 'owner'), 'peek');

        $call = AgentToolCall::sole();
        $this->assertSame('ERROR', $call->status);
        $this->assertNull($call->output);
    }

    public function test_cancelled_run_stops_and_closes_its_approval_request(): void
    {
        $organization = $this->tenant();
        $project = $this->makeProject($organization);
        $this->connect();
        $this->settings('site_reporter', $organization, ['autonomy' => 'L2']);
        $engineer = $this->member($organization, 'engineer');
        $dpr = Dpr::create(['organization_id' => $organization->id, 'project_id' => $project->id, 'report_date' => now()->toDateString(), 'status' => 'DRAFT']);
        $this->fakeClaude([$this->toolUse('dpr_submit_for_approval', ['dpr_id' => $dpr->id])]);

        $run = $this->runAgent('site_reporter', $engineer, 'send it', $project);

        $this->actingAs($engineer)->post("/agent-runs/{$run->id}/cancel")->assertRedirect();

        $this->assertSame('CANCELLED', $run->refresh()->status);
        $this->assertSame('CANCELLED', Approval::where('doc_type', 'agent_action')->sole()->status);
        $this->assertSame('DRAFT', $dpr->refresh()->status);
    }

    public function test_run_started_in_a_queue_acts_as_its_user(): void
    {
        $organization = $this->tenant();
        $project = $this->makeProject($organization);
        $this->connect();
        $owner = $this->member($organization, 'owner');
        $this->fakeClaude([$this->toolUse('projects_list', []), $this->finalAnswer('ok')]);

        // No signed-in user here, as on a queue worker.
        $this->assertFalse(auth()->check());
        $run = $this->runAgent('project_brain', $owner, 'list', $project);

        $this->assertSame($owner->id, AuditLog::where('action', 'agent.tool_call')->sole()->user_id);
        $this->assertFalse(auth()->check(), 'The worker must not stay signed in as the user.');
        $this->assertSame(AgentRun::COMPLETED, $run->status);
    }
}
