@extends('layouts.app')
@section('title', __('Notification settings'))
@section('content')
    <x-page-header :title="__('Notification settings')" :subtitle="__('Choose how you hear about approvals and issues')" />

    <form method="POST" action="{{ route('notify.preferences.update') }}" class="card max-w-2xl">
        @csrf @method('PATCH')
        <div class="divide-y divide-rule">
            <div class="flex items-start justify-between gap-4 p-4">
                <div>
                    <p class="font-semibold">{{ __('In the app') }}</p>
                    <p class="text-sm text-ink-3">{{ __('The bell at the top of every page. Always on.') }}</p>
                </div>
                <input type="checkbox" class="mt-1 size-5" checked disabled aria-label="{{ __('In the app') }}">
            </div>

            <div class="flex items-start justify-between gap-4 p-4">
                <div>
                    <label for="email" class="font-semibold">{{ __('Email') }}</label>
                    <p class="text-sm text-ink-3">{{ auth()->user()->email }}</p>
                    @unless ($channels['email']['connected'])<p class="text-sm text-laterite">{{ __('Not connected yet') }}</p>@endunless
                </div>
                <input id="email" name="email" type="checkbox" value="1" class="mt-1 size-5" @checked($channels['email']['enabled']) @disabled(! $channels['email']['connected'])>
            </div>

            <div class="flex items-start justify-between gap-4 p-4">
                <div>
                    <label for="push" class="font-semibold">{{ __('Push on the mobile app') }}</label>
                    <p class="text-sm text-ink-3">{{ __('Phones signed in: :count', ['count' => $deviceCount]) }}</p>
                    @unless ($channels['push']['connected'])<p class="text-sm text-laterite">{{ __('Not connected yet') }}</p>@endunless
                </div>
                <input id="push" name="push" type="checkbox" value="1" class="mt-1 size-5" @checked($channels['push']['enabled']) @disabled(! $channels['push']['connected'])>
            </div>

            <div class="flex items-start justify-between gap-4 p-4">
                <div>
                    <label for="whatsapp" class="font-semibold">{{ __('WhatsApp') }}</label>
                    @if ($phoneOk)
                        <p class="text-sm text-ink-3">{{ __('I agree to get work updates on WhatsApp at :phone.', ['phone' => auth()->user()->phone]) }}</p>
                    @else
                        <p class="text-sm text-ink-3">{{ __('Ask your company admin to add your mobile number on the Team page first.') }}</p>
                    @endif
                    @unless ($channels['whatsapp']['connected'])<p class="text-sm text-laterite">{{ __('Not connected yet') }}</p>@endunless
                    @error('whatsapp')<p class="error">{{ $message }}</p>@enderror
                </div>
                <input id="whatsapp" name="whatsapp" type="checkbox" value="1" class="mt-1 size-5" @checked($channels['whatsapp']['enabled']) @disabled(! $channels['whatsapp']['connected'] || ! $phoneOk)>
            </div>
        </div>
        <div class="border-t border-rule px-4 py-3"><button class="btn btn-primary">{{ __('Save') }}</button></div>
    </form>
@endsection
