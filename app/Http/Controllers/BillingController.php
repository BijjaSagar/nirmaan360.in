<?php

namespace App\Http\Controllers;

use App\Models\BillingPayment;
use App\Services\BillingService;
use App\Services\RazorpayGateway;
use App\Services\Seo\Analytics;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use RuntimeException;

class BillingController extends Controller
{
    public function __construct(private BillingService $billing, private RazorpayGateway $gateway) {}

    public function index(Request $request): View
    {
        $organization = $request->user()->organization;

        return view('billing.index', [
            'organization' => $organization,
            'plans' => config('buildos.plans'),
            'payments' => $organization->payments()->where('status', 'paid')->latest('paid_at')->take(12)->get(),
            'gatewayLive' => $this->gateway->isLive(),
        ]);
    }

    public function checkout(Request $request): View|RedirectResponse
    {
        $data = $request->validate([
            'plan' => ['required', Rule::in(array_keys(array_filter(config('buildos.plans'), fn ($plan) => $plan['buyable'])))],
            'cycle' => ['required', Rule::in(['monthly', 'yearly'])],
        ]);

        if (! $this->gateway->isLive()) {
            return back()->withErrors(['plan' => __('Online payment is not switched on yet.')]);
        }

        try {
            $payment = $this->billing->startCheckout($request->user()->organization, $request->user(), $data['plan'], $data['cycle']);
        } catch (RuntimeException $exception) {
            Log::error('Checkout could not start', ['error' => $exception->getMessage()]);

            return back()->withErrors(['plan' => __('We could not reach the payment service. Please try again.')]);
        }

        // Which plan and period, never the amount.
        app(Analytics::class)->record($request, 'begin_checkout', ['plan' => $data['plan'], 'cycle' => $data['cycle']]);

        return view('billing.checkout', ['payment' => $payment, 'keyId' => $this->gateway->keyId()]);
    }

    public function confirm(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'razorpay_order_id' => ['required', 'string', 'max:64'],
            'razorpay_payment_id' => ['required', 'string', 'max:64'],
            'razorpay_signature' => ['required', 'string', 'max:128'],
        ]);

        $payment = BillingPayment::where('gateway_order_id', $data['razorpay_order_id'])->firstOrFail();
        $payment = $this->billing->confirmCheckout($payment, $data['razorpay_payment_id'], $data['razorpay_signature']);

        if ($payment->isRefundDue()) {
            return redirect()->route('billing.index')->with('success', __('Payment received, but that plan is no longer offered, so your plan has not changed. We will contact you about a refund.'));
        }

        return redirect()->route('billing.index')->with('success', __('Payment received. Your plan is active.'));
    }
}
