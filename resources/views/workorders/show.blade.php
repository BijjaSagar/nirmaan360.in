@extends('layouts.app')
@section('title', $workOrder->number)
@section('content')
    <x-page-header :title="$workOrder->title" :subtitle="$workOrder->number.' · '.$workOrder->party->name">
        <x-stamp :status="$workOrder->status" />
    </x-page-header>

    <div class="grid grid-cols-2 gap-3 sm:grid-cols-6">
        <x-stat :label="__('Rate type')" :value="__(ucfirst(str_replace('_', ' ', $workOrder->rate_type)))" />
        @can('money.view')<x-stat :label="__('Value')" :value="inr($workOrder->totalValue())" />@endcan
        <x-stat :label="__('Retention')" :value="(float) $workOrder->retention_pct.'%'" />
        <x-stat :label="__('TDS')" :value="(float) $workOrder->tds_pct.'%'" />
        <x-stat :label="__('GST')" :value="(float) $workOrder->gst_pct.'%'" />
        @can('money.view')<x-stat :label="__('Advance')" :value="inr($workOrder->advance_amount)" />@endcan
    </div>

    <section class="card mt-5 overflow-x-auto">
        <div class="card-head"><h2 class="card-title">@can('money.view'){{ __('Items and rates') }}@else{{ __('Items') }}@endcan</h2></div>
        <table class="ledger">
            <thead><tr><th>{{ __('Code') }}</th><th>{{ __('Description') }}</th><th>{{ __('Unit') }}</th><th class="r">{{ __('Quantity') }}</th>@can('money.view')<th class="r">{{ __('Rate') }}</th>@endcan</tr></thead>
            <tbody>
            @foreach ($workOrder->items as $line)
                <tr>
                    <td class="num text-xs">{{ $line->boqItem->code }}</td>
                    <td>{{ $line->boqItem->description }}</td>
                    <td>{{ $line->boqItem->unit }}</td>
                    <td class="r">{{ fmt_qty($line->qty) }}</td>
                    @can('money.view')<td class="r">{{ inr($line->rate) }}</td>@endcan
                </tr>
            @endforeach
            </tbody>
        </table>
    </section>

    <section class="card mt-5">
        <div class="card-head"><h2 class="card-title">{{ __('Bills') }}</h2></div>
        @forelse ($bills as $bill)
            <a href="{{ route('ra-bills.show', $bill) }}" class="flex items-center justify-between gap-3 border-b border-rule px-4 py-2.5 text-sm last:border-b-0 hover:bg-paper/70">
                <span class="num text-xs">{{ $bill->number }}</span>
                @can('money.view')<span class="num ml-auto">{{ inr($bill->net_payable) }}</span>@else<span class="ml-auto"></span>@endcan
                <x-stamp :status="$bill->status" />
            </a>
        @empty
            <p class="px-4 py-5 text-sm text-ink-3">{{ __('No bills yet.') }}</p>
        @endforelse
    </section>
@endsection
