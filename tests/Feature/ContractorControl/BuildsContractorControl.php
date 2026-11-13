<?php

namespace Tests\Feature\ContractorControl;

use App\Models\BoqItem;
use App\Models\ContractorPayment;
use App\Models\Organization;
use App\Models\Party;
use App\Models\ProgressEntry;
use App\Models\Project;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderItem;
use App\Services\ApprovalService;
use App\Services\ContractorControl\ContractorPaymentService;
use App\Services\ContractorControl\PaymentAheadGuard;
use Illuminate\Support\Str;
use Tests\BuildsTenant;

trait BuildsContractorControl
{
    use BuildsTenant;

    private Organization $organization;

    private User $owner;

    private User $manager;

    private User $supervisor;

    private Project $project;

    private BoqItem $item;

    private function site(): void
    {
        $this->organization = $this->tenant();
        $this->owner = $this->member($this->organization, 'owner');
        $this->manager = $this->member($this->organization, 'pm');
        $this->supervisor = $this->member($this->organization, 'engineer');
        $this->actingAs($this->owner);
        $this->project = $this->makeProject($this->organization);
        $this->item = $this->makeBoqItem($this->project, '100.000', '1000.00');
    }

    private function contract(?BoqItem $item = null, string $qty = '100.000', string $rate = '1000.00', ?Party $party = null): WorkOrder
    {
        $party ??= $this->contractorNamed('Contractor '.Str::random(4));

        $order = WorkOrder::create([
            'organization_id' => $this->organization->id,
            'project_id' => $this->project->id,
            'party_id' => $party->id,
            'number' => 'WO-'.Str::upper(Str::random(6)),
            'title' => 'Flooring',
            'status' => 'ACTIVE',
        ]);

        $this->addLine($order, $item ?? $this->item, $qty, $rate);

        return $order;
    }

    private function addLine(WorkOrder $order, BoqItem $item, string $qty, string $rate): void
    {
        WorkOrderItem::create(['organization_id' => $this->organization->id, 'work_order_id' => $order->id, 'boq_item_id' => $item->id, 'qty' => $qty, 'rate' => $rate]);
    }

    private function contractorNamed(string $name): Party
    {
        return Party::create(['organization_id' => $this->organization->id, 'type' => 'subcontractor', 'name' => $name]);
    }

    private function workDone(BoqItem $item, string $qty, ?WorkOrder $order = null): ProgressEntry
    {
        return ProgressEntry::create([
            'organization_id' => $this->organization->id,
            'project_id' => $this->project->id,
            'boq_item_id' => $item->id,
            'work_date' => '2026-11-01',
            'qty_done' => $qty,
            'work_order_id' => $order?->id,
        ]);
    }

    private function paymentData(array $overrides = []): array
    {
        return ['paid_on' => '2026-11-01', 'amount' => '1000', 'mode' => 'bank', ...$overrides];
    }

    /** A payment raised by the supervisor and left waiting for approval. */
    private function raise(WorkOrder $order, string $amount, array $overrides = []): ContractorPayment
    {
        return app(ContractorPaymentService::class)->raise($order, $this->paymentData(['amount' => $amount, ...$overrides]), null, $this->supervisor);
    }

    /**
     * The manager approves. A payment that puts the contract ahead of the work past the project's limit can only
     * be approved by the owner with a written reason (PaymentAheadGuard), so fixtures that need one paid ahead,
     * such as the "paid 90%, work 70%" example, go through the owner's override. PaymentAheadGuardTest covers the rule.
     */
    private function approve(ContractorPayment $payment): ContractorPayment
    {
        $ahead = app(PaymentAheadGuard::class)->blocks($payment);

        app(ApprovalService::class)->act(
            $payment->currentApproval,
            $ahead ? $this->owner : $this->manager,
            'approve',
            context: $ahead ? ['override_reason' => 'Test fixture: paid ahead of the work on purpose for this example.'] : [],
        );

        return $payment->refresh();
    }

    private function paid(WorkOrder $order, string $amount, array $overrides = []): ContractorPayment
    {
        return $this->approve($this->raise($order, $amount, $overrides));
    }
}
