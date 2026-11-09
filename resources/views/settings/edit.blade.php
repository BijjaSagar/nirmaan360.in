@extends('layouts.app')
@section('title', __('Settings'))
@section('content')
    <x-page-header :title="__('Settings')" />

    <div class="grid max-w-5xl gap-5 lg:grid-cols-2">
        <form method="POST" action="{{ route('settings.organization') }}" class="card">
            @csrf @method('PATCH')
            <div class="card-head"><h2 class="card-title">{{ __('Company details') }}</h2></div>
            <div class="grid gap-3 p-4 sm:grid-cols-2">
                <x-field :label="__('Company name')" name="name" class="sm:col-span-2"><input id="name" name="name" value="{{ old('name', $organization->name) }}" class="input" required></x-field>
                <x-field :label="__('GSTIN')" name="gstin"><input id="gstin" name="gstin" value="{{ old('gstin', $organization->gstin) }}" maxlength="15" class="input num uppercase"></x-field>
                <x-field :label="__('PAN')" name="pan"><input id="pan" name="pan" value="{{ old('pan', $organization->pan) }}" maxlength="10" class="input num uppercase"></x-field>
                <x-field :label="__('Mobile')" name="phone"><input id="phone" name="phone" value="{{ old('phone', $organization->phone) }}" class="input"></x-field>
                <x-field :label="__('Email')" name="email"><input id="email" name="email" type="email" value="{{ old('email', $organization->email) }}" class="input"></x-field>
                <x-field :label="__('State')" name="state" class="sm:col-span-2"><input id="state" name="state" value="{{ old('state', $organization->state) }}" class="input"></x-field>
                <x-field :label="__('Address')" name="address" class="sm:col-span-2"><textarea id="address" name="address" rows="2" class="input">{{ old('address', $organization->address) }}</textarea></x-field>
            </div>
            <div class="border-t border-rule px-4 py-3"><button class="btn btn-primary">{{ __('Save') }}</button></div>
        </form>

        <form method="POST" action="{{ route('settings.approvals') }}" class="card">
            @csrf @method('PATCH')
            <div class="card-head"><h2 class="card-title">{{ __('Approval chains') }}</h2></div>
            <div class="space-y-4 p-4">
                <p class="text-sm text-ink-3">{{ __('Each document goes through these levels in order. Leave every level blank to approve on submission.') }}</p>
                @foreach ($docTypes as $docType => $label)
                    @php $levels = $rules[$docType]->levels ?? []; @endphp
                    <div>
                        <p class="label">{{ __($label) }}</p>
                        <div class="grid grid-cols-2 gap-2 sm:grid-cols-4">
                            @for ($level = 0; $level < 4; $level++)
                                <select name="rules[{{ $docType }}][]" class="input text-xs" aria-label="{{ __($label) }} {{ $level + 1 }}">
                                    <option value="">—</option>
                                    @foreach ($levelRoles as $role => $roleLabel)
                                        <option value="{{ $role }}" @selected(($levels[$level] ?? null) === $role)>{{ __($roleLabel) }}</option>
                                    @endforeach
                                </select>
                            @endfor
                        </div>
                    </div>
                @endforeach
            </div>
            <div class="border-t border-rule px-4 py-3"><button class="btn btn-primary">{{ __('Save') }}</button></div>
        </form>
    </div>
@endsection
