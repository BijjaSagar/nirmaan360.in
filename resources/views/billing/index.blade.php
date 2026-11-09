@extends('layouts.app')
@section('title', __('Billing'))
@section('content')
    <x-page-header :title="__('Billing')" :subtitle="__('Your plan and payments')" />

    @php
        $current = $organization->effectivePlan();
    @endphp

    <section class="card mb-5 p-5">
        <div class="flex flex-wrap items-center justify-between gap-4">
            <div>
                <p class="eyebrow text-ink-3">{{ __('Current plan') }}</p>
                <p class="mt-1 font-display text-3xl font-semibold">{{ __($plans[$current]['label']) }}</p>
                <p class="mt-0.5 text-sm text-ink-3">
                    @if ($organization->isOnTrial())
                        {{ __('Trial ends on :date', ['date' => $organization->trial_ends_at->format('d M Y')]) }}
                    @elseif ($organization->paid_until && ! $organization->hasLapsed())
                        {{ __('Paid until :date', ['date' => $organization->paid_until->format('d M Y')]) }}
                    @elseif ($current === 'free')
                        {{ __('Free plan') }}
                    @endif
                </p>
            </div>
            <a href="{{ route('pricing') }}" target="_blank" rel="noopener" class="link text-sm">{{ __('Compare plans') }}</a>
        </div>
    </section>

    @unless ($gatewayLive)
        <p class="mb-5 rounded-[3px] border border-haldi bg-haldi/15 px-3 py-2 text-sm">{{ __('Online payment is not switched on yet. To upgrade now,') }} <a href="{{ route('contact', ['type' => 'sales']) }}" class="link">{{ __('contact us') }}</a>.</p>
    @endunless

    <div class="grid gap-5 md:grid-cols-2">
        @foreach ($plans as $key => $plan)
            @continue(! $plan['buyable'])
            <form method="POST" action="{{ route('billing.checkout') }}" class="card flex flex-col p-5" x-data="{ cycle: 'yearly' }">
                @csrf
                <input type="hidden" name="plan" value="{{ $key }}">
                <input type="hidden" name="cycle" :value="cycle">
                <h2 class="card-title text-2xl">{{ __($plan['label']) }}</h2>
                <p class="text-sm text-ink-3">{{ __($plan['tagline']) }}</p>
                <div class="mt-4 space-y-2 text-sm">
                    <label class="flex cursor-pointer items-center justify-between gap-3 rounded-[3px] border border-rule p-3" :class="cycle === 'monthly' && 'border-neel'"><span class="flex items-center gap-2"><input type="radio" value="monthly" x-model="cycle"> {{ __('Monthly') }}</span><span class="num">{{ inr($plan['monthly']) }}</span></label>
                    <label class="flex cursor-pointer items-center justify-between gap-3 rounded-[3px] border border-rule p-3" :class="cycle === 'yearly' && 'border-neel'"><span class="flex items-center gap-2"><input type="radio" value="yearly" x-model="cycle"> {{ __('Yearly') }} <span class="text-xs font-semibold text-moss">{{ __('2 months free') }}</span></span><span class="num">{{ inr($plan['yearly']) }}</span></label>
                </div>
                <p class="mt-2 text-xs text-ink-3">{{ __('Plus :gst% GST', ['gst' => $gstPct]) }}</p>
                <button class="btn btn-primary mt-4" @disabled(! $gatewayLive)>{{ $current === $key ? __('Renew') : __('Choose :plan', ['plan' => __($plan['label'])]) }}</button>
            </form>
        @endforeach
    </div>

    <section class="card mt-5 overflow-x-auto">
        <div class="card-head"><h2 class="card-title">{{ __('Payments') }}</h2></div>
        @if ($payments->isEmpty())
            <p class="px-4 py-5 text-sm text-ink-3">{{ __('No payments yet.') }}</p>
        @else
            <table class="ledger">
                <thead><tr><th>{{ __('Date') }}</th><th>{{ __('Plan') }}</th><th>{{ __('Period ends') }}</th><th class="r">{{ __('Amount') }}</th><th>{{ __('Reference') }}</th></tr></thead>
                <tbody>
                @foreach ($payments as $payment)
                    <tr>
                        <td class="num text-xs">{{ $payment->paid_at->format('d M Y') }}</td>
                        <td>{{ __($plans[$payment->plan]['label'] ?? $payment->plan) }} · {{ __(ucfirst($payment->cycle)) }}</td>
                        <td class="num text-xs">{{ $payment->period_end->format('d M Y') }}</td>
                        <td class="r">{{ inr($payment->total_amount) }}</td>
                        <td class="num text-xs">{{ $payment->gateway_payment_id }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        @endif
    </section>
@endsection
