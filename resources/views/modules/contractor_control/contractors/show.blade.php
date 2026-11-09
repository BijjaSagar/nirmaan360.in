@extends('layouts.app')
@section('title', $party->name)
@section('content')
    @php($shortBy = \App\Support\Dec::of($summary['short_by'])->isPositive())
    <x-page-header :title="$party->name" :subtitle="__('Contractor ledger')">
        <a href="{{ route('contractor-control.contractors.pdf', $party) }}" class="btn btn-ghost">{{ __('Download PDF') }}</a>
    </x-page-header>

    <section class="card p-4">
        <div class="flex flex-wrap items-start justify-between gap-2">
            <p class="font-display text-[28px] leading-tight font-semibold {{ $summary['status'] === 'red' ? 'text-laterite' : ($summary['status'] === 'green' ? 'text-moss' : 'text-ink-3') }}">{{ $summary['label'] }}</p>
            @include('modules.contractor_control._status', ['row' => $summary])
        </div>
        <div class="mt-2">@include('modules.contractor_control._bars', ['work' => $summary['work_pct'], 'paid' => $summary['paid_pct'], 'status' => $summary['status']])</div>
    </section>

    <div class="mt-5 grid grid-cols-2 gap-3 sm:grid-cols-4">
        @include('modules.contractor_control._stat', ['label' => __('Total contract'), 'value' => inr($summary['contract_value'])])
        @include('modules.contractor_control._stat', ['label' => __('Paid till date'), 'value' => inr($summary['paid'])])
        @include('modules.contractor_control._stat', ['label' => __('Of which advance'), 'value' => inr($summary['advance'])])
        @include('modules.contractor_control._stat', ['label' => __('Balance'), 'value' => inr($summary['balance_money']), 'accent' => $shortBy, 'note' => $shortBy ? __('Short by :amount', ['amount' => inr($summary['short_by'])]) : null])
    </div>

    @if (\App\Support\Dec::of($finance_paid)->isPositive())
        <p class="mt-2 text-xs text-ink-3">{{ __(':amount paid against this contractor\'s RA bills in Finance is a separate record and is not counted here.', ['amount' => inr($finance_paid)]) }}</p>
    @endif

    <section class="card mt-5">
        <div class="card-head"><h2 class="card-title">{{ __('Contracts') }}</h2></div>
        @foreach ($contracts as $row)
            <a href="{{ route('contractor-control.contracts.show', $row['work_order']) }}" class="block border-b border-rule px-4 py-3 last:border-b-0 hover:bg-paper/70">
                <div class="flex flex-wrap items-start justify-between gap-x-3 gap-y-1">
                    <div class="min-w-0">
                        <p class="text-sm font-semibold">{{ $row['work_order']->title }}</p>
                        <p class="text-xs text-ink-3"><span class="num">{{ $row['work_order']->number }}</span> · {{ $row['project']->name }}</p>
                    </div>
                    @include('modules.contractor_control._status', ['row' => $row])
                </div>
                <p class="mt-1 text-sm font-semibold {{ $row['status'] === 'red' ? 'text-laterite' : 'text-moss' }}">{{ $row['label'] }}</p>
                <dl class="mt-1 grid grid-cols-2 gap-x-4 text-xs sm:grid-cols-3">
                    <div><dt class="text-ink-3">{{ __('Contract') }}</dt><dd class="num">{{ inr($row['contract_value']) }}</dd></div>
                    <div><dt class="text-ink-3">{{ __('Paid') }}</dt><dd class="num">{{ inr($row['paid']) }}</dd></div>
                    <div><dt class="text-ink-3">{{ __('Balance') }}</dt><dd class="num">{{ inr($row['balance_money']) }}</dd></div>
                </dl>
            </a>
        @endforeach
    </section>

    <section class="card mt-5">
        <div class="card-head"><h2 class="card-title">{{ __('Full history') }}</h2></div>
        @forelse ($history as $entry)
            @include('modules.contractor_control.payments._row', ['payment' => $entry['payment'], 'running' => $entry])
        @empty
            <x-empty :title="__('No payments yet')" />
        @endforelse
    </section>

    @if ($not_counted->isNotEmpty())
        <section class="card mt-5">
            <div class="card-head"><h2 class="card-title">{{ __('Not counted as paid') }}</h2></div>
            @foreach ($not_counted as $payment)
                @include('modules.contractor_control.payments._row', ['payment' => $payment])
            @endforeach
        </section>
    @endif
@endsection
