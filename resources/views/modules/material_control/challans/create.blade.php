@extends('layouts.app')
@section('title', __('New delivery challan'))
@section('content')
    <x-page-header :title="__('New delivery challan')" :subtitle="$project->name" />

    <form method="POST" action="{{ route('material_control.challans.store', $project) }}" enctype="multipart/form-data" class="card max-w-xl space-y-4 p-4"
          x-data="{
              suppliers: @js($suppliers),
              materials: @js($materialOptions),
              party: @js((string) old('party_id')),
              material: @js((string) old('material_id')),
              unit: @js((string) old('unit_id')),
              newSupplier: @js((bool) old('supplier_name')),
              get supplier() { return this.suppliers.find(s => String(s.party_id) === String(this.party)); },
              get chosen() { return this.materials.find(m => String(m.id) === String(this.material)); },
          }">
        @csrf
        <input type="hidden" name="client_uuid" value="{{ old('client_uuid', (string) \Illuminate\Support\Str::uuid()) }}">

        <div>
            <div class="flex items-center justify-between">
                <label class="label" for="party_id">{{ __('Supplier') }}</label>
                <button type="button" class="link text-xs" @click="newSupplier = !newSupplier" x-text="newSupplier ? @js(__('Choose from list')) : @js(__('New supplier'))"></button>
            </div>
            <select id="party_id" name="party_id" class="input min-h-12 text-base" x-model="party" x-show="!newSupplier" :disabled="newSupplier" :required="!newSupplier">
                <option value="">{{ __('Choose') }}</option>
                @foreach ($suppliers as $supplier)
                    <option value="{{ $supplier['party_id'] }}" @selected((string) old('party_id') === (string) $supplier['party_id'])>{{ $supplier['name'] }}@if ($supplier['warn']) ({{ __('warning') }})@endif</option>
                @endforeach
            </select>
            <input name="supplier_name" class="input min-h-12 text-base" maxlength="160" placeholder="{{ __('Supplier name') }}" value="{{ old('supplier_name') }}" x-show="newSupplier" x-cloak :disabled="!newSupplier" :required="newSupplier">
            <div x-show="supplier && supplier.warn" x-cloak class="mt-2 rounded-[3px] border border-laterite bg-laterite/10 px-3 py-2 text-sm font-medium text-laterite" role="alert">
                <p x-text="supplier ? supplier.warning : ''"></p>
                <p class="mt-0.5 text-xs font-normal">{{ __('Think before buying from this supplier again.') }}</p>
            </div>
            <p x-show="supplier && supplier.rating" x-cloak class="hint" x-text="supplier ? @js(__('Rating')) + ' ' + supplier.rating + '/5 · ' + @js(__('Short deliveries')) + ' ' + supplier.short_deliveries : ''"></p>
            @error('party_id')<p class="error">{{ $message }}</p>@enderror
            @error('supplier_name')<p class="error">{{ $message }}</p>@enderror
        </div>

        <x-field :label="__('Material')" name="material_id">
            <select id="material_id" name="material_id" class="input min-h-12 text-base" x-model="material" @change="unit = ''" required>
                <option value="">{{ __('Choose') }}</option>
                @foreach ($materialOptions as $option)
                    <option value="{{ $option['id'] }}" @selected((string) old('material_id') === (string) $option['id'])>{{ $option['label'] }}</option>
                @endforeach
            </select>
        </x-field>

        <div class="grid grid-cols-2 gap-3">
            <x-field :label="__('Quantity received')" name="qty">
                <input id="qty" name="qty" type="number" inputmode="decimal" step="0.001" min="0.001" value="{{ old('qty') }}" class="input num min-h-12 text-base" required>
            </x-field>
            <x-field :label="__('Unit')" name="unit_id">
                <select id="unit_id" name="unit_id" class="input min-h-12 text-base" x-model="unit">
                    <option value="" x-text="chosen ? chosen.base_unit : '—'"></option>
                    <template x-for="u in (chosen ? chosen.units : [])" :key="u.id"><option :value="u.id" x-text="u.code"></option></template>
                </select>
            </x-field>
        </div>

        <x-field :label="__('Quantity written on challan')" name="challan_qty" :hint="__('If less arrived than the challan says, enter the challan figure. The difference is recorded against the supplier.')">
            <input id="challan_qty" name="challan_qty" type="number" inputmode="decimal" step="0.001" min="0" value="{{ old('challan_qty') }}" class="input num min-h-12 text-base">
        </x-field>

        <div class="grid grid-cols-2 gap-3">
            <x-field :label="__('Date')" name="challan_date">
                <input id="challan_date" name="challan_date" type="date" max="{{ today()->format('Y-m-d') }}" value="{{ old('challan_date', today()->format('Y-m-d')) }}" class="input min-h-12 text-base" required>
            </x-field>
            <x-field :label="__('Vehicle no.')" name="vehicle_no">
                <input id="vehicle_no" name="vehicle_no" value="{{ old('vehicle_no') }}" maxlength="30" class="input min-h-12 text-base uppercase" placeholder="MH12AB1234">
            </x-field>
        </div>

        <div class="grid gap-3 sm:grid-cols-2">
            <x-field :label="__('Challan no.')" name="challan_no">
                <input id="challan_no" name="challan_no" value="{{ old('challan_no') }}" maxlength="60" class="input min-h-12 text-base">
            </x-field>
            @if ($stores->count() > 1)
                <x-field :label="__('Store')" name="store_id">
                    <select id="store_id" name="store_id" class="input min-h-12 text-base">
                        @foreach ($stores as $store)<option value="{{ $store->id }}" @selected((string) old('store_id') === (string) $store->id)>{{ $store->name }}</option>@endforeach
                    </select>
                </x-field>
            @endif
        </div>

        <x-field :label="__('Photo of challan')" name="photo" :hint="__('Keep this as proof of what arrived.')">
            <input id="photo" name="photo" type="file" accept="image/*" capture="environment" class="input min-h-12 text-base">
        </x-field>
        @includeIf('modules.slip_reading._challan_form', ['project' => $project])

        @if ($canAttachBill)
            <x-field :label="__('Bill, if it has come')" name="bill" :hint="__('Leave empty and it shows as bill pending for the accountant.')">
                <input id="bill" name="bill" type="file" accept="image/*,application/pdf" class="input min-h-12 text-base">
            </x-field>
        @endif

        <div class="flex flex-wrap gap-2">
            <button class="btn btn-primary min-h-12 w-full text-base sm:w-auto">{{ __('Save delivery') }}</button>
            <a href="{{ route('material_control.challans.index', $project) }}" class="btn btn-ghost min-h-12 w-full text-base sm:w-auto">{{ __('Cancel') }}</a>
        </div>
    </form>
@endsection
