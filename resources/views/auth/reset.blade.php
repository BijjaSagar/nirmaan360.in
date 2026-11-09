@extends('layouts.auth')
@section('title', __('Choose a new password'))
@section('content')
    <h1 class="text-4xl">{{ __('Choose a new password') }}</h1>
    <form method="POST" action="{{ route('password.update') }}" class="mt-6 space-y-4">
        @csrf
        <input type="hidden" name="token" value="{{ $token }}">
        <x-field :label="__('Email')" name="email">
            <input id="email" name="email" type="email" value="{{ old('email', $email) }}" class="input" required autocomplete="username">
        </x-field>
        <x-field :label="__('New password')" name="password" :hint="__('At least 8 characters.')">
            <input id="password" name="password" type="password" class="input" required autofocus autocomplete="new-password">
        </x-field>
        <x-field :label="__('Confirm password')" name="password_confirmation">
            <input id="password_confirmation" name="password_confirmation" type="password" class="input" required autocomplete="new-password">
        </x-field>
        <button class="btn btn-primary w-full">{{ __('Change password') }}</button>
    </form>
@endsection
