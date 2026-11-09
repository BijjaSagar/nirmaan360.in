@extends('layouts.auth')
@section('title', __('Join your team'))
@section('content')
    <h1 class="text-4xl">{{ __('Join :company', ['company' => $invitation->organization->name]) }}</h1>
    <p class="mt-2 text-sm text-ink-3">{{ __('You have been invited as :role.', ['role' => __((string) $roleLabel)]) }}</p>

    @if ($signedInAs)
        <p class="mt-6 rounded-lg border border-haldi bg-haldi/15 px-3 py-2 text-sm">{{ __('You are signed in as :name. Sign out, then open this link again.', ['name' => $signedInAs->name]) }}</p>
        <form method="POST" action="{{ route('logout') }}" class="mt-4">@csrf<button class="btn btn-primary w-full">{{ __('Sign out') }}</button></form>
    @else
        <form method="POST" action="{{ request()->fullUrl() }}" class="mt-6 space-y-4">
            @csrf
            <x-field :label="__('Email')">
                <input value="{{ $invitation->email }}" class="input" readonly aria-readonly="true">
            </x-field>
            <x-field :label="__('Your name')" name="name">
                <input id="name" name="name" value="{{ old('name') }}" class="input" required autofocus autocomplete="name" maxlength="120">
            </x-field>
            <x-field :label="__('Choose a password')" name="password" :hint="__('At least 8 characters.')">
                <input id="password" name="password" type="password" class="input" required autocomplete="new-password">
            </x-field>
            <x-field :label="__('Confirm password')" name="password_confirmation">
                <input id="password_confirmation" name="password_confirmation" type="password" class="input" required autocomplete="new-password">
            </x-field>
            @error('email')<p class="error">{{ $message }}</p>@enderror
            <button class="btn btn-primary w-full">{{ __('Accept invitation') }}</button>
        </form>
    @endif
@endsection
