@extends('modules.contractor_portal.layout')
@section('title', $bill->number)
@section('content')
    <x-page-header :title="($bill->is_final ? __('Final bill') : __('Bill')).' '.$bill->number" :subtitle="$bill->workOrder?->number.' · '.$bill->project?->name.' · '.$bill->period_from->format('d M Y').' – '.$bill->period_to->format('d M Y')">
        <x-stamp :status="$bill->status" />
        <a href="{{ route('contractor-portal.bills.pdf', $bill) }}" class="btn btn-ghost">{{ __('Download PDF') }}</a>
        <a href="{{ route('contractor-portal.bills') }}" class="btn btn-ghost">{{ __('All bills') }}</a>
    </x-page-header>

    <div class="grid gap-5 lg:grid-cols-3">
        <section class="card overflow-x-auto lg:col-span-2">
            <table class="ledger min-w-[760px]">
                <thead><tr><th>{{ __('Item') }}</th><th class="w-[30%]">{{ __('Description') }}</th><th>{{ __('Unit') }}</th><th class="r">{{ __('Rate') }}</th><th class="r">{{ __('Previous') }}</th><th class="r">{{ __('This bill') }}</th><th class="r">{{ __('Cumulative') }}</th><th class="r">{{ __('Amount') }}</th></tr></thead>
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

        <section class="card">
            <div class="card-head"><h2 class="card-title">{{ __('Bill abstract') }}</h2></div>
            <dl class="text-sm">
                <div class="flex justify-between border-b border-rule px-4 py-2"><dt>{{ __('Gross value of work') }}</dt><dd class="num">{{ inr($bill->gross_amount) }}</dd></div>
                @foreach ($bill->deductions as $deduction)
                    <div class="flex justify-between gap-3 border-b border-rule px-4 py-2">
                        <dt>{{ __($deduction->label) }} @if ($deduction->pct !== null && \App\Support\Dec::of($deduction->pct)->isPositive())<span class="num text-xs text-ink-3">{{ \App\Services\ContractorControl\Figures::pct($deduction->pct) }}%</span>@endif</dt>
                        <dd class="num whitespace-nowrap text-laterite">−{{ inr($deduction->amount) }}</dd>
                    </div>
                @endforeach
                <div class="flex justify-between border-b border-rule px-4 py-2"><dt>{{ __('GST') }}</dt><dd class="num">{{ $bill->hasGst() ? '+'.inr($bill->gst_amount) : __('Not charged') }}</dd></div>
                @if ($bill->is_final)
                    <div class="flex justify-between border-b border-rule px-4 py-2"><dt>{{ __('Retention released') }}</dt><dd class="num whitespace-nowrap">+{{ inr($bill->retention_released) }}</dd></div>
                @endif
                <div class="flex items-baseline justify-between bg-paper-2/60 px-4 py-3"><dt class="font-display text-lg font-semibold whitespace-nowrap">{{ __('Net payable') }}</dt><dd class="num text-lg font-medium whitespace-nowrap">{{ inr($bill->net_payable) }}</dd></div>
                @if ($bill->status === 'PAID')
                    <div class="flex justify-between border-t border-rule px-4 py-2"><dt>{{ __('Received') }}</dt><dd class="num">{{ inr($bill->paid_amount ?? $bill->net_payable) }}@if ($bill->paid_at) <span class="text-xs text-ink-3">{{ $bill->paid_at->format('d M Y') }}</span>@endif</dd></div>
                @endif
            </dl>
        </section>
    </div>
@endsection
