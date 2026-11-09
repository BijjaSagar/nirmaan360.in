<?php

namespace App\Services\Finance;

use App\Models\AuditLog;
use App\Models\GstInvoice;
use App\Models\Organization;
use App\Models\RaBill;
use App\Models\User;
use App\Support\Dec;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Tax invoices for approved client RA bills.
 *
 * The invoice carries empty IRN fields. Someone generates the IRN on the government's
 * e-invoice portal and records it here. This class never talks to that portal.
 */
class GstInvoiceService
{
    public function issueRules(): array
    {
        return [
            'invoice_date' => ['required', 'date', 'before_or_equal:today'],
            'sac' => ['required', 'regex:/^\d{4,8}$/'],
            'description' => ['required', 'string', 'max:200'],
        ];
    }

    public function irnRules(): array
    {
        return [
            'irn' => ['required', 'string', 'regex:/^[0-9a-fA-F]{64}$/'],
            'irn_ack_no' => ['required', 'string', 'max:20'],
            'irn_ack_date' => ['required', 'date'],
        ];
    }

    /** Issue the invoice. If this bill already has one, return it instead of making a second. */
    public function issue(RaBill $bill, array $data, User $by): GstInvoice
    {
        return DB::transaction(function () use ($bill, $data, $by) {
            $bill = RaBill::lockForUpdate()->findOrFail($bill->id);

            if ($existing = GstInvoice::where('ra_bill_id', $bill->id)->where('status', 'ISSUED')->first()) {
                return $existing;
            }

            if ($bill->type !== 'client' || ! in_array($bill->status, SettlementService::BILLED, true)) {
                throw ValidationException::withMessages(['invoice' => __('Only an approved client bill can have a tax invoice.')]);
            }

            if (! Dec::of($bill->gst_amount)->isPositive()) {
                throw ValidationException::withMessages(['invoice' => __('This bill was made without GST, so it cannot have a tax invoice.')]);
            }

            $organization = Organization::lockForUpdate()->findOrFail($bill->organization_id);
            $supplierCode = Gst::codeFromGstin($organization->gstin);

            if ($supplierCode === null || strlen((string) $organization->gstin) !== 15) {
                throw ValidationException::withMessages(['invoice' => __('Add a valid company GSTIN in Settings before issuing a tax invoice.')]);
            }

            $placeCode = Gst::stateCode($bill->project->state);

            if ($placeCode === null) {
                throw ValidationException::withMessages(['invoice' => __('Set the project state to a valid Indian state. It decides the place of supply.')]);
            }

            if (! $bill->party) {
                throw ValidationException::withMessages(['invoice' => __('This bill has no client. Add a client to the project first.')]);
            }

            $date = Carbon::parse($data['invoice_date']);
            $year = Gst::financialYear($date);
            $serial = (int) GstInvoice::withoutGlobalScopes()->where('organization_id', $organization->id)->where('financial_year', $year)->max('serial') + 1;
            $interState = $supplierCode !== $placeCode;
            $parts = Gst::split($bill->gst_amount, $interState);

            return GstInvoice::create([
                'organization_id' => $bill->organization_id,
                'project_id' => $bill->project_id,
                'ra_bill_id' => $bill->id,
                'party_id' => $bill->party_id,
                'financial_year' => $year,
                'serial' => $serial,
                'invoice_no' => $this->number($year, $serial),
                'invoice_date' => $date->toDateString(),
                'supplier_name' => $organization->name,
                'supplier_gstin' => strtoupper($organization->gstin),
                'supplier_address' => $organization->address,
                'buyer_name' => $bill->party->name,
                'buyer_gstin' => $bill->party->gstin ? strtoupper($bill->party->gstin) : null,
                'buyer_address' => $bill->party->address,
                'place_of_supply_code' => $placeCode,
                'place_of_supply' => Gst::STATES[$placeCode],
                'supply_type' => $interState ? 'inter' : 'intra',
                'sac' => $data['sac'],
                'description' => $data['description'],
                'taxable_amount' => Dec::money($bill->gross_amount),
                'gst_pct' => Dec::money($bill->terms['gst_pct'] ?? 0),
                'cgst_amount' => $parts['cgst'],
                'sgst_amount' => $parts['sgst'],
                'igst_amount' => $parts['igst'],
                'invoice_value' => Dec::money(Dec::of($bill->gross_amount)->plus(Dec::of($bill->gst_amount))),
                'status' => 'ISSUED',
                'created_by' => $by->id,
            ]);
        });
    }

    /** Record the IRN the e-invoice portal gave. Once recorded it cannot be replaced. */
    public function recordIrn(GstInvoice $invoice, array $data): GstInvoice
    {
        if ($invoice->status !== 'ISSUED') {
            throw ValidationException::withMessages(['irn' => __('A cancelled invoice cannot take an IRN.')]);
        }

        if ($invoice->irn !== null) {
            throw ValidationException::withMessages(['irn' => __('This invoice already has an IRN.')]);
        }

        $invoice->update([
            'irn' => strtolower($data['irn']),
            'irn_ack_no' => $data['irn_ack_no'],
            'irn_ack_date' => $data['irn_ack_date'],
        ]);

        return $invoice;
    }

    /** Cancel an invoice with no IRN. Its number stays used, and the RA bill can be invoiced again. */
    public function cancel(GstInvoice $invoice): GstInvoice
    {
        if ($invoice->status !== 'ISSUED') {
            throw ValidationException::withMessages(['invoice' => __('This invoice is already cancelled.')]);
        }

        if ($invoice->irn !== null) {
            throw ValidationException::withMessages(['invoice' => __('This invoice has an IRN. Cancel it on the e-invoice portal and raise a credit note with your CA.')]);
        }

        $invoice->update(['status' => 'CANCELLED', 'cancelled_at' => now()]);
        AuditLog::record('gst_invoice.cancelled', $invoice);

        return $invoice;
    }

    /** GST allows at most 16 characters: INV/26-27/00001 */
    private function number(string $year, int $serial): string
    {
        return sprintf('INV/%s/%05d', substr($year, 2), $serial);
    }
}
