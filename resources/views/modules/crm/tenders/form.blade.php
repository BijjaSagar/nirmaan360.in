@extends('layouts.app')
@section('title', $tender->exists ? __('Edit tender') : __('Add tender'))
@section('content')
    @php
        $stages = \App\Services\Crm\Vocab::stages();
        $dt = fn ($value) => $value ? \Illuminate\Support\Carbon::parse($value)->format('Y-m-d\TH:i') : '';
    @endphp
    <x-page-header :title="$tender->exists ? __('Edit tender') : __('Add tender')" :subtitle="$tender->exists ? $tender->title : __('Details, dates and value')">
        <a href="{{ $tender->exists ? route('crm.tenders.show', $tender) : route('crm.tenders.index') }}" class="btn btn-ghost">{{ __('Cancel') }}</a>
    </x-page-header>

    <form method="POST" action="{{ $tender->exists ? route('crm.tenders.update', $tender) : route('crm.tenders.store') }}" class="space-y-5">
        @csrf
        @if ($tender->exists) @method('PATCH') @else <input type="hidden" name="client_uuid" value="{{ old('client_uuid', \Illuminate\Support\Str::uuid()) }}"> @endif
        <input type="hidden" name="lead_id" value="{{ old('lead_id', $tender->lead_id) }}">

        <section class="card">
            <div class="card-head"><h2 class="card-title">{{ __('Tender') }}</h2></div>
            <div class="grid gap-3 p-4 sm:grid-cols-2">
                <x-field :label="__('Title')" name="title" class="sm:col-span-2"><input id="title" name="title" value="{{ old('title', $tender->title) }}" class="input" maxlength="200" required></x-field>
                <x-field :label="__('Issuing authority')" name="authority"><input id="authority" name="authority" value="{{ old('authority', $tender->authority) }}" class="input" maxlength="160" required></x-field>
                <x-field :label="__('Tender number')" name="reference"><input id="reference" name="reference" value="{{ old('reference', $tender->reference) }}" class="input" maxlength="120"></x-field>
                <x-field :label="__('Client in your contacts')" name="party_id" :hint="__('Carried to the project as its client.')">
                    <select id="party_id" name="party_id" class="input">
                        <option value="">—</option>
                        @foreach ($parties as $party)
                            <option value="{{ $party->id }}" @selected((string) old('party_id', $tender->party_id) === (string) $party->id)>{{ $party->name }} ({{ \App\Services\Crm\Vocab::partyTypes()[$party->type] ?? $party->type }})</option>
                        @endforeach
                    </select>
                </x-field>
                <x-field :label="__('Portal link')" name="portal_url"><input id="portal_url" name="portal_url" type="url" value="{{ old('portal_url', $tender->portal_url) }}" class="input" maxlength="500" placeholder="https://"></x-field>
                <x-field :label="__('Location')" name="location"><input id="location" name="location" value="{{ old('location', $tender->location) }}" class="input" maxlength="160"></x-field>
                <x-field :label="__('State')" name="state"><input id="state" name="state" value="{{ old('state', $tender->state) }}" class="input" maxlength="60"></x-field>
            </div>
        </section>

        <section class="card">
            <div class="card-head"><h2 class="card-title">{{ __('Value and dates') }}</h2></div>
            <div class="grid gap-3 p-4 sm:grid-cols-2 lg:grid-cols-3">
                <x-field :label="__('Estimated value (₹)')" name="estimated_value"><input id="estimated_value" name="estimated_value" type="number" step="0.01" min="0" inputmode="decimal" value="{{ old('estimated_value', $tender->estimated_value) }}" class="input num"></x-field>
                <x-field :label="__('Our quoted value (₹)')" name="bid_value"><input id="bid_value" name="bid_value" type="number" step="0.01" min="0" inputmode="decimal" value="{{ old('bid_value', $tender->bid_value) }}" class="input num"></x-field>
                <x-field :label="__('Published on')" name="published_on"><input id="published_on" name="published_on" type="date" value="{{ old('published_on', $tender->published_on?->toDateString()) }}" class="input"></x-field>
                <x-field :label="__('Pre-bid meeting')" name="pre_bid_at"><input id="pre_bid_at" name="pre_bid_at" type="datetime-local" value="{{ old('pre_bid_at', $dt($tender->pre_bid_at)) }}" class="input"></x-field>
                <x-field :label="__('Pre-bid venue or link')" name="pre_bid_note" class="sm:col-span-1 lg:col-span-2"><input id="pre_bid_note" name="pre_bid_note" value="{{ old('pre_bid_note', $tender->pre_bid_note) }}" class="input" maxlength="255"></x-field>
                <x-field :label="__('Submission deadline')" name="due_at"><input id="due_at" name="due_at" type="datetime-local" value="{{ old('due_at', $dt($tender->due_at)) }}" class="input"></x-field>
                <x-field :label="__('Bid opening')" name="opens_at"><input id="opens_at" name="opens_at" type="datetime-local" value="{{ old('opens_at', $dt($tender->opens_at)) }}" class="input"></x-field>
            </div>
        </section>

        <section class="card">
            <div class="card-head"><h2 class="card-title">{{ __('Team and notes') }}</h2></div>
            <div class="grid gap-3 p-4 sm:grid-cols-2">
                <x-field :label="__('Owner')" name="owner_id">
                    <select id="owner_id" name="owner_id" class="input">
                        <option value="">—</option>
                        @foreach ($owners as $owner)<option value="{{ $owner->id }}" @selected((string) old('owner_id', $tender->owner_id) === (string) $owner->id)>{{ $owner->name }}</option>@endforeach
                    </select>
                </x-field>
                @unless ($tender->exists)
                    <x-field :label="__('Starting stage')" name="stage">
                        <select id="stage" name="stage" class="input">
                            @foreach (\App\Models\CrmTender::OPEN_STAGES as $stage)<option value="{{ $stage }}" @selected(old('stage', $tender->stage) === $stage)>{{ $stages[$stage] }}</option>@endforeach
                        </select>
                    </x-field>
                @endunless
                @include('modules.crm.partials.tag-input', ['tags' => $tender->tags])
                @include('modules.crm.partials.custom-inputs', ['values' => $tender->custom_fields ?? []])
                <x-field :label="__('Notes')" name="notes" class="sm:col-span-2"><textarea id="notes" name="notes" rows="3" class="input" maxlength="5000">{{ old('notes', $tender->notes) }}</textarea></x-field>
            </div>
        </section>

        <div class="flex justify-end gap-2">
            <button class="btn btn-primary">{{ $tender->exists ? __('Save changes') : __('Add tender') }}</button>
        </div>
    </form>
@endsection
