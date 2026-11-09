@extends('layouts.app')
@section('title', __('Tax invoices'))
@section('content')
    <x-page-header :title="__('Tax invoices')" :subtitle="$project->name" />
    @include('modules.finance._tabs')

    @can('finance.manage')
        <section class="card">
            <div class="card-head"><h2 class="card-title">{{ __('RA bills to invoice') }}</h2></div>
            @forelse ($bills as $bill)
                <form method="POST" action="{{ route('finance.project.invoices.store', $bill) }}" class="grid items-end gap-3 border-b border-rule p-4 last:border-b-0 sm:grid-cols-5">
                    @csrf
                    <div>
                        <p class="num text-sm font-medium">{{ $bill->number }}</p>
                        <p class="num text-xs text-ink-3">{{ inr(\App\Support\Dec::of($bill->gross_amount)->plus(\App\Support\Dec::of($bill->gst_amount))) }}</p>
                    </div>
                    <div><label class="label">{{ __('Invoice date') }}</label><input name="invoice_date" type="date" max="{{ now()->format('Y-m-d') }}" value="{{ now()->format('Y-m-d') }}" class="input" required></div>
                    <div><label class="label">{{ __('SAC') }}</label><input name="sac" value="{{ config('modules.finance.default_sac') }}" maxlength="8" class="input num" required></div>
                    <div><label class="label">{{ __('Description') }}</label><input name="description" value="{{ config('modules.finance.default_invoice_description') }}" maxlength="200" class="input" required></div>
                    <div><button class="btn btn-primary">{{ __('Issue invoice') }}</button></div>
                </form>
            @empty
                <x-empty :title="__('Nothing to invoice')" :note="__('Approved client RA bills with GST appear here.')" />
            @endforelse
        </section>
    @endcan

    <section class="card mt-5">
        <div class="card-head"><h2 class="card-title">{{ __('Issued invoices') }}</h2></div>
        @forelse ($invoices as $invoice)
            <div class="border-b border-rule px-4 py-3 last:border-b-0">
                <div class="flex flex-wrap items-baseline justify-between gap-2">
                    <div>
                        <span class="num text-sm font-medium">{{ $invoice->invoice_no }}</span>
                        <span class="text-sm">{{ $invoice->buyer_name }}</span>
                        <span class="num text-xs text-ink-3">{{ $invoice->invoice_date->format('d M Y') }} · {{ $invoice->raBill?->number }}</span>
                    </div>
                    <div class="flex items-center gap-3">
                        <span class="num text-sm font-medium">{{ inr($invoice->invoice_value) }}</span>
                        <x-stamp :status="$invoice->status" />
                        <a href="{{ route('finance.project.invoices.pdf', $invoice) }}" class="link text-xs">{{ __('Download PDF') }}</a>
                    </div>
                </div>
                <p class="mt-1 text-xs text-ink-3">
                    @if ($invoice->irn)
                        {{ __('IRN') }}: <span class="num break-all">{{ $invoice->irn }}</span>
                    @else
                        {{ __('No IRN recorded. Generate it on the e-invoice portal if your turnover requires one, then save it here.') }}
                    @endif
                </p>
                @can('finance.manage')
                    @if ($invoice->status === 'ISSUED' && ! $invoice->irn)
                        <details class="mt-2">
                            <summary class="link cursor-pointer text-xs">{{ __('Save IRN') }}</summary>
                            <form method="POST" action="{{ route('finance.project.invoices.irn', $invoice) }}" class="mt-2 grid items-end gap-3 sm:grid-cols-4">
                                @csrf
                                @method('PATCH')
                                <div class="sm:col-span-2"><label class="label">{{ __('IRN') }}</label><input name="irn" minlength="64" maxlength="64" class="input num" required></div>
                                <div><label class="label">{{ __('Acknowledgement no.') }}</label><input name="irn_ack_no" maxlength="20" class="input num" required></div>
                                <div><label class="label">{{ __('Acknowledgement date') }}</label><input name="irn_ack_date" type="datetime-local" class="input" required></div>
                                <div><button class="btn btn-ghost">{{ __('Save IRN') }}</button></div>
                            </form>
                        </details>
                        <form method="POST" action="{{ route('finance.project.invoices.cancel', $invoice) }}" class="mt-2" onsubmit="return confirm(@js(__('Cancel this invoice? Its number stays used.')))">@csrf<button class="link text-xs">{{ __('Cancel invoice') }}</button></form>
                    @endif
                @endcan
            </div>
        @empty
            <x-empty :title="__('No tax invoices yet')" />
        @endforelse
    </section>
@endsection
