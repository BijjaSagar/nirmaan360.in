@extends('layouts.app')
@section('title', $member->name)
@section('content')
    <x-page-header :title="$member->name" :subtitle="$member->email">
        <a href="{{ route('team.index') }}" class="btn btn-ghost">{{ __('Back to team') }}</a>
    </x-page-header>

    @if ($isSelf)
        <p class="card mb-5 p-4 text-sm text-ink-3">{{ __('You cannot change your own role or deactivate yourself.') }}</p>
    @else
        <form method="POST" action="{{ route('team.update', $member) }}" class="card mb-5 space-y-4 p-4">
            @csrf @method('PATCH')

            <div class="grid gap-3 sm:grid-cols-2">
                <x-field :label="__('Role')" name="role">
                    <select id="role" name="role" class="input">
                        @foreach ($roleOptions as $key => $label)
                            <option value="{{ $key }}" @selected(old('role', $member->roleKey()) === $key)>{{ $label }}</option>
                        @endforeach
                    </select>
                </x-field>
                <div class="flex items-end pb-2">
                    <label class="flex items-center gap-1.5 text-sm">
                        <input type="checkbox" name="is_active" value="1" @checked(old('is_active', $member->is_active))>
                        {{ __('Active') }}
                    </label>
                </div>
            </div>
            <p class="hint">{{ __('Deactivating someone signs out all their devices.') }}</p>

            @include('modules.access._project-access', [
                'access' => old('project_access', $member->limited_to_projects ? 'chosen' : 'all'),
                'selected' => array_map('intval', old('project_ids', $assigned)),
            ])
            <p class="hint">{{ __('Owners and admins always see every project.') }}</p>

            <button class="btn btn-primary">{{ __('Save') }}</button>
        </form>
    @endif

    @include('modules.access._devices', [
        'devices' => $devices,
        'revokeRoute' => fn ($id) => route('team.devices.destroy', [$member, $id]),
        'revokeAllUrl' => route('team.devices.destroy-all', $member),
    ])
@endsection
