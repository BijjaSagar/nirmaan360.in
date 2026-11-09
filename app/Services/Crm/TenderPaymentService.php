<?php

namespace App\Services\Crm;

use App\Models\CrmTender;
use App\Models\CrmTenderPayment;
use App\Models\User;
use App\Support\Dec;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** EMD and fees paid for tenders, and tracking what comes back. All sums use exact decimals. */
class TenderPaymentService
{
    public function __construct(private ActivityService $activities) {}

    public function rules(): array
    {
        return [
            'kind' => ['required', Rule::in(CrmTenderPayment::KINDS)],
            'amount' => ['required', 'numeric', 'gt:0', 'max:9999999999999'],
            'mode' => ['required', Rule::in(CrmTenderPayment::MODES)],
            'reference' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', Rule::in(['planned', 'paid'])],
            'paid_on' => ['nullable', 'date', 'before_or_equal:today'],
            'valid_until' => ['nullable', 'date'],
            'refundable' => ['nullable', 'boolean'],
            'refund_expected_on' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /** Rules for marking money as refunded, forfeited or adjusted. */
    public function settleRules(): array
    {
        return [
            'outcome' => ['required', Rule::in(['refunded', 'forfeited', 'adjusted'])],
            'settled_on' => ['nullable', 'date', 'before_or_equal:today'],
            'refunded_amount' => ['nullable', 'numeric', 'min:0', 'max:9999999999999'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function record(CrmTender $tender, array $data, User $user): CrmTenderPayment
    {
        $status = $data['status'] ?? 'paid';

        $payment = CrmTenderPayment::create([
            ...$this->attributes($data),
            'organization_id' => $tender->organization_id,
            'tender_id' => $tender->id,
            'status' => $status,
            'paid_on' => $status === 'paid' ? ($data['paid_on'] ?? today()->toDateString()) : null,
            // EMD comes back by default. Fees do not.
            'refundable' => array_key_exists('refundable', $data) ? (bool) $data['refundable'] : ($data['kind'] === 'emd'),
            'created_by' => $user->id,
        ]);

        $this->activities->log($tender, 'system', __(':kind of :amount recorded', ['kind' => Vocab::paymentKinds()[$payment->kind], 'amount' => inr($payment->amount)]), $user);

        return $payment;
    }

    /** Edit a payment that has not been settled. Settled money is changed by undoing the settlement first. */
    public function update(CrmTenderPayment $payment, array $data): CrmTenderPayment
    {
        $this->assertNotSettled($payment);

        $status = $data['status'] ?? $payment->status;

        $payment->update([
            ...$this->attributes($data),
            'status' => $status,
            'paid_on' => $status === 'paid' ? ($data['paid_on'] ?? $payment->paid_on ?? today()->toDateString()) : null,
            'refundable' => (bool) ($data['refundable'] ?? false),
        ]);

        return $payment;
    }

    /**
     * Mark paid money as refunded (fully or in part), forfeited, or adjusted against a security deposit.
     * Whatever is not refunded counts as a cost.
     */
    public function settle(CrmTenderPayment $payment, string $outcome, array $data, User $user): CrmTenderPayment
    {
        if ($payment->status !== 'paid') {
            throw ValidationException::withMessages(['outcome' => __('Only a payment that has been made can be refunded, forfeited or adjusted.')]);
        }

        $values = ['status' => $outcome, 'settled_on' => $data['settled_on'] ?? today()->toDateString()];

        if ($outcome === 'refunded') {
            $refunded = Dec::of(filled($data['refunded_amount'] ?? null) ? $data['refunded_amount'] : $payment->amount);

            if ($refunded->isGreaterThan(Dec::of($payment->amount))) {
                throw ValidationException::withMessages(['refunded_amount' => __('The refund cannot be more than the amount paid (:amount).', ['amount' => inr($payment->amount)])]);
            }

            if (! $refunded->isPositive()) {
                throw ValidationException::withMessages(['refunded_amount' => __('Enter the amount that came back, or choose Forfeited.')]);
            }

            $values['refunded_amount'] = Dec::money($refunded);
        } elseif ($outcome === 'forfeited') {
            $values['refunded_amount'] = '0.00';
        }

        if (filled($data['notes'] ?? null)) {
            $values['notes'] = trim($payment->notes."\n".$data['notes']);
        }

        $payment->update($values);

        $labels = Vocab::paymentStatuses();
        $this->activities->log($payment->tender, 'system', __(':kind of :amount marked :status', ['kind' => Vocab::paymentKinds()[$payment->kind], 'amount' => inr($payment->amount), 'status' => mb_strtolower($labels[$outcome])]), $user);

        return $payment;
    }

    /** Put a settled payment back to Paid, for a mistake. The audit log keeps the history. */
    public function undoSettlement(CrmTenderPayment $payment, User $user): CrmTenderPayment
    {
        if (! in_array($payment->status, ['refunded', 'forfeited', 'adjusted'], true)) {
            throw ValidationException::withMessages(['outcome' => __('This payment has not been settled.')]);
        }

        $payment->update(['status' => 'paid', 'settled_on' => null, 'refunded_amount' => null]);
        $this->activities->log($payment->tender, 'system', __(':kind of :amount put back to paid', ['kind' => Vocab::paymentKinds()[$payment->kind], 'amount' => inr($payment->amount)]), $user);

        return $payment;
    }

    public function delete(CrmTenderPayment $payment): void
    {
        $this->assertNotSettled($payment);
        $payment->delete();
    }

    /** @return Builder<CrmTenderPayment> */
    public function query(int $organizationId, array $filters = []): Builder
    {
        $query = CrmTenderPayment::query()->with('tender')->whereHas('tender')->where('crm_tender_payments.organization_id', $organizationId);

        if (! empty($filters['kind'])) {
            $query->where('kind', $filters['kind']);
        }

        match ($filters['view'] ?? null) {
            'held' => $query->where('status', 'paid')->where('refundable', true),
            'refund_due' => $query->where('status', 'paid')->where('refundable', true)
                ->whereIn('tender_id', CrmTender::query()->where('organization_id', $organizationId)->whereIn('stage', ['lost', 'no_bid'])->select('id')),
            'settled' => $query->whereIn('status', ['refunded', 'forfeited', 'adjusted']),
            'planned' => $query->where('status', 'planned'),
            default => null,
        };

        return $query->orderByRaw('paid_on IS NULL')->orderByDesc('paid_on')->orderByDesc('id');
    }

    /**
     * Totals for a set of payments.
     *  held: refundable money still with the authority
     *  refund_due: the part of held that should be coming back because the tender is over
     *  refunded: money that came back
     *  cost: money that will not come back (fees, forfeited EMD, deductions from a refund)
     *  adjusted: money moved into a security deposit
     *
     * @param  Collection<int,CrmTenderPayment>  $payments  With the tender loaded.
     * @return array{held:string,refund_due:string,refunded:string,cost:string,adjusted:string,planned:string}
     */
    public function totals(Collection $payments): array
    {
        $held = $due = $refunded = $cost = $adjusted = $planned = Dec::of(0);

        foreach ($payments as $payment) {
            $amount = Dec::of($payment->amount);

            switch ($payment->status) {
                case 'planned':
                    $planned = $planned->plus($amount);
                    break;
                case 'paid':
                    if ($payment->refundable) {
                        $held = $held->plus($amount);
                        $due = $payment->refundIsDue() ? $due->plus($amount) : $due;
                    } else {
                        $cost = $cost->plus($amount);
                    }
                    break;
                case 'refunded':
                    $back = Dec::of($payment->refunded_amount);
                    $refunded = $refunded->plus($back);
                    $cost = $cost->plus($amount->minus($back));
                    break;
                case 'forfeited':
                    $cost = $cost->plus($amount);
                    break;
                case 'adjusted':
                    $adjusted = $adjusted->plus($amount);
                    break;
            }
        }

        return [
            'held' => Dec::money($held),
            'refund_due' => Dec::money($due),
            'refunded' => Dec::money($refunded),
            'cost' => Dec::money($cost),
            'adjusted' => Dec::money($adjusted),
            'planned' => Dec::money($planned),
        ];
    }

    private function assertNotSettled(CrmTenderPayment $payment): void
    {
        if (in_array($payment->status, ['refunded', 'forfeited', 'adjusted'], true)) {
            throw ValidationException::withMessages(['outcome' => __('This payment has been settled. Undo the settlement to change it.')]);
        }
    }

    private function attributes(array $data): array
    {
        return [
            'kind' => $data['kind'],
            'amount' => Dec::money($data['amount']),
            'mode' => $data['mode'],
            'reference' => filled($data['reference'] ?? null) ? $data['reference'] : null,
            'valid_until' => filled($data['valid_until'] ?? null) ? $data['valid_until'] : null,
            'refund_expected_on' => filled($data['refund_expected_on'] ?? null) ? $data['refund_expected_on'] : null,
            'notes' => filled($data['notes'] ?? null) ? $data['notes'] : null,
        ];
    }
}
