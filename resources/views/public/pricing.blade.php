@extends('layouts.public')
@section('title', __('Pricing'))
@section('description', __('Simple plans for Indian contractors. Start free on one site, or try Contractor or Business for 30 days with no card.'))
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
    <section class="bg-[#f6f2ea]">
        <div class="site-wrap pt-16 pb-24 lg:pt-20">
            <div class="flex flex-wrap items-end justify-between gap-6">
                <div class="max-w-2xl">
                    <h1 class="text-[clamp(2.5rem,5vw,3.75rem)]">{{ __('Pay for the sites you run, not for every login.') }}</h1>
                    <p class="mt-4 text-lg text-ink-2">{{ __('Every paid plan starts with a :days-day trial. No card needed.', ['days' => $trialDays]) }}</p>
                </div>
                <div class="inline-flex rounded-md bg-white p-1 text-[15px] font-semibold" role="group" aria-label="{{ __('Billing period') }}">
                    <button type="button" @click="yearly = false" :class="!yearly ? 'bg-ink text-white' : 'text-ink-2'" class="rounded px-4 py-1.5">{{ __('Monthly') }}</button>
                    <button type="button" @click="yearly = true" :class="yearly ? 'bg-ink text-white' : 'text-ink-2'" class="rounded px-4 py-1.5">{{ __('Yearly') }} <span :class="yearly ? 'text-haldi' : 'text-moss'">{{ __('2 months free') }}</span></button>
                </div>
            </div>
        </div>
    </section>

    <section>
        <div class="site-wrap -mt-14">
            <div class="grid gap-5 md:grid-cols-2 xl:grid-cols-4">
                @foreach ($order as $key)
                    @php $plan = $plans[$key]; $featured = $key === 'contractor'; @endphp
                    <div class="flex flex-col rounded-lg border bg-white p-6 shadow-sm {{ $featured ? 'border-laterite ring-1 ring-laterite' : 'border-black/10' }}">
                        <div class="flex items-center justify-between gap-2">
                            <h2 class="text-[21px]">{{ __($plan['label']) }}</h2>
                            @if ($featured)<span class="text-[12.5px] font-semibold text-laterite">{{ __('Most contractors') }}</span>@endif
                        </div>
                        <p class="mt-1 min-h-11 text-[15px] leading-snug text-ink-2">{{ __($plan['tagline']) }}</p>
                        <div class="mt-4 min-h-[76px]">
                            @if ($plan['monthly'] === null)
                                <p class="text-[30px] font-extrabold tracking-tight">{{ __('Custom') }}</p>
                                <p class="text-sm text-ink-3">{{ __('Priced for your setup') }}</p>
                            @elseif ($plan['monthly'] === 0)
                                <p class="text-[34px] font-extrabold tracking-tight">₹0</p>
                                <p class="text-sm text-ink-3">{{ __('Free forever') }}</p>
                            @else
                                <p x-show="!yearly"><span class="text-[34px] font-extrabold tracking-tight">₹{{ \App\Support\Dec::indian($plan['monthly'], 0) }}</span> <span class="text-ink-3">{{ __('/month') }}</span></p>
                                <p x-show="yearly" x-cloak><span class="text-[34px] font-extrabold tracking-tight">₹{{ \App\Support\Dec::indian($plan['yearly'], 0) }}</span> <span class="text-ink-3">{{ __('/year') }}</span></p>
                                <p class="text-sm text-ink-3">{{ __('Plus :gst% GST', ['gst' => $gstPct]) }}</p>
                            @endif
                        </div>
                        <div class="mt-auto pt-5">
                            @if ($key === 'enterprise')
                                <a href="{{ route('contact', ['type' => 'sales']) }}" class="site-btn site-btn-outline w-full">{{ __('Talk to us') }}</a>
                            @elseif ($key === 'free')
                                <a href="{{ route('register', ['plan' => 'free']) }}" class="site-btn site-btn-outline w-full">{{ __('Start free') }}</a>
                            @else
                                <a href="{{ route('register', ['plan' => $key]) }}" class="site-btn {{ $featured ? 'site-btn-primary' : 'site-btn-outline' }} w-full">{{ __('Start a 30-day trial') }}</a>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    </section>

    <section>
        <div class="site-wrap py-16 lg:py-20">
            <h2 class="text-[clamp(1.75rem,3vw,2.25rem)]">{{ __('What is in each plan') }}</h2>
            <div class="mt-7 overflow-x-auto">
                <table class="w-full min-w-[720px] text-[15.5px]">
                    <thead>
                        <tr class="border-b-2 border-ink text-left">
                            <th class="py-3 pr-4 font-semibold"><span class="sr-only">{{ __('Feature') }}</span></th>
                            @foreach ($order as $key)<th class="px-3 py-3 text-center font-semibold">{{ __($plans[$key]['label']) }}</th>@endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rows as [$label, $value])
                            <tr class="border-b border-black/8">
                                <td class="py-3 pr-4">{{ __($label) }}</td>
                                @foreach ($order as $key)
                                    @php $cell = $value($plans[$key]); @endphp
                                    <td class="px-3 py-3 text-center">
                                        @if ($cell === true)
                                            <x-icon name="check" class="mx-auto size-5 text-moss" /><span class="sr-only">{{ __('Included') }}</span>
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
            <p class="mt-5 text-[15px] text-ink-3">{{ __('AI agents are in early access and are not part of these plans yet. Prices are in Indian rupees.') }}</p>
        </div>
    </section>

    <section class="bg-[#eaf1f6]">
        <div class="site-wrap grid gap-10 py-16 lg:grid-cols-[1fr_1.6fr] lg:py-20">
            <h2 class="text-[clamp(1.75rem,3vw,2.25rem)]">{{ __('About billing') }}</h2>
            <div class="faq divide-y divide-black/10 border-y border-black/10">
                @foreach ([
                    ['What happens after the trial?', 'Your company moves to the free plan and your data stays. Work orders and RA bills unlock again when you buy a plan.'],
                    ['Does it charge me automatically?', 'No. You pay for a month or a year at a time. Nothing renews until you pay again.'],
                    ['What if a paid period runs out?', 'You get 3 extra days to renew. After that the company moves to the free plan until you pay.'],
                    ['Can I change plans?', 'Yes, from Billing inside the app. A new plan starts on the day you pay for it.'],
                ] as [$question, $answer])
                    <details><summary>{{ __($question) }}<x-icon name="plus" class="faq-plus size-5 text-ink-3 transition-transform" /></summary><p class="pb-5 text-[16.5px] leading-relaxed text-ink-2">{{ __($answer) }}</p></details>
                @endforeach
            </div>
        </div>
    </section>
</div>
@endsection
