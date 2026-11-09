@extends('layouts.app')
@section('title', $party->exists ? __('Edit company') : __('Add company'))
@section('content')
    <x-page-header :title="$party->exists ? __('Edit company') : __('Add company')" :subtitle="$party->exists ? $party->name : null">
        <a href="{{ $party->exists ? route('crm.companies.show', $party) : route('crm.companies.index') }}" class="btn btn-ghost">{{ __('Cancel') }}</a>
    </x-page-header>

    <form method="POST" action="{{ $party->exists ? route('crm.companies.update', $party) : route('crm.companies.store') }}" class="card">
        @csrf
        @if ($party->exists) @method('PATCH') @endif
        <div class="grid gap-3 p-4 sm:grid-cols-2">
            <x-field :label="__('Type')" name="type">
                <select id="type" name="type" class="input">@foreach (\App\Services\Crm\Vocab::partyTypes() as $key => $label)<option value="{{ $key }}" @selected(old('type', $party->type) === $key)>{{ $label }}</option>@endforeach</select>
            </x-field>
            <x-field :label="__('Name')" name="name"><input id="name" name="name" value="{{ old('name', $party->name) }}" class="input" maxlength="160" required></x-field>
            <x-field :label="__('Contact person')" name="contact_person"><input id="contact_person" name="contact_person" value="{{ old('contact_person', $party->contact_person) }}" class="input" maxlength="120"></x-field>
            <x-field :label="__('Mobile')" name="phone" :hint="__('With country code if not an Indian number, for example +971...')"><input id="phone" name="phone" value="{{ old('phone', $party->phone) }}" class="input" inputmode="tel" maxlength="20"></x-field>
            <x-field :label="__('Email')" name="email"><input id="email" name="email" type="email" value="{{ old('email', $party->email) }}" class="input" maxlength="190"></x-field>
            <x-field :label="__('GSTIN')" name="gstin"><input id="gstin" name="gstin" value="{{ old('gstin', $party->gstin) }}" class="input num uppercase" maxlength="15"></x-field>
            <x-field :label="__('PAN')" name="pan"><input id="pan" name="pan" value="{{ old('pan', $party->pan) }}" class="input num uppercase" maxlength="10"></x-field>
            <x-field :label="__('Owner')" name="owner_id">
                <select id="owner_id" name="owner_id" class="input">
                    <option value="">—</option>
                    @foreach ($owners as $owner)<option value="{{ $owner->id }}" @selected((string) old('owner_id', $profile?->owner_id) === (string) $owner->id)>{{ $owner->name }}</option>@endforeach
                </select>
            </x-field>
            <x-field :label="__('Address')" name="address" class="sm:col-span-2"><input id="address" name="address" value="{{ old('address', $party->address) }}" class="input" maxlength="500"></x-field>
            @include('modules.crm.partials.tag-input', ['tags' => $profile?->tags ?? []])
            @include('modules.crm.partials.custom-inputs', ['values' => $profile?->custom_fields ?? []])
            <x-field :label="__('Notes')" name="notes" class="sm:col-span-2"><textarea id="notes" name="notes" rows="3" class="input" maxlength="5000">{{ old('notes', $party->notes) }}</textarea></x-field>
        </div>
        <div class="flex justify-end border-t border-rule p-4"><button class="btn btn-primary">{{ $party->exists ? __('Save changes') : __('Add company') }}</button></div>
    </form>
@endsection
