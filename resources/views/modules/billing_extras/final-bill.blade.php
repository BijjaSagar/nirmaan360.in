@extends('layouts.app')
@section('title', __('Prepare final bill'))
@section('content')
    <x-page-header :title="__('Prepare final bill')" :subtitle="($workOrder ? $workOrder->number.' · ' : __('Client contract').' · ').($position['party']?->name ?? '—')" />

    <div class="grid grid-cols-2 gap-3 sm:grid-cols-3">
        <x-stat :label="__('Retention to release')" :value="inr($position['retention_held'])" />
        <x-stat :label="__('Advance to recover')" :value="inr($position['advance_outstanding'])" />
        <x-stat :label="__('Outstanding so far')" :value="inr($position['outstanding'])" />
    </div>

    <form method="POST" action="{{ route('billing-extras.final-bills.store', $project) }}" class="card mt-5 max-w-2xl">
        @csrf
        <input type="hidden" name="type" value="{{ $type }}">
        @if ($workOrder)<input type="hidden" name="work_order_id" value="{{ $workOrder->id }}">@endif
        <div class="grid gap-4 p-4 sm:grid-cols-2">
            <p class="text-sm text-ink-2 sm:col-span-2">{{ __('The final bill adds any approved work not yet billed, releases all retention held and recovers all remaining advance. No further RA bills can be raised while it stands, and approving it closes the contract.') }}</p>
            <x-field :label="__('Period from')" name="period_from"><input id="period_from" name="period_from" type="date" value="{{ old('period_from', now()->startOfMonth()->format('Y-m-d')) }}" class="input" required></x-field>
            <x-field :label="__('Period to')" name="period_to"><input id="period_to" name="period_to" type="date" value="{{ old('period_to', now()->format('Y-m-d')) }}" class="input" required></x-field>
            @if ($workOrder?->rate_type !== 'labour_only')
                <x-field :label="__('Material recovery (₹)')" name="material_recovery" class="sm:col-span-2"><input id="material_recovery" name="material_recovery" type="number" step="0.01" min="0" value="{{ old('material_recovery', 0) }}" class="input num"></x-field>
            @endif
            <x-field :label="__('GST on this bill')" name="gst" class="sm:col-span-2">
                <select id="gst" name="gst" class="input">
                    <option value="with" @selected(old('gst', 'with') === 'with')>{{ __('With GST') }}</option>
                    <option value="without" @selected(old('gst') === 'without')>{{ __('Without GST') }}</option>
                </select>
            </x-field>
            @error('type')<p class="error sm:col-span-2">{{ $message }}</p>@enderror
            @error('work_order_id')<p class="error sm:col-span-2">{{ $message }}</p>@enderror
        </div>
        <div class="flex justify-end border-t border-rule px-4 py-3">
            <button class="btn btn-primary">{{ __('Generate final bill') }}</button>
        </div>
    </form>
@endsection
