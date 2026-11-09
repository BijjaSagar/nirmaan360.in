<?php

namespace App\Services\ContractorControl;

use App\Models\ContractorPayment;
use App\Models\Party;
use App\Models\RaBill;
use App\Models\VendorPayment;
use App\Models\WorkOrder;
use App\Support\Dec;
use Brick\Math\BigDecimal;
use Illuminate\Support\Collection;

/**
 * Contract, paid, advance, balance and full history, for one contract or one contractor.
 * finance_paid is what the Finance module has settled on this contractor's RA bills. It is
 * shown beside these figures for reference and is never added to them.
 */
class ContractorLedgerService
{
    public function __construct(private WorkPaymentRatio $ratio, private MilestoneService $milestones) {}

    /** Every contractor with a contract this person can see. */
    public function contractors(): Collection
    {
        return $this->ratio->forWorkOrders(WorkOrder::get())
            ->groupBy(fn (array $row) => $row['work_order']->party_id)
            ->map(fn (Collection $rows) => ['party' => $rows->first()['party'], ...$this->ratio->rollup($rows)])
            ->sortBy(fn (array $row) => $row['party']?->name)
            ->values();
    }

    public function forContract(WorkOrder $workOrder): array
    {
        $ratio = $this->ratio->forContract($workOrder);
        $lines = $this->ratio->lines($workOrder);
        $payments = $this->payments(fn ($query) => $query->where('work_order_id', $workOrder->id));

        return [
            'ratio' => $ratio,
            'lines' => $lines['lines'],
            'unallocated' => $lines['unallocated'],
            'milestones' => $this->milestones->track($workOrder),
            'history' => $this->history($payments, $ratio['contract_value']),
            'not_counted' => $payments->where('status', '!=', 'APPROVED')->values(),
            'finance_paid' => $this->financePaid(RaBill::where('work_order_id', $workOrder->id)->select('id')),
        ];
    }

    public function forContractor(Party $party): array
    {
        $rows = $this->ratio->forWorkOrders(WorkOrder::where('party_id', $party->id)->get())->values();
        $summary = $this->ratio->rollup($rows);
        $payments = $this->payments(fn ($query) => $query->where('party_id', $party->id));

        return [
            'party' => $party,
            'summary' => $summary,
            'contracts' => $rows,
            'history' => $this->history($payments, $summary['contract_value']),
            'not_counted' => $payments->where('status', '!=', 'APPROVED')->values(),
            'finance_paid' => $this->financePaid(RaBill::where('party_id', $party->id)->where('type', 'subcontractor')->select('id')),
        ];
    }

    private function payments(callable $where): Collection
    {
        return ContractorPayment::with('workOrder', 'project', 'boqItem', 'milestone', 'enteredBy', 'reversal')
            ->tap($where)
            ->orderBy('paid_on')
            ->orderBy('id')
            ->get();
    }

    /** Approved payments in date order, each with what had been paid by then and what was left. */
    private function history(Collection $payments, string $contractValue): array
    {
        $running = BigDecimal::zero();
        $rows = [];

        foreach ($payments->where('status', 'APPROVED') as $payment) {
            $running = $running->plus(Dec::of($payment->amount));

            $rows[] = [
                'payment' => $payment,
                'paid_so_far' => Dec::money($running),
                'balance' => Dec::money(Dec::of($contractValue)->minus($running)),
            ];
        }

        return $rows;
    }

    private function financePaid($raBillIds): string
    {
        $paid = VendorPayment::whereIn('ra_bill_id', $raBillIds)->get(['amount']);

        return Dec::money($paid->reduce(fn (BigDecimal $sum, $row) => $sum->plus(Dec::of($row->amount)), BigDecimal::zero()));
    }
}
