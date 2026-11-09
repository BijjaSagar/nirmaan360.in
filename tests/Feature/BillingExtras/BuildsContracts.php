<?php

namespace Tests\Feature\BillingExtras;

use App\Models\MeasurementEntry;
use App\Models\Party;
use App\Models\RaBill;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\ApprovalService;
use App\Services\RaBillService;
use Illuminate\Support\Str;
use Tests\BuildsTenant;

trait BuildsContracts
{
    use BuildsTenant;

    /** A client contract: retention 5%, TDS 2%, GST 18%, advance recovered at 10%. BOQ rate 5,000. */
    private function clientContract(array $terms = []): object
    {
        $organization = $this->tenant();
        $engineer = $this->member($organization);
        $owner = $this->member($organization, 'owner');
        $this->actingAs($engineer);

        $project = $this->makeProject($organization, [
            'retention_pct' => '5',
            'tds_pct' => '2',
            'gst_pct' => '18',
            'advance_amount' => '100000',
            'advance_recovery_pct' => '10',
            ...$terms,
        ]);

        return (object) ['organization' => $organization, 'engineer' => $engineer, 'owner' => $owner, 'project' => $project, 'item' => $this->makeBoqItem($project)];
    }

    /** A work order for one BOQ line. Retention 10%, TDS 1%, no GST unless overridden. */
    private function workOrder(object $contract, array $attributes = [], string $qty = '100', string $rate = '1200'): WorkOrder
    {
        $party = Party::create(['organization_id' => $contract->organization->id, 'type' => 'subcontractor', 'name' => 'Shree Formwork']);

        $workOrder = WorkOrder::create([
            'organization_id' => $contract->organization->id,
            'project_id' => $contract->project->id,
            'party_id' => $party->id,
            'number' => 'WO-'.Str::upper(Str::random(5)),
            'title' => 'Slab casting',
            'retention_pct' => '10',
            'tds_pct' => '1',
            'gst_pct' => '0',
            ...$attributes,
        ]);

        $workOrder->items()->create(['organization_id' => $contract->organization->id, 'boq_item_id' => $contract->item->id, 'qty' => $qty, 'rate' => $rate]);

        return $workOrder;
    }

    /** Record and approve a simple quantity. A negative quantity is entered as a deduction. */
    private function measure(object $contract, string $qty, ?WorkOrder $workOrder = null, array $overrides = []): MeasurementEntry
    {
        $deduction = str_starts_with($qty, '-');

        return $this->approveEntry($this->draftEntry($contract->project, $contract->item, $contract->engineer, [
            'nos' => ltrim($qty, '-'),
            'length' => null,
            'breadth' => null,
            'depth' => null,
            'is_deduction' => $deduction,
            'work_order_id' => $workOrder?->id,
            ...$overrides,
        ]), $contract->engineer, $contract->owner);
    }

    private function clientBill(object $contract): RaBill
    {
        return app(RaBillService::class)->generate($contract->project, 'client', '2026-11-01', '2026-11-30', $contract->owner);
    }

    private function subBill(object $contract, WorkOrder $workOrder, mixed $materialRecovery = 0): RaBill
    {
        return app(RaBillService::class)->generate($contract->project, 'subcontractor', '2026-11-01', '2026-11-30', $contract->owner, $workOrder, $materialRecovery);
    }

    private function finalClientBill(object $contract): RaBill
    {
        return app(RaBillService::class)->generateFinal($contract->project, 'client', '2026-11-01', '2026-11-30', $contract->owner);
    }

    private function finalSubBill(object $contract, WorkOrder $workOrder): RaBill
    {
        return app(RaBillService::class)->generateFinal($contract->project, 'subcontractor', '2026-11-01', '2026-11-30', $contract->owner, $workOrder);
    }

    /** Take a bill through every approval level. */
    private function approveBill(RaBill $bill, User $owner): RaBill
    {
        $approval = app(ApprovalService::class)->submit($bill, $owner);

        while ($approval && $approval->status === 'IN_REVIEW') {
            $approval = app(ApprovalService::class)->act($approval, $owner, 'approve');
        }

        return $bill->refresh();
    }

    private function approvedClientBill(object $contract): RaBill
    {
        return $this->approveBill($this->clientBill($contract), $contract->owner);
    }
}
