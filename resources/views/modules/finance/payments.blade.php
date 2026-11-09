@extends('layouts.app')
@section('title', __('Payments'))
@section('content')
    <x-page-header :title="__('Payments')" :subtitle="$project->name" />
    @include('modules.finance._tabs')

    <section class="card">
        <div class="card-head"><h2 class="card-title">{{ __('Bills to pay') }}</h2></div>
        @forelse ($bills as $row)
            @php
                $bill = $row['bill'];
                $isVendor = $row['kind'] === 'vendor';
            @endphp
            <div class="border-b border-rule px-4 py-3 last:border-b-0">
                <div class="flex flex-wrap items-baseline justify-between gap-2">
                    <div>
                        <a href="{{ $isVendor ? route('finance.project.vendor-bills.show', $bill) : route('ra-bills.show', $bill) }}" class="link num text-sm">{{ $isVendor ? $bill->bill_no : $bill->number }}</a>
                        <span class="text-sm">{{ $bill->party?->name ?? '—' }}</span>
                        <span class="text-xs text-ink-3">{{ $isVendor ? __('Vendor bill') : __('Subcontractor RA bill') }}</span>
                    </div>
                    <p class="num text-sm">{{ __('Due') }} <span class="font-medium">{{ inr($row['outstanding']) }}</span> <span class="text-xs text-ink-3">{{ __('of :total', ['total' => inr($row['payable'])]) }}</span></p>
                </div>
                @can('finance.manage')
                    <details class="mt-2">
                        <summary class="link cursor-pointer text-xs">{{ __('Record payment') }}</summary>
                        <form method="POST" action="{{ $isVendor ? route('finance.project.payments.vendor', $bill) : route('finance.project.payments.subcontractor', $bill) }}" class="mt-2 grid items-end gap-3 sm:grid-cols-5">
                            @csrf
                            <input type="hidden" name="client_uuid" value="{{ \Illuminate\Support\Str::uuid() }}">
                            <div><label class="label">{{ __('Amount (₹)') }}</label><input name="amount" type="number" step="0.01" min="0.01" value="{{ $row['outstanding'] }}" class="input num" required></div>
                            <div><label class="label">{{ __('Date') }}</label><input name="paid_on" type="date" max="{{ now()->format('Y-m-d') }}" value="{{ now()->format('Y-m-d') }}" class="input" required></div>
                            <div>
                                <label class="label">{{ __('Mode') }}</label>
                                <select name="mode" class="input">@foreach (config('modules.finance.payment_modes') as $mode => $label)<option value="{{ $mode }}">{{ __($label) }}</option>@endforeach</select>
                            </div>
                            <div><label class="label">{{ __('UTR or cheque no.') }}</label><input name="reference" maxlength="60" class="input"></div>
                            <div><button class="btn btn-primary">{{ __('Save payment') }}</button></div>
                        </form>
                    </details>
                @endcan
            </div>
        @empty
            <x-empty :title="__('Nothing to pay')" :note="__('Approved vendor and subcontractor bills with a balance appear here.')" />
        @endforelse
    </section>

    <section class="card mt-5 overflow-x-auto">
        <div class="card-head"><h2 class="card-title">{{ __('Payment entries') }}</h2></div>
        @if ($payments->isEmpty())
            <x-empty :title="__('No payments yet')" />
        @else
            <table class="ledger min-w-[760px]">
                <thead><tr><th>{{ __('Voucher') }}</th><th>{{ __('Date') }}</th><th>{{ __('Party') }}</th><th>{{ __('Bill') }}</th><th>{{ __('Mode') }}</th><th>{{ __('Reference') }}</th><th class="r">{{ __('Amount') }}</th><th></th></tr></thead>
                <tbody>
                @foreach ($payments as $payment)
                    <tr>
                        <td class="num text-xs">{{ $payment->voucherNo() }}</td>
                        <td class="num text-xs whitespace-nowrap">{{ $payment->paid_on->format('d M Y') }}</td>
                        <td>{{ $payment->party?->name ?? '—' }}</td>
                        <td class="num text-xs">{{ $payment->documentNo() }}</td>
                        <td>{{ __(config('modules.finance.payment_modes.'.$payment->mode)) }}</td>
                        <td>{{ $payment->reference ?? '—' }}</td>
                        <td class="r">{{ inr($payment->amount) }}</td>
                        <td class="text-right">
                            @if ($payment->isReversal())
                                <span class="text-xs text-ink-3">{{ __('Reversal') }}</span>
                            @elseif ($payment->reversal)
                                <span class="text-xs text-ink-3">{{ __('Reversed') }}</span>
                            @else
                                @can('finance.manage')@include('modules.finance._reverse', ['action' => route('finance.project.payments.reverse', $payment)])@endcan
                            @endif
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        @endif
    </section>
    <div class="mt-4">{{ $payments->links() }}</div>
@endsection
