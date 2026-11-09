@extends('layouts.app')
@section('title', __('Email'))
@section('content')
    <x-page-header :title="__('Email')" :subtitle="__('Used for password reset emails')">
        <x-stamp :status="$configured ? 'active' : 'draft'" />
    </x-page-header>
    @include('admin._tabs')

    <div class="grid max-w-5xl gap-5 lg:grid-cols-2">
        <form method="POST" action="{{ route('admin.email.update') }}" class="card" autocomplete="off">
            @csrf @method('PATCH')
            <div class="card-head"><h2 class="card-title">{{ __('SMTP server') }}</h2></div>
            <div class="grid gap-3 p-4 sm:grid-cols-2">
                <x-field :label="__('Host')" name="mail_host" :hint="__('For example smtp.hostinger.com or smtp.resend.com')" class="sm:col-span-2"><input id="mail_host" name="mail_host" value="{{ old('mail_host', $values['mail_host']) }}" class="input" autocomplete="off"></x-field>
                <x-field :label="__('Port')" name="mail_port"><input id="mail_port" name="mail_port" type="number" value="{{ old('mail_port', $values['mail_port'] ?? 587) }}" class="input num"></x-field>
                <x-field :label="__('Security')" name="mail_security">
                    <select id="mail_security" name="mail_security" class="input">
                        <option value="tls" @selected(old('mail_security', $values['mail_security']) !== 'ssl')>TLS (587)</option>
                        <option value="ssl" @selected(old('mail_security', $values['mail_security']) === 'ssl')>SSL (465)</option>
                    </select>
                </x-field>
                <x-field :label="__('Username')" name="mail_username"><input id="mail_username" name="mail_username" value="{{ old('mail_username', $values['mail_username']) }}" class="input" autocomplete="off"></x-field>
                <x-field :label="__('Password')" name="mail_password" :hint="$hasPassword ? __('Saved. Leave blank to keep it.') : __('Not saved yet.')"><input id="mail_password" name="mail_password" type="password" class="input" autocomplete="new-password"></x-field>
                <x-field :label="__('From email')" name="mail_from_address"><input id="mail_from_address" name="mail_from_address" type="email" value="{{ old('mail_from_address', $values['mail_from_address']) }}" class="input"></x-field>
                <x-field :label="__('From name')" name="mail_from_name"><input id="mail_from_name" name="mail_from_name" value="{{ old('mail_from_name', $values['mail_from_name']) }}" class="input"></x-field>
            </div>
            <div class="border-t border-rule px-4 py-3"><button class="btn btn-primary">{{ __('Save') }}</button></div>
        </form>

        <section class="card self-start p-4">
            <h2 class="card-title">{{ __('Send a test email') }}</h2>
            <p class="mt-1 text-sm text-ink-3">{{ __('Sends a short email to :email.', ['email' => auth()->user()->email]) }}</p>
            <form method="POST" action="{{ route('admin.email.test') }}" class="mt-3">@csrf<button class="btn btn-ghost" @disabled(! $configured)>{{ __('Send test') }}</button></form>
        </section>
    </div>
@endsection
