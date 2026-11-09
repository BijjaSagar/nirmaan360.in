@extends('layouts.app')
@section('title', __('Generate RA bill'))
@section('content')
    <x-page-header :title="__('Generate RA bill')" :subtitle="$project->name" />

    <form method="POST" action="{{ route('ra-bills.store', $project) }}" class="card max-w-2xl" x-data="{ type: @js(old('type', 'client')) }">
        @csrf
        <div class="grid gap-4 p-4 sm:grid-cols-2">
            <x-field :label="__('Bill type')" name="type" class="sm:col-span-2">
                <select id="type" name="type" x-model="type" class="input">
                    <option value="client">{{ __('Client bill (BOQ rates)') }}</option>
                    <option value="subcontractor">{{ __('Subcontractor bill (work order rates)') }}</option>
                </select>
            </x-field>
            <x-field x-show="type === 'subcontractor'" :label="__('Work order')" name="work_order_id" class="sm:col-span-2">
                <select id="work_order_id" name="work_order_id" class="input">
                    <option value="">{{ __('Choose') }}</option>
                    @foreach ($workOrders as $workOrder)
                        <option value="{{ $workOrder->id }}" @selected(old('work_order_id') == $workOrder->id)>{{ $workOrder->number }} · {{ $workOrder->party->name }}</option>
                    @endforeach
                </select>
            </x-field>
            <x-field :label="__('Period from')" name="period_from"><input id="period_from" name="period_from" type="date" value="{{ old('period_from', now()->startOfMonth()->format('Y-m-d')) }}" class="input" required></x-field>
            <x-field :label="__('Period to')" name="period_to"><input id="period_to" name="period_to" type="date" value="{{ old('period_to', now()->format('Y-m-d')) }}" class="input" required></x-field>
            <x-field :label="__('Material recovery (₹)')" name="material_recovery" class="sm:col-span-2"><input id="material_recovery" name="material_recovery" type="number" step="0.01" min="0" value="{{ old('material_recovery', 0) }}" class="input num"></x-field>
        </div>
        <div class="flex flex-wrap items-center justify-between gap-3 border-t border-rule px-4 py-3">
            <p class="text-sm text-ink-3" x-show="type === 'client'">{{ __(':count approved measurements are ready to bill.', ['count' => $unbilledClient]) }}</p>
            <span x-show="type !== 'client'"></span>
            <button class="btn btn-primary">{{ __('Generate draft') }}</button>
        </div>
    </form>
@endsection
