@extends('layouts.public')
@section('title', __('Contact'))
@section('content')
<section class="bg-[#f6f2ea]">
    <div class="site-wrap grid gap-12 py-16 lg:grid-cols-[1fr_1.1fr] lg:py-24">
        <div>
            <h1 class="text-[clamp(2.5rem,5vw,3.75rem)]">{{ __('Tell us about your projects.') }}</h1>
            <p class="mt-4 max-w-md text-lg leading-relaxed text-ink-2">{{ __('Larger rollouts, AI early access or a question about the product. Leave your details and we will get back to you.') }}</p>
            <ul class="mt-8 space-y-4 text-[16px]">
                <li class="flex gap-3"><x-icon name="crane" class="size-6 text-laterite" />{{ __('Enterprise and infrastructure pricing') }}</li>
                <li class="flex gap-3"><x-icon name="message" class="size-6 text-laterite" />{{ __('AI agent early access') }}</li>
                <li class="flex gap-3"><x-icon name="users" class="size-6 text-laterite" />{{ __('Help moving your team across') }}</li>
            </ul>
            @if (site_setting('contact_email') || site_setting('contact_phone'))
                <div class="mt-8 space-y-2 border-t border-black/10 pt-6 text-[17px] font-semibold">
                    @if (site_setting('contact_phone'))<a href="tel:{{ preg_replace('/[^0-9+]/', '', site_setting('contact_phone')) }}" class="flex items-center gap-3"><x-icon name="call" class="size-5 text-laterite" />{{ site_setting('contact_phone') }}</a>@endif
                    @if (site_setting('contact_email'))<a href="mailto:{{ site_setting('contact_email') }}" class="flex items-center gap-3"><x-icon name="mail" class="size-5 text-laterite" />{{ site_setting('contact_email') }}</a>@endif
                </div>
            @endif
        </div>
        <form method="POST" action="{{ route('contact.store') }}" class="rounded-lg bg-white p-7 shadow-sm sm:p-9">
            @csrf
            @if (session('success'))
                <p class="mb-5 rounded-md bg-moss/10 px-4 py-3 text-[15px] font-medium text-moss" role="status">{{ session('success') }}</p>
            @endif
            <div class="absolute -left-[9999px]" aria-hidden="true"><label>Website<input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>
            <div class="grid gap-4 sm:grid-cols-2">
                <x-field :label="__('I am interested in')" name="type" class="sm:col-span-2">
                    <select id="type" name="type" class="input py-2.5">
                        <option value="sales" @selected(old('type', $type) === 'sales')>{{ __('Enterprise or a larger rollout') }}</option>
                        <option value="ai_early_access" @selected(old('type', $type) === 'ai_early_access')>{{ __('AI agent early access') }}</option>
                        <option value="support" @selected(old('type', $type) === 'support')>{{ __('A question about the product') }}</option>
                    </select>
                </x-field>
                <x-field :label="__('Your name')" name="name"><input id="name" name="name" value="{{ old('name') }}" class="input py-2.5" required autocomplete="name"></x-field>
                <x-field :label="__('Company name')" name="company"><input id="company" name="company" value="{{ old('company') }}" class="input py-2.5" autocomplete="organization"></x-field>
                <x-field :label="__('Email')" name="email"><input id="email" name="email" type="email" value="{{ old('email') }}" class="input py-2.5" required autocomplete="email"></x-field>
                <x-field :label="__('Mobile (optional)')" name="phone"><input id="phone" name="phone" value="{{ old('phone') }}" class="input py-2.5" inputmode="tel" autocomplete="tel"></x-field>
                <x-field :label="__('Message')" name="message" class="sm:col-span-2"><textarea id="message" name="message" rows="4" class="input py-2.5">{{ old('message') }}</textarea></x-field>
            </div>
            <button class="site-btn site-btn-primary mt-5 w-full">{{ __('Send') }}</button>
        </form>
    </div>
</section>
@endsection
