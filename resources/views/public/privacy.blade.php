@extends('layouts.public')
@section('content')
<article class="site-wrap max-w-3xl py-16 text-[17px] leading-relaxed text-ink-2 [&_h2]:mt-9 [&_h2]:text-[23px] [&_h2]:text-ink [&_p]:mt-3 [&_ul]:mt-3 [&_ul]:list-disc [&_ul]:space-y-1.5 [&_ul]:pl-5">
    <h1 class="text-[clamp(2.25rem,4.5vw,3.25rem)] text-ink">Privacy policy</h1>
    @if (filled(site_setting('privacy_text')))
        {!! \Illuminate\Support\Str::markdown(site_setting('privacy_text'), ['html_input' => 'strip', 'allow_unsafe_links' => false]) !!}
    @else
    <p>This policy explains what Nirmaan360 collects when you use nirmaan360.in and our apps, and what we do with it.</p>

    <h2>What we collect</h2>
    <ul>
        <li>Account details: your name, email, mobile number and company.</li>
        <li>Project records you and your team enter, such as BOQs, daily reports, measurements, attendance, bills and photos.</li>
        <li>Payment records. Card and bank details are handled by Razorpay and never reach our servers.</li>
        <li>Technical data needed to run the service, such as IP address and sign-in activity.</li>
    </ul>

    <h2>How we use it</h2>
    <p>We use this information to run the service, keep your account secure, process payments and reply to you. We do not sell your data. Your project records are not used to train shared AI models unless you opt in.</p>

    @if (app(\App\Services\Seo\Analytics::class)->measurementId())
    {{-- Only shown while Google Analytics is switched on in /admin/seo. --}}
    <h2>Analytics cookies</h2>
    <p>If you choose Accept on the cookie question, we use Google Analytics to count visits to our website{{ app(\App\Services\Seo\Analytics::class)->includesApp() ? ' and to screens inside the app' : '' }}, and to see which pages are used. It sets cookies only after you accept. We do not send Google your name, email, mobile number, company or project details, or any amounts. You can change your choice at any time from Cookie settings at the bottom of the page.</p>
    @endif

    <h2>Who can see your records</h2>
    <p>Your project records are visible only to people in your company account, and to anyone you send a client sign-off link. We use service providers for hosting, file storage and payments, and they handle data only so that we can run the service.</p>

    <h2>Where your data is stored</h2>
    <p>Data is currently hosted on Laravel Cloud in the Asia Pacific (Singapore) region.</p>

    <h2>Your rights</h2>
    <p>Under India's Digital Personal Data Protection Act, 2023 you can ask to access, correct or delete your personal data, or withdraw consent. To make a request, use our <a href="{{ route('contact', ['type' => 'support']) }}" class="link">contact form</a>.</p>

    <h2>How long we keep it</h2>
    <p>We keep your records while your account is active. If you ask us to delete your account, we remove your data except where the law requires us to keep it.</p>

    <h2>Changes</h2>
    <p>If this policy changes in a way that matters, we will tell you in the product before it takes effect.</p>
    @endif
</article>
@endsection
