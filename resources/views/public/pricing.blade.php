@extends('layouts.public')
@section('title', __('Pricing'))
@section('description', __('Simple plans for Indian contractors. Start free on one site, or try Contractor or Business for :days days with no card.', ['days' => config('buildos.trial_days')]))
@section('content')
@php
    $order = ['free', 'contractor', 'business', 'enterprise'];
    $rows = [
        ['Projects', fn ($p) => $p['max_projects'] ?? __('Unlimited')],
        ['Users', fn ($p) => $p['max_users'] ?? __('Unlimited')],
        ['Daily reports and issues', fn ($p) => in_array('site', $p['features'])],
        ['Measurement book and approvals', fn ($p) => in_array('measurement', $p['features'])],
        ['Attendance and workers', fn ($p) => in_array('labour', $p['features'])],
        ['Petty cash', fn ($p) => in_array('petty_cash', $p['features'])],
        ['Work orders and RA bills', fn ($p) => in_array('billing', $p['features'])],
        ['Client sign-off link', fn ($p) => in_array('billing', $p['features'])],
        ['Contacts directory', fn ($p) => in_array('parties', $p['features'])],
        ['API access', fn ($p) => in_array('api', $p['features'])],
    ];
@endphp
<div x-data="{ yearly: false }">
    <section>
        <div class="site-wrap pt-14 pb-10 text-center lg:pt-24 lg:pb-12">
            <h1 class="mx-auto max-w-3xl text-[clamp(2.6rem,5.8vw,4.5rem)]">{{ __('Pay for the sites you run, not for every login.') }}</h1>
            <p class="mt-5 text-[18px] text-ink-2">{{ __('Every paid plan starts with a :days-day trial. No card needed.', ['days' => $trialDays]) }}</p>
            <div class="mt-7 inline-flex rounded-full bg-sand p-1 text-[15px]" role="group" aria-label="{{ __('Billing period') }}">
                <button type="button" @click="yearly = false" :class="!yearly ? 'bg-white text-ink shadow-sm' : 'text-ink-2'" class="rounded-full px-5 py-1.5">{{ __('Monthly') }}</button>
                <button type="button" @click="yearly = true" :class="yearly ? 'bg-white text-ink shadow-sm' : 'text-ink-2'" class="rounded-full px-5 py-1.5">{{ __('Yearly') }} <span class="text-laterite">{{ __('2 months free') }}</span></button>
            </div>
        </div>
    </section>

    <section>
        <div class="site-wrap">
            <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-4">
                @foreach ($order as $key)
                    @php $plan = $plans[$key]; $featured = $key === 'contractor'; @endphp
                    <div class="site-card flex flex-col p-6 {{ $featured ? 'border-ink/60' : '' }}">
                        <div class="flex items-start justify-between gap-2">
                            <x-icon :name="'plan-'.$key" class="size-10" />
                            @if ($featured)<span class="tag-soon">{{ __('Most contractors') }}</span>@endif
                        </div>
                        <h2 class="mt-5 text-[28px]">{{ __($plan['label']) }}</h2>
                        <p class="mt-1 min-h-11 text-[15px] leading-snug text-ink-2">{{ __($plan['tagline']) }}</p>
                        <div class="mt-5 min-h-[76px]">
                            @if ($plan['monthly'] === null)
                                <p class="font-serif text-[38px] leading-none">{{ __('Custom') }}</p>
                                <p class="mt-1.5 text-[13.5px] text-ink-3">{{ __('Priced for your setup') }}</p>
                            @elseif ($plan['monthly'] === 0)
                                <p class="font-serif text-[38px] leading-none">₹0</p>
                                <p class="mt-1.5 text-[13.5px] text-ink-3">{{ __('Free forever') }}</p>
                            @else
                                <p x-show="!yearly" class="leading-none"><span class="font-serif text-[38px]">₹{{ \App\Support\Dec::indian($plan['monthly'], 0) }}</span> <span class="text-[15px] text-ink-3">{{ __('/month') }}</span></p>
                                <p x-show="yearly" x-cloak class="leading-none"><span class="font-serif text-[38px]">₹{{ \App\Support\Dec::indian($plan['yearly'], 0) }}</span> <span class="text-[15px] text-ink-3">{{ __('/year') }}</span></p>
                                <p class="mt-1.5 text-[13.5px] text-ink-3">{{ plan_gst_note() }}</p>
                            @endif
                        </div>
                        <div class="mt-auto pt-5">
                            @if ($key === 'enterprise')
                                <a href="{{ route('contact', ['type' => 'sales']) }}" class="site-btn site-btn-outline w-full">{{ __('Talk to us') }}</a>
                            @elseif ($key === 'free')
                                <a href="{{ route('register', ['plan' => 'free']) }}" class="site-btn site-btn-outline w-full">{{ __('Start free') }}</a>
                            @else
                                <a href="{{ route('register', ['plan' => $key]) }}" class="site-btn {{ $featured ? 'site-btn-primary' : 'site-btn-outline' }} w-full">{{ __('Start a :days-day trial', ['days' => config('buildos.trial_days')]) }}</a>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    </section>

    <section>
        <div class="site-wrap py-16 lg:py-24">
            <h2 class="text-center text-[clamp(2rem,3.6vw,2.75rem)]">{{ __('What is in each plan') }}</h2>
            <div class="relative mt-9 overflow-x-auto">
                <table class="w-full min-w-[720px] text-[15.5px]">
                    <thead>
                        <tr class="border-b border-ink/60 text-left">
                            <th class="py-3 pr-4 font-medium"><span class="sr-only">{{ __('Feature') }}</span></th>
                            @foreach ($order as $key)<th class="px-3 py-3 text-center font-serif text-[19px] font-medium">{{ __($plans[$key]['label']) }}</th>@endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rows as [$label, $value])
                            <tr class="border-b border-rule">
                                <td class="py-3.5 pr-4">{{ __($label) }}</td>
                                @foreach ($order as $key)
                                    @php $cell = $value($plans[$key]); @endphp
                                    <td class="px-3 py-3.5 text-center">
                                        @if ($cell === true)
                                            <x-icon name="check" class="mx-auto size-5" /><span class="sr-only">{{ __('Included') }}</span>
                                        @elseif ($cell === false)
                                            <span class="text-ink-3/60" aria-label="{{ __('Not included') }}">–</span>
                                        @else
                                            {{ $cell }}
                                        @endif
                                    </td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <p class="mt-5 text-center text-[15px] text-ink-3">{{ __('AI agents are in early access and are not part of these plans yet. Prices are in Indian rupees.') }}</p>
        </div>
    </section>

    <section class="site-panel mb-3 bg-sand sm:mb-5">
        <div class="site-wrap grid gap-10 py-16 lg:grid-cols-[1fr_1.6fr] lg:py-20">
            <h2 class="text-[clamp(2rem,3.6vw,2.75rem)]">{{ __('About billing') }}</h2>
            <div class="faq divide-y divide-ink/10 border-y border-ink/10">
                @foreach ([
                    ['What happens after the trial?', 'Your company moves to the free plan and your data stays. Work orders and RA bills unlock again when you buy a plan.'],
                    ['Does it charge me automatically?', 'No. You pay for a month or a year at a time. Nothing renews until you pay again.'],
                    ['What if a paid period runs out?', 'You get :days extra days to renew. After that the company moves to the free plan until you pay.'],
                    ['Can I change plans?', 'Yes, from Billing inside the app. A new plan starts on the day you pay for it.'],
                ] as [$question, $answer])
                    <details><summary>{{ __($question) }}<x-icon name="plus" class="faq-plus size-5 text-ink-3 transition-transform" /></summary><p class="pb-5 text-[16px] leading-relaxed text-ink-2">{{ __($answer, ['days' => config('buildos.payment_grace_days')]) }}</p></details>
                @endforeach
            </div>
        </div>
    </section>
</div>
@endsection
