<?php

namespace App\Services\ContractorControl;

use App\Models\ContractorMilestone;
use App\Models\ContractorPayment;
use App\Models\User;
use App\Models\WorkOrder;
use App\Support\ClientUuidRepeat;
use App\Support\Dec;
use App\Support\Idempotent;
use Brick\Math\BigDecimal;
use Illuminate\Validation\ValidationException;

/** Parts of a contract: pay this much for each part, and track it against that part. */
class MilestoneService
{
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:160'],
            'amount' => ['required', 'numeric', 'gt:0', 'max:9999999999', 'decimal:0,2'],
            'client_uuid' => ['nullable', 'uuid'],
        ];
    }

    public function add(WorkOrder $workOrder, array $data, User $by): ContractorMilestone
    {
        $uuid = $data['client_uuid'] ?? null;
        $find = fn () => $uuid ? ClientUuidRepeat::reachable(ContractorMilestone::withoutGlobalScopes()->where('organization_id', $workOrder->organization_id)->where('client_uuid', $uuid)->first()) : null;

        if ($existing = $find()) {
            return $existing;
        }

        $parts = ContractorMilestone::where('work_order_id', $workOrder->id)->get(['amount', 'sort']);
        $total = $parts->reduce(fn (BigDecimal $sum, $part) => $sum->plus(Dec::of($part->amount)), Dec::of($data['amount']));

        if ($total->isGreaterThan(Dec::of($workOrder->totalValue()))) {
            throw ValidationException::withMessages(['amount' => __('The parts would total more than the contract value of :value.', ['value' => inr($workOrder->totalValue())])]);
        }

        return Idempotent::create(fn () => ContractorMilestone::create([
            'organization_id' => $workOrder->organization_id,
            'project_id' => $workOrder->project_id,
            'work_order_id' => $workOrder->id,
            'title' => $data['title'],
            'amount' => Dec::money($data['amount']),
            'sort' => (int) $parts->max('sort') + 1,
            'created_by' => $by->id,
            'client_uuid' => $uuid,
        ]), $find);
    }

    /** Mark a part finished, or undo that. */
    public function toggleDone(ContractorMilestone $milestone, User $by): ContractorMilestone
    {
        $milestone->update($milestone->isDone()
            ? ['done_on' => null, 'done_by' => null]
            : ['done_on' => today()->toDateString(), 'done_by' => $by->id]);

        return $milestone;
    }

    /** A part with any payment against it stays, so the history keeps its link. */
    public function remove(ContractorMilestone $milestone): void
    {
        if ($milestone->payments()->exists()) {
            throw ValidationException::withMessages(['milestone' => __('This part has payments against it and cannot be removed.')]);
        }

        $milestone->delete();
    }

    /**
     * Each part with what has been paid against it. Only approved payments count.
     *
     * @return array{parts: list<array<string, mixed>>, total: string, unallocated: string}
     */
    public function track(WorkOrder $workOrder): array
    {
        $parts = ContractorMilestone::where('work_order_id', $workOrder->id)->orderBy('sort')->orderBy('id')->get();

        $paid = ContractorPayment::counted()
            ->where('work_order_id', $workOrder->id)
            ->whereNotNull('contractor_milestone_id')
            ->get(['contractor_milestone_id', 'amount'])
            ->groupBy('contractor_milestone_id');

        $total = BigDecimal::zero();

        $rows = $parts->map(function (ContractorMilestone $part) use ($paid, &$total) {
            $amount = Dec::of($part->amount);
            $total = $total->plus($amount);
            $spent = $paid->get($part->id, collect())->reduce(fn (BigDecimal $sum, $row) => $sum->plus(Dec::of($row->amount)), BigDecimal::zero());

            return [
                'part' => $part,
                'amount' => Dec::money($amount),
                'paid' => Dec::money($spent),
                'balance' => Dec::money($amount->minus($spent)),
                'state' => match (true) {
                    $spent->isGreaterThan($amount) => 'over',
                    $spent->isEqualTo($amount) => 'paid',
                    $spent->isPositive() => 'part_paid',
                    default => 'not_paid',
                },
                'paid_before_done' => $spent->isPositive() && ! $part->isDone(),
            ];
        });

        return [
            'parts' => $rows->values()->all(),
            'total' => Dec::money($total),
            'unallocated' => Dec::money(Dec::of($workOrder->totalValue())->minus($total)),
        ];
    }
}
