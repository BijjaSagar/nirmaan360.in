<?php

namespace App\Services\Finance;

use App\Models\AuditLog;
use App\Models\ClientReceipt;
use App\Models\RaBill;
use App\Models\User;
use App\Models\VendorBill;
use App\Models\VendorPayment;
use App\Support\Dec;
use Brick\Math\BigDecimal;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Part payments, receipts and what is still owed on each bill.
 *
 * A client's RA bill may already show TDS as a deduction, or the client may only deduct it
 * when paying. So a bill is settled against its value before TDS, by bank money plus the TDS
 * shown on each receipt. Either way the same receipts settle the bill exactly.
 *
 * On bills we pay, TDS is taken when the bill is booked, so the vendor is owed the net payable.
 */
class SettlementService
{
    /** RA bill statuses that count as billed. */
    public const BILLED = ['APPROVED', 'PAID'];

    public function paymentRules(): array
    {
        return [
            'paid_on' => ['required', 'date', 'before_or_equal:today'],
            'amount' => ['required', 'numeric', 'gt:0', 'max:9999999999', 'decimal:0,2'],
            'mode' => ['required', Rule::in(array_keys(config('modules.finance.payment_modes')))],
            'reference' => ['nullable', 'string', 'max:60'],
            'remarks' => ['nullable', 'string', 'max:500'],
            'client_uuid' => ['nullable', 'uuid'],
        ];
    }

    public function receiptRules(): array
    {
        return [
            'received_on' => ['required', 'date', 'before_or_equal:today'],
            'amount' => ['required', 'numeric', 'min:0', 'max:9999999999', 'decimal:0,2'],
            'tds_amount' => ['nullable', 'numeric', 'min:0', 'max:9999999999', 'decimal:0,2'],
            'mode' => ['required', Rule::in(array_keys(config('modules.finance.payment_modes')))],
            'reference' => ['nullable', 'string', 'max:60'],
            'remarks' => ['nullable', 'string', 'max:500'],
            'client_uuid' => ['nullable', 'uuid'],
        ];
    }

    /**
     * Client RA bills that count as billed, with their position.
     *
     * @return Collection<int, array{bill:RaBill, receivable:string, received:string, tds:string, settled:string, outstanding:string, cash_expected:string, retention:string}>
     */
    public function clientBills(int $organizationId, ?int $projectId = null): Collection
    {
        $bills = RaBill::withoutGlobalScopes()
            ->with('deductions', 'party', 'project')
            ->where('organization_id', $organizationId)
            ->where('type', 'client')
            ->whereIn('status', self::BILLED)
            ->when($projectId, fn ($q) => $q->where('project_id', $projectId))
            ->orderBy('id')
            ->get();

        $receipts = ClientReceipt::withoutGlobalScopes()
            ->where('organization_id', $organizationId)
            ->whereIn('ra_bill_id', $bills->pluck('id'))
            ->get()
            ->groupBy('ra_bill_id');

        return $bills->map(fn (RaBill $bill) => $this->clientPosition($bill, $receipts->get($bill->id, collect())))->values();
    }

    /**
     * Client RA bills the client still owes on, with their position: approved, not marked paid, and
     * with something outstanding after the receipts recorded on them.
     *
     * The RA bill page can mark a bill paid without a receipt. Such a bill is paid as far as the
     * company has said, so anything that asks the client for money or warns of a late payment leaves
     * it out, whatever its receipts add up to. The project ledger still counts it among the bills
     * marked paid without entries.
     *
     * @return Collection<int, array{bill:RaBill, receivable:string, received:string, tds:string, settled:string, outstanding:string, cash_expected:string, retention:string}>
     */
    public function clientBillsOwed(int $organizationId, ?int $projectId = null): Collection
    {
        return $this->clientBills($organizationId, $projectId)
            ->filter(fn (array $row) => $row['bill']->status !== 'PAID' && Dec::of($row['outstanding'])->isPositive())
            ->values();
    }

    /**
     * Vendor bills and subcontractor RA bills that count as billed, with their position.
     * Reversed bills stay in the list so a reversal cancels them in every total.
     *
     * @return Collection<int, array{kind:string, bill:VendorBill|RaBill, payable:string, paid:string, outstanding:string, open:bool, retention:string}>
     */
    public function payableBills(int $organizationId, ?int $projectId = null): Collection
    {
        $vendorBills = VendorBill::withoutGlobalScopes()
            ->with('party', 'project')
            ->where('organization_id', $organizationId)
            ->whereIn('status', VendorBill::POSTED)
            ->when($projectId, fn ($q) => $q->where('project_id', $projectId))
            ->orderBy('id')
            ->get();

        $subBills = RaBill::withoutGlobalScopes()
            ->with('deductions', 'party', 'project')
            ->where('organization_id', $organizationId)
            ->where('type', 'subcontractor')
            ->whereIn('status', self::BILLED)
            ->when($projectId, fn ($q) => $q->where('project_id', $projectId))
            ->orderBy('id')
            ->get();

        $payments = VendorPayment::withoutGlobalScopes()
            ->where('organization_id', $organizationId)
            ->when($projectId, fn ($q) => $q->where('project_id', $projectId))
            ->get();

        $byVendorBill = $payments->whereNotNull('vendor_bill_id')->groupBy('vendor_bill_id');
        $bySubBill = $payments->whereNotNull('ra_bill_id')->groupBy('ra_bill_id');

        return $vendorBills
            ->map(fn (VendorBill $bill) => $this->vendorPosition($bill, $byVendorBill->get($bill->id, collect())))
            ->concat($subBills->map(fn (RaBill $bill) => $this->subPosition($bill, $bySubBill->get($bill->id, collect()))))
            ->values();
    }

    /** Record money paid on a vendor bill. A repeated client_uuid returns the original row. */
    public function payVendorBill(VendorBill $bill, array $data, User $by): VendorPayment
    {
        return DB::transaction(function () use ($bill, $data, $by) {
            $bill = VendorBill::lockForUpdate()->findOrFail($bill->id);

            if ($existing = $this->repeatedPayment($bill->organization_id, $data)) {
                return $existing;
            }

            if (! in_array($bill->status, VendorBill::OPEN, true)) {
                throw ValidationException::withMessages(['amount' => __('Only an approved bill with a balance can be paid.')]);
            }

            $this->refuseOverPayment($data['amount'], $this->vendorPosition($bill, $bill->payments()->get())['outstanding']);
            $payment = $this->createPayment($bill, ['vendor_bill_id' => $bill->id], $data, $by);
            $this->syncVendorBill($bill);

            return $payment;
        });
    }

    /** Record money paid on a subcontractor RA bill. A repeated client_uuid returns the original row. */
    public function paySubcontractorBill(RaBill $bill, array $data, User $by): VendorPayment
    {
        return DB::transaction(function () use ($bill, $data, $by) {
            $bill = RaBill::lockForUpdate()->findOrFail($bill->id);

            if ($existing = $this->repeatedPayment($bill->organization_id, $data)) {
                return $existing;
            }

            if ($bill->type !== 'subcontractor' || ! in_array($bill->status, self::BILLED, true)) {
                throw ValidationException::withMessages(['amount' => __('Only an approved subcontractor bill can be paid.')]);
            }

            $this->refuseOverPayment($data['amount'], $this->subPosition($bill, $this->paymentsOnSubBill($bill))['outstanding']);
            $payment = $this->createPayment($bill, ['ra_bill_id' => $bill->id], $data, $by);
            $this->syncSubBill($bill);

            return $payment;
        });
    }

    /** Cancel a payment with an equal and opposite entry, and reopen its bill. */
    public function reversePayment(VendorPayment $payment, string $reason, User $by): VendorPayment
    {
        return DB::transaction(function () use ($payment, $reason, $by) {
            $locked = $payment->vendor_bill_id
                ? VendorBill::lockForUpdate()->findOrFail($payment->vendor_bill_id)
                : RaBill::lockForUpdate()->findOrFail($payment->ra_bill_id);

            $payment = VendorPayment::findOrFail($payment->id);
            $this->refuseSecondReversal($payment);

            $reversal = VendorPayment::create([
                ...collect($payment->getAttributes())->except(['id', 'amount', 'client_uuid', 'created_at', 'updated_at', 'remarks', 'created_by', 'paid_on'])->all(),
                'amount' => Dec::money(BigDecimal::zero()->minus(Dec::of($payment->amount))),
                'paid_on' => today()->toDateString(),
                'remarks' => $reason,
                'reversal_of_id' => $payment->id,
                'created_by' => $by->id,
            ]);

            $locked instanceof VendorBill ? $this->syncVendorBill($locked) : $this->syncSubBill($locked);
            AuditLog::record('vendor_payment.reversed', $payment, null, ['reversal_id' => $reversal->id, 'reason' => $reason]);

            return $reversal;
        });
    }

    /** Record money received on a client RA bill. A repeated client_uuid returns the original row. */
    public function recordReceipt(RaBill $bill, array $data, User $by): ClientReceipt
    {
        return DB::transaction(function () use ($bill, $data, $by) {
            $bill = RaBill::lockForUpdate()->findOrFail($bill->id);
            $uuid = $data['client_uuid'] ?? null;

            if ($uuid && ($existing = ClientReceipt::withoutGlobalScopes()->where('organization_id', $bill->organization_id)->where('client_uuid', $uuid)->first())) {
                return $existing;
            }

            if ($bill->type !== 'client' || ! in_array($bill->status, self::BILLED, true)) {
                throw ValidationException::withMessages(['amount' => __('Only an approved client bill can take a receipt.')]);
            }

            $amount = Dec::money($data['amount']);
            $tds = Dec::money($data['tds_amount'] ?? 0);
            $settling = Dec::of($amount)->plus(Dec::of($tds));

            if (! $settling->isPositive()) {
                throw ValidationException::withMessages(['amount' => __('Enter the amount received or the TDS deducted.')]);
            }

            $position = $this->clientPosition($bill, $this->receiptsOn($bill));

            if ($settling->isGreaterThan(Dec::of($position['outstanding']))) {
                throw ValidationException::withMessages(['amount' => __('Amount and TDS together are more than the :balance still due on this bill.', ['balance' => inr($position['outstanding'])])]);
            }

            $receipt = ClientReceipt::create([
                'organization_id' => $bill->organization_id,
                'project_id' => $bill->project_id,
                'party_id' => $bill->party_id,
                'ra_bill_id' => $bill->id,
                'received_on' => $data['received_on'],
                'amount' => $amount,
                'tds_amount' => $tds,
                'mode' => $data['mode'],
                'reference' => $data['reference'] ?? null,
                'remarks' => $data['remarks'] ?? null,
                'status' => 'POSTED',
                'created_by' => $by->id,
                'client_uuid' => $uuid,
            ]);

            $this->syncClientBill($bill);

            return $receipt;
        });
    }

    /** Cancel a receipt with an equal and opposite entry, and reopen its bill. */
    public function reverseReceipt(ClientReceipt $receipt, string $reason, User $by): ClientReceipt
    {
        return DB::transaction(function () use ($receipt, $reason, $by) {
            $bill = RaBill::lockForUpdate()->findOrFail($receipt->ra_bill_id);
            $receipt = ClientReceipt::findOrFail($receipt->id);
            $this->refuseSecondReversal($receipt);

            $reversal = ClientReceipt::create([
                ...collect($receipt->getAttributes())->except(['id', 'amount', 'tds_amount', 'client_uuid', 'created_at', 'updated_at', 'remarks', 'created_by', 'received_on'])->all(),
                'amount' => Dec::money(BigDecimal::zero()->minus(Dec::of($receipt->amount))),
                'tds_amount' => Dec::money(BigDecimal::zero()->minus(Dec::of($receipt->tds_amount))),
                'received_on' => today()->toDateString(),
                'remarks' => $reason,
                'reversal_of_id' => $receipt->id,
                'created_by' => $by->id,
            ]);

            $this->syncClientBill($bill);
            AuditLog::record('client_receipt.reversed', $receipt, null, ['reversal_id' => $reversal->id, 'reason' => $reason]);

            return $reversal;
        });
    }

    /** @param Collection<int, ClientReceipt> $receipts */
    private function clientPosition(RaBill $bill, Collection $receipts): array
    {
        $billTds = $this->deductionTotal($bill, 'tds');
        $received = $this->sum($receipts, 'amount');
        $tds = $this->sum($receipts, 'tds_amount');
        $receivable = Dec::of($bill->net_payable)->plus($billTds);
        $settled = $received->plus($tds);
        $outstanding = $receivable->minus($settled);
        $tdsStillDue = $billTds->minus($tds);
        $cashExpected = $tdsStillDue->isPositive() ? $outstanding->minus($tdsStillDue) : $outstanding;

        return [
            'bill' => $bill,
            'receivable' => Dec::money($receivable),
            'received' => Dec::money($received),
            'tds' => Dec::money($tds),
            'settled' => Dec::money($settled),
            'outstanding' => Dec::money($outstanding),
            'cash_expected' => Dec::money($cashExpected->isPositive() ? $cashExpected : 0),
            'retention' => Dec::money($this->deductionTotal($bill, 'retention')),
        ];
    }

    /** @param Collection<int, VendorPayment> $payments */
    private function vendorPosition(VendorBill $bill, Collection $payments): array
    {
        $paid = $this->sum($payments, 'amount');

        return [
            'kind' => 'vendor',
            'bill' => $bill,
            'payable' => Dec::money($bill->net_payable),
            'paid' => Dec::money($paid),
            'outstanding' => Dec::money(Dec::of($bill->net_payable)->minus($paid)),
            'open' => in_array($bill->status, VendorBill::OPEN, true),
            'retention' => '0.00',
        ];
    }

    /** @param Collection<int, VendorPayment> $payments */
    private function subPosition(RaBill $bill, Collection $payments): array
    {
        $paid = $this->sum($payments, 'amount');

        return [
            'kind' => 'subcontractor',
            'bill' => $bill,
            'payable' => Dec::money($bill->net_payable),
            'paid' => Dec::money($paid),
            'outstanding' => Dec::money(Dec::of($bill->net_payable)->minus($paid)),
            'open' => in_array($bill->status, self::BILLED, true),
            'retention' => Dec::money($this->deductionTotal($bill, 'retention')),
        ];
    }

    private function deductionTotal(RaBill $bill, string $type): BigDecimal
    {
        return $bill->deductions->where('type', $type)->reduce(fn (BigDecimal $sum, $row) => $sum->plus(Dec::of($row->amount)), BigDecimal::zero());
    }

    private function sum(Collection $rows, string $column): BigDecimal
    {
        return $rows->reduce(fn (BigDecimal $sum, $row) => $sum->plus(Dec::of($row->{$column})), BigDecimal::zero());
    }

    private function receiptsOn(RaBill $bill): Collection
    {
        return ClientReceipt::where('ra_bill_id', $bill->id)->get();
    }

    private function paymentsOnSubBill(RaBill $bill): Collection
    {
        return VendorPayment::where('ra_bill_id', $bill->id)->get();
    }

    private function repeatedPayment(int $organizationId, array $data): ?VendorPayment
    {
        $uuid = $data['client_uuid'] ?? null;

        return $uuid ? VendorPayment::withoutGlobalScopes()->where('organization_id', $organizationId)->where('client_uuid', $uuid)->first() : null;
    }

    private function refuseOverPayment(mixed $amount, string $outstanding): void
    {
        if (Dec::of(Dec::money($amount))->isGreaterThan(Dec::of($outstanding))) {
            throw ValidationException::withMessages(['amount' => __('This is more than the :balance still due on this bill.', ['balance' => inr($outstanding)])]);
        }
    }

    private function refuseSecondReversal(VendorPayment|ClientReceipt $entry): void
    {
        if ($entry->isReversal() || $entry->reversal()->exists()) {
            throw ValidationException::withMessages(['reason' => __('This entry is a reversal or has already been reversed.')]);
        }
    }

    private function createPayment(VendorBill|RaBill $bill, array $link, array $data, User $by): VendorPayment
    {
        return VendorPayment::create([
            ...$link,
            'organization_id' => $bill->organization_id,
            'project_id' => $bill->project_id,
            'party_id' => $bill->party_id,
            'paid_on' => $data['paid_on'],
            'amount' => Dec::money($data['amount']),
            'mode' => $data['mode'],
            'reference' => $data['reference'] ?? null,
            'remarks' => $data['remarks'] ?? null,
            'status' => 'POSTED',
            'created_by' => $by->id,
            'client_uuid' => $data['client_uuid'] ?? null,
        ]);
    }

    private function syncVendorBill(VendorBill $bill): void
    {
        $position = $this->vendorPosition($bill, $bill->payments()->get());
        $paid = Dec::of($position['paid']);

        $bill->update(match (true) {
            Dec::of($position['outstanding'])->isZero() => ['status' => 'PAID', 'paid_at' => $bill->paid_at ?? now()],
            $paid->isPositive() => ['status' => 'PART_PAID', 'paid_at' => null],
            default => ['status' => 'APPROVED', 'paid_at' => null],
        });
    }

    private function syncSubBill(RaBill $bill): void
    {
        $this->syncRaBill($bill, $this->subPosition($bill, $this->paymentsOnSubBill($bill))['outstanding']);
    }

    private function syncClientBill(RaBill $bill): void
    {
        $this->syncRaBill($bill, $this->clientPosition($bill, $this->receiptsOn($bill))['outstanding']);
    }

    /** RA bills have only APPROVED and PAID, so part payment shows in the figures, not the status. */
    private function syncRaBill(RaBill $bill, string $outstanding): void
    {
        $bill->update(Dec::of($outstanding)->isZero()
            ? ['status' => 'PAID', 'paid_at' => $bill->paid_at ?? now()]
            : ['status' => 'APPROVED', 'paid_at' => null]);
    }
}
