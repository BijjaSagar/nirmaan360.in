@extends('layouts.app')
@section('title', $lead->exists ? __('Edit lead') : __('Add lead'))
@section('content')
    <x-page-header :title="$lead->exists ? __('Edit lead') : __('Add lead')" :subtitle="$lead->exists ? $lead->name : __('Who is asking, and what for')">
        <a href="{{ $lead->exists ? route('crm.leads.show', $lead) : route('crm.leads.index') }}" class="btn btn-ghost">{{ __('Cancel') }}</a>
    </x-page-header>

    <form method="POST" action="{{ $lead->exists ? route('crm.leads.update', $lead) : route('crm.leads.store') }}" class="card">
        @csrf
        @if ($lead->exists) @method('PATCH') @else <input type="hidden" name="client_uuid" value="{{ old('client_uuid', \Illuminate\Support\Str::uuid()) }}"> @endif
        <div class="grid gap-3 p-4 sm:grid-cols-2">
            <x-field :label="__('Contact name')" name="name"><input id="name" name="name" value="{{ old('name', $lead->name) }}" class="input" maxlength="160" required></x-field>
            <x-field :label="__('Company')" name="company_name"><input id="company_name" name="company_name" value="{{ old('company_name', $lead->company_name) }}" class="input" maxlength="160"></x-field>
            <x-field :label="__('Mobile')" name="phone" :hint="__('With country code if not an Indian number, for example +971...')"><input id="phone" name="phone" value="{{ old('phone', $lead->phone) }}" class="input" inputmode="tel" maxlength="20"></x-field>
            <x-field :label="__('Email')" name="email"><input id="email" name="email" type="email" value="{{ old('email', $lead->email) }}" class="input" maxlength="190"></x-field>
            <x-field :label="__('Source')" name="source">
                <select id="source" name="source" class="input">@foreach (\App\Services\Crm\Vocab::sources() as $key => $label)<option value="{{ $key }}" @selected(old('source', $lead->source) === $key)>{{ $label }}</option>@endforeach</select>
            </x-field>
            <x-field :label="__('Expected value (₹)')" name="expected_value"><input id="expected_value" name="expected_value" type="number" step="0.01" min="0" inputmode="decimal" value="{{ old('expected_value', $lead->expected_value) }}" class="input num"></x-field>
            <x-field :label="__('Location')" name="location"><input id="location" name="location" value="{{ old('location', $lead->location) }}" class="input" maxlength="160"></x-field>
            <x-field :label="__('Owner')" name="owner_id">
                <select id="owner_id" name="owner_id" class="input">
                    <option value="">—</option>
                    @foreach ($owners as $owner)<option value="{{ $owner->id }}" @selected((string) old('owner_id', $lead->owner_id) === (string) $owner->id)>{{ $owner->name }}</option>@endforeach
                </select>
            </x-field>
            @include('modules.crm.partials.tag-input', ['tags' => $lead->tags])
            @include('modules.crm.partials.custom-inputs', ['values' => $lead->custom_fields ?? []])
            <x-field :label="__('Notes')" name="notes" class="sm:col-span-2"><textarea id="notes" name="notes" rows="3" class="input" maxlength="5000">{{ old('notes', $lead->notes) }}</textarea></x-field>
        </div>
        <div class="flex justify-end border-t border-rule p-4"><button class="btn btn-primary">{{ $lead->exists ? __('Save changes') : __('Add lead') }}</button></div>
    </form>
@endsection
