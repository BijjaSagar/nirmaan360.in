@extends('layouts.app')
@section('title', __('Convert to project'))
@section('content')
    <x-page-header :title="__('Convert to project')" :subtitle="$tender->title">
        <a href="{{ route('crm.tenders.show', $tender) }}" class="btn btn-ghost">{{ __('Cancel') }}</a>
    </x-page-header>

    <div class="grid gap-5 lg:grid-cols-3">
        <form method="POST" action="{{ route('crm.tenders.convert.store', $tender) }}" class="card lg:col-span-2">
            @csrf
            <div class="card-head"><h2 class="card-title">{{ __('New project') }}</h2></div>
            <div class="grid gap-3 p-4 sm:grid-cols-2">
                <x-field :label="__('Project name')" name="name" class="sm:col-span-2"><input id="name" name="name" value="{{ old('name', $preview['name']) }}" class="input" maxlength="160" required></x-field>
                <x-field :label="__('Project code')" name="code" :hint="__('Leave blank to make one from the name.')"><input id="code" name="code" value="{{ old('code') }}" class="input" maxlength="32"></x-field>
                <x-field :label="__('Contract value (₹)')" name="contract_value"><input id="contract_value" name="contract_value" type="number" step="0.01" min="0" inputmode="decimal" value="{{ old('contract_value', $preview['contract_value']) }}" class="input num" required></x-field>
                <x-field :label="__('Start date')" name="start_date"><input id="start_date" name="start_date" type="date" value="{{ old('start_date') }}" class="input"></x-field>
                <x-field :label="__('End date')" name="end_date"><input id="end_date" name="end_date" type="date" value="{{ old('end_date') }}" class="input"></x-field>
            </div>
            @error('plan')<p class="error px-4 pb-2">{{ $message }}</p>@enderror
            <div class="flex items-center justify-between gap-3 border-t border-rule p-4">
                <p class="text-xs text-ink-3">{{ __('Retention, TDS, GST and other billing terms start with the standard values. Change them in the project.') }}</p>
                <button class="btn btn-primary">{{ __('Create project') }}</button>
            </div>
        </form>

        <section class="card">
            <div class="card-head"><h2 class="card-title">{{ __('What comes with it') }}</h2></div>
            <dl class="space-y-3 p-4 text-sm">
                <div><dt class="eyebrow text-ink-3">{{ __('Client') }}</dt><dd>{{ $tender->party?->name ?? __('None chosen. Set one on the tender first if you want it on the project.') }}</dd></div>
                <div><dt class="eyebrow text-ink-3">{{ __('BOQ lines') }}</dt><dd class="num">{{ $preview['boq_lines'] }} · {{ inr($preview['boq_total']) }}</dd></div>
                <div><dt class="eyebrow text-ink-3">{{ __('Documents') }}</dt><dd class="num">{{ $preview['documents'] }}</dd></div>
                @if (\App\Support\Dec::of($preview['emd_held'])->isPositive())
                    <div class="rounded-[3px] border border-haldi bg-haldi/10 p-2.5 text-xs">{{ __(':amount of EMD is still held for this tender. Mark it refunded or adjusted on the tender.', ['amount' => inr($preview['emd_held'])]) }}</div>
                @endif
            </dl>
        </section>
    </div>
@endsection
