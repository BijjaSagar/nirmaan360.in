<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\MeasurementEntry;
use App\Models\Project;
use App\Models\RaBill;
use App\Models\RaBillDeduction;
use App\Models\RaBillItem;
use App\Models\User;
use App\Models\WorkOrder;
use App\Models\WorkOrderItem;
use App\Support\Dec;
use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RaBillService
{
    public function __construct(private DeductionEngine $engine) {}

    /**
     * Build a draft RA bill from approved, unbilled measurements up to the period end.
     * Client bills use BOQ rates. Subcontractor bills use the work order's rates.
     */
    public function generate(
        Project $project,
        string $type,
        string $from,
        string $to,
        User $user,
        ?WorkOrder $workOrder = null,
        mixed $materialRecovery = 0,
        bool $withGst = true,
    ): RaBill {
        return $this->build($project, $type, $from, $to, $user, $workOrder, $materialRecovery, $withGst, final: false);
    }

    /**
     * Build the draft final bill for a contract: any work not yet billed, the whole of the
     * retention held, and every rupee of advance still unrecovered. No further RA bill can
     * be raised while it stands, and approving it closes the contract.
     */
    public function generateFinal(
        Project $project,
        string $type,
        string $from,
        string $to,
        User $user,
        ?WorkOrder $workOrder = null,
        mixed $materialRecovery = 0,
        bool $withGst = true,
    ): RaBill {
        return $this->build($project, $type, $from, $to, $user, $workOrder, $materialRecovery, $withGst, final: true);
    }

    private function build(
        Project $project,
        string $type,
        string $from,
        string $to,
        User $user,
        ?WorkOrder $workOrder,
        mixed $materialRecovery,
        bool $withGst,
        bool $final,
    ): RaBill {
        if ($type === 'subcontractor' && ! $workOrder) {
            throw ValidationException::withMessages(['work_order_id' => __('Choose a work order for a subcontractor bill.')]);
        }

        if ($workOrder?->rate_type === 'labour_only' && Dec::of($materialRecovery)->isPositive()) {
            throw ValidationException::withMessages(['material_recovery' => __('A labour-only work order uses material issued free, so it has no material recovery. Use a debit note for excess wastage.')]);
        }

        return DB::transaction(function () use ($project, $type, $from, $to, $user, $workOrder, $materialRecovery, $withGst, $final) {
            // Serialises bills on one contract, so an RA bill and a final bill cannot race.
            $workOrder = $workOrder ? WorkOrder::lockForUpdate()->findOrFail($workOrder->id) : null;
            $project = Project::lockForUpdate()->findOrFail($project->id);

            $this->assertContractOpen($project, $type, $workOrder);

            $flag = $type === 'client' ? 'billed_client_bill_id' : 'billed_sub_bill_id';

            if ($final) {
                $this->assertReadyForFinalBill($project, $type, $to, $workOrder, $flag);
            }

            $entries = MeasurementEntry::with('boqItem')
                ->where('project_id', $project->id)
                ->where('status', 'APPROVED')
                ->whereDate('measured_on', '<=', $to)
                ->whereNull($flag)
                ->when($workOrder, fn ($q) => $q->where('work_order_id', $workOrder->id))
                ->lockForUpdate()
                ->get();

            if ($entries->isEmpty() && ! $final) {
                throw ValidationException::withMessages(['period_to' => __('No approved, unbilled measurements up to this date.')]);
            }

            $orderLines = $workOrder?->items()->get()->keyBy('boq_item_id');
            $missing = $workOrder
                ? $entries->pluck('boqItem')->unique('id')->reject(fn ($item) => $orderLines->has($item->id))
                : collect();

            if ($missing->isNotEmpty()) {
                throw ValidationException::withMessages([
                    'work_order_id' => __('These BOQ items have no rate on the work order: :codes', ['codes' => $missing->pluck('code')->join(', ')]),
                ]);
            }

            $this->assertLumpSumLinesHaveValue($workOrder, $orderLines, $entries);

            $priorBills = RaBill::where('project_id', $project->id)
                ->where('type', $type)
                ->where('status', '!=', 'CANCELLED')
                ->when($workOrder, fn ($q) => $q->where('work_order_id', $workOrder->id))
                ->pluck('id');

            if ($final && $entries->isEmpty() && $priorBills->isEmpty()) {
                throw ValidationException::withMessages(['period_to' => __('Nothing has been billed on this contract yet, so there is nothing to finalise.')]);
            }

            $lines = [];
            $gross = Dec::of(0);

            foreach ($entries->groupBy('boq_item_id') as $boqItemId => $group) {
                $item = $group->first()->boqItem;
                $rate = $workOrder ? $orderLines[$boqItemId]->rate : $item->rate;

                $current = $group->reduce(fn ($sum, $entry) => $sum->plus(Dec::of($entry->qty)), Dec::of(0));
                $prior = RaBillItem::whereIn('ra_bill_id', $priorBills)->where('boq_item_id', $boqItemId);
                $prevQty = Dec::of($prior->clone()->sum('current_qty'));
                $prevAmount = Dec::of($prior->clone()->sum('current_amount'));
                $currentAmount = $this->currentAmount($workOrder, $orderLines?->get($boqItemId), Dec::of($rate), $current, $prevQty, $prevAmount);

                $lines[] = [
                    'boq_item_id' => $boqItemId,
                    'code' => $item->code,
                    'description' => $item->description,
                    'unit' => $item->unit,
                    'rate' => $rate,
                    'prev_qty' => Dec::qty($prevQty),
                    'current_qty' => Dec::qty($current),
                    'cumulative_qty' => Dec::qty($prevQty->plus($current)),
                    'prev_amount' => Dec::money($prevAmount),
                    'current_amount' => Dec::money($currentAmount),
                    'cumulative_amount' => Dec::money($prevAmount->plus($currentAmount)),
                ];

                $gross = $gross->plus($currentAmount);
            }

            $terms = ($workOrder ?? $project)->billingTerms();
            $terms['rate_type'] = $workOrder?->rate_type;
            $terms['material_recovery'] = Dec::money($materialRecovery);

            if (! $withGst) {
                $terms['gst_pct'] = '0.00';
            }

            $terms['advance_balance'] = Dec::money(
                Dec::of($terms['advance_amount'])->minus(Dec::of(
                    RaBillDeduction::whereIn('ra_bill_id', $priorBills)->where('type', 'advance')->sum('amount')
                ))
            );

            if ($final) {
                // Nothing is held back from the last bill: retention stops and the advance is settled in full.
                $terms['retention_pct'] = '0.00';
                $terms['recover_full_advance'] = true;
            }

            $result = $this->engine->compute($gross, $terms);
            $released = $final ? $this->retentionHeld($priorBills) : '0.00';

            $billNo = (int) RaBill::where('project_id', $project->id)
                ->where('type', $type)
                ->when($workOrder, fn ($q) => $q->where('work_order_id', $workOrder->id))
                ->max('bill_no') + 1;

            $bill = RaBill::create([
                'organization_id' => $project->organization_id,
                'project_id' => $project->id,
                'work_order_id' => $workOrder?->id,
                'party_id' => $workOrder?->party_id ?? $project->client_id,
                'type' => $type,
                'bill_no' => $billNo,
                'number' => $this->billNumber($project, $type, $billNo, $workOrder, $final),
                'period_from' => $from,
                'period_to' => $to,
                'status' => 'DRAFT',
                'gross_amount' => Dec::money($gross),
                'deduction_total' => $result['deduction_total'],
                'gst_amount' => $result['gst_amount'],
                'net_payable' => Dec::money(Dec::of($result['net_payable'])->plus(Dec::of($released))),
                'retention_released' => $released,
                'is_final' => $final,
                'terms' => $terms,
                'created_by' => $user->id,
            ]);

            foreach ($lines as $line) {
                $bill->items()->create([...$line, 'organization_id' => $bill->organization_id]);
            }

            foreach ($result['deductions'] as $sort => $deduction) {
                $bill->deductions()->create([...$deduction, 'sort' => $sort, 'organization_id' => $bill->organization_id]);
            }

            // Mark only still-unbilled rows, so a measurement can never reach two bills.
            $marked = MeasurementEntry::whereIn('id', $entries->pluck('id'))->whereNull($flag)->update([$flag => $bill->id]);

            if ($marked !== $entries->count()) {
                throw ValidationException::withMessages(['period_to' => __('Some measurements were billed by someone else. Please try again.')]);
            }

            return $bill;
        });
    }

    /** Cancel an unapproved bill and free its measurements for a later bill. */
    public function cancel(RaBill $bill): RaBill
    {
        if (! $bill->isCancellable()) {
            throw ValidationException::withMessages(['status' => __('Only draft, returned or rejected bills can be cancelled.')]);
        }

        return DB::transaction(function () use ($bill) {
            $flag = $bill->type === 'client' ? 'billed_client_bill_id' : 'billed_sub_bill_id';

            MeasurementEntry::where($flag, $bill->id)->update([$flag => null]);
            $bill->update(['status' => 'CANCELLED']);
            AuditLog::record('ra_bill.cancelled', $bill);

            return $bill;
        });
    }

    public function markPaid(RaBill $bill): RaBill
    {
        if ($bill->status !== 'APPROVED') {
            throw ValidationException::withMessages(['status' => __('Only approved bills can be marked paid.')]);
        }

        $bill->update(['status' => 'PAID', 'paid_at' => now(), 'paid_amount' => $bill->adjustedNet()]);

        return $bill;
    }

    /** A contract stops taking RA bills as soon as a final bill exists. Cancelling that final bill reopens it. */
    private function assertContractOpen(Project $project, string $type, ?WorkOrder $workOrder): void
    {
        $field = $workOrder ? 'work_order_id' : 'type';

        $finalBill = $this->billsOn($project, $type, $workOrder)
            ->where('is_final', true)
            ->where('status', '!=', 'CANCELLED')
            ->first();

        if ($finalBill) {
            throw ValidationException::withMessages([$field => __('Final bill :number already exists on this contract, so no further bills can be raised.', ['number' => $finalBill->number])]);
        }

        if ($workOrder?->isClosed()) {
            throw ValidationException::withMessages([$field => __('This work order is closed, so no further bills can be raised.')]);
        }
    }

    /** Anything still open would be stranded, or would change the figures, once the contract closes. */
    private function assertReadyForFinalBill(Project $project, string $type, string $to, ?WorkOrder $workOrder, string $flag): void
    {
        $field = $workOrder ? 'work_order_id' : 'type';

        $openBills = $this->billsOn($project, $type, $workOrder)
            ->whereIn('status', ['DRAFT', 'IN_REVIEW', 'RETURNED', 'REJECTED'])
            ->pluck('number');

        if ($openBills->isNotEmpty()) {
            throw ValidationException::withMessages([$field => __('Approve or cancel these bills before the final bill: :numbers', ['numbers' => $openBills->join(', ')])]);
        }

        $entries = MeasurementEntry::where('project_id', $project->id)
            ->when($workOrder, fn ($q) => $q->where('work_order_id', $workOrder->id));

        $unsettled = $entries->clone()->whereIn('status', ['DRAFT', 'IN_REVIEW', 'RETURNED'])->count();

        if ($unsettled > 0) {
            throw ValidationException::withMessages([$field => __(':count measurements are still in draft or review. Approve or delete them before the final bill.', ['count' => $unsettled])]);
        }

        $later = $entries->clone()->where('status', 'APPROVED')->whereNull($flag)->whereDate('measured_on', '>', $to)->count();

        if ($later > 0) {
            throw ValidationException::withMessages(['period_to' => __(':count approved measurements are dated after this period. Choose a later end date so they are billed.', ['count' => $later])]);
        }
    }

    /** A lump sum line is worth quantity × rate. Without a quantity it would bill nothing, silently. */
    private function assertLumpSumLinesHaveValue(?WorkOrder $workOrder, ?Collection $orderLines, Collection $entries): void
    {
        if ($workOrder?->rate_type !== 'lump_sum') {
            return;
        }

        $unvalued = $entries->pluck('boqItem')->unique('id')
            ->filter(fn ($item) => Dec::of($orderLines[$item->id]->qty)->isNegativeOrZero());

        if ($unvalued->isNotEmpty()) {
            throw ValidationException::withMessages([
                'work_order_id' => __('These lump sum items have no quantity on the work order, so their value is unknown: :codes', ['codes' => $unvalued->pluck('code')->join(', ')]),
            ]);
        }
    }

    /**
     * Item rate and labour-only pay for what was measured. A lump sum pays for measured
     * progress only up to the line's fixed value, however much is measured beyond it.
     */
    private function currentAmount(?WorkOrder $workOrder, ?WorkOrderItem $orderLine, BigDecimal $rate, BigDecimal $current, BigDecimal $prevQty, BigDecimal $prevAmount): BigDecimal
    {
        if ($workOrder?->rate_type !== 'lump_sum') {
            return Dec::of(Dec::money($current->multipliedBy($rate)));
        }

        $value = Dec::of(Dec::money(Dec::of($orderLine->qty)->multipliedBy($rate)));
        $earned = Dec::of(Dec::money($prevQty->plus($current)->multipliedBy($rate)));

        return ($earned->isGreaterThan($value) ? $value : $earned)->minus($prevAmount);
    }

    private function retentionHeld(Collection $billIds): string
    {
        $held = BigDecimal::zero();

        foreach (RaBillDeduction::whereIn('ra_bill_id', $billIds)->where('type', 'retention')->pluck('amount') as $amount) {
            $held = $held->plus(Dec::of($amount));
        }

        return Dec::money($held->isNegative() ? BigDecimal::zero() : $held);
    }

    private function billsOn(Project $project, string $type, ?WorkOrder $workOrder): Builder
    {
        return RaBill::where('project_id', $project->id)
            ->where('type', $type)
            ->when($workOrder, fn ($q) => $q->where('work_order_id', $workOrder->id));
    }

    private function billNumber(Project $project, string $type, int $billNo, ?WorkOrder $workOrder, bool $final = false): string
    {
        $prefix = $type === 'client'
            ? ($final ? 'FB' : 'RA')
            : ($final ? 'FSB-' : 'SB-').$workOrder->id;

        return sprintf('%s-%s-%03d', $prefix, $project->code, $billNo);
    }
}
