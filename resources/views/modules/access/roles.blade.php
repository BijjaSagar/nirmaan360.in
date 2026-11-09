@extends('layouts.app')
@section('title', __('Roles'))
@section('content')
    <x-page-header :title="__('Roles')" :subtitle="__('Make a role from just the permissions a person needs.')">
        <a href="{{ route('team.index') }}" class="btn btn-ghost">{{ __('Back to team') }}</a>
        <a href="{{ route('team.roles.create') }}" class="btn btn-primary">{{ __('New role') }}</a>
    </x-page-header>

    <div class="card mb-5 overflow-x-auto">
        <div class="card-head"><span class="card-title">{{ __('Your roles') }}</span></div>
        @if ($roles->isEmpty())
            <x-empty :title="__('No custom roles yet')" :note="__('The built-in roles below keep working as they are.')" />
        @else
            <table class="ledger min-w-[560px]">
                <thead><tr><th>{{ __('Role') }}</th><th class="r">{{ __('Permissions') }}</th><th class="r">{{ __('People') }}</th><th></th></tr></thead>
                <tbody>
                @foreach ($roles as $role)
                    <tr>
                        <td class="font-semibold">{{ $role->name }}</td>
                        <td class="r num">{{ count($role->permissions) }}</td>
                        <td class="r num">{{ $role->users_count }}</td>
                        <td class="r"><a href="{{ route('team.roles.edit', $role) }}" class="link text-sm">{{ __('Edit') }}</a></td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        @endif
    </div>

    <div class="card">
        <div class="card-head"><span class="card-title">{{ __('Built-in roles') }}</span></div>
        <ul class="divide-y divide-rule">
            @foreach ($builtIn as $role)
                <li class="px-4 py-2 text-sm">{{ __($role['label'] ?? '') }}</li>
            @endforeach
        </ul>
    </div>
@endsection
