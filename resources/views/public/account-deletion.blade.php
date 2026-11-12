@extends('layouts.public')
@section('content')
{{--
    Google Play's account deletion link. Every promise here is what AccountDeletionService and
    CompanyDeletionService do, so a change to either must be read against this page
    (tests/Feature/AccountDeletionPolicyTest). No admin override: the text has to match the code.
--}}
<article class="site-wrap max-w-3xl py-16 text-[17px] leading-relaxed text-ink-2 [&_h2]:mt-9 [&_h2]:text-[23px] [&_h2]:text-ink [&_p]:mt-3 [&_ul]:mt-3 [&_ul]:list-disc [&_ul]:space-y-1.5 [&_ul]:pl-5">
    <h1 class="text-[clamp(2.25rem,4.5vw,3.25rem)] text-ink">Delete your Nirmaan360 account</h1>
    <p>You can delete your Nirmaan360 account yourself at any time, in the Nirmaan360 app or on this website. This page explains how, and what happens to your data.</p>

    <h2>In the app</h2>
    <p>Open <strong>Settings</strong>, then <strong>Delete account</strong>. The app shows what will happen, then asks for your password.</p>

    <h2>On the website</h2>
    <p>Sign in, then open <strong>Account</strong>, <strong>Delete my account</strong> in the menu. A contractor sign-in finds <strong>Delete my account</strong> in the contractor portal's menu. You can also go straight to <a href="{{ route('account.delete') }}" class="link">Delete my account</a>. The page shows what will happen, then asks for your password. If you are your company's only owner, you also type the company's name.</p>
    <p>If you sign in with a mobile OTP or with Microsoft and do not know your password, set one first with <a href="{{ route('password.request') }}" class="link">Forgot password</a>.</p>

    <h2>If you cannot sign in</h2>
    <p>Write to us through our <a href="{{ route('contact', ['type' => 'support']) }}" class="link">contact form</a>. Give the email address of your account and ask for the account to be deleted. We reply to that address to confirm the request is yours. Once you confirm, we carry it out within 30 days, with the same result as deleting the account yourself, described below.</p>

    <h2>If you are a member of a company</h2>
    <p>This covers everyone except a company's only owner, including contractor sign-ins and an owner whose company has another owner. Your account is deleted straight away, and this cannot be undone.</p>
    <p>Deleted at once:</p>
    <ul>
        <li>your sign-in: your password, any Microsoft sign-in link, and every website session and phone you were signed in on;</li>
        <li>your name, email address and mobile number;</li>
        <li>your notification settings, the notifications sent to you and your phones' notification tokens;</li>
        <li>your questions to the AI agents and their answers;</li>
        <li>your email address in the company's emailed report lists and in its invitations;</li>
        <li>your access to the company's projects. Any offline-plan phone licence you held is freed for someone else.</li>
    </ul>
    <p>Kept by the company:</p>
    <ul>
        <li>The work you recorded for it, such as daily reports, measurements, bills, approvals, attendance with its photos and locations, expense claims and leave. These are the company's business records, which it may need for its contracts and taxes. They now show "Deleted user" instead of your name.</li>
        <li>The company's audit trail of what was done and when, linked to "Deleted user" and to the network (IP) address each action came from. It is kept for security.</li>
        <li>Changes the AI agents proposed or made in the company's records, like any other record.</li>
    </ul>

    <h2>If you are your company's only owner</h2>
    <p>A company cannot be left without an owner, so deleting your account deletes the company and everything in it.</p>
    <ul>
        <li>You are signed out everywhere straight away, and everyone in the company is told.</li>
        <li>The company keeps working for {{ $graceDays }} days. Until then any owner, you included, can cancel by signing in again: in the app under Settings, Delete account, or on the website under Account, Delete my account. Your name, email address and mobile number stay until then, so that you can sign in.</li>
        <li>If someone else is made an owner and cancels the deletion, the company stays and your own account is deleted then, as for a member.</li>
        <li>After the {{ $graceDays }} days, the company is erased: every project, record, photo and file, and the account of every person in it. We then email you to confirm it is done.</li>
    </ul>
    <p>To keep the company going without you, make another member an owner under Team first, then delete your account; it is then deleted on its own, as for a member. To keep a copy of the company's data, an owner can export it first under Account, Company data.</p>

    <h2>Kept as the law requires</h2>
    <p>Records of payments made to Nirmaan360 for plans and AI credits are kept after the company is erased, because Indian tax law requires it. They hold the company's name and GSTIN, what was bought, the amounts, the dates and the payment references. We also keep a note that the company was deleted and when, with no names in it.</p>

    <h2>Copies that can remain for a while</h2>
    <ul>
        <li>Notifications already delivered to other people, which can show your name, for example as the sender.</li>
        <li>Names and numbers held in the company's own records, such as its contact details, a worker list, a contact on a project, a phone's name or a note. The company can change or remove them.</li>
        <li>Company data exports made before the deletion. We remove our copy 7 days after it is made.</li>
        <li>The app on other people's phones, until it next updates its data.</li>
        <li>Messages you sent us through the contact form.</li>
        <li>Our server logs, and backup copies of the database. Backup copies are overwritten on a rolling schedule.</li>
        <li>Razorpay keeps its own record of any payment you made, under its own privacy policy.</li>
    </ul>

    <h2>Questions</h2>
    <p>For anything about your data, use our <a href="{{ route('contact', ['type' => 'support']) }}" class="link">contact form</a>. Our <a href="{{ route('privacy') }}" class="link">privacy policy</a> explains what we collect and why.</p>
</article>
@endsection
