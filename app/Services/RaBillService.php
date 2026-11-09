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
use App\Support\Dec;
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
        if ($type === 'subcontractor' && ! $workOrder) {
            throw ValidationException::withMessages(['work_order_id' => __('Choose a work order for a subcontractor bill.')]);
        }

        return DB::transaction(function () use ($project, $type, $from, $to, $user, $workOrder, $materialRecovery, $withGst) {
            $flag = $type === 'client' ? 'billed_client_bill_id' : 'billed_sub_bill_id';

            $entries = MeasurementEntry::with('boqItem')
                ->where('project_id', $project->id)
                ->where('status', 'APPROVED')
                ->whereDate('measured_on', '<=', $to)
                ->whereNull($flag)
                ->when($workOrder, fn ($q) => $q->where('work_order_id', $workOrder->id))
                ->lockForUpdate()
                ->get();

            if ($entries->isEmpty()) {
                throw ValidationException::withMessages(['period_to' => __('No approved, unbilled measurements up to this date.')]);
            }

            $rates = $workOrder?->items()->pluck('rate', 'boq_item_id');
            $missing = $workOrder
                ? $entries->pluck('boqItem')->unique('id')->reject(fn ($item) => $rates->has($item->id))
                : collect();

            if ($missing->isNotEmpty()) {
                throw ValidationException::withMessages([
                    'work_order_id' => __('These BOQ items have no rate on the work order: :codes', ['codes' => $missing->pluck('code')->join(', ')]),
                ]);
            }

            $priorBills = RaBill::where('project_id', $project->id)
                ->where('type', $type)
                ->where('status', '!=', 'CANCELLED')
                ->when($workOrder, fn ($q) => $q->where('work_order_id', $workOrder->id))
                ->pluck('id');

            $lines = [];
            $gross = Dec::of(0);

            foreach ($entries->groupBy('boq_item_id') as $boqItemId => $group) {
                $item = $group->first()->boqItem;
                $rate = $workOrder ? $rates[$boqItemId] : $item->rate;

                $current = $group->reduce(fn ($sum, $entry) => $sum->plus(Dec::of($entry->qty)), Dec::of(0));
                $prior = RaBillItem::whereIn('ra_bill_id', $priorBills)->where('boq_item_id', $boqItemId);
                $prevQty = Dec::of($prior->clone()->sum('current_qty'));
                $prevAmount = Dec::of($prior->clone()->sum('current_amount'));
                $currentAmount = Dec::of(Dec::money($current->multipliedBy(Dec::of($rate))));

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
            $terms['material_recovery'] = Dec::money($materialRecovery);

            if (! $withGst) {
                $terms['gst_pct'] = '0.00';
            }

            $terms['advance_balance'] = Dec::money(
                Dec::of($terms['advance_amount'])->minus(Dec::of(
                    RaBillDeduction::whereIn('ra_bill_id', $priorBills)->where('type', 'advance')->sum('amount')
                ))
            );

            $result = $this->engine->compute($gross, $terms);

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
                'number' => $this->billNumber($project, $type, $billNo, $workOrder),
                'period_from' => $from,
                'period_to' => $to,
                'status' => 'DRAFT',
                'gross_amount' => Dec::money($gross),
                'deduction_total' => $result['deduction_total'],
                'gst_amount' => $result['gst_amount'],
                'net_payable' => $result['net_payable'],
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

        $bill->update(['status' => 'PAID', 'paid_at' => now()]);

        return $bill;
    }

    private function billNumber(Project $project, string $type, int $billNo, ?WorkOrder $workOrder): string
    {
        $prefix = $type === 'client' ? 'RA' : 'SB-'.$workOrder->id;

        return sprintf('%s-%s-%03d', $prefix, $project->code, $billNo);
    }
}
