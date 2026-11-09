@extends('layouts.app')
@section('title', __('API keys'))
@section('content')
    <x-page-header :title="__('API keys')" :subtitle="__('Let your own tools read your projects, BOQ, daily reports and bills.')">
        <a href="{{ route('api-docs') }}" class="btn btn-ghost">{{ __('API documentation') }}</a>
    </x-page-header>

    @if ($newKey)
        <div class="mb-6 rounded-[4px] border border-moss bg-moss/10 p-4" role="status" x-data="{ copied: false }">
            <p class="font-semibold text-moss">{{ __('Key ":name" created. Copy it now.', ['name' => $newKey['name']]) }}</p>
            <p class="mb-2 text-sm text-ink-2">{{ __('This is the only time it is shown. We keep only a fingerprint, so it cannot be shown again. If you lose it, revoke it and make a new one.') }}</p>
            <div class="flex flex-wrap items-center gap-2">
                <code class="num break-all rounded-[3px] border border-rule bg-sheet px-2 py-1.5 text-sm" x-ref="secret">{{ $newKey['secret'] }}</code>
                <button type="button" class="btn btn-ink" @click="navigator.clipboard.writeText($refs.secret.textContent.trim()).then(() => copied = true)" x-text="copied ? @js(__('Copied')) : @js(__('Copy'))"></button>
            </div>
        </div>
    @endif

    <form method="POST" action="{{ route('api-keys.store') }}" class="card mb-6 grid gap-3 p-4 sm:grid-cols-6">
        @csrf
        <p class="card-title sm:col-span-6">{{ __('New key') }}</p>
        <x-field :label="__('Name')" name="name" class="sm:col-span-3" :hint="__('What will use this key, for example Power BI.')"><input id="name" name="name" value="{{ old('name') }}" class="input" maxlength="80" required></x-field>
        <x-field :label="__('Requests a minute')" name="rate_limit_per_minute" class="sm:col-span-1"><input id="rate_limit_per_minute" name="rate_limit_per_minute" type="number" min="10" max="{{ $maxRate }}" value="{{ old('rate_limit_per_minute', $defaultRate) }}" class="input num"></x-field>
        <x-field :label="__('Expires on')" name="expires_on" class="sm:col-span-2" :hint="__('Optional.')"><input id="expires_on" name="expires_on" type="date" min="{{ now()->addDay()->format('Y-m-d') }}" value="{{ old('expires_on') }}" class="input"></x-field>
        <fieldset class="sm:col-span-6">
            <legend class="label">{{ __('What it can read') }}</legend>
            <div class="flex flex-wrap gap-x-5 gap-y-1 text-sm">
                @foreach ($scopes as $scope => $label)
                    <label class="inline-flex items-center gap-1.5"><input type="checkbox" name="scopes[]" value="{{ $scope }}" @checked(in_array($scope, old('scopes', array_keys($scopes)), true))> {{ __($label) }}</label>
                @endforeach
            </div>
            @error('scopes')<p class="error">{{ $message }}</p>@enderror
        </fieldset>
        <div class="sm:col-span-6"><button class="btn btn-primary">{{ __('Create key') }}</button></div>
    </form>

    <div class="card overflow-x-auto">
        @if ($keys->isEmpty())
            <x-empty :title="__('No keys yet')" />
        @else
            <table class="ledger min-w-[820px]">
                <thead><tr><th>{{ __('Name') }}</th><th>{{ __('Key') }}</th><th>{{ __('Can read') }}</th><th class="r">{{ __('Per minute') }}</th><th>{{ __('Last used') }}</th><th>{{ __('Status') }}</th><th></th></tr></thead>
                <tbody>
                @foreach ($keys as $key)
                    <tr>
                        <td class="font-semibold">{{ $key->name }}<span class="block text-xs font-normal text-ink-3">{{ __('Made by :name on :date', ['name' => $key->creator?->name ?? '—', 'date' => $key->created_at->format('d M Y')]) }}</span></td>
                        <td class="num text-xs">{{ $key->prefix }}…</td>
                        <td class="text-xs">{{ collect($key->scopes)->map(fn ($scope) => __($scopes[$scope] ?? $scope))->implode(', ') }}</td>
                        <td class="r">{{ $key->rate_limit_per_minute }}</td>
                        <td class="text-xs">{{ $key->last_used_at ? $key->last_used_at->diffForHumans() : __('Never') }}</td>
                        <td>
                            @if ($key->revoked_at)<span class="stamp border-laterite text-laterite">{{ __('Revoked') }}</span>
                            @elseif (! $key->isActive())<span class="stamp border-ink-3 text-ink-3">{{ __('Expired') }}</span>
                            @else<x-stamp status="active" />@endif
                        </td>
                        <td class="r">
                            @if (! $key->revoked_at)
                                <form method="POST" action="{{ route('api-keys.revoke', $key) }}" onsubmit="return confirm(@js(__('Revoke this key? Tools using it will stop working.')))">@csrf<button class="link text-xs">{{ __('Revoke') }}</button></form>
                            @endif
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        @endif
    </div>
@endsection
