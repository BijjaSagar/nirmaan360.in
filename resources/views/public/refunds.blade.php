@extends('layouts.public')
@section('content')
<article class="site-wrap max-w-3xl py-16 text-[17px] leading-relaxed text-ink-2 [&_h2]:mt-9 [&_h2]:text-[23px] [&_h2]:text-ink [&_p]:mt-3">
    <h1 class="text-[clamp(2.25rem,4.5vw,3.25rem)] text-ink">Refund and cancellation policy</h1>
    @if (filled(site_setting('refund_text')))
        {!! \Illuminate\Support\Str::markdown(site_setting('refund_text'), ['html_input' => 'strip', 'allow_unsafe_links' => false]) !!}
    @else
    @php($contactEmail = site_setting('contact_email'))
    <p>This policy covers payments for Nirmaan360 plans. Companies pay for their plan online through Razorpay.</p>

    <h2>Free trial</h2>
    <p>A new company can try a paid plan for {{ config('buildos.trial_days') }} days with no card needed. Nothing is charged during the trial. When it ends, the company moves to the free plan and its data stays in place.</p>

    <h2>Paying for a plan</h2>
    <p>Paid plans are prepaid monthly or yearly and are charged in Indian rupees {{ ['included' => 'including GST', 'none' => ''][config('buildos.subscription_gst_mode')] ?? 'plus GST' }} through Razorpay. A plan starts on your account as soon as the payment is confirmed.</p>

    <h2>Cancelling a plan</h2>
    <p>Plans do not renew automatically, so nothing is charged again unless you pay for the next period yourself. To stop, simply do not renew. Your plan stays active until the end of the period you paid for. If it is not renewed within {{ config('buildos.payment_grace_days') }} days after that, your company moves to the free plan and your data stays in place.</p>

    <h2>Refunds</h2>
    <p>Because every company can try a paid plan free before paying, a paid period that has started is not refunded, in full or in part. We refund the full amount when:</p>
    <ul class="mt-3 list-disc space-y-1 pl-6">
        <li>you were charged twice for the same period, or charged by mistake;</li>
        <li>a payment went through but your plan did not start, and we cannot put it right.</li>
    </ul>
    <p>To ask for a refund, write to us within 7 days of the payment with its date, amount and Razorpay payment ID, using our <a href="{{ route('contact', ['type' => 'support']) }}" class="link">contact form</a>@if (filled($contactEmail)) or at <a href="mailto:{{ $contactEmail }}" class="link">{{ $contactEmail }}</a>@endif. An approved refund goes back to the card, UPI or bank account you paid from, through Razorpay, within 5 to 7 working days.</p>

    <h2>Delivery of the service</h2>
    <p>Nirmaan360 is an online service and nothing is shipped. Once a payment is confirmed, the plan is available straight away on the website and in the Android and iPhone apps.</p>

    <h2>Questions</h2>
    <p>For anything about a payment or your plan, use our <a href="{{ route('contact', ['type' => 'support']) }}" class="link">contact form</a>. These terms are governed by the laws of India.</p>
    @endif
</article>
@endsection
