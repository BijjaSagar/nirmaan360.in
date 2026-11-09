@extends('layouts.app')
@section('title', __('Receipts'))
@section('content')
    <x-page-header :title="__('Receipts')" :subtitle="$project->name" />
    @include('modules.finance._tabs')

    <section class="card">
        <div class="card-head"><h2 class="card-title">{{ __('Bills to collect') }}</h2></div>
        @forelse ($bills as $row)
            @php
                $bill = $row['bill'];
                $tdsLeft = \App\Support\Dec::money(\App\Support\Dec::of($row['outstanding'])->minus(\App\Support\Dec::of($row['cash_expected'])));
            @endphp
            <div class="border-b border-rule px-4 py-3 last:border-b-0">
                <div class="flex flex-wrap items-baseline justify-between gap-2">
                    <div>
                        <a href="{{ route('ra-bills.show', $bill) }}" class="link num text-sm">{{ $bill->number }}</a>
                        <span class="text-sm">{{ $bill->party?->name ?? '—' }}</span>
                    </div>
                    <p class="num text-sm">{{ __('Due') }} <span class="font-medium">{{ inr($row['outstanding']) }}</span> <span class="text-xs text-ink-3">{{ __('of :total', ['total' => inr($row['receivable'])]) }}</span></p>
                </div>
                @can('finance.manage')
                    <details class="mt-2">
                        <summary class="link cursor-pointer text-xs">{{ __('Record receipt') }}</summary>
                        <form method="POST" action="{{ route('finance.project.receipts.store', $bill) }}" class="mt-2 grid items-end gap-3 sm:grid-cols-6">
                            @csrf
                            <input type="hidden" name="client_uuid" value="{{ \Illuminate\Support\Str::uuid() }}">
                            <div><label class="label">{{ __('Received in bank (₹)') }}</label><input name="amount" type="number" step="0.01" min="0" value="{{ $row['cash_expected'] }}" class="input num" required></div>
                            <div><label class="label">{{ __('TDS deducted (₹)') }}</label><input name="tds_amount" type="number" step="0.01" min="0" value="{{ $tdsLeft }}" class="input num"></div>
                            <div><label class="label">{{ __('Date') }}</label><input name="received_on" type="date" max="{{ now()->format('Y-m-d') }}" value="{{ now()->format('Y-m-d') }}" class="input" required></div>
                            <div>
                                <label class="label">{{ __('Mode') }}</label>
                                <select name="mode" class="input">@foreach (config('modules.finance.payment_modes') as $mode => $label)<option value="{{ $mode }}">{{ __($label) }}</option>@endforeach</select>
                            </div>
                            <div><label class="label">{{ __('UTR or cheque no.') }}</label><input name="reference" maxlength="60" class="input"></div>
                            <div><button class="btn btn-primary">{{ __('Save receipt') }}</button></div>
                        </form>
                    </details>
                @endcan
            </div>
        @empty
            <x-empty :title="__('Nothing to collect')" :note="__('Approved client RA bills with a balance appear here.')" />
        @endforelse
    </section>

    <section class="card mt-5 overflow-x-auto">
        <div class="card-head"><h2 class="card-title">{{ __('Receipt entries') }}</h2></div>
        @if ($receipts->isEmpty())
            <x-empty :title="__('No receipts yet')" />
        @else
            <table class="ledger min-w-[820px]">
                <thead><tr><th>{{ __('Voucher') }}</th><th>{{ __('Date') }}</th><th>{{ __('Client') }}</th><th>{{ __('RA bill') }}</th><th>{{ __('Reference') }}</th><th class="r">{{ __('Received') }}</th><th class="r">{{ __('TDS') }}</th><th></th></tr></thead>
                <tbody>
                @foreach ($receipts as $receipt)
                    <tr>
                        <td class="num text-xs">{{ $receipt->voucherNo() }}</td>
                        <td class="num text-xs whitespace-nowrap">{{ $receipt->received_on->format('d M Y') }}</td>
                        <td>{{ $receipt->party?->name ?? '—' }}</td>
                        <td class="num text-xs">{{ $receipt->raBill?->number ?? '—' }}</td>
                        <td>{{ $receipt->reference ?? '—' }}</td>
                        <td class="r">{{ inr($receipt->amount) }}</td>
                        <td class="r">{{ inr($receipt->tds_amount) }}</td>
                        <td class="text-right">
                            @if ($receipt->isReversal())
                                <span class="text-xs text-ink-3">{{ __('Reversal') }}</span>
                            @elseif ($receipt->reversal)
                                <span class="text-xs text-ink-3">{{ __('Reversed') }}</span>
                            @else
                                @can('finance.manage')@include('modules.finance._reverse', ['action' => route('finance.project.receipts.reverse', $receipt)])@endcan
                            @endif
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        @endif
    </section>
    <div class="mt-4">{{ $receipts->links() }}</div>
@endsection
