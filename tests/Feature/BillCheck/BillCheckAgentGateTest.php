<?php

namespace Tests\Feature\BillCheck;

use App\Models\AgentToolCall;
use App\Models\Approval;
use App\Models\BillCheck;
use App\Models\RaBill;
use App\Services\ApprovalService;
use App\Services\BillCheck\BillChecks;
use App\Services\BillCheck\Flag;
use Illuminate\Support\Carbon;
use Tests\Feature\Agents\AgentTestCase;

/**
 * The second way a bill gets submitted: the billing agent's tool, after a person allows it. The gate
 * stands in front of it as well. The model is faked, as in every agent test. No request leaves the machine.
 */
class BillCheckAgentGateTest extends AgentTestCase
{
    public function test_the_agent_tool_is_held_until_the_findings_are_acknowledged(): void
    {
        $this->travelTo(Carbon::parse('2026-12-20 11:00:00'));
        $organization = $this->tenant('Meghdoot Buildcon');
        // Percentages made up for this test.
        $project = $this->makeProject($organization, ['retention_pct' => '6.50', 'tds_pct' => '1.75', 'gst_pct' => '12.50']);
        $item = $this->makeBoqItem($project, '100.000', '2400.00');
        $owner = $this->member($organization, 'owner');
        $engineer = $this->member($organization, 'engineer');
        $this->connect();
        $this->settings('measurement_billing', $organization, ['autonomy' => 'L2']);
        $this->approveEntry($this->draftEntry($project, $item, $engineer, ['measured_on' => '2026-11-10']), $engineer, $owner);
        app(BillChecks::class)->setRequired($organization->id, true, $owner);

        $submit = fn (string $id) => fn () => $this->toolUse('ra_bill_submit_for_approval', ['bill_id' => RaBill::sole()->id], $id);

        $this->fakeClaude([
            $this->toolUse('ra_bill_create_draft', ['project_id' => $project->id, 'type' => 'client', 'period_from' => '2026-11-01', 'period_to' => '2026-11-30'], 'toolu_1'),
            $submit('toolu_2'),
            $this->finalAnswer('The bill could not be submitted yet.'),
            $submit('toolu_3'),
            $this->finalAnswer('Bill sent to its approval chain.'),
        ]);

        // First try: a person allows the action, the tool runs, and the gate refuses.
        $run = $this->runAgent('measurement_billing', $owner, 'bill November', $project);
        $this->assertSame('WAITING_APPROVAL', $run->status);
        app(ApprovalService::class)->act(Approval::where('doc_type', 'agent_action')->sole(), $owner, 'approve');

        $this->assertSame('COMPLETED', $run->refresh()->status);
        $bill = RaBill::sole();
        $this->assertSame('DRAFT', $bill->status);

        $call = AgentToolCall::where('tool', 'ra_bill_submit_for_approval')->sole();
        $this->assertSame(AgentToolCall::ERROR, $call->status);
        $this->assertStringContainsString('Check this bill before submitting it.', $call->error);

        // The tool runs inside a transaction that the refusal rolls back. The check is still there afterwards.
        $check = BillCheck::withoutGlobalScopes()->sole();
        $this->assertSame($bill->id, $check->ra_bill_id);
        $this->assertSame($owner->id, $check->run_by);
        $this->assertSame(
            ['F3:client', 'F3:gstin', 'F3:state', 'T1:retention', 'T1:tds', 'T1:gst', 'T1:advance_recovery', 'D6'],
            array_column($check->flags, 'key'),
            'No client, GSTIN, state, confirmed term or photo was entered in this test, and the check says each.',
        );

        // The findings that need it are acknowledged by a person, and the agent is asked again.
        foreach ($check->flags as $flag) {
            if ($flag['level'] !== Flag::NOTE) {
                app(BillChecks::class)->acknowledge($check, $flag['key'], 'Seen, as on the contract form', $owner);
            }
        }

        $second = $this->runAgent('measurement_billing', $owner, 'submit it now', $project);
        app(ApprovalService::class)->act(Approval::where('doc_type', 'agent_action')->latest('id')->firstOrFail(), $owner, 'approve');

        $this->assertSame('COMPLETED', $second->refresh()->status);
        $this->assertSame('IN_REVIEW', $bill->refresh()->status);
        $this->assertSame(1, BillCheck::withoutGlobalScopes()->count());
    }

    public function test_with_the_gate_off_the_agent_tool_works_as_before(): void
    {
        $organization = $this->tenant('Meghdoot Buildcon');
        $project = $this->makeProject($organization);
        $item = $this->makeBoqItem($project);
        $owner = $this->member($organization, 'owner');
        $engineer = $this->member($organization, 'engineer');
        $this->connect();
        $this->settings('measurement_billing', $organization, ['autonomy' => 'L2']);
        $this->approveEntry($this->draftEntry($project, $item, $engineer), $engineer, $owner);

        $this->fakeClaude([
            $this->toolUse('ra_bill_create_draft', ['project_id' => $project->id, 'type' => 'client', 'period_from' => '2026-10-01', 'period_to' => now()->toDateString()], 'toolu_1'),
            fn () => $this->toolUse('ra_bill_submit_for_approval', ['bill_id' => RaBill::sole()->id], 'toolu_2'),
            $this->finalAnswer('Bill sent to its approval chain.'),
        ]);

        $run = $this->runAgent('measurement_billing', $owner, 'bill this month', $project);
        app(ApprovalService::class)->act(Approval::where('doc_type', 'agent_action')->sole(), $owner, 'approve');

        $this->assertSame('COMPLETED', $run->refresh()->status);
        $this->assertSame('IN_REVIEW', RaBill::sole()->status);
        $this->assertSame(0, BillCheck::withoutGlobalScopes()->count());
    }
}
