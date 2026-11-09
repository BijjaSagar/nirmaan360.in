@extends('layouts.app')
@section('title', __('Single sign-on'))
@section('content')
    <x-page-header :title="__('Single sign-on')" :subtitle="__('Let your team sign in with their Google or Microsoft account.')" />

    @unless ($planAllows)
        <p class="mb-5 rounded-[3px] border border-haldi bg-haldi/15 px-3 py-2 text-sm">{{ __('Single sign-on is part of the Business and Enterprise plans. Your team signs in with a password for now.') }}</p>
    @endunless

    <div class="card mb-6 p-4 text-sm">
        <p class="font-semibold">{{ __('How it works') }}</p>
        <ul class="mt-2 list-disc space-y-1 pl-5 text-ink-2">
            <li>{{ __('It is for people who already have a Nirmaan360 account. Add them under Team first.') }}</li>
            <li>{{ __('They are matched by email, and only when Google or Microsoft confirms that email address.') }}</li>
            <li>{{ __('Signing in this way never creates a company or a new user.') }}</li>
            <li>{{ __('Password sign-in keeps working for everyone.') }}</li>
        </ul>
    </div>

    <div class="grid gap-4 lg:grid-cols-2">
        @foreach ($providers as $key => $label)
            @php $connected = $gateway->isConnected($key); @endphp
            <div class="card p-4">
                <div class="mb-3 flex items-center justify-between gap-3">
                    <p class="card-title">{{ $label }}</p>
                    <span class="stamp {{ $connected ? 'border-moss text-moss' : 'border-ink-3 text-ink-3' }}">{{ $connected ? __('Connected') : __('Not connected') }}</span>
                </div>

                @if ($connected)
                    <p class="text-sm text-ink-2">{{ __('The sign-in button is shown on the sign-in page.') }}</p>
                @else
                    <p class="text-sm text-ink-2">{{ __('The platform owner has not saved the :provider keys yet, so there is no sign-in button.', ['provider' => $label]) }}</p>
                @endif

                @if ($canSaveKeys)
                    <form method="POST" action="{{ route('sso.keys.update') }}" class="mt-4 space-y-3 border-t border-rule pt-4">
                        @csrf @method('PATCH')
                        <input type="hidden" name="provider" value="{{ $key }}">
                        <p class="eyebrow text-[10px] text-ink-3">{{ __('Platform keys') }}</p>
                        <p class="text-xs text-ink-3">{{ __('Register this address as the redirect URI with :provider:', ['provider' => $label]) }} <code class="num break-all">{{ $gateway->redirectUri($key) }}</code></p>
                        <x-field :label="__('Client ID')" name="client_id"><input id="client_id_{{ $key }}" name="client_id" value="{{ old('provider') === $key ? old('client_id') : $gateway->clientId($key) }}" class="input" autocomplete="off"></x-field>
                        <x-field :label="__('Client secret')" name="client_secret" :hint="$connected ? __('Saved. Leave empty to keep it.') : null"><input id="client_secret_{{ $key }}" name="client_secret" type="password" class="input" autocomplete="new-password"></x-field>
                        @if ($key === 'microsoft')
                            <x-field :label="__('Directory (tenant) ID')" name="tenant" :hint="__('Leave empty to allow any work, school or personal account. In Azure, add the optional claim xms_edov to the ID token so Microsoft confirms the email domain.')"><input id="tenant" name="tenant" value="{{ $tenant === 'common' ? '' : $tenant }}" class="input" autocomplete="off"></x-field>
                        @endif
                        <div class="flex gap-2">
                            <button name="action" value="save" class="btn btn-primary">{{ __('Save keys') }}</button>
                            @if ($connected)<button name="action" value="disconnect" class="btn btn-danger" onclick="return confirm(@js(__('Disconnect this provider? Its sign-in button will disappear.')))">{{ __('Disconnect') }}</button>@endif
                        </div>
                    </form>
                @endif
            </div>
        @endforeach
    </div>
@endsection
