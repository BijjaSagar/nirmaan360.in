@extends('layouts.app')
@section('title', __('Issue material'))
@section('content')
    <x-page-header :title="__('Issue material')" :subtitle="$project->name" />

    @if ($stores->isEmpty())
        <div class="card"><x-empty :title="__('Add a store first')" :note="__('Material can only be issued from a store.')"><a href="{{ route('procurement.stock.index', $project) }}" class="btn btn-ghost">{{ __('Go to stores') }}</a></x-empty></div>
    @else
        <form method="POST" action="{{ route('procurement.issues.store', $project) }}" class="space-y-5">
            @csrf
            <input type="hidden" name="client_uuid" value="{{ old('client_uuid', (string) \Illuminate\Support\Str::uuid()) }}">

            <section class="card grid gap-3 p-4 sm:grid-cols-3">
                <x-field :label="__('From store')" name="store_id">
                    <select id="store_id" name="store_id" class="input" required>
                        @foreach ($stores as $store)<option value="{{ $store->id }}" @selected((string) old('store_id') === (string) $store->id)>{{ $store->name }}</option>@endforeach
                    </select>
                </x-field>
                <x-field :label="__('Date')" name="issue_date"><input id="issue_date" name="issue_date" type="date" max="{{ today()->format('Y-m-d') }}" value="{{ old('issue_date', today()->format('Y-m-d')) }}" class="input" required></x-field>
                <x-field :label="__('Issued to')" name="issued_to"><input id="issued_to" name="issued_to" value="{{ old('issued_to') }}" class="input" maxlength="160" placeholder="{{ __('Contractor or foreman') }}"></x-field>
                <x-field :label="__('Remarks')" name="remarks" class="sm:col-span-3"><textarea id="remarks" name="remarks" rows="2" class="input">{{ old('remarks') }}</textarea></x-field>
            </section>

            <section class="card p-4">
                <h2 class="card-title mb-3">{{ __('Materials issued') }}</h2>
                @include('modules.procurement.partials.lines', ['showActivity' => true])
            </section>

            <button class="btn btn-primary">{{ __('Post issue') }}</button>
        </form>
    @endif
@endsection
