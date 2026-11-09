@extends('layouts.app')
@section('title', __('GST reports'))
@section('content')
    @php
        $sections = [
            'b2b' => __('B2B (registered clients)'),
            'b2cl' => __('B2C large (other state, above the limit)'),
            'b2cs' => __('B2C small'),
        ];
    @endphp
    <x-page-header :title="__('GST reports')" :subtitle="__('GSTR-1 data for :month', ['month' => $report['label']])">
        <a href="{{ route('finance.reports.gstr1.excel', ['month' => $report['month']]) }}" class="btn btn-ghost">{{ __('Download Excel') }}</a>
        <a href="{{ route('finance.reports.gstr1.json', ['month' => $report['month']]) }}" class="btn btn-ghost">{{ __('Download JSON') }}</a>
    </x-page-header>
    @include('modules.finance.reports._ca-note')

    <form method="GET" class="card mb-5 flex flex-wrap items-end gap-3 p-4">
        <x-field :label="__('Month')" name="month"><input id="month" name="month" type="month" value="{{ $report['month'] }}" class="input"></x-field>
        <button class="btn btn-ghost">{{ __('Show') }}</button>
    </form>

    @if (blank($report['gstin']))
        <p class="mb-4 text-sm font-medium text-laterite">{{ __('Add your company GSTIN in Settings.') }}</p>
    @endif
    @if ($report['uninvoiced_bills'] > 0)
        <p class="mb-4 text-sm font-medium text-laterite">{{ trans_choice('{1} :count approved client bill with GST has no tax invoice, so it is not in this data.|[2,*] :count approved client bills with GST have no tax invoice, so they are not in this data.', $report['uninvoiced_bills'], ['count' => $report['uninvoiced_bills']]) }}</p>
    @endif

    <section class="card overflow-x-auto">
        <div class="card-head">
            <h2 class="card-title">{{ __('Summary') }}</h2>
            <x-stamp :status="$report['reconciled'] ? 'approved' : 'deviation'" />
        </div>
        <table class="ledger min-w-[720px]">
            <thead><tr><th>{{ __('Section') }}</th><th class="r">{{ __('Invoices') }}</th><th class="r">{{ __('Taxable value') }}</th><th class="r">IGST</th><th class="r">CGST</th><th class="r">SGST</th><th class="r">{{ __('Invoice value') }}</th></tr></thead>
            <tbody>
            @foreach ($sections as $key => $label)
                @php $row = $report['summary'][$key]; @endphp
                <tr><td>{{ $label }}</td><td class="r">{{ $row['count'] }}</td><td class="r">{{ inr($row['taxable']) }}</td><td class="r">{{ inr($row['igst']) }}</td><td class="r">{{ inr($row['cgst']) }}</td><td class="r">{{ inr($row['sgst']) }}</td><td class="r">{{ inr($row['value']) }}</td></tr>
            @endforeach
            </tbody>
            <tfoot>
            @php $total = $report['summary']['total']; @endphp
            <tr class="font-semibold"><td class="px-3 py-2">{{ __('Total') }}</td><td class="num px-3 py-2 text-right">{{ $total['count'] }}</td><td class="num px-3 py-2 text-right whitespace-nowrap">{{ inr($total['taxable']) }}</td><td class="num px-3 py-2 text-right whitespace-nowrap">{{ inr($total['igst']) }}</td><td class="num px-3 py-2 text-right whitespace-nowrap">{{ inr($total['cgst']) }}</td><td class="num px-3 py-2 text-right whitespace-nowrap">{{ inr($total['sgst']) }}</td><td class="num px-3 py-2 text-right whitespace-nowrap">{{ inr($total['value']) }}</td></tr>
            </tfoot>
        </table>
    </section>

    <section class="card mt-5 overflow-x-auto">
        <div class="card-head"><h2 class="card-title">{{ __('Invoices') }}</h2></div>
        @if ($report['invoices']->isEmpty())
            <x-empty :title="__('No tax invoices in this month')" :note="__('Issue tax invoices from each project’s Finance section.')" />
        @else
            <table class="ledger min-w-[780px]">
                <thead><tr><th>{{ __('Invoice no.') }}</th><th>{{ __('Date') }}</th><th>{{ __('Client') }}</th><th>GSTIN</th><th>{{ __('Place of supply') }}</th><th class="r">{{ __('Taxable value') }}</th><th class="r">{{ __('Invoice value') }}</th><th>{{ __('Status') }}</th></tr></thead>
                <tbody>
                @foreach ($report['invoices'] as $invoice)
                    <tr>
                        <td class="num text-xs">{{ $invoice->invoice_no }}</td>
                        <td class="num text-xs whitespace-nowrap">{{ $invoice->invoice_date->format('d M Y') }}</td>
                        <td>{{ $invoice->buyer_name }}</td>
                        <td class="num text-xs">{{ $invoice->buyer_gstin ?? '—' }}</td>
                        <td>{{ $invoice->place_of_supply }}</td>
                        <td class="r">{{ inr($invoice->taxable_amount) }}</td>
                        <td class="r">{{ inr($invoice->invoice_value) }}</td>
                        <td><x-stamp :status="$invoice->status" /></td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        @endif
    </section>
    <p class="mt-2 text-xs text-ink-3">{{ __('Credit notes, exports and advances are not recorded here. Your CA should add them before filing.') }}</p>
@endsection
