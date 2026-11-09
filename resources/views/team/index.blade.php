@extends('layouts.app')
@section('title', __('Team'))
@section('content')
    <x-page-header :title="__('Team')" />

    @if ($canAdd)
        <form method="POST" action="{{ route('team.store') }}" class="card mb-5 grid gap-3 p-4 sm:grid-cols-6">
            @csrf
            <x-field :label="__('Name')" name="name"><input id="name" name="name" value="{{ old('name') }}" class="input" required></x-field>
            <x-field :label="__('Email')" name="email" class="sm:col-span-2"><input id="email" name="email" type="email" value="{{ old('email') }}" class="input" required autocomplete="off"></x-field>
            <x-field :label="__('Mobile')" name="phone"><input id="phone" name="phone" value="{{ old('phone') }}" class="input" inputmode="tel"></x-field>
            <x-field :label="__('Role')" name="role">
                <select id="role" name="role" class="input">
                    @foreach ($roles as $key => $role)<option value="{{ $key }}" @selected(old('role', 'engineer') === $key)>{{ __($role['label']) }}</option>@endforeach
                </select>
            </x-field>
            <x-field :label="__('Temporary password')" name="password"><input id="password" name="password" type="password" class="input" required autocomplete="new-password"></x-field>
            <div class="sm:col-span-6"><button class="btn btn-primary">{{ __('Add member') }}</button></div>
        </form>
    @else
        <p class="mb-5 rounded-[3px] border border-haldi bg-haldi/15 px-3 py-2 text-sm">{{ __('Your plan user limit has been reached. Upgrade to add more.') }}</p>
    @endif

    <div class="card overflow-x-auto">
        <table class="ledger min-w-[720px]">
            <thead><tr><th>{{ __('Name') }}</th><th>{{ __('Email') }}</th><th>{{ __('Mobile') }}</th><th>{{ __('Role and access') }}</th></tr></thead>
            <tbody>
            @foreach ($users as $user)
                <tr>
                    <td class="font-semibold">{{ $user->name }}</td>
                    <td>{{ $user->email }}</td>
                    <td class="num text-xs">{{ $user->phone ?? '—' }}</td>
                    <td>
                        @if ($user->is(auth()->user()))
                            <span class="text-sm text-ink-3">{{ __($user->roleLabel()) }} · {{ __('You') }}</span>
                        @else
                            <form method="POST" action="{{ route('team.update', $user) }}" class="flex flex-wrap items-center gap-2">
                                @csrf @method('PATCH')
                                <select name="role" class="input w-44 py-1 text-xs" aria-label="{{ __('Role') }}">
                                    @foreach ($roles as $key => $role)<option value="{{ $key }}" @selected($user->role === $key)>{{ __($role['label']) }}</option>@endforeach
                                </select>
                                <label class="flex items-center gap-1 text-xs"><input type="checkbox" name="is_active" value="1" @checked($user->is_active)> {{ __('Active') }}</label>
                                <button class="btn btn-ghost py-0.5 text-xs">{{ __('Save') }}</button>
                            </form>
                        @endif
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
@endsection
