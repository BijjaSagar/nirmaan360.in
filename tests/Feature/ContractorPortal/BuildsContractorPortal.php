<?php

namespace Tests\Feature\ContractorPortal;

use App\Models\BoqItem;
use App\Models\ContractorMilestone;
use App\Models\ContractorPayment;
use App\Models\Organization;
use App\Models\Party;
use App\Models\ProgressEntry;
use App\Models\Project;
use App\Models\RaBill;
use App\Models\RaBillDeduction;
use App\Models\RaBillItem;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderItem;
use App\Services\ApprovalService;
use App\Services\ContractorControl\ContractorPaymentService;
use App\Support\Dec;
use Illuminate\Support\Facades\Auth;
use Illuminate\Testing\TestResponse;
use Tests\BuildsTenant;

/**
 * One company with two subcontractors on the same project and the same BOQ line, each with a
 * contract, work done, a milestone, an approved payment and an approved bill. The first has a
 * portal login. Every figure that must stay private carries eight digits found nowhere else,
 * so a page or payload that leaks one is caught by its digits however it is formatted.
 */
trait BuildsContractorPortal
{
    use BuildsTenant;

    /** The client's BOQ rate on the shared line: the builder's margin lives in the gap to the contract rate. */
    public const CLIENT_RATE = '73105001.00';

    public const OUR_RATE = '4100.00';

    public const RIVAL_RATE = '73105002.00';

    public const OUR_PAYMENT = '25000.00';

    public const RIVAL_PAYMENT = '73105003.00';

    public const BUDGET = '73105004.00';

    public const OUR_MILESTONE = '50000.00';

    public const RIVAL_MILESTONE = '73105005.00';

    public const PENDING_PAYMENT = '73105006.00';

    public const DRAFT_BILL = '73105007.00';

    /** Figures that must never reach the contractor, by their first eight digits. */
    public const PRIVATE = [self::CLIENT_RATE, self::RIVAL_RATE, self::RIVAL_PAYMENT, self::BUDGET, self::RIVAL_MILESTONE, self::PENDING_PAYMENT, self::DRAFT_BILL];

    protected Organization $organization;

    protected User $owner;

    protected User $manager;

    protected User $engineer;

    protected User $contractor;

    protected Project $project;

    protected Project $otherProject;

    protected BoqItem $item;

    protected Party $party;

    protected Party $rival;

    protected WorkOrder $workOrder;

    protected WorkOrder $rivalWorkOrder;

    protected ContractorMilestone $milestone;

    protected ContractorPayment $payment;

    protected ContractorPayment $rivalPayment;

    protected ContractorPayment $pendingPayment;

    protected RaBill $bill;

    protected RaBill $rivalBill;

    protected RaBill $draftBill;

    protected function buildPortalWorld(): void
    {
        $this->organization = $this->tenant('Sentinel Infra', 'business');
        $org = $this->organization->id;

        $this->owner = $this->member($this->organization, 'owner');
        $this->manager = $this->member($this->organization, 'pm');
        $this->engineer = $this->member($this->organization, 'engineer');
        $this->actingAs($this->owner);

        $this->project = $this->makeProject($this->organization, ['name' => 'Riverside Tower', 'budget_amount' => self::BUDGET, 'deadline_date' => today()->addDays(30)->toDateString()]);
        $this->otherProject = $this->makeProject($this->organization, ['name' => 'Hilltop Villas']);
        $this->item = $this->makeBoqItem($this->project, '100.000', self::CLIENT_RATE);

        $this->party = Party::create(['organization_id' => $org, 'type' => 'subcontractor', 'name' => 'Shree Formwork', 'email' => 'shree@example.com', 'phone' => '9000000001']);
        $this->rival = Party::create(['organization_id' => $org, 'type' => 'subcontractor', 'name' => 'Other Contractor Works']);

        $this->workOrder = $this->contractFor($this->party, 'WO-OURS', 'Slab formwork', self::OUR_RATE);
        $this->rivalWorkOrder = $this->contractFor($this->rival, 'WO-RIVAL', 'Rival shuttering', self::RIVAL_RATE);
        $this->workOrder->update(['end_date' => today()->addDays(10)->toDateString(), 'deadline_label' => 'Before Lakshmi Puja']);

        // Work on the shared line, tagged to each contract.
        $this->workDone($this->workOrder, '70');
        $this->workDone($this->rivalWorkOrder, '30');

        $this->milestone = ContractorMilestone::create(['organization_id' => $org, 'project_id' => $this->project->id, 'work_order_id' => $this->workOrder->id, 'title' => 'Plinth complete', 'amount' => self::OUR_MILESTONE, 'sort' => 1, 'created_by' => $this->owner->id]);
        ContractorMilestone::create(['organization_id' => $org, 'project_id' => $this->project->id, 'work_order_id' => $this->rivalWorkOrder->id, 'title' => 'Rival plinth', 'amount' => self::RIVAL_MILESTONE, 'sort' => 1, 'created_by' => $this->owner->id]);

        $this->payment = $this->paid($this->workOrder, self::OUR_PAYMENT, ['boq_item_id' => $this->item->id, 'reference' => 'UTR-OURS-1']);
        $this->rivalPayment = $this->paid($this->rivalWorkOrder, self::RIVAL_PAYMENT, ['reference' => 'UTR-RIVAL-1']);
        // Still in the company's approval chain: the contractor must not see it.
        $this->pendingPayment = $this->raise($this->workOrder, self::PENDING_PAYMENT);

        $this->bill = $this->billOn($this->workOrder, 'APPROVED', 1, self::OUR_RATE);
        $this->rivalBill = $this->billOn($this->rivalWorkOrder, 'APPROVED', 1, self::RIVAL_RATE);
        $this->draftBill = $this->billOn($this->workOrder, 'DRAFT', 2, self::OUR_RATE, self::DRAFT_BILL);

        $this->contractor = $this->contractorLogin($this->party, 'Shree Formwork Login');

        $this->signOut();
    }

    protected function contractorLogin(Party $party, string $name = 'Contractor login'): User
    {
        return User::factory()->create([
            'organization_id' => $party->organization_id,
            'name' => $name,
            'role' => User::CONTRACTOR_ROLE,
            'contractor_party_id' => $party->id,
            'is_active' => true,
        ]);
    }

    protected function contractFor(Party $party, string $number, string $title, string $rate, ?Project $project = null, ?BoqItem $item = null): WorkOrder
    {
        $project ??= $this->project;

        $order = WorkOrder::create([
            'organization_id' => $party->organization_id, 'project_id' => $project->id, 'party_id' => $party->id,
            'number' => $number, 'title' => $title, 'rate_type' => 'item_rate', 'status' => 'ACTIVE',
        ]);
        WorkOrderItem::create(['organization_id' => $party->organization_id, 'work_order_id' => $order->id, 'boq_item_id' => ($item ?? $this->item)->id, 'qty' => '100.000', 'rate' => $rate]);

        return $order;
    }

    protected function workDone(WorkOrder $order, string $qty): ProgressEntry
    {
        return ProgressEntry::create([
            'organization_id' => $order->organization_id, 'project_id' => $order->project_id, 'boq_item_id' => $this->item->id,
            'work_order_id' => $order->id, 'work_date' => '2026-11-01', 'qty_done' => $qty, 'entered_by' => $this->engineer->id,
        ]);
    }

    /** A payment the engineer raised, waiting for the manager. */
    protected function raise(WorkOrder $order, string $amount, array $overrides = []): ContractorPayment
    {
        return app(ContractorPaymentService::class)->raise($order, ['paid_on' => '2026-11-05', 'amount' => $amount, 'mode' => 'bank', ...$overrides], null, $this->engineer);
    }

    protected function paid(WorkOrder $order, string $amount, array $overrides = []): ContractorPayment
    {
        $payment = $this->raise($order, $amount, $overrides);
        app(ApprovalService::class)->act($payment->currentApproval, $this->manager, 'approve');

        return $payment->refresh();
    }

    /** A subcontractor bill for 50 units on the shared line at the contract rate, with a retention line. */
    protected function billOn(WorkOrder $order, string $status, int $seq, string $rate, ?string $net = null): RaBill
    {
        $org = $order->organization_id;
        $amount = (string) Dec::of($rate)->multipliedBy(50)->toScale(2);
        $retention = Dec::percentOf($amount, 5);
        $net ??= Dec::money(Dec::of($amount)->minus(Dec::of($retention)));

        $bill = RaBill::create([
            'organization_id' => $org, 'project_id' => $order->project_id, 'work_order_id' => $order->id, 'party_id' => $order->party_id,
            'type' => 'subcontractor', 'bill_no' => $seq, 'number' => $order->number.'/RA/'.$seq,
            'period_from' => '2026-10-01', 'period_to' => '2026-10-31', 'status' => $status,
            'gross_amount' => $amount, 'deduction_total' => $retention, 'gst_amount' => '0.00', 'net_payable' => $net,
            'terms' => ['gst_pct' => '0', 'rate_type' => 'item_rate', 'retention_pct' => '5'],
        ]);
        RaBillItem::create([
            'organization_id' => $org, 'ra_bill_id' => $bill->id, 'boq_item_id' => $this->item->id,
            'code' => $this->item->code, 'description' => $this->item->description, 'unit' => $this->item->unit, 'rate' => $rate,
            'prev_qty' => '0.000', 'current_qty' => '50.000', 'cumulative_qty' => '50.000',
            'prev_amount' => '0.00', 'current_amount' => $amount, 'cumulative_amount' => $amount,
        ]);
        RaBillDeduction::create(['organization_id' => $org, 'ra_bill_id' => $bill->id, 'type' => 'retention', 'label' => 'Retention', 'pct' => '5.00', 'amount' => $retention, 'sort' => 1]);

        return $bill;
    }

    protected function signOut(): void
    {
        Auth::forgetUser();
        $this->app['auth']->forgetGuards();
    }

    /** Nothing private reaches the response: no private figure by its digits, and no name of the other contractor. */
    protected function assertNothingPrivate(TestResponse $response, string $label = ''): void
    {
        $text = (string) $response->getContent();
        $digits = (string) preg_replace('/[,.]/', '', $text);

        foreach (self::PRIVATE as $figure) {
            $this->assertStringNotContainsString(substr($figure, 0, 8), $digits, trim($label.' leaks the figure '.$figure));
        }

        foreach (['WO-RIVAL', 'Rival shuttering', 'Other Contractor Works', 'Rival plinth', 'UTR-RIVAL-1', 'Hilltop Villas', 'Over limit'] as $word) {
            $this->assertStringNotContainsString($word, $text, trim($label.' leaks '.$word));
        }

        $this->assertStringNotContainsString($this->engineer->name, $text, trim($label.' names a member of staff'));
        $this->assertStringNotContainsString($this->manager->name, $text, trim($label.' names a member of staff'));
    }
}
