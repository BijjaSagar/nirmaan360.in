<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    @include('layouts.partials.head')
    <title>{{ __('Payment') }} · {{ config('buildos.brand') }}</title>
</head>
<body class="grid min-h-screen place-items-center bg-paper p-6 font-sans text-ink antialiased">
<div class="card w-full max-w-md p-6 text-center">
    <p class="eyebrow text-ink-3">{{ __('Payment') }}</p>
    <p class="mt-2 font-display text-3xl font-semibold">{{ __(config("buildos.plans.{$payment->plan}.label")) }} · {{ __(ucfirst($payment->cycle)) }}</p>
    <dl class="mt-4 text-sm">
        <div class="flex justify-between border-b border-rule py-2"><dt>{{ __('Plan price') }}</dt><dd class="num">{{ inr($payment->base_amount) }}</dd></div>
        <div class="flex justify-between border-b border-rule py-2"><dt>{{ __('GST') }}</dt><dd class="num">{{ inr($payment->gst_amount) }}</dd></div>
        <div class="flex justify-between py-2 font-semibold"><dt>{{ __('Total') }}</dt><dd class="num">{{ inr($payment->total_amount) }}</dd></div>
    </dl>
    <button id="pay" type="button" class="btn btn-primary mt-4 w-full">{{ __('Pay now') }}</button>
    <a href="{{ route('billing.index') }}" class="link mt-3 block text-sm">{{ __('Back to billing') }}</a>
</div>

<form id="confirm" method="POST" action="{{ route('billing.confirm') }}">
    @csrf
    <input type="hidden" name="razorpay_order_id">
    <input type="hidden" name="razorpay_payment_id">
    <input type="hidden" name="razorpay_signature">
</form>

<script src="https://checkout.razorpay.com/v1/checkout.js"></script>
<script>
    const form = document.getElementById('confirm');
    const checkout = new Razorpay({
        ...@js([
            'key' => $keyId,
            'amount' => (int) $payment->paise(),
            'currency' => $payment->currency,
            'name' => config('buildos.brand'),
            'description' => config("buildos.plans.{$payment->plan}.label").' ('.$payment->cycle.')',
            'order_id' => $payment->gateway_order_id,
            'prefill' => ['name' => auth()->user()->name, 'email' => auth()->user()->email, 'contact' => auth()->user()->phone],
            'theme' => ['color' => '#b4451f'],
        ]),
        handler(response) {
            for (const field of ['razorpay_order_id', 'razorpay_payment_id', 'razorpay_signature']) {
                form.elements[field].value = response[field];
            }
            form.submit();
        },
    });
    document.getElementById('pay').addEventListener('click', () => checkout.open());
    checkout.open();
</script>
</body>
</html>
