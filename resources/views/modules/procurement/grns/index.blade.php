@extends('layouts.app')
@section('title', __('Goods receipts'))
@section('content')
    <x-page-header :title="__('Goods receipts')" :subtitle="$project->name" />

    @can('procurement.stock')
        @if ($openOrders->isNotEmpty())
            <section class="card mb-5">
                <div class="card-head"><h2 class="card-title">{{ __('Waiting for delivery') }}</h2></div>
                @foreach ($openOrders as $order)
                    <div class="flex flex-wrap items-center justify-between gap-2 border-b border-rule px-4 py-2.5 text-sm last:border-b-0">
                        <span><a href="{{ route('procurement.purchase-orders.show', $order) }}" class="link num text-xs">{{ $order->number }}</a> · {{ $order->vendor->name }}</span>
                        <a href="{{ route('procurement.grns.create', [$project, 'purchase_order' => $order->id]) }}" class="btn btn-primary">{{ __('Receive goods') }}</a>
                    </div>
                @endforeach
            </section>
        @endif
    @endcan

    <div class="card overflow-x-auto">
        @if ($grns->isEmpty())
            <x-empty :title="__('No goods receipts yet')" :note="__('Receipts are posted against an issued purchase order.')" />
        @else
            <table class="ledger min-w-[680px]">
                <thead><tr><th>{{ __('No.') }}</th><th>{{ __('Date') }}</th><th>{{ __('PO') }}</th><th>{{ __('Vendor') }}</th><th>{{ __('Challan') }}</th><th>{{ __('Store') }}</th><th>{{ __('Status') }}</th></tr></thead>
                <tbody>
                @foreach ($grns as $grn)
                    <tr>
                        <td><a href="{{ route('procurement.grns.show', $grn) }}" class="link num text-xs">{{ $grn->number }}</a></td>
                        <td class="num text-xs">{{ $grn->grn_date->format('d M Y') }}</td>
                        <td class="num text-xs">{{ $grn->purchaseOrder->number }}</td>
                        <td>{{ $grn->purchaseOrder->vendor->name }}</td>
                        <td>{{ $grn->challan_no ?? '—' }}</td>
                        <td>{{ $grn->store->name }}</td>
                        <td><x-stamp :status="$grn->status" /></td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        @endif
    </div>
    <div class="mt-4">{{ $grns->links() }}</div>
@endsection
