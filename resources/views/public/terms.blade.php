@extends('layouts.public')
@section('content')
<article class="site-wrap max-w-3xl py-16 text-[17px] leading-relaxed text-ink-2 [&_h2]:mt-9 [&_h2]:text-[23px] [&_h2]:text-ink [&_p]:mt-3">
    <h1 class="text-[clamp(2.25rem,4.5vw,3.25rem)] text-ink">Terms of service</h1>
    @if (filled(site_setting('terms_text')))
        {!! \Illuminate\Support\Str::markdown(site_setting('terms_text'), ['html_input' => 'strip', 'allow_unsafe_links' => false]) !!}
    @else
    <p>By creating an account on Nirmaan360 you agree to these terms on behalf of yourself and your company.</p>

    <h2>Your account</h2>
    <p>You are responsible for your team's access, for keeping passwords safe, and for the accuracy of what you enter.</p>

    <h2>Your data</h2>
    <p>Your project records belong to you. You give us permission to store and process them only to provide the service.</p>

    <h2>Plans and payment</h2>
    <p>Paid plans are prepaid monthly or yearly and are charged in Indian rupees {{ ['included' => 'including GST', 'none' => ''][config('buildos.subscription_gst_mode')] ?? 'plus GST' }} through Razorpay. Plans do not renew automatically. If a paid period ends and is not renewed within {{ config('buildos.payment_grace_days') }} days, your company moves to the free plan and your data stays in place. Trials end after {{ config('buildos.trial_days') }} days and move to the free plan in the same way. When a payment is refunded is set out in our <a href="{{ route('refunds') }}" class="link">refund and cancellation policy</a>.</p>

    <h2>Check your figures</h2>
    <p>Nirmaan360 helps you prepare measurements, bills and calculations. You remain responsible for checking them against your contracts, drawings, codes and tax rules before you rely on them.</p>
    {{-- Readers are named only once a company can use one: the same test its own switch is shown by. Each form is one whole text, so Hindi and Marathi can say it in their own order. --}}
    {{-- The terms are in English. The Hindi and Marathi line is used only once a reader is offered, which the platform admin does after a native reader has checked it; until then this stays English too. --}}
    @if (app(\App\Services\DocumentReading\ReaderSettings::class)->offered())
    <p>{{ __('AI agents, AI readers and the other AI features can be wrong. What they prepare is saved as a draft or shown as a suggestion, and marked as such. Nothing is approved, posted to stock, billed to your client or sent outside your company until a person in your company confirms it.') }}</p>
    @else
    <p>AI agents can be wrong. What they prepare is saved as a draft and marked as one. Nothing is approved, posted to stock, billed to your client or sent outside your company until a person in your company confirms it.</p>
    @endif

    <h2>Acceptable use</h2>
    <p>Do not misuse the service, try to reach another company's data, or interfere with its operation. We may suspend accounts that do.</p>

    <h2>Availability</h2>
    <p>We work to keep the service running, but it is provided as is and may sometimes be unavailable. To the extent the law allows, we are not liable for indirect losses arising from its use.</p>

    <h2>Ending your account</h2>
    <p>You can stop using the service at any time. You can delete your account, or your company if you are its only owner, yourself in the app or on the website, as set out on our <a href="{{ route('account-deletion') }}" class="link">account deletion page</a>. An owner can export the company's data first under Account, Company data.</p>

    <h2>Governing law</h2>
    <p>These terms are governed by the laws of India.</p>
    @endif
</article>
@endsection
