@extends('layouts.app')
@section('title', __('Receive goods'))
@section('content')
    <x-page-header :title="__('Receive goods')" :subtitle="$order->number.' · '.$order->vendor->name" />

    <form method="POST" action="{{ route('procurement.grns.store', $project) }}" class="space-y-5">
        @csrf
        <input type="hidden" name="client_uuid" value="{{ old('client_uuid', (string) \Illuminate\Support\Str::uuid()) }}">
        <input type="hidden" name="purchase_order_id" value="{{ $order->id }}">

        <section class="card grid gap-3 p-4 sm:grid-cols-4">
            <x-field :label="__('Date received')" name="grn_date"><input id="grn_date" name="grn_date" type="date" max="{{ today()->format('Y-m-d') }}" value="{{ old('grn_date', today()->format('Y-m-d')) }}" class="input" required></x-field>
            <x-field :label="__('Store')" name="store_id">
                <select id="store_id" name="store_id" class="input">
                    @foreach ($stores as $store)<option value="{{ $store->id }}" @selected((string) old('store_id', $order->store_id) === (string) $store->id)>{{ $store->name }}</option>@endforeach
                </select>
            </x-field>
            <x-field :label="__('Challan no.')" name="challan_no"><input id="challan_no" name="challan_no" value="{{ old('challan_no') }}" class="input" maxlength="60"></x-field>
            <x-field :label="__('Vehicle no.')" name="vehicle_no"><input id="vehicle_no" name="vehicle_no" value="{{ old('vehicle_no') }}" class="input" maxlength="30"></x-field>
            <x-field :label="__('Remarks')" name="remarks" class="sm:col-span-4"><textarea id="remarks" name="remarks" rows="2" class="input">{{ old('remarks') }}</textarea></x-field>
        </section>

        <section class="card">
            <div class="card-head"><h2 class="card-title">{{ __('What arrived') }}</h2></div>
            <div class="overflow-x-auto">
                <table class="ledger min-w-[640px]">
                    <thead><tr><th>{{ __('Material') }}</th><th class="r">{{ __('Still due') }}</th><th>{{ __('Accepted') }}</th><th>{{ __('Rejected') }}</th></tr></thead>
                    <tbody>
                    @foreach ($lines as $line)
                        <tr>
                            <td>{{ $line['item']->material->name }}<input type="hidden" name="items[{{ $loop->index }}][purchase_order_item_id]" value="{{ $line['item']->id }}"></td>
                            <td class="r">{{ fmt_qty($line['due_ordered']) }} {{ $line['item']->unit->code }}</td>
                            <td><input name="items[{{ $loop->index }}][qty]" value="{{ old('items.'.$loop->index.'.qty') }}" type="number" step="0.001" min="0.001" class="input num w-32" aria-label="{{ __('Accepted') }}"></td>
                            <td><input name="items[{{ $loop->index }}][rejected_qty]" value="{{ old('items.'.$loop->index.'.rejected_qty') }}" type="number" step="0.001" min="0" class="input num w-28" aria-label="{{ __('Rejected') }}"></td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            <p class="hint px-4 pb-3">{{ __('Quantities are in the unit shown under Still due. Leave a line blank if it did not arrive.') }}</p>
        </section>

        <button class="btn btn-primary">{{ __('Post to stock') }}</button>
    </form>
@endsection
