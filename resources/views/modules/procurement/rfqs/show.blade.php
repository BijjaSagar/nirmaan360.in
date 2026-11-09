@extends('layouts.app')
@section('title', $rfq->number)
@section('content')
    @php $vendorsQuoted = $comparison['vendors']; @endphp
    <x-page-header :title="$rfq->number" :subtitle="__('From indent :number', ['number' => $rfq->indent->number])">
        <x-stamp :status="$rfq->status" />
        @if ($rfq->isOpen())
            @can('procurement.purchase')
                <form method="POST" action="{{ route('procurement.rfqs.close', $rfq) }}">@csrf<button class="btn btn-ghost">{{ __('Close RFQ') }}</button></form>
            @endcan
        @endif
    </x-page-header>

    <div class="space-y-5">
        <section class="card">
            <div class="card-head">
                <h2 class="card-title">{{ __('Comparison') }}</h2>
                <span class="text-xs text-ink-3">{{ __('L1 is the lowest cost including GST') }}</span>
            </div>
            @if ($vendorsQuoted->isEmpty())
                <p class="px-4 py-5 text-sm text-ink-3">{{ __('No quotations recorded yet.') }}</p>
            @else
                <form method="POST" action="{{ route('procurement.rfqs.award', $rfq) }}">
                    @csrf
                    <input type="hidden" name="client_uuid" value="{{ \Illuminate\Support\Str::uuid() }}">
                    <div class="overflow-x-auto">
                        <table class="ledger min-w-[720px]">
                            <thead>
                                <tr>
                                    <th>{{ __('Material') }}</th>
                                    <th class="r">{{ __('Quantity') }}</th>
                                    @foreach ($vendorsQuoted as $quotation)
                                        <th class="r">{{ $quotation->vendor->name }}@if ($quotation->isExpired())<span class="block text-laterite">{{ __('Expired') }}</span>@endif</th>
                                    @endforeach
                                </tr>
                            </thead>
                            <tbody>
                            @foreach ($comparison['lines'] as $line)
                                <tr>
                                    <td>{{ $line['item']->material->name }}
                                        @if ($line['item']->awarded_quotation_id)
                                            <span class="block text-xs text-moss">{{ __('Awarded') }}</span>
                                        @elseif ($rfq->isOpen())
                                            @can('procurement.purchase')
                                                <input type="hidden" name="picks[{{ $loop->index }}][rfq_item_id]" value="{{ $line['item']->id }}">
                                                <select name="picks[{{ $loop->index }}][quotation_id]" class="input mt-1 w-40" aria-label="{{ __('Award to') }}">
                                                    <option value="">{{ __('Not now') }}</option>
                                                    @foreach ($vendorsQuoted as $quotation)
                                                        @isset($line['cells'][$quotation->id])
                                                            <option value="{{ $quotation->id }}" @selected($line['l1'] === $quotation->id)>{{ $quotation->vendor->name }}</option>
                                                        @endisset
                                                    @endforeach
                                                </select>
                                            @endcan
                                        @endif
                                    </td>
                                    <td class="r">{{ fmt_qty($line['item']->qty) }} {{ $line['item']->unit->code }}</td>
                                    @foreach ($vendorsQuoted as $quotation)
                                        @php $cell = $line['cells'][$quotation->id] ?? null; @endphp
                                        <td class="r {{ $line['l1'] === $quotation->id ? 'bg-moss/10 font-medium text-moss' : '' }}">
                                            @if ($cell)
                                                {{ inr($cell['rate']) }}<span class="block text-[11px] text-ink-3">{{ __('GST :pct%', ['pct' => $cell['gst_pct']]) }} · {{ inr($cell['total']) }}</span>
                                            @else
                                                —
                                            @endif
                                        </td>
                                    @endforeach
                                </tr>
                            @endforeach
                            </tbody>
                            <tfoot>
                                <tr>
                                    <td colspan="2" class="font-semibold">{{ __('Total including GST') }}</td>
                                    @foreach ($vendorsQuoted as $quotation)
                                        <td class="r font-medium {{ $comparison['cheapest_full'] === $quotation->id ? 'text-moss' : '' }}">{{ inr($quotation->total) }}</td>
                                    @endforeach
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                    @if ($rfq->isOpen())
                        @can('procurement.purchase')
                            <div class="border-t border-rule p-4"><button class="btn btn-primary">{{ __('Award and draft POs') }}</button></div>
                        @endcan
                    @endif
                </form>
            @endif
        </section>

        @if ($rfq->isOpen())
            @can('procurement.purchase')
                <section class="card" x-data="{ open: {{ $errors->any() ? 'true' : 'false' }} }">
                    <div class="card-head">
                        <h2 class="card-title">{{ __('Record a quotation') }}</h2>
                        <button type="button" class="btn btn-ghost" @click="open = !open">{{ __('Show or hide') }}</button>
                    </div>
                    <form x-show="open" x-cloak method="POST" action="{{ route('procurement.rfqs.quotations.store', $rfq) }}" class="space-y-3 p-4">
                        @csrf
                        <div class="grid gap-3 sm:grid-cols-3">
                            <x-field :label="__('Vendor')" name="party_id">
                                <select id="party_id" name="party_id" class="input" required>
                                    <option value="">{{ __('Choose') }}</option>
                                    @foreach ($vendors as $vendor)<option value="{{ $vendor->id }}">{{ $vendor->name }}</option>@endforeach
                                </select>
                            </x-field>
                            <x-field :label="__('Quote reference')" name="quote_ref"><input id="quote_ref" name="quote_ref" class="input" maxlength="60"></x-field>
                            <x-field :label="__('Valid until')" name="valid_until"><input id="valid_until" name="valid_until" type="date" class="input"></x-field>
                            <x-field :label="__('Delivery in days')" name="delivery_days"><input id="delivery_days" name="delivery_days" type="number" min="0" class="input num"></x-field>
                            <x-field :label="__('Payment terms')" name="payment_terms" class="sm:col-span-2"><input id="payment_terms" name="payment_terms" class="input" maxlength="160"></x-field>
                        </div>
                        <div class="overflow-x-auto">
                            <table class="ledger min-w-[520px]">
                                <thead><tr><th>{{ __('Material') }}</th><th class="r">{{ __('Quantity') }}</th><th>{{ __('Rate (₹) delivered, before GST') }}</th><th>{{ __('GST %') }}</th></tr></thead>
                                <tbody>
                                @foreach ($comparison['lines'] as $line)
                                    <tr>
                                        <td>{{ $line['item']->material->name }}<input type="hidden" name="items[{{ $loop->index }}][rfq_item_id]" value="{{ $line['item']->id }}"></td>
                                        <td class="r">{{ fmt_qty($line['item']->qty) }} {{ $line['item']->unit->code }}</td>
                                        <td><input name="items[{{ $loop->index }}][rate]" type="number" step="0.01" min="0.01" class="input num w-32" aria-label="{{ __('Rate') }}"></td>
                                        <td><input name="items[{{ $loop->index }}][gst_pct]" type="number" step="0.01" min="0" max="100" value="{{ $line['item']->material->gst_pct }}" class="input num w-20" aria-label="{{ __('GST %') }}"></td>
                                    </tr>
                                @endforeach
                                </tbody>
                            </table>
                        </div>
                        <p class="hint">{{ __('Leave a rate blank if the vendor did not quote that line.') }}</p>
                        <button class="btn btn-ink">{{ __('Save quotation') }}</button>
                    </form>
                </section>

                <form method="POST" action="{{ route('procurement.rfqs.vendors.store', $rfq) }}" class="card flex flex-wrap items-end gap-3 p-4">
                    @csrf
                    <x-field :label="__('Add a vendor to this RFQ')" name="party_id" class="min-w-48 flex-1">
                        <select name="party_id" class="input" required>
                            <option value="">{{ __('Choose') }}</option>
                            @foreach ($vendors as $vendor)<option value="{{ $vendor->id }}">{{ $vendor->name }}</option>@endforeach
                        </select>
                    </x-field>
                    <button class="btn btn-ghost">{{ __('Add vendor') }}</button>
                </form>
            @endcan
        @endif

        <section class="card">
            <div class="card-head"><h2 class="card-title">{{ __('Vendors invited') }}</h2></div>
            @foreach ($rfq->quotations as $quotation)
                <div class="flex items-center justify-between gap-2 border-b border-rule px-4 py-2.5 text-sm last:border-b-0">
                    <span>{{ $quotation->vendor->name }}</span>
                    <x-stamp :status="$quotation->status" />
                </div>
            @endforeach
        </section>

        @if ($rfq->purchaseOrders->isNotEmpty())
            <section class="card">
                <div class="card-head"><h2 class="card-title">{{ __('Purchase orders') }}</h2></div>
                @foreach ($rfq->purchaseOrders as $order)
                    <div class="flex items-center justify-between gap-2 border-b border-rule px-4 py-2.5 text-sm last:border-b-0">
                        <a href="{{ route('procurement.purchase-orders.show', $order) }}" class="link num text-xs">{{ $order->number }}</a>
                        <span>{{ $order->vendor->name }}</span>
                        <x-stamp :status="$order->status" />
                    </div>
                @endforeach
            </section>
        @endif
    </div>
@endsection
