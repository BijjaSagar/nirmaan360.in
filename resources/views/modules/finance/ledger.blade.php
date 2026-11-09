@extends('layouts.app')
@section('title', __('Project ledger'))
@section('content')
    @php
        $s = $statement['summary'];
        $labels = [
            'client_bill' => __('Client RA bill'),
            'subcontractor_bill' => __('Subcontractor RA bill'),
            'vendor_bill' => __('Vendor bill'),
            'vendor_reversal' => __('Vendor bill reversal'),
            'receipt' => __('Receipt'),
            'receipt_reversal' => __('Receipt reversal'),
            'payment' => __('Payment'),
            'payment_reversal' => __('Payment reversal'),
            'petty_topup' => __('Petty cash top-up'),
            'petty_expense' => __('Petty cash expense'),
        ];
    @endphp

    <x-page-header :title="__('Project ledger')" :subtitle="$project->name" />
    @include('modules.finance._tabs')

    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <x-stat :label="__('Receivable from client')" :value="inr($s['receivable_outstanding'])" :note="__('Billed :billed, received with TDS :settled', ['billed' => inr($s['billed_to_client']), 'settled' => inr(\App\Support\Dec::of($s['received'])->plus(\App\Support\Dec::of($s['tds_by_client'])))])" />
        <x-stat :label="__('Payable to vendors')" :value="inr($s['payable_outstanding'])" :note="__('Billed :billed, paid :paid', ['billed' => inr($s['billed_by_vendors']), 'paid' => inr($s['paid'])])" />
        <x-stat :label="__('Petty cash in hand')" :value="inr($s['petty_in_hand'])" :note="__('Top-ups :topups, spent :spent', ['topups' => inr($s['petty_topups']), 'spent' => inr($s['petty_spent'])])" />
        <x-stat :label="__('Net position')" :value="inr($s['net_position'])" :note="__('Receivable − payable + petty cash in hand')" />
        <x-stat :label="__('Net cash to date')" :value="inr($s['net_cash'])" :note="__('Received − paid − petty cash top-ups')" />
        <x-stat :label="__('Retention with client')" :value="inr($s['retention_with_client'])" />
        <x-stat :label="__('Retention from subcontractors')" :value="inr($s['retention_from_subcontractors'])" />
    </div>

    @if ($s['marked_paid_without_entries'] > 0)
        <div class="mt-4 rounded-[3px] border border-haldi bg-haldi/10 px-3 py-2 text-sm font-medium" role="note">{{ trans_choice('{1} :count bill was marked paid but its receipts or payments do not add up. Record them so this ledger is right.|[2,*] :count bills were marked paid but their receipts or payments do not add up. Record them so this ledger is right.', $s['marked_paid_without_entries'], ['count' => $s['marked_paid_without_entries']]) }}</div>
    @endif

    <form method="GET" class="card mt-5 flex flex-wrap items-end gap-3 p-4">
        <x-field :label="__('From')" name="from"><input id="from" name="from" type="date" value="{{ $filters['from'] ?? '' }}" class="input"></x-field>
        <x-field :label="__('To')" name="to"><input id="to" name="to" type="date" value="{{ $filters['to'] ?? '' }}" class="input"></x-field>
        <button class="btn btn-ghost">{{ __('Show') }}</button>
    </form>

    <section class="card mt-5 overflow-x-auto">
        <div class="card-head"><h2 class="card-title">{{ __('Statement') }}</h2></div>
        @if ($statement['entries'] === [] && \App\Support\Dec::of($statement['opening'])->isZero())
            <x-empty :title="__('No entries yet')" :note="__('Approved bills, receipts, payments and petty cash appear here.')" />
        @else
            <table class="ledger min-w-[860px]">
                <thead><tr><th>{{ __('Date') }}</th><th>{{ __('Type') }}</th><th>{{ __('Reference') }}</th><th>{{ __('Party') }}</th><th>{{ __('Detail') }}</th><th class="r">{{ __('Debit') }}</th><th class="r">{{ __('Credit') }}</th><th class="r">{{ __('Balance') }}</th></tr></thead>
                <tbody>
                <tr><td colspan="7" class="font-semibold">{{ __('Opening balance') }}</td><td class="r font-medium">{{ inr($statement['opening']) }}</td></tr>
                @foreach ($statement['entries'] as $entry)
                    <tr>
                        <td class="num text-xs whitespace-nowrap">{{ \Illuminate\Support\Carbon::parse($entry['date'])->format('d M Y') }}</td>
                        <td>{{ $labels[$entry['type']] }}</td>
                        <td class="num text-xs">@if ($entry['url'])<a href="{{ $entry['url'] }}" class="link">{{ $entry['reference'] }}</a>@else{{ $entry['reference'] }}@endif</td>
                        <td>{{ $entry['party'] ?? '—' }}</td>
                        <td class="text-xs text-ink-3">{{ $entry['note'] ? __($entry['note']) : '' }}</td>
                        <td class="r">{{ \App\Support\Dec::of($entry['debit'])->isZero() ? '' : inr($entry['debit']) }}</td>
                        <td class="r">{{ \App\Support\Dec::of($entry['credit'])->isZero() ? '' : inr($entry['credit']) }}</td>
                        <td class="r font-medium">{{ inr($entry['balance']) }}</td>
                    </tr>
                @endforeach
                </tbody>
                <tfoot>
                <tr class="font-semibold"><td colspan="5" class="px-3 py-2">{{ __('Total for this period') }}</td><td class="num px-3 py-2 text-right whitespace-nowrap">{{ inr($statement['total_debit']) }}</td><td class="num px-3 py-2 text-right whitespace-nowrap">{{ inr($statement['total_credit']) }}</td><td class="num px-3 py-2 text-right whitespace-nowrap">{{ inr($statement['closing']) }}</td></tr>
                </tfoot>
            </table>
        @endif
    </section>
    <p class="mt-2 text-xs text-ink-3">{{ __('Debit is what others owe the project or what it has paid out. Credit is what it has received or now owes. GST and TDS we deduct are in the tax reports, and retention is outside the balance until released.') }}</p>
@endsection
