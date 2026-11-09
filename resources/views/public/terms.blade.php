@extends('layouts.public')
@section('title', __('Terms'))
@section('content')
<article class="site-wrap max-w-3xl py-16 text-[17px] leading-relaxed text-ink-2 [&_h2]:mt-9 [&_h2]:text-[23px] [&_h2]:text-ink [&_p]:mt-3">
    <h1 class="text-[clamp(2.25rem,4.5vw,3.25rem)] text-ink">Terms of service</h1>
    <p>By creating an account on Nirmaan360 you agree to these terms on behalf of yourself and your company.</p>

    <h2>Your account</h2>
    <p>You are responsible for your team's access, for keeping passwords safe, and for the accuracy of what you enter.</p>

    <h2>Your data</h2>
    <p>Your project records belong to you. You give us permission to store and process them only to provide the service.</p>

    <h2>Plans and payment</h2>
    <p>Paid plans are prepaid monthly or yearly and are charged in Indian rupees plus GST through Razorpay. Plans do not renew automatically. If a paid period ends and is not renewed within 3 days, your company moves to the free plan and your data stays in place. Trials end after 30 days and move to the free plan in the same way.</p>

    <h2>Check your figures</h2>
    <p>Nirmaan360 helps you prepare measurements, bills and calculations. You remain responsible for checking them against your contracts, drawings, codes and tax rules before you rely on them.</p>

    <h2>Acceptable use</h2>
    <p>Do not misuse the service, try to reach another company's data, or interfere with its operation. We may suspend accounts that do.</p>

    <h2>Availability</h2>
    <p>We work to keep the service running, but it is provided as is and may sometimes be unavailable. To the extent the law allows, we are not liable for indirect losses arising from its use.</p>

    <h2>Ending your account</h2>
    <p>You can stop using the service at any time. Use our <a href="{{ route('contact', ['type' => 'support']) }}" class="link">contact form</a> if you want your account closed or your data exported.</p>

    <h2>Governing law</h2>
    <p>These terms are governed by the laws of India.</p>
</article>
@endsection
