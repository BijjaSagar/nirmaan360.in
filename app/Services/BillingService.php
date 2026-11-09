<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\BillingPayment;
use App\Models\Organization;
use App\Models\User;
use App\Support\Dec;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Prepaid monthly or yearly plans. Each period is a one-off Razorpay order,
 * so nothing renews or charges by itself.
 */
class BillingService
{
    public function __construct(private RazorpayGateway $gateway) {}

    /** @return array{base:string,gst:string,total:string} */
    public function quote(string $plan, string $cycle): array
    {
        $price = config("buildos.plans.{$plan}.{$cycle}");

        if (! config("buildos.plans.{$plan}.buyable") || $price === null) {
            throw ValidationException::withMessages(['plan' => __('This plan cannot be bought online.')]);
        }

        $price = Dec::money($price);
        $pct = config('buildos.subscription_gst_pct');

        return match (config('buildos.subscription_gst_mode')) {
            'none' => ['base' => $price, 'gst' => '0.00', 'total' => $price],
            'included' => $this->splitIncludedGst($price, $pct),
            default => $this->addGst($price, $pct),
        };
    }

    /** @return array{base:string,gst:string,total:string} */
    private function addGst(string $price, mixed $pct): array
    {
        $gst = Dec::percentOf($price, $pct);

        return ['base' => $price, 'gst' => $gst, 'total' => Dec::money(Dec::of($price)->plus(Dec::of($gst)))];
    }

    /**
     * GST is the remainder, so the two parts always add back to the price.
     *
     * @return array{base:string,gst:string,total:string}
     */
    private function splitIncludedGst(string $price, mixed $pct): array
    {
        $base = Dec::beforePercent($price, $pct);

        return ['base' => $base, 'gst' => Dec::money(Dec::of($price)->minus(Dec::of($base))), 'total' => $price];
    }

    public function startCheckout(Organization $organization, User $user, string $plan, string $cycle): BillingPayment
    {
        $quote = $this->quote($plan, $cycle);

        $payment = BillingPayment::create([
            'organization_id' => $organization->id,
            'user_id' => $user->id,
            'plan' => $plan,
            'cycle' => $cycle,
            'base_amount' => $quote['base'],
            'gst_amount' => $quote['gst'],
            'total_amount' => $quote['total'],
        ]);

        $order = $this->gateway->createOrder($payment->paise(), 'n360-'.$payment->id, [
            'organization_id' => (string) $organization->id,
            'plan' => $plan,
            'cycle' => $cycle,
        ]);

        $payment->update(['gateway_order_id' => $order['id']]);

        return $payment;
    }

    public function confirmCheckout(BillingPayment $payment, string $paymentId, string $signature): BillingPayment
    {
        if (! $this->gateway->checkoutSignatureIsValid((string) $payment->gateway_order_id, $paymentId, $signature)) {
            throw ValidationException::withMessages(['payment' => __('We could not verify this payment.')]);
        }

        return $this->markPaid($payment, $paymentId);
    }

    /** Safe to call twice: the browser and the webhook can both report one payment. */
    public function markPaid(BillingPayment $payment, string $paymentId): BillingPayment
    {
        return DB::transaction(function () use ($payment, $paymentId) {
            $payment = BillingPayment::withoutGlobalScopes()->lockForUpdate()->findOrFail($payment->id);

            if ($payment->status === 'paid') {
                return $payment;
            }

            $organization = Organization::lockForUpdate()->findOrFail($payment->organization_id);

            // Renewing the same plan adds to the time left. A plan change starts today.
            $start = $organization->plan === $payment->plan && $organization->paid_until?->isFuture()
                ? $organization->paid_until
                : now();

            $end = $payment->cycle === 'yearly' ? $start->copy()->addYearNoOverflow() : $start->copy()->addMonthNoOverflow();

            $payment->update([
                'status' => 'paid',
                'gateway_payment_id' => $paymentId,
                'paid_at' => now(),
                'period_end' => $end,
            ]);

            $organization->update([
                'plan' => $payment->plan,
                'plan_status' => 'active',
                'billing_cycle' => $payment->cycle,
                'paid_until' => $end,
                'trial_ends_at' => null,
            ]);

            AuditLog::record('billing.paid', $payment, null, ['plan' => $payment->plan, 'cycle' => $payment->cycle, 'total' => $payment->total_amount]);

            return $payment;
        });
    }
}
