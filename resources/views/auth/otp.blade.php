@extends('layouts.auth')
@section('title', __('Sign in with mobile OTP'))
@section('content')
    <h1 class="text-4xl">{{ __('Sign in with mobile OTP') }}</h1>

    @if (session('dev_code'))
        <p class="mt-3 rounded-[3px] border border-haldi bg-haldi/15 px-3 py-2 text-sm">{{ __('Development mode. Your code is') }} <span class="num font-medium">{{ session('dev_code') }}</span></p>
    @endif

    <form method="POST" action="{{ route('otp.send') }}" class="mt-6 space-y-4">
        @csrf
        <x-field :label="__('Mobile number')" name="phone">
            <input id="phone" name="phone" value="{{ old('phone', $phone) }}" class="input" inputmode="tel" required autocomplete="tel">
        </x-field>
        <button class="btn btn-ghost w-full">{{ __('Send code') }}</button>
    </form>

    @if ($phone)
        <form method="POST" action="{{ route('otp.verify') }}" class="mt-6 space-y-4 border-t border-rule pt-6">
            @csrf
            <input type="hidden" name="phone" value="{{ $phone }}">
            <x-field :label="__('6-digit code')" name="code">
                <input id="code" name="code" class="input num tracking-[0.4em]" inputmode="numeric" maxlength="6" required autocomplete="one-time-code">
            </x-field>
            <button class="btn btn-primary w-full">{{ __('Verify and sign in') }}</button>
        </form>
    @endif

    <a href="{{ route('login') }}" class="link mt-6 block text-center text-sm">{{ __('Use email and password') }}</a>
@endsection
