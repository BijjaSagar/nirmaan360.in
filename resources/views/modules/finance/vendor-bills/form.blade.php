@extends('layouts.app')
@section('title', $bill->exists ? __('Edit vendor bill') : __('Add vendor bill'))
@section('content')
    @php
        $plain = fn ($value) => $value === null ? '' : rtrim(rtrim((string) $value, '0'), '.');
        $gstRate = old('gst_pct', $bill->exists ? $plain($bill->gst_pct) : '18');
        if ($gstRate === '') { $gstRate = '0'; }
    @endphp
    <x-page-header :title="$bill->exists ? __('Edit vendor bill') : __('Add vendor bill')" :subtitle="$project->name" />

    @if ($vendors->isEmpty())
        <div class="card"><x-empty :title="__('No vendors yet')" :note="__('Add a vendor or subcontractor under Contacts first.')" /></div>
    @else
        <form method="POST" action="{{ $bill->exists ? route('finance.project.vendor-bills.update', $bill) : route('finance.project.vendor-bills.store', $project) }}" class="card grid gap-4 p-4 sm:grid-cols-2">
            @csrf
            @if ($bill->exists)
                @method('PATCH')
            @else
                <input type="hidden" name="client_uuid" value="{{ old('client_uuid', (string) \Illuminate\Support\Str::uuid()) }}">
            @endif
            <input type="hidden" name="purchase_order_id" value="{{ old('purchase_order_id', $bill->purchase_order_id ?? request('purchase_order_id')) }}">
            <input type="hidden" name="grn_id" value="{{ old('grn_id', $bill->grn_id ?? request('grn_id')) }}">

            <x-field :label="__('Vendor')" name="party_id" class="sm:col-span-2">
                <select id="party_id" name="party_id" class="input" required>
                    <option value="">—</option>
                    @foreach ($vendors as $vendor)
                        <option value="{{ $vendor->id }}" @selected((string) old('party_id', $bill->party_id) === (string) $vendor->id)>{{ $vendor->name }}</option>
                    @endforeach
                </select>
            </x-field>
            <x-field :label="__('Vendor bill no.')" name="bill_no"><input id="bill_no" name="bill_no" value="{{ old('bill_no', $bill->bill_no) }}" maxlength="50" class="input num" required></x-field>
            <x-field :label="__('Cost head')" name="cost_head">
                <select id="cost_head" name="cost_head" class="input" required>
                    @foreach (config('modules.finance.cost_heads') as $head)
                        <option value="{{ $head }}" @selected(old('cost_head', $bill->cost_head) === $head)>{{ __($head) }}</option>
                    @endforeach
                </select>
            </x-field>
            <x-field :label="__('Bill date')" name="bill_date"><input id="bill_date" name="bill_date" type="date" max="{{ now()->format('Y-m-d') }}" value="{{ old('bill_date', $bill->bill_date?->format('Y-m-d') ?? now()->format('Y-m-d')) }}" class="input" required></x-field>
            <x-field :label="__('Due date')" name="due_date"><input id="due_date" name="due_date" type="date" value="{{ old('due_date', $bill->due_date?->format('Y-m-d')) }}" class="input"></x-field>
            <x-field :label="__('Taxable value (₹)')" name="taxable_amount"><input id="taxable_amount" name="taxable_amount" type="number" step="0.01" min="0.01" value="{{ old('taxable_amount', $bill->taxable_amount) }}" class="input num" required></x-field>
            <x-field :label="__('GST rate')" name="gst_pct">
                <select id="gst_pct" name="gst_pct" class="input" required>
                    @foreach (config('modules.finance.gst_rates') as $rate)
                        <option value="{{ $rate }}" @selected((string) $gstRate === (string) $rate)>{{ $rate }}%</option>
                    @endforeach
                </select>
            </x-field>
            <x-field :label="__('Supply')" name="supply_type">
                <select id="supply_type" name="supply_type" class="input" required>
                    <option value="intra" @selected(old('supply_type', $bill->supply_type ?? 'intra') === 'intra')>{{ __('Within the state (CGST + SGST)') }}</option>
                    <option value="inter" @selected(old('supply_type', $bill->supply_type) === 'inter')>{{ __('Between states (IGST)') }}</option>
                </select>
            </x-field>
            <x-field :label="__('TDS rate %')" name="tds_pct" :hint="__('TDS is taken on the taxable value when the bill is booked.')"><input id="tds_pct" name="tds_pct" type="number" step="0.01" min="0" max="30" value="{{ old('tds_pct', $bill->exists ? $plain($bill->tds_pct) : '') }}" class="input num"></x-field>
            <x-field :label="__('TDS section')" name="tds_section">
                <select id="tds_section" name="tds_section" class="input">
                    <option value="">—</option>
                    @foreach (config('modules.finance.tds_sections') as $section)
                        <option value="{{ $section }}" @selected(old('tds_section', $bill->tds_section) === $section)>{{ $section }}</option>
                    @endforeach
                </select>
            </x-field>
            <x-field :label="__('Description')" name="description" class="sm:col-span-2"><textarea id="description" name="description" rows="2" maxlength="1000" class="input">{{ old('description', $bill->description) }}</textarea></x-field>
            <div class="sm:col-span-2"><button class="btn btn-primary">{{ __('Save as draft') }}</button></div>
        </form>
    @endif
@endsection
