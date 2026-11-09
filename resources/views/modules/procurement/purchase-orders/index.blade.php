@extends('layouts.app')
@section('title', __('Purchase orders'))
@section('content')
    <x-page-header :title="__('Purchase orders')" :subtitle="$project->name" />

    <div class="card overflow-x-auto">
        @if ($orders->isEmpty())
            <x-empty :title="__('No purchase orders yet')" :note="__('Purchase orders are drafted when you award an RFQ.')" />
        @else
            <table class="ledger min-w-[680px]">
                <thead><tr><th>{{ __('No.') }}</th><th>{{ __('Vendor') }}</th><th>{{ __('Date') }}</th><th>{{ __('Store') }}</th>@can('money.view')<th class="r">{{ __('Total') }}</th>@endcan<th>{{ __('Status') }}</th></tr></thead>
                <tbody>
                @foreach ($orders as $order)
                    <tr>
                        <td><a href="{{ route('procurement.purchase-orders.show', $order) }}" class="link num text-xs">{{ $order->number }}</a></td>
                        <td>{{ $order->vendor->name }}</td>
                        <td class="num text-xs">{{ $order->po_date->format('d M Y') }}</td>
                        <td>{{ $order->store->name }}</td>
                        @can('money.view')<td class="r">{{ inr($order->total) }}</td>@endcan
                        <td><x-stamp :status="$order->status" /></td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        @endif
    </div>
    <div class="mt-4">{{ $orders->links() }}</div>
@endsection
