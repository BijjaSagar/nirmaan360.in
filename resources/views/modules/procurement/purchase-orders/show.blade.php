@extends('layouts.app')
@section('title', $order->number)
@section('content')
    <x-page-header :title="$order->number" :subtitle="$order->vendor->name">
        <x-stamp :status="$order->status" />
        @can('money.view')<a href="{{ route('procurement.purchase-orders.pdf', $order) }}" class="btn btn-ghost">{{ __('Download PDF') }}</a>@endcan
        @can('procurement.purchase')
            @if ($order->status === 'DRAFT')
                <form method="POST" action="{{ route('procurement.purchase-orders.issue', $order) }}">@csrf<button class="btn btn-primary">{{ __('Issue PO') }}</button></form>
            @endif
            @if (in_array($order->status, ['DRAFT', 'ISSUED', 'PARTIAL'], true))
                <form method="POST" action="{{ route('procurement.purchase-orders.close', $order) }}">@csrf<button class="btn btn-danger">{{ in_array($order->status, ['DRAFT', 'ISSUED'], true) ? __('Cancel PO') : __('Close short') }}</button></form>
            @endif
        @endcan
        @can('procurement.stock')
            @if ($order->canReceive())
                <a href="{{ route('procurement.grns.create', [$project, 'purchase_order' => $order->id]) }}" class="btn btn-primary">{{ __('Receive goods') }}</a>
            @endif
        @endcan
    </x-page-header>

    <div class="grid items-start gap-5 lg:grid-cols-3">
        <div class="space-y-5 lg:col-span-2">
            <section class="card">
                <div class="card-head"><h2 class="card-title">{{ __('Lines') }}</h2></div>
                <div class="overflow-x-auto">
                    <table class="ledger min-w-[720px]">
                        <thead><tr><th>{{ __('Material') }}</th><th class="r">{{ __('Ordered') }}</th>@can('money.view')<th class="r">{{ __('Rate') }}</th>@endcan<th class="r">{{ __('GST %') }}</th>@can('money.view')<th class="r">{{ __('Amount') }}</th>@endcan<th class="r">{{ __('Received') }}</th><th class="r">{{ __('Due') }}</th></tr></thead>
                        <tbody>
                        @foreach ($order->items as $line)
                            <tr>
                                <td>{{ $line->material->name }}</td>
                                <td class="r">{{ fmt_qty($line->qty) }} {{ $line->unit->code }}</td>
                                @can('money.view')<td class="r">{{ inr($line->rate) }}</td>@endcan
                                <td class="r">{{ $line->gst_pct }}</td>
                                @can('money.view')<td class="r">{{ inr($line->amount) }}</td>@endcan
                                <td class="r">{{ fmt_qty($received[$line->id]) }} {{ $line->material->baseUnit->code }}</td>
                                <td class="r">{{ fmt_qty($due[$line->id]) }} {{ $line->material->baseUnit->code }}</td>
                            </tr>
                        @endforeach
                        </tbody>
                        @can('money.view')
                        <tfoot>
                            <tr><td colspan="4" class="text-right text-ink-3">{{ __('Subtotal') }}</td><td class="r">{{ inr($order->subtotal) }}</td><td colspan="2"></td></tr>
                            <tr><td colspan="4" class="text-right text-ink-3">{{ __('GST') }}</td><td class="r">{{ inr($order->gst_amount) }}</td><td colspan="2"></td></tr>
                            <tr><td colspan="4" class="text-right font-semibold">{{ __('Total') }}</td><td class="r font-medium">{{ inr($order->total) }}</td><td colspan="2"></td></tr>
                        </tfoot>
                        @endcan
                    </table>
                </div>
            </section>

            @if ($order->grns->isNotEmpty())
                <section class="card">
                    <div class="card-head"><h2 class="card-title">{{ __('Goods receipts') }}</h2></div>
                    @foreach ($order->grns as $grn)
                        <div class="flex flex-wrap items-center justify-between gap-2 border-b border-rule px-4 py-2.5 text-sm last:border-b-0">
                            <a href="{{ route('procurement.grns.show', $grn) }}" class="link num text-xs">{{ $grn->number }}</a>
                            <span class="num text-xs">{{ $grn->grn_date->format('d M Y') }}</span>
                            <x-stamp :status="$grn->status" />
                        </div>
                    @endforeach
                </section>
            @endif
        </div>

        <div class="space-y-5">
            <section class="card">
                <dl class="space-y-2 p-4 text-sm">
                    <div><dt class="text-ink-3">{{ __('Date') }}</dt><dd>{{ $order->po_date->format('d M Y') }}</dd></div>
                    <div><dt class="text-ink-3">{{ __('Deliver to store') }}</dt><dd>{{ $order->store->name }}</dd></div>
                    <div><dt class="text-ink-3">{{ __('Delivery by') }}</dt><dd>{{ $order->delivery_by?->format('d M Y') ?? '—' }}</dd></div>
                    @if ($order->rfq)<div><dt class="text-ink-3">{{ __('RFQ') }}</dt><dd><a href="{{ route('procurement.rfqs.show', $order->rfq) }}" class="link num text-xs">{{ $order->rfq->number }}</a></dd></div>@endif
                    @if ($order->status !== 'DRAFT' && $order->terms)<div><dt class="text-ink-3">{{ __('Terms') }}</dt><dd class="whitespace-pre-line">{{ $order->terms }}</dd></div>@endif
                </dl>
            </section>

            @if ($order->status === 'DRAFT')
                @can('procurement.purchase')
                    <form method="POST" action="{{ route('procurement.purchase-orders.update', $order) }}" class="card space-y-3 p-4">
                        @csrf @method('PATCH')
                        <x-field :label="__('Delivery by')" name="delivery_by"><input id="delivery_by" name="delivery_by" type="date" value="{{ old('delivery_by', $order->delivery_by?->format('Y-m-d')) }}" class="input"></x-field>
                        <x-field :label="__('Terms')" name="terms"><textarea id="terms" name="terms" rows="4" class="input">{{ old('terms', $order->terms) }}</textarea></x-field>
                        <button class="btn btn-ghost">{{ __('Save') }}</button>
                    </form>
                @endcan
            @endif
        </div>
    </div>
@endsection
