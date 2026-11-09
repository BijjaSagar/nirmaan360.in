@extends('modules.contractor_portal.layout')
@section('title', $contract['number'])
@section('content')
    @php
        use App\Services\ContractorControl\Figures;
    @endphp
    <x-page-header :title="$contract['title']" :subtitle="$contract['number'].' · '.$contract['project_name']">
        @if ($contract['contract_status'] === 'CLOSED')<x-stamp status="closed" />@endif
        <a href="{{ route('contractor-portal.home') }}" class="btn btn-ghost">{{ __('All work orders') }}</a>
    </x-page-header>

    <section class="card p-4">
        <div class="flex flex-wrap items-start justify-between gap-2">
            <p class="font-display text-[28px] leading-tight font-semibold {{ $contract['status'] === 'red' ? 'text-laterite' : ($contract['status'] === 'green' ? 'text-moss' : 'text-ink-3') }}">{{ $contract['label'] }}</p>
            @include('modules.contractor_portal._status', ['row' => $contract])
        </div>
        <div class="mt-2">@include('modules.contractor_portal._bars', ['work' => $contract['work_pct'], 'paid' => $contract['paid_pct'], 'status' => $contract['status']])</div>
        @if ($contract['status'] === 'red')
            <p class="mt-2 text-sm font-medium text-laterite">{{ __('Payment is ahead of the work recorded by :gap%.', ['gap' => Figures::pct($contract['gap_pct'])]) }}</p>
        @elseif ($contract['status'] === 'none')
            <p class="mt-2 text-sm text-ink-3">{{ __('This work order has no quantities yet, so there is no contract value to compare against.') }}</p>
        @endif
        <div class="mt-2">@include('modules.contractor_portal._timing', ['row' => $contract])</div>
    </section>

    <div class="mt-5 grid grid-cols-2 gap-3 sm:grid-cols-5">
        @include('modules.contractor_portal._stat', ['label' => __('Contract value'), 'value' => inr($contract['contract_value'])])
        @include('modules.contractor_portal._stat', ['label' => __('Work done'), 'value' => inr($contract['earned']), 'note' => __(':pct% of the contract', ['pct' => Figures::pct($contract['work_pct'])])])
        @include('modules.contractor_portal._stat', ['label' => __('Paid till date'), 'value' => inr($contract['paid']), 'note' => \App\Support\Dec::of($contract['advance'])->isPositive() ? __('Of which advance :amount', ['amount' => inr($contract['advance'])]) : null])
        @include('modules.contractor_portal._stat', ['label' => __('Balance to receive'), 'value' => inr($contract['balance_money'])])
        @include('modules.contractor_portal._stat', ['label' => __('Work still to do'), 'value' => inr($contract['balance_work_value']), 'note' => __(':pct% of work left', ['pct' => Figures::pct($contract['balance_work_pct'])])])
    </div>

    <section class="card mt-5">
        <div class="card-head"><h2 class="card-title">{{ __('Items on this work order') }}</h2><span class="text-xs text-ink-3">{{ __('At your contract rates') }}</span></div>
        <div class="overflow-x-auto">
            <table class="ledger min-w-[760px]">
                <thead><tr><th>{{ __('Item') }}</th><th>{{ __('Unit') }}</th><th class="r">{{ __('Contract qty') }}</th><th class="r">{{ __('Done') }}</th><th class="r">{{ __('Balance qty') }}</th><th class="r">{{ __('Rate') }}</th><th class="r">{{ __('Value') }}</th><th class="r">{{ __('Work %') }}</th></tr></thead>
                <tbody>
                @forelse ($contract['lines'] as $line)
                    <tr>
                        <td><span class="num text-xs text-ink-3">{{ $line['code'] }}</span> {{ $line['description'] }}</td>
                        <td>{{ $line['unit'] }}</td>
                        <td class="r">{{ fmt_qty($line['qty']) }}</td>
                        <td class="r font-medium">{{ fmt_qty($line['done_qty']) }}</td>
                        <td class="r">{{ fmt_qty($line['balance_qty']) }}</td>
                        <td class="r">{{ inr($line['rate']) }}</td>
                        <td class="r">{{ inr($line['value']) }}</td>
                        <td class="r">{{ Figures::pct($line['work_pct']) }}%</td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="py-5 text-center text-ink-3">{{ __('No items on this work order yet.') }}</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </section>

    @if ($contract['parts'] !== [])
        <section class="card mt-5">
            <div class="card-head"><h2 class="card-title">{{ __('Parts of the contract') }}</h2></div>
            @foreach ($contract['parts'] as $part)
                <div class="flex flex-wrap items-center justify-between gap-x-3 gap-y-1 border-b border-rule px-4 py-2.5 last:border-b-0">
                    <div class="min-w-0">
                        <p class="text-sm">{{ $part['title'] }}</p>
                        <p class="num text-xs text-ink-3">{{ __('Pay :amount', ['amount' => inr($part['amount'])]) }} · {{ __('Paid :amount', ['amount' => inr($part['paid'])]) }}</p>
                    </div>
                    @if ($part['done'])
                        <span class="stamp border-moss text-moss">{{ __('Done') }}</span>
                    @else
                        <span class="stamp border-ink-3 text-ink-3">{{ __('Not done') }}</span>
                    @endif
                </div>
            @endforeach
        </section>
    @endif

    <section class="card mt-5">
        <div class="card-head"><h2 class="card-title">{{ __('Payments on this work order') }}</h2></div>
        @forelse ($contract['payments'] as $payment)
            @include('modules.contractor_portal._payment-row', ['payment' => $payment])
        @empty
            <x-empty :title="__('No payments yet')" />
        @endforelse
    </section>

    <section class="card mt-5">
        <div class="card-head"><h2 class="card-title">{{ __('Bills on this work order') }}</h2></div>
        @include('modules.contractor_portal._bills-table', ['bills' => $contract['bills']])
    </section>
@endsection
