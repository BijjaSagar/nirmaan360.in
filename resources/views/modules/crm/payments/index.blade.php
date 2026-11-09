@extends('layouts.app')
@section('title', __('EMD & fees'))
@section('content')
    @php
        $views = ['' => __('All'), 'held' => __('Held'), 'refund_due' => __('Refund due'), 'settled' => __('Settled'), 'planned' => __('To pay')];
        $current = $filters['view'] ?? '';
    @endphp
    <x-page-header :title="__('EMD & fees')" :subtitle="__('Money paid to authorities for tenders, and what has come back')" />

    <div class="grid grid-cols-2 gap-3 lg:grid-cols-5">
        @include('modules.crm.partials.stat', ['label' => __('Held'), 'amount' => $totals['held'], 'note' => __('Refundable, still with the authority')])
        @include('modules.crm.partials.stat', ['label' => __('Refund due'), 'amount' => $totals['refund_due'], 'note' => __('Tender is over'), 'accent' => \App\Support\Dec::of($totals['refund_due'])->isPositive()])
        @include('modules.crm.partials.stat', ['label' => __('Refunded'), 'amount' => $totals['refunded']])
        @include('modules.crm.partials.stat', ['label' => __('Not recoverable'), 'amount' => $totals['cost'], 'note' => __('Fees and forfeited EMD')])
        @include('modules.crm.partials.stat', ['label' => __('Adjusted'), 'amount' => $totals['adjusted'], 'note' => __('Moved to a security deposit')])
    </div>

    <div class="mt-5 mb-3 flex flex-wrap gap-1.5">
        @foreach ($views as $key => $label)
            <a href="{{ route('crm.payments.index', array_filter([...$filters, 'view' => $key])) }}" class="btn min-h-9 {{ $current === $key ? 'btn-ink' : 'btn-ghost' }}">{{ $label }}</a>
        @endforeach
    </div>

    <div class="card">
        @forelse ($payments as $payment)
            @include('modules.crm.payments.row', ['payment' => $payment, 'showTender' => true])
        @empty
            <x-empty :title="__('Nothing here')" :note="__('Add EMD and fees from a tender and they appear in this list.')" />
        @endforelse
    </div>
    <div class="mt-4">{{ $payments->links() }}</div>
@endsection
