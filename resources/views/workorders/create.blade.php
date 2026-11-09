@extends('layouts.app')
@section('title', __('New work order'))
@section('content')
    <x-page-header :title="__('New work order')" :subtitle="$project->name" />

    @if ($parties->isEmpty())
        <div class="card"><x-empty :title="__('Add a subcontractor first')" :note="__('Work orders are issued to subcontractors from your contacts.')"><a href="{{ route('parties.index', ['type' => 'subcontractor']) }}" class="btn btn-primary">{{ __('Go to contacts') }}</a></x-empty></div>
    @else
        <form method="POST" action="{{ route('work-orders.store', $project) }}" class="space-y-5">
            @csrf
            <section class="card">
                <div class="grid gap-4 p-4 sm:grid-cols-3">
                    <x-field :label="__('Subcontractor')" name="party_id">
                        <select id="party_id" name="party_id" class="input" required>
                            <option value="">{{ __('Choose') }}</option>
                            @foreach ($parties as $party)<option value="{{ $party->id }}" @selected(old('party_id') == $party->id)>{{ $party->name }}</option>@endforeach
                        </select>
                    </x-field>
                    <x-field :label="__('Title')" name="title" class="sm:col-span-2"><input id="title" name="title" value="{{ old('title') }}" class="input" required></x-field>
                    <x-field :label="__('Rate type')" name="rate_type">
                        <select id="rate_type" name="rate_type" class="input">
                            <option value="item_rate">{{ __('Item rate') }}</option>
                            <option value="lump_sum">{{ __('Lump sum') }}</option>
                            <option value="labour_only">{{ __('Labour only') }}</option>
                        </select>
                    </x-field>
                    <x-field :label="__('Start date')" name="start_date"><input id="start_date" name="start_date" type="date" value="{{ old('start_date') }}" class="input"></x-field>
                    <x-field :label="__('End date')" name="end_date"><input id="end_date" name="end_date" type="date" value="{{ old('end_date') }}" class="input"></x-field>
                    <x-field :label="__('Scope')" name="scope" class="sm:col-span-3"><textarea id="scope" name="scope" rows="2" class="input">{{ old('scope') }}</textarea></x-field>
                </div>
                <div class="grid gap-4 border-t border-rule p-4 sm:grid-cols-6">
                    <x-field :label="__('Retention %')" name="retention_pct"><input id="retention_pct" name="retention_pct" type="number" step="0.01" value="{{ old('retention_pct', 5) }}" class="input num" required></x-field>
                    <x-field :label="__('TDS %')" name="tds_pct"><input id="tds_pct" name="tds_pct" type="number" step="0.01" value="{{ old('tds_pct', 1) }}" class="input num" required></x-field>
                    <x-field :label="__('GST %')" name="gst_pct"><input id="gst_pct" name="gst_pct" type="number" step="0.01" value="{{ old('gst_pct', 18) }}" class="input num" required></x-field>
                    <x-field :label="__('Advance (₹)')" name="advance_amount"><input id="advance_amount" name="advance_amount" type="number" step="0.01" value="{{ old('advance_amount', 0) }}" class="input num" required></x-field>
                    <x-field :label="__('Advance recovery %')" name="advance_recovery_pct"><input id="advance_recovery_pct" name="advance_recovery_pct" type="number" step="0.01" value="{{ old('advance_recovery_pct', 0) }}" class="input num" required></x-field>
                    <x-field :label="__('Deductions on')" name="deduction_basis">
                        <select id="deduction_basis" name="deduction_basis" class="input">
                            <option value="gross">{{ __('Gross') }}</option>
                            <option value="running">{{ __('Balance') }}</option>
                        </select>
                    </x-field>
                    <x-field :label="__('Deduction order')" name="deduction_order" class="sm:col-span-6">
                        <select id="deduction_order" name="deduction_order" class="input sm:w-1/2">
                            @foreach (config('buildos.deduction_orders') as $value => $label)
                                <option value="{{ $value }}" @selected(old('deduction_order') === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </x-field>
                </div>
            </section>

            <section class="card overflow-x-auto">
                <div class="card-head"><h2 class="card-title">{{ __('Items and rates') }}</h2><span class="text-xs text-ink-3">{{ __('Only rows with a rate are included.') }}</span></div>
                @error('items')<p class="error px-4 pt-3">{{ $message }}</p>@enderror
                <table class="ledger min-w-[760px]">
                    <thead><tr><th>{{ __('Code') }}</th><th class="w-1/2">{{ __('Description') }}</th><th>{{ __('Unit') }}</th><th class="r">{{ __('Quantity') }}</th><th class="r">{{ __('Rate (₹)') }}</th></tr></thead>
                    <tbody>
                    @foreach ($boqItems as $item)
                        <tr>
                            <td class="num text-xs">{{ $item->code }}</td>
                            <td>{{ $item->description }}</td>
                            <td>{{ $item->unit }}</td>
                            <td class="r"><input name="items[{{ $item->id }}][qty]" type="number" step="0.001" min="0" value="{{ old("items.{$item->id}.qty", $item->qty) }}" class="input num ml-auto w-28 text-right" aria-label="{{ __('Quantity') }} {{ $item->code }}"></td>
                            <td class="r"><input name="items[{{ $item->id }}][rate]" type="number" step="0.01" min="0" value="{{ old("items.{$item->id}.rate") }}" class="input num ml-auto w-28 text-right" aria-label="{{ __('Rate') }} {{ $item->code }}"></td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </section>

            <button class="btn btn-primary">{{ __('Create work order') }}</button>
        </form>
    @endif
@endsection
