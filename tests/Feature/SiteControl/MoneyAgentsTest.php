<?php

namespace Tests\Feature\SiteControl;

use App\Models\AgentRun;
use App\Models\Party;
use App\Models\PettyCashEntry;
use App\Models\PettyCashWallet;
use App\Models\Project;
use App\Models\RaBill;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\Agents\AgentRegistry;
use App\Services\Agents\Tools\BoqSearch;
use App\Services\Agents\Tools\PettyCashSummary;
use App\Services\Agents\Tools\ProjectSummary;
use App\Services\Agents\Tools\RaBillsSearch;
use App\Services\Agents\Tools\ToolContext;
use App\Services\Agents\Tools\WorkOrdersSearch;
use App\Services\ApprovalService;
use Tests\Feature\Agents\AgentTestCase;

/** An agent cannot tell a site engineer what a site engineer may not see: tool results and the prompt both close the gap. */
class MoneyAgentsTest extends AgentTestCase
{
    private User $owner;

    private User $engineer;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        $organization = $this->tenant();
        $this->owner = $this->member($organization, 'owner');
        $this->engineer = $this->member($organization, 'engineer');
        $this->actingAs($this->owner);
        $this->project = $this->makeProject($organization, ['contract_value' => '72000000.00']);
        $item = $this->makeBoqItem($this->project, '100.000', '7200.00');

        $party = Party::create(['organization_id' => $organization->id, 'type' => 'subcontractor', 'name' => 'Shree Formwork']);
        $order = WorkOrder::create(['organization_id' => $organization->id, 'project_id' => $this->project->id, 'party_id' => $party->id, 'number' => 'WO-1', 'title' => 'Slab', 'status' => 'ACTIVE']);
        $order->items()->create(['organization_id' => $organization->id, 'boq_item_id' => $item->id, 'qty' => '10.000', 'rate' => '6500.00']);

        $bill = RaBill::create([
            'organization_id' => $organization->id, 'project_id' => $this->project->id, 'type' => 'client', 'bill_no' => 1,
            'number' => 'RA/1', 'period_from' => '2026-11-01', 'period_to' => '2026-11-30', 'status' => 'DRAFT',
            'gross_amount' => '720000.00', 'gst_amount' => '129600.00', 'net_payable' => '813600.00',
        ]);
        $bill->deductions()->create(['organization_id' => $organization->id, 'type' => 'retention', 'label' => 'Retention', 'pct' => '5.00', 'amount' => '36000.00']);
        app(ApprovalService::class)->submit($bill, $this->owner);

        $own = PettyCashWallet::create(['organization_id' => $organization->id, 'project_id' => $this->project->id, 'name' => 'Engineer imprest', 'user_id' => $this->engineer->id]);
        PettyCashEntry::create(['organization_id' => $organization->id, 'petty_cash_wallet_id' => $own->id, 'type' => 'topup', 'amount' => '4000.00', 'entry_date' => '2026-11-01']);
        $other = PettyCashWallet::create(['organization_id' => $organization->id, 'project_id' => $this->project->id, 'name' => 'Owner cash', 'user_id' => $this->owner->id]);
        PettyCashEntry::create(['organization_id' => $organization->id, 'petty_cash_wallet_id' => $other->id, 'type' => 'topup', 'amount' => '95000.00', 'entry_date' => '2026-11-01']);
    }

    private function context(User $user): ToolContext
    {
        $agent = app(AgentRegistry::class)->find('project_brain');

        $run = AgentRun::create([
            'organization_id' => $user->organization_id, 'agent_id' => $agent->id, 'agent_version_id' => $agent->current_version_id, 'user_id' => $user->id,
            'project_id' => $this->project->id, 'status' => AgentRun::RUNNING, 'autonomy' => 'L0', 'model' => 'claude-sonnet-5-5',
            'input' => 'hello', 'messages' => [], 'max_steps' => 5, 'max_seconds' => 60, 'max_credits' => '1.0000',
        ]);

        return new ToolContext($run, $user, 'toolu_1');
    }

    public function test_read_tools_send_null_money_to_a_site_engineer_and_figures_to_the_owner(): void
    {
        $this->actingAs($this->engineer);
        $context = $this->context($this->engineer);
        $input = ['project_id' => $this->project->id];

        $boq = app(BoqSearch::class)->run($input, $context)->data['items'][0];
        $this->assertSame('100.000', $boq['boq_qty']);
        $this->assertArrayHasKey('rate', $boq);
        $this->assertNull($boq['rate']);
        $this->assertNull($boq['amount']);

        $summary = app(ProjectSummary::class)->run($input, $context)->data;
        $this->assertNull($summary['contract_value']);
        $this->assertNull($summary['boq_value']);
        $this->assertSame(1, $summary['client_bills']['count']);
        $this->assertNull($summary['client_bills']['gross_billed']);
        $this->assertNull($summary['client_bills']['paid_net']);

        $bill = app(RaBillsSearch::class)->run($input, $context)->data['bills'][0];
        $this->assertSame('RA/1', $bill['number']);
        $this->assertNull($bill['gross_amount']);
        $this->assertNull($bill['net_payable']);
        $this->assertSame('Retention', $bill['deductions'][0]['label']);
        $this->assertNull($bill['deductions'][0]['amount']);

        $this->assertNull(app(WorkOrdersSearch::class)->run($input, $context)->data['work_orders'][0]['total_value']);

        $wallets = app(PettyCashSummary::class)->run($input, $context)->data['wallets'];
        $this->assertSame([['name' => 'Engineer imprest', 'balance' => '4000.00']], array_map(fn ($wallet) => ['name' => $wallet['name'], 'balance' => $wallet['balance']], $wallets));

        $json = json_encode([$boq, $summary, $bill, $wallets]);
        foreach (['7200', '72000000', '813600', '720000', '36000', '65000', '95000'] as $figure) {
            $this->assertStringNotContainsString($figure, (string) $json);
        }

        $this->actingAs($this->owner);
        $context = $this->context($this->owner);
        $this->assertSame('7200.00', app(BoqSearch::class)->run($input, $context)->data['items'][0]['rate']);
        $this->assertSame('72000000.00', app(ProjectSummary::class)->run($input, $context)->data['contract_value']);
        $this->assertSame('813600.00', app(RaBillsSearch::class)->run($input, $context)->data['bills'][0]['net_payable']);
        $this->assertSame('65000.00', app(WorkOrdersSearch::class)->run($input, $context)->data['work_orders'][0]['total_value']);
        $this->assertCount(2, app(PettyCashSummary::class)->run($input, $context)->data['wallets']);
    }

    public function test_the_model_is_told_when_the_user_may_not_see_money(): void
    {
        $this->connect();
        $this->fakeClaude([$this->finalAnswer('Half the slab is cast.')]);

        $this->runAgent('project_brain', $this->engineer, 'How is the slab going?', $this->project);
        $system = implode("\n", array_column($this->sentToModel[0]['system'], 'text'));
        $this->assertStringContainsString('This user may not see money', $system);
        $this->assertStringContainsString('Never state, estimate or work out a rupee figure', $system);

        $this->sentToModel = [];
        $this->runAgent('project_brain', $this->owner, 'How is the slab going?', $this->project);
        $this->assertStringNotContainsString('may not see money', implode("\n", array_column($this->sentToModel[0]['system'], 'text')));
    }
}
