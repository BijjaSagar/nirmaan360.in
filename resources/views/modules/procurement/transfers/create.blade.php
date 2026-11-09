@extends('layouts.app')
@section('title', __('New transfer'))
@section('content')
    <x-page-header :title="__('New transfer')" :subtitle="$project->name" />

    @if ($stores->isEmpty())
        <div class="card"><x-empty :title="__('Add a store first')" :note="__('Material can only be transferred out of a store.')"><a href="{{ route('procurement.stock.index', $project) }}" class="btn btn-ghost">{{ __('Go to stores') }}</a></x-empty></div>
    @else
        <form method="POST" action="{{ route('procurement.transfers.store', $project) }}" class="space-y-5">
            @csrf
            <input type="hidden" name="client_uuid" value="{{ old('client_uuid', (string) \Illuminate\Support\Str::uuid()) }}">

            <section class="card grid gap-3 p-4 sm:grid-cols-4">
                <x-field :label="__('From store')" name="from_store_id">
                    <select id="from_store_id" name="from_store_id" class="input" required>
                        @foreach ($stores as $store)<option value="{{ $store->id }}" @selected((string) old('from_store_id') === (string) $store->id)>{{ $store->name }}</option>@endforeach
                    </select>
                </x-field>
                <x-field :label="__('To store')" name="to_store_id">
                    <select id="to_store_id" name="to_store_id" class="input" required>
                        <option value="">{{ __('Choose') }}</option>
                        @foreach ($destinations as $store)<option value="{{ $store->id }}" @selected((string) old('to_store_id') === (string) $store->id)>{{ $store->name }} ({{ $store->project->code }})</option>@endforeach
                    </select>
                </x-field>
                <x-field :label="__('Dispatch date')" name="dispatch_date"><input id="dispatch_date" name="dispatch_date" type="date" max="{{ today()->format('Y-m-d') }}" value="{{ old('dispatch_date', today()->format('Y-m-d')) }}" class="input" required></x-field>
                <x-field :label="__('Vehicle no.')" name="vehicle_no"><input id="vehicle_no" name="vehicle_no" value="{{ old('vehicle_no') }}" class="input" maxlength="30"></x-field>
                <x-field :label="__('Remarks')" name="remarks" class="sm:col-span-4"><textarea id="remarks" name="remarks" rows="2" class="input">{{ old('remarks') }}</textarea></x-field>
            </section>

            <section class="card p-4">
                <h2 class="card-title mb-3">{{ __('Materials to send') }}</h2>
                @include('modules.procurement.partials.lines')
            </section>

            <button class="btn btn-primary">{{ __('Dispatch') }}</button>
        </form>
    @endif
@endsection
