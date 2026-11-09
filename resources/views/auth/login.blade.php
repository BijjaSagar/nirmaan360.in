@extends('layouts.auth')
@section('title', __('Sign in'))
@section('content')
    <h1 class="text-4xl">{{ __('Sign in') }}</h1>
    <form method="POST" action="{{ route('login') }}" class="mt-6 space-y-4">
        @csrf
        <x-field :label="__('Email')" name="email">
            <input id="email" name="email" type="email" value="{{ old('email') }}" class="input" required autofocus autocomplete="username">
        </x-field>
        <x-field :label="__('Password')" name="password">
            <input id="password" name="password" type="password" class="input" required autocomplete="current-password">
        </x-field>
        <div class="flex items-center justify-between gap-3">
            <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="remember" value="1"> {{ __('Keep me signed in') }}</label>
            @if (\App\Http\Controllers\Auth\PasswordResetController::available())
                <a href="{{ route('password.request') }}" class="link text-sm">{{ __('Forgot password?') }}</a>
            @endif
        </div>
        <button class="btn btn-primary w-full">{{ __('Sign in') }}</button>
    </form>
    @if ($otpEnabled)
        <a href="{{ route('otp.show') }}" class="link mt-4 block text-center text-sm">{{ __('Sign in with mobile OTP') }}</a>
    @endif
    @include('modules.sso.buttons')
    <p class="mt-6 text-center text-sm text-ink-3">{{ __('New here?') }} <a href="{{ route('register') }}" class="link">{{ __('Start a :days-day trial', ['days' => config('buildos.trial_days')]) }}</a></p>
@endsection
