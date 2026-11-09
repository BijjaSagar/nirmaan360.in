@extends('layouts.app')
@section('title', $role->exists ? __('Edit role') : __('New role'))
@section('content')
    <x-page-header :title="$role->exists ? __('Edit role') : __('New role')">
        <a href="{{ route('team.roles.index') }}" class="btn btn-ghost">{{ __('Back to roles') }}</a>
    </x-page-header>

    @php $ticked = old('permissions', $role->permissions ?? []); @endphp

    <form method="POST" action="{{ $role->exists ? route('team.roles.update', $role) : route('team.roles.store') }}" class="space-y-5">
        @csrf
        @if ($role->exists) @method('PATCH') @endif

        <div class="card grid gap-3 p-4 sm:grid-cols-2">
            <x-field :label="__('Role name')" name="name">
                <input id="name" name="name" value="{{ old('name', $role->name) }}" class="input" required maxlength="80">
            </x-field>
            <x-field :label="__('Approves as')" name="approval_role" :hint="__('Needs an approve permission below to have any effect.')">
                <select id="approval_role" name="approval_role" class="input">
                    <option value="">{{ __('Does not approve') }}</option>
                    @foreach ($approvalRoles as $key => $label)
                        <option value="{{ $key }}" @selected(old('approval_role', $role->approval_role) === $key)>{{ __($label) }}</option>
                    @endforeach
                </select>
            </x-field>
        </div>

        <div class="card">
            <div class="card-head"><span class="card-title">{{ __('Permissions') }}</span></div>
            <p class="hint px-4 pt-3">{{ __('Only permissions you hold yourself are listed.') }}</p>
            @error('permissions')<p class="error px-4 pt-2">{{ $message }}</p>@enderror
            @error('permissions.*')<p class="error px-4 pt-2">{{ $message }}</p>@enderror
            <div class="divide-y divide-rule">
                @foreach ($groups as $module => $permissions)
                    <fieldset class="grid gap-2 px-4 py-3 sm:grid-cols-[180px_1fr]">
                        <legend class="contents"><span class="text-sm font-semibold">{{ $catalog->moduleLabel($module) }}</span></legend>
                        <div class="flex flex-wrap gap-x-4 gap-y-1">
                            @foreach ($permissions as $permission)
                                <label class="flex items-center gap-1.5 text-sm">
                                    <input type="checkbox" name="permissions[]" value="{{ $permission }}" @checked(in_array($permission, $ticked, true))>
                                    {{ $catalog->actionLabel($permission) }}
                                </label>
                            @endforeach
                        </div>
                    </fieldset>
                @endforeach
            </div>
        </div>

        <button class="btn btn-primary">{{ __('Save') }}</button>
    </form>

    @if ($role->exists)
        <form method="POST" action="{{ route('team.roles.destroy', $role) }}" class="mt-3">
            @csrf @method('DELETE')
            <button class="btn btn-danger">{{ __('Delete role') }}</button>
        </form>
    @endif
@endsection
