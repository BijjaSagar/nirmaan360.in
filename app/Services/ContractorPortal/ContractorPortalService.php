<?php

namespace App\Services\ContractorPortal;

use App\Models\ContractorPayment;
use App\Models\Party;
use App\Models\RaBill;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderItem;
use App\Services\ContractorControl\MilestoneService;
use App\Services\ContractorControl\WorkPaymentRatio;
use App\Services\SiteControl\WorkProgress;
use App\Support\Dec;
use Brick\Math\BigDecimal;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Everything a contractor login may read: its own contracts, payments and bills in the company
 * that invited it, and nothing else. Every query names the contractor's party, so a row of another
 * contractor's never comes back, even on the same project and the same BOQ line. The figures are
 * the ones the company already works with: WorkProgress for work done, WorkPaymentRatio for paid
 * against work, MilestoneService for the parts of a contract. Each row is rebuilt from named keys,
 * so the company's alert limit, the client's BOQ rate and the names of its staff never pass through.
 */
class ContractorPortalService
{
    public function __construct(
        private WorkPaymentRatio $ratio,
        private WorkProgress $progress,
        private MilestoneService $milestones,
    ) {}

    /**
     * The subcontractor behind a login, or null when the link is missing, points outside the
     * company, or no longer leads to a live subcontractor. Filters by company itself, so the
     * answer is the same with or without a signed-in user.
     */
    public function partyFor(User $user): ?Party
    {
        if (! $user->isContractor() || $user->contractor_party_id === null) {
            return null;
        }

        return Party::withoutGlobalScopes()
            ->where('organization_id', $user->organization_id)
            ->where('type', 'subcontractor')
            ->whereNull('deleted_at')
            ->find($user->contractor_party_id);
    }

    /** The party, or a refusal for anyone who is not a working contractor login. */
    public function party(User $user): Party
    {
        $party = $this->partyFor($user);

        abort_if($party === null, 403, __('This login is not linked to a contractor.'));

        return $party;
    }

    /**
     * Every contract of this contractor across the company's projects, newest first, with the totals.
     *
     * @return array{summary: array<string, mixed>, contracts: list<array<string, mixed>>}
     */
    public function overview(Party $party, ?Carbon $today = null): array
    {
        $rows = $this->ratio->forWorkOrders($this->workOrders($party)->get());

        return [
            'summary' => $this->summary($rows),
            'contracts' => $rows->map(fn (array $row) => $this->contractRow($row, $today))->values()->all(),
        ];
    }

    /**
     * One contract in full: lines at the contract rate with the quantity done, the parts, the
     * payments against it and the bills raised on it. A contract of anyone else's is not found.
     *
     * @return array<string, mixed>
     */
    public function contract(Party $party, WorkOrder $workOrder, ?Carbon $today = null): array
    {
        abort_unless((int) $workOrder->party_id === (int) $party->id, 404);

        $workOrder->loadMissing('project', 'items.boqItem');
        $figures = $this->progress->workOrderLineFigures($workOrder);

        return [
            ...$this->contractRow($this->ratio->forContract($workOrder), $today),
            'lines' => $workOrder->items->map(fn (WorkOrderItem $line) => $this->line($line, $figures[$line->id]))->values()->all(),
            'parts' => array_map($this->part(...), $this->milestones->track($workOrder)['parts']),
            'payments' => $this->payments($party, $workOrder->id),
            'bills' => $this->bills($party, $workOrder->id),
        ];
    }

    /**
     * Payments that count as paid to this contractor, newest first. Requests still inside the
     * company's approval chain are its own business and are left out; reversals are shown, so the
     * history adds up to the paid figure.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function payments(Party $party, ?int $workOrderId = null): Collection
    {
        return ContractorPayment::counted()
            ->with('workOrder', 'project', 'boqItem', 'milestone')
            ->where('party_id', $party->id)
            ->whereIn('work_order_id', $this->workOrderIds($party))
            ->when($workOrderId, fn ($query) => $query->where('work_order_id', $workOrderId))
            ->latest('paid_on')
            ->latest('id')
            ->get()
            ->map($this->payment(...));
    }

    /**
     * Bills raised on this contractor's contracts that the company has approved or paid, newest first.
     *
     * @return Collection<int, RaBill>
     */
    public function bills(Party $party, ?int $workOrderId = null): Collection
    {
        return $this->billQuery($party)
            ->with('workOrder', 'project')
            ->when($workOrderId, fn ($query) => $query->where('work_order_id', $workOrderId))
            ->latest('period_to')
            ->latest('id')
            ->get();
    }

    /** One bill with its lines and deductions. Anyone else's bill, or one still in the company's hands, is not found. */
    public function bill(Party $party, RaBill $bill): RaBill
    {
        abort_unless($this->billQuery($party)->whereKey($bill->id)->exists(), 404);

        return $bill->load('items', 'deductions', 'workOrder', 'project', 'party', 'organization');
    }

    /** @return array<string, mixed> The fields of a bill that go out through the API. */
    public function billFields(RaBill $bill, bool $withLines = false): array
    {
        $fields = [
            'id' => $bill->id,
            'number' => $bill->number,
            'is_final' => (bool) $bill->is_final,
            'status' => $bill->status,
            'work_order_id' => $bill->work_order_id,
            'work_order_number' => $bill->workOrder?->number,
            'project' => $bill->project?->name,
            'period_from' => $bill->period_from?->toDateString(),
            'period_to' => $bill->period_to?->toDateString(),
            'gross_amount' => Dec::money($bill->gross_amount),
            'deduction_total' => Dec::money($bill->deduction_total),
            'gst_amount' => Dec::money($bill->gst_amount),
            'retention_released' => Dec::money($bill->retention_released ?? 0),
            'net_payable' => Dec::money($bill->net_payable),
            'paid_amount' => Dec::money($bill->paid_amount ?? 0),
            'paid_at' => $bill->paid_at?->toDateString(),
        ];

        if (! $withLines) {
            return $fields;
        }

        return [
            ...$fields,
            'items' => $bill->items->map(fn ($line) => [
                'code' => $line->code,
                'description' => $line->description,
                'unit' => $line->unit,
                'rate' => Dec::money($line->rate),
                'prev_qty' => Dec::qty($line->prev_qty),
                'current_qty' => Dec::qty($line->current_qty),
                'cumulative_qty' => Dec::qty($line->cumulative_qty),
                'current_amount' => Dec::money($line->current_amount),
                'cumulative_amount' => Dec::money($line->cumulative_amount),
            ])->values()->all(),
            'deductions' => $bill->deductions->map(fn ($deduction) => [
                'label' => $deduction->label,
                'pct' => $deduction->pct === null ? null : Dec::money($deduction->pct),
                'amount' => Dec::money($deduction->amount),
            ])->values()->all(),
        ];
    }

    private function workOrders(Party $party)
    {
        return WorkOrder::with('project')->where('party_id', $party->id)->latest('id');
    }

    /** A subquery of this contractor's contract ids, so a payment or bill is read through its contract as well as its party. */
    private function workOrderIds(Party $party)
    {
        return WorkOrder::where('party_id', $party->id)->select('id');
    }

    private function billQuery(Party $party)
    {
        return RaBill::where('type', 'subcontractor')
            ->where('party_id', $party->id)
            ->whereIn('work_order_id', $this->workOrderIds($party))
            ->whereIn('status', config('modules.contractor_portal.visible_bill_statuses', ['APPROVED', 'PAID']));
    }

    /**
     * The contractor's totals across every contract. The company's alert limit does not apply
     * here, so only the plain comparison of paid against work goes out.
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private function summary(Collection $rows): array
    {
        $rollup = $this->ratio->rollup($rows);

        return [
            ...$this->moneyFigures($rollup),
            'contracts' => $rows->count(),
            'contracts_ahead' => $rows->where('status', 'red')->count(),
        ];
    }

    /** @param  array<string, mixed>  $row  One row from WorkPaymentRatio. */
    private function contractRow(array $row, ?Carbon $today): array
    {
        /** @var WorkOrder $workOrder */
        $workOrder = $row['work_order'];
        $project = $row['project'];

        return [
            'work_order' => $workOrder,
            'project' => $project,
            'id' => $workOrder->id,
            'number' => $workOrder->number,
            'title' => $workOrder->title,
            'contract_status' => $workOrder->status,
            'project_name' => $project?->name,
            'project_code' => $project?->code,
            'start_date' => $workOrder->start_date?->toDateString(),
            ...$this->moneyFigures($row),
            ...$this->timing($workOrder, $project?->deadline_date, $row['work_pct'], $today),
        ];
    }

    /** The paid-against-work figures a contractor may see. Named one by one: nothing else from the ratio passes. */
    private function moneyFigures(array $row): array
    {
        return [
            'contract_value' => $row['contract_value'],
            'earned' => $row['earned'] ?? Dec::money(Dec::of($row['contract_value'])->minus(Dec::of($row['balance_work_value']))),
            'paid' => $row['paid'],
            'advance' => $row['advance'] ?? '0.00',
            'balance_money' => $row['balance_money'],
            'work_pct' => $row['work_pct'],
            'paid_pct' => $row['paid_pct'],
            'gap_pct' => $row['gap_pct'],
            'status' => $row['status'],
            'label' => $row['label'],
            'balance_work_pct' => $row['balance_work_pct'],
            'balance_work_value' => $row['balance_work_value'],
        ];
    }

    /** Days left against the contract's own date, or the project's, and how much work is left to do in them. */
    private function timing(WorkOrder $workOrder, mixed $projectDeadline, string $workPct, ?Carbon $today): array
    {
        $today = ($today ?? today())->copy()->startOfDay();
        $date = $workOrder->end_date ?? $projectDeadline;
        $deadline = $date ? Carbon::parse($date)->startOfDay() : null;
        $daysLeft = $deadline ? (int) $today->diffInDays($deadline, false) : null;
        $left = BigDecimal::of(100)->minus(Dec::of($workPct));
        $left = $left->isNegative() ? BigDecimal::zero() : $left;

        return [
            'deadline' => $deadline?->toDateString(),
            'deadline_label' => $workOrder->end_date ? $workOrder->deadline_label : null,
            'days_left' => $daysLeft,
            'time_state' => match (true) {
                $workOrder->isClosed() => 'closed',
                $left->isZero() => 'done',
                $daysLeft === null => 'no_date',
                $daysLeft < 0 => 'overdue',
                $daysLeft === 0 => 'due_today',
                default => 'on_track',
            },
        ];
    }

    /** @param  array{done: string, percent: string}  $figures */
    private function line(WorkOrderItem $line, array $figures): array
    {
        $qty = Dec::of($line->qty);
        $rate = Dec::of($line->rate);
        $done = Dec::of($figures['done']);

        return [
            'id' => $line->id,
            'boq_item_id' => $line->boq_item_id,
            'code' => $line->boqItem?->code,
            'description' => $line->boqItem?->description,
            'unit' => $line->boqItem?->unit,
            'qty' => Dec::qty($qty),
            'rate' => Dec::money($rate),
            'value' => Dec::money($qty->multipliedBy($rate)),
            'done_qty' => Dec::qty($done),
            'done_value' => Dec::money($done->multipliedBy($rate)),
            'balance_qty' => Dec::qty($qty->minus($done)),
            'work_pct' => $figures['percent'],
        ];
    }

    /** @param  array<string, mixed>  $row  One part from MilestoneService::track(). */
    private function part(array $row): array
    {
        return [
            'id' => $row['part']->id,
            'title' => $row['part']->title,
            'amount' => $row['amount'],
            'paid' => $row['paid'],
            'balance' => $row['balance'],
            'state' => $row['state'],
            'done' => $row['part']->isDone(),
            'done_on' => $row['part']->done_on?->toDateString(),
        ];
    }

    private function payment(ContractorPayment $payment): array
    {
        return [
            'id' => $payment->id,
            'number' => $payment->number,
            'paid_on' => $payment->paid_on?->toDateString(),
            'amount' => Dec::money($payment->amount),
            'mode' => $payment->mode,
            'mode_label' => __(config('modules.contractor_control.payment_modes.'.$payment->mode, $payment->mode)),
            'paid_to' => $payment->paid_to,
            'reference' => $payment->reference,
            'is_advance' => (bool) $payment->is_advance,
            'is_reversal' => $payment->isReversal(),
            'work_order_id' => $payment->work_order_id,
            'work_order_number' => $payment->workOrder?->number,
            'work_order_title' => $payment->workOrder?->title,
            'project' => $payment->project?->name,
            'against' => $payment->boqItem?->code ?? $payment->milestone?->title,
        ];
    }
}
