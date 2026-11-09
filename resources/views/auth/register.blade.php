@extends('layouts.auth')
@section('title', __('Start a :days-day trial', ['days' => config('buildos.trial_days')]))
@section('content')
    <h1 class="text-4xl">{{ __('Start a :days-day trial', ['days' => config('buildos.trial_days')]) }}</h1>
    <p class="mt-1 text-sm text-ink-3">
        @if ($plan === 'free')
            {{ __('Free plan: 1 project and 3 users.') }}
        @else
            {{ __('Plan: :plan. No card needed. After the trial you move to the free plan.', ['plan' => __(config("buildos.plans.{$plan}.label"))]) }}
        @endif
    </p>
    <form method="POST" action="{{ route('register') }}" class="mt-6 space-y-4">
        @csrf
        <input type="hidden" name="plan" value="{{ old('plan', $plan) }}">
        <x-field :label="__('Company name')" name="company">
            <input id="company" name="company" value="{{ old('company') }}" class="input" required autofocus autocomplete="organization">
        </x-field>
        <x-field :label="__('Your name')" name="name">
            <input id="name" name="name" value="{{ old('name') }}" class="input" required autocomplete="name">
        </x-field>
        <x-field :label="__('Email')" name="email">
            <input id="email" name="email" type="email" value="{{ old('email') }}" class="input" required autocomplete="username">
        </x-field>
        <x-field :label="__('Mobile (optional)')" name="phone">
            <input id="phone" name="phone" value="{{ old('phone') }}" class="input" inputmode="tel" autocomplete="tel">
        </x-field>
        <x-field :label="__('Password')" name="password" :hint="__('At least 8 characters.')">
            <input id="password" name="password" type="password" class="input" required autocomplete="new-password">
        </x-field>
        <x-field :label="__('Confirm password')" name="password_confirmation">
            <input id="password_confirmation" name="password_confirmation" type="password" class="input" required autocomplete="new-password">
        </x-field>
        <p class="text-xs text-ink-3">{{ __('By creating an account you agree to our') }} <a href="{{ route('terms') }}" class="link">{{ __('Terms') }}</a> {{ __('and') }} <a href="{{ route('privacy') }}" class="link">{{ __('Privacy') }}</a>.</p>
        <button class="btn btn-primary w-full">{{ __('Create account') }}</button>
    </form>
    <p class="mt-6 text-center text-sm text-ink-3">{{ __('Already registered?') }} <a href="{{ route('login') }}" class="link">{{ __('Sign in') }}</a></p>
@endsection
