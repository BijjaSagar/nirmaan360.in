@extends('layouts.public')
@section('content')
<article class="site-wrap max-w-3xl py-16 text-[17px] leading-relaxed text-ink-2 [&_h2]:mt-9 [&_h2]:text-[23px] [&_h2]:text-ink [&_p]:mt-3 [&_ul]:mt-3 [&_ul]:list-disc [&_ul]:space-y-1.5 [&_ul]:pl-5">
    <h1 class="text-[clamp(2.25rem,4.5vw,3.25rem)] text-ink">Privacy policy</h1>
    @if (filled(site_setting('privacy_text')))
        {!! \Illuminate\Support\Str::markdown(site_setting('privacy_text'), ['html_input' => 'strip', 'allow_unsafe_links' => false]) !!}
    @else
    @php
        // The sentences about readers and voice notes appear only once those can be used: the same test the company's own switch is shown by.
        // Each paragraph about the AI provider is one whole text in each form, so Hindi and Marathi can say it in their own order.
        $readerSettings = app(\App\Services\DocumentReading\ReaderSettings::class);
        $readers = $readerSettings->offered();
        $voiceNotes = $readers && $readerSettings->voiceOffered();
        // The policy is in English. Its Hindi and Marathi lines about the AI provider are used only once a reader is offered,
        // which the platform admin does after a native reader has checked them; until then every paragraph stays English.
        $aiLocale = $readers ? null : 'en';
    @endphp
    <p>This policy explains what Nirmaan360 collects when you use nirmaan360.in and our apps, and what we do with it.</p>

    <h2>What we collect</h2>
    <ul>
        <li>Account details: your name, email, mobile number and company.</li>
        <li>Project records you and your team enter, such as BOQs, daily reports, measurements, attendance, bills and photos.</li>
        <li>Details of workers and contractors your team adds, such as their name and phone number.</li>
        <li>Payment records. Card and bank details are handled by Razorpay and never reach our servers.</li>
        <li>Technical data needed to run the service, such as IP address and sign-in activity.</li>
        <li>Location, in the phone app only: when you mark attendance with a photo, the app reads your phone's precise location once and saves it with that photo, so the company can check it was taken at the site. The app does not read your location at any other time or in the background.</li>
        <li>A notification token for your phone, only after you turn on alerts in the app. It is used only to deliver your notifications through Google Firebase Cloud Messaging.</li>
    </ul>

    <h2>How we use it</h2>
    <p>{{ __('We use this information to run the service, keep your account secure, process payments and reply to you. We do not sell your data. We do not use your records to train AI models. The AI provider states that it does not use what it receives through this service to train its models.', [], $aiLocale) }}</p>

    @if (app(\App\Services\Seo\Analytics::class)->measurementId())
    {{-- Only shown while Google Analytics is switched on in /admin/seo. --}}
    <h2>Analytics cookies</h2>
    <p>If you choose Accept on the cookie question, we use Google Analytics to count visits to our website{{ app(\App\Services\Seo\Analytics::class)->includesApp() ? ' and to screens inside the app' : '' }}, and to see which pages are used. It sets cookies only after you accept. We do not send Google your name, email, mobile number, company or project details, or any amounts. You can change your choice at any time from Cookie settings at the bottom of the page.</p>
    @endif

    <h2>Who can see your records</h2>
    @if ($readers)
    <p>{{ __('Your project records are visible only to people in your company account, and to anyone you send a client sign-off link. We use service providers for hosting, file storage, payments and phone notifications (Google Firebase Cloud Messaging), and an AI model provider (Anthropic) for the AI agents and readers, only when your company uses them. They handle data only so that we can run the service.') }}</p>
    @else
    <p>Your project records are visible only to people in your company account, and to anyone you send a client sign-off link. We use service providers for hosting, file storage, payments and phone notifications (Google Firebase Cloud Messaging), and an AI model provider (Anthropic) for the AI agents, only when your company uses them. They handle data only so that we can run the service.</p>
    @endif

    <h2>Where your data is stored</h2>
    @if ($readers)
    <p>{{ __('Data is currently hosted on Laravel Cloud in the Asia Pacific (Singapore) region. When you use an AI agent or reader, what it needs is processed on the provider\'s servers outside India. The provider states that it deletes this within 30 days, except content its safety systems flag, which can be kept longer. Pages and photos sent to a reader are sent as they are, after location and phone details inside the photo file are removed.') }}</p>
    @else
    <p>Data is currently hosted on Laravel Cloud in the Asia Pacific (Singapore) region. When you use an AI agent, what it needs is processed on the provider's servers outside India. The provider states that it deletes this within 30 days, except content its safety systems flag, which can be kept longer.</p>
    @endif

    <h2>Your rights</h2>
    <p>Under India's Digital Personal Data Protection Act, 2023 you can ask to access, correct or delete your personal data, or withdraw consent. You can delete your account yourself, as set out below. For any other request, use our <a href="{{ route('contact', ['type' => 'support']) }}" class="link">contact form</a>.</p>

    <h2 id="deleting-your-account">Deleting your account</h2>
    <p>We keep your personal data while your account exists. You can delete your account at any time in the app, under Settings, Delete account, or on the website, under Account, Delete my account (a contractor sign-in finds Delete my account in the contractor portal's menu). If you cannot sign in, use our <a href="{{ route('contact', ['type' => 'support']) }}" class="link">contact form</a>. Our <a href="{{ route('account-deletion') }}" class="link">account deletion page</a> sets out each step.</p>
    <ul>
        <li>If you are a member of a company, your account is deleted straight away: your sign-in, name, email address, mobile number, notification settings, phone notification tokens and questions to the AI agents. The records you made for the company, such as reports, measurements, bills, attendance and photos, stay with the company and show "Deleted user". So does its audit trail of what was done, with the network address each action came from.</li>
        <li>If you are your company's only owner, deleting your account deletes the company. It keeps working for {{ \App\Services\Notify\CompanyDeletionService::GRACE_DAYS }} days, and any owner can cancel by signing in during that time: in the app under Settings, Delete account, or on the website under Account, Delete my account. After that the company and everything in it, including every person's account, is erased, and we email you when it is done.</li>
        @if ($readers)
        <li>{{ __('Pages, photos and document text sent to an AI reader, and what the reader read from them, are part of the company\'s records. They stay with the company when one person\'s account is deleted, and are erased with the company.') }}</li>
        @endif
        @if ($voiceNotes)
        <li>{{ __('The text of voice notes you spoke into the app is deleted with your account, and with the company when it is erased.') }}</li>
        @endif
        <li>Records of payments made to Nirmaan360 (company name, GSTIN, what was bought, amounts, dates and payment references) are kept as Indian tax law requires.</li>
        <li>Some copies can remain for a while: notifications already delivered to other people, names held in the company's own records, company data exports for up to 7 days, our server logs and backup copies of the database. Backup copies are overwritten on a rolling schedule.</li>
    </ul>

    <h2>Changes</h2>
    <p>If this policy changes in a way that matters, we will tell you in the product before it takes effect.</p>
    @endif
</article>
@endsection
