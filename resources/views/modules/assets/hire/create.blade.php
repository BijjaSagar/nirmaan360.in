@extends('layouts.app')
@section('title', __('New hire bill'))
@php
    $lastMonth = now()->subMonthNoOverflow();
    $unitFor = fn (?string $basis) => ['hour' => __('hours'), 'day' => __('days'), 'km' => __('km'), 'month' => __('months')][$basis] ?? '';
@endphp
@section('content')
    <x-page-header :title="__('New hire bill')" :subtitle="__('Work out what a hired machine is owed for a period')" />

    <div class="grid gap-5 lg:grid-cols-2">
        <form method="POST" action="{{ route('hire-bills.store') }}" class="card grid gap-4 p-4 sm:grid-cols-2">
            @csrf
            <input type="hidden" name="client_uuid" value="{{ \Illuminate\Support\Str::uuid() }}">
            <x-field :label="__('Hired machine')" name="asset_id" class="sm:col-span-2">
                <select id="asset_id" name="asset_id" class="input" required>
                    <option value="">—</option>
                    @foreach ($assets as $asset)<option value="{{ $asset->id }}" @selected((string) old('asset_id', $selected) === (string) $asset->id)>{{ $asset->code }} · {{ $asset->name }} ({{ __(config('modules.assets.hire_bases')[$asset->hire_basis] ?? '') }} · {{ inr($asset->hire_rate) }})</option>@endforeach
                </select>
            </x-field>
            <x-field :label="__('From')" name="period_from"><input id="period_from" name="period_from" type="date" value="{{ old('period_from', $lastMonth->startOfMonth()->format('Y-m-d')) }}" class="input" required></x-field>
            <x-field :label="__('To')" name="period_to"><input id="period_to" name="period_to" type="date" value="{{ old('period_to', $lastMonth->endOfMonth()->format('Y-m-d')) }}" class="input" required></x-field>
            <x-field :label="__('Owner\'s invoice no.')" name="vendor_invoice_no"><input id="vendor_invoice_no" name="vendor_invoice_no" value="{{ old('vendor_invoice_no') }}" class="input" maxlength="60"></x-field>
            <div></div>
            <x-field :label="__('GST %')" name="gst_pct"><input id="gst_pct" name="gst_pct" type="number" step="0.01" min="0" max="100" value="{{ old('gst_pct', 0) }}" class="input num"></x-field>
            <x-field :label="__('TDS %')" name="tds_pct" :hint="__('Worked out on the hire charges, before GST.')"><input id="tds_pct" name="tds_pct" type="number" step="0.01" min="0" max="100" value="{{ old('tds_pct', 0) }}" class="input num"></x-field>
            <x-field :label="__('Other deduction (₹)')" name="other_deduction"><input id="other_deduction" name="other_deduction" type="number" step="0.01" min="0" value="{{ old('other_deduction') }}" class="input num"></x-field>
            <x-field :label="__('What it is for')" name="other_deduction_note"><input id="other_deduction_note" name="other_deduction_note" value="{{ old('other_deduction_note') }}" class="input" maxlength="255"></x-field>
            <div class="flex flex-wrap gap-2 sm:col-span-2">
                <button type="submit" formaction="{{ route('hire-bills.preview') }}" class="btn btn-ghost">{{ __('Work it out') }}</button>
                <button class="btn btn-primary">{{ __('Create draft bill') }}</button>
            </div>
        </form>

        <div>
            @if ($preview)
                <section class="card">
                    <div class="card-head"><h2 class="card-title">{{ $previewAsset->code }} · {{ $previewAsset->name }}</h2></div>
                    @include('modules.assets.hire._calc', ['calc' => $preview, 'unitLabel' => $unitFor($preview['basis']), 'note' => old('other_deduction_note')])
                    <p class="border-t border-rule px-4 py-3 text-xs text-ink-3">
                        {{ __(':count unbilled logs are in this period.', ['count' => $preview['log_count']]) }}
                        @if ($preview['down_days'] > 0) {{ __(':days days when the machine was down and did no work are left out.', ['days' => $preview['down_days']]) }} @endif
                        {{ __('Nothing is saved until you create the draft bill.') }}
                    </p>
                </section>
            @else
                <section class="card p-4 text-sm text-ink-2">
                    <h2 class="card-title">{{ __('How the bill is worked out') }}</h2>
                    <ul class="mt-2 list-disc space-y-1 pl-5">
                        <li>{{ __('Per hour: the hours logged, but never less than the monthly minimum for the days the machine was available.') }}</li>
                        <li>{{ __('Per day worked: the days with any work logged.') }}</li>
                        <li>{{ __('Per km: the kilometres logged.') }}</li>
                        <li>{{ __('Per month: the monthly rate for each calendar month, less the days the machine was down.') }}</li>
                        <li>{{ __('On wet hire, fuel you issued to the machine is taken off the bill.') }}</li>
                    </ul>
                </section>
            @endif
        </div>
    </div>
@endsection
