@extends('layouts.app')
@section('title', $bill->bill_no)
@section('content')
    <x-page-header :title="__('Vendor bill').' '.$bill->bill_no" :subtitle="($bill->party?->name ?? '—').' · '.$bill->bill_date->format('d M Y')">
        <x-stamp :status="$bill->status" />
        @can('finance.manage')
            @if ($bill->isEditable())
                <a href="{{ route('finance.project.vendor-bills.edit', $bill) }}" class="btn btn-ghost">{{ __('Edit') }}</a>
                <form method="POST" action="{{ route('finance.project.vendor-bills.submit', $bill) }}">@csrf<button class="btn btn-primary">{{ __('Submit for approval') }}</button></form>
            @endif
            @if ($bill->isCancellable())
                <form method="POST" action="{{ route('finance.project.vendor-bills.cancel', $bill) }}" onsubmit="return confirm(@js(__('Cancel this bill?')))">@csrf<button class="btn btn-danger">{{ __('Cancel bill') }}</button></form>
            @endif
        @endcan
    </x-page-header>
    @include('modules.finance._tabs')

    <div class="grid gap-5 lg:grid-cols-3">
        <div class="space-y-5 lg:col-span-2">
            <section class="card">
                <div class="card-head"><h2 class="card-title">{{ __('Bill details') }}</h2></div>
                <dl class="grid gap-3 p-4 text-sm sm:grid-cols-2">
                    <div><dt class="text-ink-3">{{ __('Cost head') }}</dt><dd>{{ __($bill->cost_head) }}</dd></div>
                    <div><dt class="text-ink-3">{{ __('Due date') }}</dt><dd class="num">{{ $bill->due_date?->format('d M Y') ?? '—' }}</dd></div>
                    <div><dt class="text-ink-3">{{ __('Supply') }}</dt><dd>{{ $bill->supply_type === 'inter' ? __('Between states (IGST)') : __('Within the state (CGST + SGST)') }}</dd></div>
                    <div><dt class="text-ink-3">{{ __('Vendor GSTIN') }}</dt><dd class="num">{{ $bill->party?->gstin ?? '—' }}</dd></div>
                    @if ($bill->purchase_order_id || $bill->grn_id)
                        <div><dt class="text-ink-3">{{ __('Linked purchase record') }}</dt><dd class="num">{{ collect(['PO #'.$bill->purchase_order_id => $bill->purchase_order_id, 'GRN #'.$bill->grn_id => $bill->grn_id])->filter()->keys()->join(' · ') }}</dd></div>
                    @endif
                    @if ($bill->description)<div class="sm:col-span-2"><dt class="text-ink-3">{{ __('Description') }}</dt><dd>{{ $bill->description }}</dd></div>@endif
                    @if ($bill->remarks)<div class="sm:col-span-2"><dt class="text-ink-3">{{ __('Reason for reversal') }}</dt><dd>{{ $bill->remarks }}</dd></div>@endif
                </dl>
            </section>

            @if ($bill->payments->isNotEmpty())
                <section class="card overflow-x-auto">
                    <div class="card-head"><h2 class="card-title">{{ __('Payments') }}</h2></div>
                    <table class="ledger">
                        <thead><tr><th>{{ __('Voucher') }}</th><th>{{ __('Date') }}</th><th>{{ __('Reference') }}</th><th class="r">{{ __('Amount') }}</th></tr></thead>
                        <tbody>
                        @foreach ($bill->payments as $payment)
                            <tr><td class="num text-xs">{{ $payment->voucherNo() }}</td><td class="num text-xs">{{ $payment->paid_on->format('d M Y') }}</td><td>{{ $payment->reference ?? '—' }}</td><td class="r">{{ inr($payment->amount) }}</td></tr>
                        @endforeach
                        </tbody>
                    </table>
                </section>
            @endif
        </div>

        <div class="space-y-5">
            <section class="card">
                <div class="card-head"><h2 class="card-title">{{ __('Bill abstract') }}</h2></div>
                <dl class="text-sm">
                    <div class="flex justify-between border-b border-rule px-4 py-2"><dt>{{ __('Taxable value') }}</dt><dd class="num">{{ inr($bill->taxable_amount) }}</dd></div>
                    @if ($bill->supply_type === 'inter')
                        <div class="flex justify-between border-b border-rule px-4 py-2"><dt>IGST <span class="num text-xs text-ink-3">{{ (float) $bill->gst_pct }}%</span></dt><dd class="num">{{ inr($bill->igst_amount) }}</dd></div>
                    @else
                        <div class="flex justify-between border-b border-rule px-4 py-2"><dt>CGST <span class="num text-xs text-ink-3">{{ (float) $bill->gst_pct / 2 }}%</span></dt><dd class="num">{{ inr($bill->cgst_amount) }}</dd></div>
                        <div class="flex justify-between border-b border-rule px-4 py-2"><dt>SGST <span class="num text-xs text-ink-3">{{ (float) $bill->gst_pct / 2 }}%</span></dt><dd class="num">{{ inr($bill->sgst_amount) }}</dd></div>
                    @endif
                    <div class="flex justify-between border-b border-rule px-4 py-2 font-semibold"><dt>{{ __('Total') }}</dt><dd class="num">{{ inr($bill->total_amount) }}</dd></div>
                    <div class="flex justify-between gap-3 border-b border-rule px-4 py-2"><dt>{{ __('TDS') }} @if ($bill->tds_section)<span class="num text-xs text-ink-3">{{ $bill->tds_section }} · {{ (float) $bill->tds_pct }}%</span>@endif</dt><dd class="num whitespace-nowrap text-laterite">−{{ inr($bill->tds_amount) }}</dd></div>
                    <div class="flex items-baseline justify-between bg-paper-2/60 px-4 py-3"><dt class="font-display text-lg font-semibold whitespace-nowrap">{{ __('Net payable') }}</dt><dd class="num text-lg font-medium whitespace-nowrap">{{ inr($bill->net_payable) }}</dd></div>
                </dl>
            </section>

            @includeIf('modules.slip_reading._vendor_bill_note', ['bill' => $bill])
            <x-approval-trail :approvals="$bill->approvals" />

            @can('vendor_bill.approve')
                @if ($bill->status === 'APPROVED')
                    <section class="card p-4">
                        <h2 class="card-title">{{ __('Correct this bill') }}</h2>
                        <p class="hint mb-3">{{ __('An approved bill cannot be changed. Reversing it adds an equal and opposite entry.') }}</p>
                        @include('modules.finance._reverse', ['action' => route('finance.project.vendor-bills.reverse', $bill)])
                    </section>
                @endif
            @endcan
        </div>
    </div>
@endsection
