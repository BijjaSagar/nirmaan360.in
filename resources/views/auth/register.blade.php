@extends('layouts.auth')
@section('title', __('Start a 30-day trial'))
@section('content')
    <h1 class="text-4xl">{{ __('Start a 30-day trial') }}</h1>
    <p class="mt-1 text-sm text-ink-3">{{ __('No card needed. After the trial you move to the free plan.') }}</p>
    <form method="POST" action="{{ route('register') }}" class="mt-6 space-y-4">
        @csrf
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
        <button class="btn btn-primary w-full">{{ __('Create account') }}</button>
    </form>
    <p class="mt-6 text-center text-sm text-ink-3">{{ __('Already registered?') }} <a href="{{ route('login') }}" class="link">{{ __('Sign in') }}</a></p>
@endsection
