@extends('layouts.auth')
@section('title', __('Reset your password'))
@section('content')
    <h1 class="text-4xl">{{ __('Reset your password') }}</h1>
    <p class="mt-1 text-sm text-ink-3">{{ __('Enter your email and we will send you a reset link.') }}</p>
    <form method="POST" action="{{ route('password.email') }}" class="mt-6 space-y-4">
        @csrf
        <x-field :label="__('Email')" name="email">
            <input id="email" name="email" type="email" value="{{ old('email') }}" class="input" required autofocus autocomplete="username">
        </x-field>
        <button class="btn btn-primary w-full">{{ __('Send reset link') }}</button>
    </form>
    <a href="{{ route('login') }}" class="link mt-6 block text-center text-sm">{{ __('Back to sign in') }}</a>
@endsection
