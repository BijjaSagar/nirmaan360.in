<?php

namespace App\Services\BillingExtras;

use App\Models\BillNote;
use App\Models\Project;
use App\Models\RaBill;
use App\Models\WorkOrder;
use App\Support\Dec;
use Brick\Math\BigDecimal;
use Illuminate\Support\Collection;

/**
 * Where each party stands on each contract of a project: the client on the project
 * itself, and each subcontractor on their work order. Only approved documents count.
 *
 * adjusted = bills approved or paid + approved notes.
 * outstanding = adjusted − paid: positive means still to be settled with the party,
 * negative means they have been overpaid, for example by a note raised after payment.
 */
class ContractPositionService
{
    private const BILLED_STATUSES = ['APPROVED', 'PAID'];

    /** @return list<array<string, mixed>> The client contract first, then each work order. */
    public function forProject(Project $project): array
    {
        $bills = $project->raBills()
            ->whereIn('status', self::BILLED_STATUSES)
            ->with(['deductions', 'notes' => fn ($q) => $q->where('status', 'APPROVED')])
            ->get();

        $finalBills = $project->raBills()->where('is_final', true)->where('status', '!=', 'CANCELLED')->get();

        $rows = [$this->row($project, null, $bills->where('type', 'client'), $finalBills->firstWhere('type', 'client'))];

        foreach ($project->workOrders()->with('party')->orderBy('id')->get() as $workOrder) {
            $rows[] = $this->row(
                $project,
                $workOrder,
                $bills->where('type', 'subcontractor')->where('work_order_id', $workOrder->id),
                $finalBills->firstWhere('work_order_id', $workOrder->id),
            );
        }

        return $rows;
    }

    /** One contract's row, by bill type and work order. */
    public function forContract(Project $project, string $type, ?WorkOrder $workOrder = null): array
    {
        return collect($this->forProject($project))
            ->first(fn (array $row) => $row['type'] === $type && $row['work_order']?->id === $workOrder?->id);
    }

    /** The change approved notes make to billed value before tax, for the dashboard's client billing figure. */
    public function clientNoteAdjustment(): string
    {
        $adjustment = BigDecimal::zero();

        $notes = BillNote::where('status', 'APPROVED')->whereHas('bill', fn ($q) => $q->where('type', 'client'))->get(['kind', 'taxable_amount']);

        foreach ($notes as $note) {
            $taxable = Dec::of($note->taxable_amount);
            $adjustment = $note->kind === 'debit' ? $adjustment->plus($taxable) : $adjustment->minus($taxable);
        }

        return Dec::money($adjustment);
    }

    private function row(Project $project, ?WorkOrder $workOrder, Collection $bills, ?RaBill $finalBill): array
    {
        $billed = BigDecimal::zero();
        $notes = BigDecimal::zero();
        $paid = BigDecimal::zero();
        $retention = BigDecimal::zero();
        $advance = BigDecimal::zero();

        foreach ($bills as $bill) {
            $billed = $billed->plus(Dec::of($bill->net_payable));
            $retention = $retention->minus(Dec::of($bill->retention_released));

            foreach ($bill->notes as $note) {
                $notes = $notes->plus(Dec::of($note->net_effect));
            }

            foreach ($bill->deductions as $deduction) {
                match ($deduction->type) {
                    'retention' => $retention = $retention->plus(Dec::of($deduction->amount)),
                    'advance' => $advance = $advance->plus(Dec::of($deduction->amount)),
                    default => null,
                };
            }

            if ($bill->status === 'PAID') {
                // Bills paid before amounts were recorded were paid at their net payable.
                $paid = $paid->plus(Dec::of($bill->paid_amount ?? $bill->net_payable));
            }
        }

        $adjusted = $billed->plus($notes);
        $advanceLeft = Dec::of(($workOrder ?? $project)->advance_amount)->minus($advance);

        return [
            'type' => $workOrder ? 'subcontractor' : 'client',
            'work_order' => $workOrder,
            'party' => $workOrder ? $workOrder->party : $project->client,
            'billed' => Dec::money($billed),
            'notes' => Dec::money($notes),
            'adjusted' => Dec::money($adjusted),
            'paid' => Dec::money($paid),
            'outstanding' => Dec::money($adjusted->minus($paid)),
            'retention_held' => Dec::money($retention),
            'advance_outstanding' => Dec::money($advanceLeft->isNegative() ? BigDecimal::zero() : $advanceLeft),
            'final_bill' => $finalBill,
            'closed' => $finalBill !== null && in_array($finalBill->status, self::BILLED_STATUSES, true),
        ];
    }
}
