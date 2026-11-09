@extends('layouts.app')
@section('title', $bill->number)
@section('content')
    <x-page-header :title="__('RA bill').' '.$bill->number" :subtitle="($bill->type === 'client' ? __('Client') : __('Subcontractor')).' · '.($bill->party?->name ?? '—').' · '.$bill->period_from->format('d M Y').' – '.$bill->period_to->format('d M Y')">
        <x-stamp :status="$bill->status" />
        <a href="{{ route('ra-bills.pdf', $bill) }}" class="btn btn-ghost">{{ __('Download PDF') }}</a>
        @can('ra_bill.generate')
            @if ($bill->isCancellable())
                <form method="POST" action="{{ route('ra-bills.cancel', $bill) }}" onsubmit="return confirm(@js(__('Cancel this bill? Its measurements can then be billed again.')))">@csrf<button class="btn btn-danger">{{ __('Cancel bill') }}</button></form>
            @endif
            @if ($bill->isEditable())
                <form method="POST" action="{{ route('ra-bills.submit', $bill) }}">@csrf<button class="btn btn-primary">{{ __('Submit for approval') }}</button></form>
            @endif
        @endcan
        @can('ra_bill.approve')
            @if ($bill->status === 'APPROVED')
                <form method="POST" action="{{ route('ra-bills.paid', $bill) }}">@csrf<button class="btn btn-primary">{{ __('Mark as paid') }}</button></form>
            @endif
        @endcan
    </x-page-header>

    @if ($clientLink)
        <div class="mb-5 rounded-[3px] border border-neel bg-neel/8 p-3" x-data="{ copied: false }">
            <p class="text-sm font-semibold text-neel">{{ __('Waiting for client sign-off. Send this link to the client. It works for 14 days.') }}</p>
            <div class="mt-2 flex gap-2">
                <input readonly value="{{ $clientLink }}" class="input num text-xs" aria-label="{{ __('Client sign-off link') }}" x-ref="link">
                <button type="button" class="btn btn-ghost" @click="navigator.clipboard.writeText($refs.link.value); copied = true" x-text="copied ? @js(__('Copied')) : @js(__('Copy'))"></button>
            </div>
        </div>
    @endif

    <div class="grid gap-5 lg:grid-cols-3">
        <div class="space-y-5 lg:col-span-2">
            <section class="card overflow-x-auto">
                <table class="ledger min-w-[820px]">
                    <thead>
                    <tr><th>{{ __('Item') }}</th><th class="w-[30%]">{{ __('Description') }}</th><th>{{ __('Unit') }}</th><th class="r">{{ __('Rate') }}</th><th class="r">{{ __('Previous') }}</th><th class="r">{{ __('This bill') }}</th><th class="r">{{ __('Cumulative') }}</th><th class="r">{{ __('Amount') }}</th></tr>
                    </thead>
                    <tbody>
                    @foreach ($bill->items as $line)
                        <tr>
                            <td class="num text-xs">{{ $line->code }}</td>
                            <td>{{ \Illuminate\Support\Str::limit($line->description, 80) }}</td>
                            <td>{{ $line->unit }}</td>
                            <td class="r">{{ \App\Support\Dec::indian($line->rate) }}</td>
                            <td class="r">{{ fmt_qty($line->prev_qty) }}</td>
                            <td class="r font-medium">{{ fmt_qty($line->current_qty) }}</td>
                            <td class="r">{{ fmt_qty($line->cumulative_qty) }}</td>
                            <td class="r font-medium">{{ \App\Support\Dec::indian($line->current_amount) }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </section>
        </div>

        <div class="space-y-5">
            <section class="card">
                <div class="card-head"><h2 class="card-title">{{ __('Bill abstract') }}</h2></div>
                <dl class="text-sm">
                    <div class="flex justify-between border-b border-rule px-4 py-2"><dt>{{ __('Gross value of work') }}</dt><dd class="num">{{ inr($bill->gross_amount) }}</dd></div>
                    @foreach ($bill->deductions as $deduction)
                        <div class="flex justify-between gap-3 border-b border-rule px-4 py-2">
                            <dt>{{ __($deduction->label) }} @if ($deduction->pct !== null && (float) $deduction->pct > 0)<span class="num text-xs text-ink-3">{{ (float) $deduction->pct }}%</span>@endif</dt>
                            <dd class="num whitespace-nowrap text-laterite">−{{ inr($deduction->amount) }}</dd>
                        </div>
                    @endforeach
                    <div class="flex justify-between border-b border-rule px-4 py-2"><dt>{{ __('GST') }} @if ($bill->hasGst())<span class="num text-xs text-ink-3">{{ (float) ($bill->terms['gst_pct'] ?? 0) }}%</span>@endif</dt><dd class="num">{{ $bill->hasGst() ? '+'.inr($bill->gst_amount) : __('Not charged') }}</dd></div>
                    <div class="flex items-baseline justify-between bg-paper-2/60 px-4 py-3"><dt class="font-display text-lg font-semibold whitespace-nowrap">{{ __('Net payable') }}</dt><dd class="num text-lg font-medium whitespace-nowrap">{{ inr($bill->net_payable) }}</dd></div>
                </dl>
            </section>
            <x-approval-trail :approvals="$bill->approvals" />
        </div>
    </div>
@endsection
