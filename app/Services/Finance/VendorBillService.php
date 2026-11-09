<?php

namespace App\Services\Finance;

use App\Models\Approval;
use App\Models\ApprovalRule;
use App\Models\AuditLog;
use App\Models\Party;
use App\Models\Project;
use App\Models\User;
use App\Models\VendorBill;
use App\Services\ApprovalService;
use App\Support\Dec;
use App\Support\Idempotent;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class VendorBillService
{
    public function __construct(private ApprovalService $approvals) {}

    public function rules(): array
    {
        return [
            'party_id' => ['bail', 'required', 'integer', org_exists('parties')->whereIn('type', ['vendor', 'subcontractor'])->whereNull('deleted_at')],
            'bill_no' => ['required', 'string', 'max:50'],
            'bill_date' => ['required', 'date', 'before_or_equal:today'],
            'due_date' => ['nullable', 'date', 'after_or_equal:bill_date'],
            'cost_head' => ['required', Rule::in(config('modules.finance.cost_heads'))],
            'description' => ['nullable', 'string', 'max:1000'],
            'supply_type' => ['required', Rule::in(['intra', 'inter'])],
            'taxable_amount' => ['required', 'numeric', 'gt:0', 'max:9999999999', 'decimal:0,2'],
            'gst_pct' => ['required', Rule::in(config('modules.finance.gst_rates'))],
            'tds_section' => ['nullable', Rule::requiredIf(fn () => Dec::of(is_numeric(request('tds_pct')) ? request('tds_pct') : 0)->isPositive()), Rule::in(config('modules.finance.tds_sections'))],
            'tds_pct' => ['nullable', 'numeric', 'min:0', 'max:30', 'decimal:0,2'],
            'purchase_order_id' => ['nullable', 'integer', 'min:1'],
            'grn_id' => ['nullable', 'integer', 'min:1'],
            'client_uuid' => ['nullable', 'uuid'],
        ];
    }

    /**
     * Work out the bill's money columns.
     * GST is charged on the taxable value, and TDS is taken on the taxable value too,
     * because GST shown separately on an invoice is outside TDS.
     *
     * @return array{taxable_amount:string, gst_pct:string, cgst_amount:string, sgst_amount:string, igst_amount:string, total_amount:string, tds_pct:string, tds_amount:string, net_payable:string}
     */
    public function compute(mixed $taxable, mixed $gstPct, string $supplyType, mixed $tdsPct): array
    {
        $tax = Dec::percentOf($taxable, $gstPct);
        $parts = Gst::split($tax, $supplyType === 'inter');
        $total = Dec::of(Dec::money($taxable))->plus(Dec::of($tax));
        $tds = Dec::percentOf($taxable, $tdsPct ?? 0);

        return [
            'taxable_amount' => Dec::money($taxable),
            'gst_pct' => Dec::money($gstPct),
            'cgst_amount' => $parts['cgst'],
            'sgst_amount' => $parts['sgst'],
            'igst_amount' => $parts['igst'],
            'total_amount' => Dec::money($total),
            'tds_pct' => Dec::money($tdsPct ?? 0),
            'tds_amount' => $tds,
            'net_payable' => Dec::money($total->minus(Dec::of($tds))),
        ];
    }

    /** Save a draft. A repeated client_uuid returns the original row. */
    public function create(Project $project, array $data, User $by): VendorBill
    {
        $uuid = $data['client_uuid'] ?? null;

        if ($uuid && ($existing = $this->findByClientUuid($project->organization_id, $uuid))) {
            return $existing;
        }

        return Idempotent::create(function () use ($project, $data, $by, $uuid) {
            $this->lockParty($data['party_id']);
            $this->refuseDuplicate($project->organization_id, $data['party_id'], $data['bill_no']);

            return VendorBill::create([
                ...$this->attributes($data),
                'organization_id' => $project->organization_id,
                'project_id' => $project->id,
                'status' => 'DRAFT',
                'created_by' => $by->id,
                'client_uuid' => $uuid,
            ]);
        }, fn () => $uuid ? $this->findByClientUuid($project->organization_id, $uuid) : null);
    }

    public function update(VendorBill $bill, array $data): VendorBill
    {
        return DB::transaction(function () use ($bill, $data) {
            $bill = VendorBill::lockForUpdate()->findOrFail($bill->id);

            if (! $bill->isEditable()) {
                throw ValidationException::withMessages(['status' => __('Only draft or returned bills can be changed.')]);
            }

            $this->lockParty($data['party_id']);
            $this->refuseDuplicate($bill->organization_id, $data['party_id'], $data['bill_no'], $bill->id);
            $bill->update($this->attributes($data));

            return $bill;
        });
    }

    /** Send a bill for approval. The company's chain is created the first time it is needed. */
    public function submit(VendorBill $bill, User $by): ?Approval
    {
        ApprovalRule::withoutGlobalScopes()->firstOrCreate(
            ['organization_id' => $bill->organization_id, 'doc_type' => 'vendor_bill'],
            ['levels' => config('modules.finance.vendor_bill_approval_levels'), 'active' => true],
        );

        return $this->approvals->submit($bill, $by);
    }

    public function cancel(VendorBill $bill): VendorBill
    {
        if (! $bill->isCancellable()) {
            throw ValidationException::withMessages(['status' => __('Only draft, returned or rejected bills can be cancelled.')]);
        }

        $bill->update(['status' => 'CANCELLED']);
        AuditLog::record('vendor_bill.cancelled', $bill);

        return $bill;
    }

    /** Undo an approved bill with an equal and opposite entry. Any payments must be reversed first. */
    public function reverse(VendorBill $bill, string $reason, User $by): VendorBill
    {
        return DB::transaction(function () use ($bill, $reason, $by) {
            $bill = VendorBill::lockForUpdate()->findOrFail($bill->id);

            if ($bill->status !== 'APPROVED') {
                throw ValidationException::withMessages(['status' => __('Only an approved bill with no payments can be reversed. Reverse its payments first.')]);
            }

            $money = ['taxable_amount', 'cgst_amount', 'sgst_amount', 'igst_amount', 'total_amount', 'tds_amount', 'net_payable'];

            $reversal = VendorBill::create([
                ...collect($bill->getAttributes())->except(['id', 'status', 'client_uuid', 'created_at', 'updated_at', 'paid_at', 'approved_at', 'bill_no', 'bill_date', 'due_date', 'remarks', 'created_by', ...$money])->all(),
                ...collect($money)->mapWithKeys(fn ($column) => [$column => Dec::money(BigDecimal::zero()->minus(Dec::of($bill->{$column})))])->all(),
                'bill_no' => mb_substr($bill->bill_no, 0, 56).'-REV',
                // Dated today, so a period already reported to the CA is left as it was.
                'bill_date' => today()->toDateString(),
                'status' => 'REVERSAL',
                'reversal_of_id' => $bill->id,
                'remarks' => $reason,
                'approved_at' => now(),
                'created_by' => $by->id,
            ]);

            $bill->update(['status' => 'REVERSED']);
            AuditLog::record('vendor_bill.reversed', $bill, null, ['reversal_id' => $reversal->id, 'reason' => $reason]);

            return $reversal;
        });
    }

    private function attributes(array $data): array
    {
        return [
            'party_id' => $data['party_id'],
            'bill_no' => trim($data['bill_no']),
            'bill_date' => $data['bill_date'],
            'due_date' => $data['due_date'] ?? null,
            'cost_head' => $data['cost_head'],
            'description' => $data['description'] ?? null,
            'supply_type' => $data['supply_type'],
            'tds_section' => $data['tds_section'] ?? null,
            'purchase_order_id' => $data['purchase_order_id'] ?? null,
            'grn_id' => $data['grn_id'] ?? null,
            ...$this->compute($data['taxable_amount'], $data['gst_pct'], $data['supply_type'], $data['tds_pct'] ?? 0),
        ];
    }

    /** Serialises saves for one vendor, so the duplicate check below cannot be raced. */
    private function lockParty(int|string $partyId): void
    {
        Party::lockForUpdate()->findOrFail($partyId);
    }

    /** The same vendor invoice cannot be booked twice. A cancelled or rejected one can be entered again. */
    private function refuseDuplicate(int $organizationId, int|string $partyId, string $billNo, ?int $ignoreId = null): void
    {
        $taken = VendorBill::withoutGlobalScopes()
            ->where('organization_id', $organizationId)
            ->where('party_id', $partyId)
            ->where('bill_no', trim($billNo))
            ->whereNotIn('status', ['CANCELLED', 'REJECTED'])
            ->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))
            ->exists();

        if ($taken) {
            throw ValidationException::withMessages(['bill_no' => __('This vendor bill number is already entered.')]);
        }
    }

    private function findByClientUuid(int $organizationId, string $uuid): ?VendorBill
    {
        return VendorBill::withoutGlobalScopes()->where('organization_id', $organizationId)->where('client_uuid', $uuid)->first();
    }
}
