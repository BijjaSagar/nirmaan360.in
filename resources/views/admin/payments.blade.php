@extends('layouts.app')
@section('title', __('Payments'))
@section('content')
    <x-page-header :title="__('Payments')" :subtitle="__('Razorpay keys, GST and trial length')">
        <x-stamp :status="$live ? 'active' : 'draft'" />
    </x-page-header>
    @include('admin._tabs')

    <div class="grid max-w-5xl gap-5 lg:grid-cols-2">
        <form method="POST" action="{{ route('admin.payments.update') }}" class="card" autocomplete="off">
            @csrf @method('PATCH')
            <div class="card-head"><h2 class="card-title">{{ __('Razorpay') }}</h2><span class="text-sm {{ $live ? 'text-moss' : 'text-ink-3' }}">{{ $live ? __('Online payment is on') : __('Online payment is off') }}</span></div>
            <div class="space-y-3 p-4">
                <x-field :label="__('Key ID')" name="razorpay_key_id"><input id="razorpay_key_id" name="razorpay_key_id" value="{{ old('razorpay_key_id', $keyId) }}" class="input num" autocomplete="off"></x-field>
                <x-field :label="__('Key secret')" name="razorpay_key_secret" :hint="$hasSecret ? __('Saved. Leave blank to keep it.') : __('Not saved yet.')"><input id="razorpay_key_secret" name="razorpay_key_secret" type="password" class="input num" autocomplete="new-password"></x-field>
                <x-field :label="__('Webhook secret')" name="razorpay_webhook_secret" :hint="$hasWebhookSecret ? __('Saved. Leave blank to keep it.') : __('Not saved yet.')"><input id="razorpay_webhook_secret" name="razorpay_webhook_secret" type="password" class="input num" autocomplete="new-password"></x-field>
                <div>
                    <p class="label">{{ __('Webhook address to enter in Razorpay') }}</p>
                    <p class="num rounded-[3px] bg-paper-2/60 px-2.5 py-1.5 text-xs break-all">{{ url('/api/v1/webhooks/razorpay') }}</p>
                    <p class="hint">{{ __('Choose the payment.captured event.') }}</p>
                </div>
                <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="clear_keys" value="1"> {{ __('Remove the saved Razorpay keys') }}</label>
            </div>
            <div class="card-head border-t border-b-0"><h2 class="card-title">{{ __('Plan rules') }}</h2></div>
            <div class="grid gap-3 p-4 sm:grid-cols-3">
                <x-field :label="__('GST on plan prices')" name="subscription_gst_mode" class="sm:col-span-3">
                    <select id="subscription_gst_mode" name="subscription_gst_mode" class="input">
                        <option value="plus" @selected(old('subscription_gst_mode', $gstMode) === 'plus')>{{ __('With GST: add GST on top of the plan price') }}</option>
                        <option value="included" @selected(old('subscription_gst_mode', $gstMode) === 'included')>{{ __('With GST: the plan price already includes GST') }}</option>
                        <option value="none" @selected(old('subscription_gst_mode', $gstMode) === 'none')>{{ __('Without GST: charge the plan price only') }}</option>
                    </select>
                </x-field>
                <x-field :label="__('Trial days')" name="trial_days"><input id="trial_days" name="trial_days" type="number" min="1" value="{{ old('trial_days', $trialDays) }}" class="input num" required></x-field>
                <x-field :label="__('GST %')" name="subscription_gst_pct"><input id="subscription_gst_pct" name="subscription_gst_pct" type="number" min="0" max="100" value="{{ old('subscription_gst_pct', $gstPct) }}" class="input num" required></x-field>
                <x-field :label="__('Grace days')" name="payment_grace_days"><input id="payment_grace_days" name="payment_grace_days" type="number" min="0" value="{{ old('payment_grace_days', $graceDays) }}" class="input num" required></x-field>
            </div>
            <div class="border-t border-rule px-4 py-3"><button class="btn btn-primary">{{ __('Save') }}</button></div>
        </form>

        <section class="card self-start p-4">
            <h2 class="card-title">{{ __('Check your keys') }}</h2>
            <p class="mt-1 text-sm text-ink-3">{{ __('Asks Razorpay whether the saved keys work. No payment is made.') }}</p>
            <form method="POST" action="{{ route('admin.payments.test') }}" class="mt-3">@csrf<button class="btn btn-ghost" @disabled(! $live)>{{ __('Test keys') }}</button></form>
        </section>
    </div>
@endsection
