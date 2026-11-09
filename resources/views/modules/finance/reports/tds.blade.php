@extends('layouts.app')
@section('title', __('TDS reports'))
@section('content')
    <x-page-header :title="__('TDS reports')" :subtitle="$report['from'].' – '.$report['to']">
        <a href="{{ route('finance.reports.tds.excel', ['from' => $report['from'], 'to' => $report['to']]) }}" class="btn btn-ghost">{{ __('Download Excel') }}</a>
    </x-page-header>
    @include('modules.finance.reports._ca-note')

    <form method="GET" class="card mb-5 flex flex-wrap items-end gap-3 p-4">
        <x-field :label="__('From')" name="from"><input id="from" name="from" type="date" value="{{ $report['from'] }}" class="input"></x-field>
        <x-field :label="__('To')" name="to"><input id="to" name="to" type="date" value="{{ $report['to'] }}" class="input"></x-field>
        <button class="btn btn-ghost">{{ __('Show') }}</button>
    </form>

    @if ($report['missing_pan'] > 0)
        <p class="mb-4 text-sm font-medium text-laterite">{{ trans_choice('{1} :count deductee has no PAN saved. A higher TDS rate can apply.|[2,*] :count deductees have no PAN saved. A higher TDS rate can apply.', $report['missing_pan'], ['count' => $report['missing_pan']]) }}</p>
    @endif

    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
        <x-stat :label="__('TDS deducted by us')" :value="inr($report['deducted_total'])" :note="__('Payable to the government')" />
        <x-stat :label="__('TDS deducted by clients')" :value="inr($report['client_total'])" :note="__('To claim against Form 26AS')" />
    </div>

    <section class="card mt-5 overflow-x-auto">
        <div class="card-head"><h2 class="card-title">{{ __('By section') }}</h2></div>
        @if ($report['by_section'] === [])
            <x-empty :title="__('No TDS in this period')" />
        @else
            <table class="ledger">
                <thead><tr><th>{{ __('Section') }}</th><th class="r">{{ __('Entries') }}</th><th class="r">{{ __('Amount credited') }}</th><th class="r">{{ __('TDS') }}</th></tr></thead>
                <tbody>
                @foreach ($report['by_section'] as $row)
                    <tr><td class="num">{{ $row['section'] }}</td><td class="r">{{ $row['count'] }}</td><td class="r">{{ inr($row['base']) }}</td><td class="r">{{ inr($row['tds']) }}</td></tr>
                @endforeach
                </tbody>
            </table>
        @endif
    </section>

    <section class="card mt-5 overflow-x-auto">
        <div class="card-head"><h2 class="card-title">{{ __('TDS deducted by us') }}</h2></div>
        @if ($report['deducted']->isEmpty())
            <x-empty :title="__('No TDS in this period')" />
        @else
            <table class="ledger min-w-[820px]">
                <thead><tr><th>{{ __('Date') }}</th><th>{{ __('Deductee') }}</th><th>PAN</th><th>{{ __('Section') }}</th><th>{{ __('Document') }}</th><th class="r">{{ __('Amount credited') }}</th><th class="r">{{ __('Rate') }}</th><th class="r">{{ __('TDS') }}</th></tr></thead>
                <tbody>
                @foreach ($report['deducted'] as $row)
                    <tr>
                        <td class="num text-xs whitespace-nowrap">{{ $row['date'] }}</td>
                        <td>{{ $row['party'] ?? '—' }}</td>
                        <td class="num text-xs {{ blank($row['pan']) ? 'text-laterite' : '' }}">{{ $row['pan'] ?? __('Missing') }}</td>
                        <td class="num text-xs">{{ $row['section'] }}</td>
                        <td class="num text-xs">{{ $row['document'] }}</td>
                        <td class="r">{{ inr($row['base']) }}</td>
                        <td class="r">{{ (float) $row['rate'] }}%</td>
                        <td class="r font-medium">{{ inr($row['tds']) }}</td>
                    </tr>
                @endforeach
                </tbody>
                <tfoot><tr class="font-semibold"><td colspan="5" class="px-3 py-2">{{ __('Total') }}</td><td class="num px-3 py-2 text-right whitespace-nowrap">{{ inr($report['deducted_base']) }}</td><td></td><td class="num px-3 py-2 text-right whitespace-nowrap">{{ inr($report['deducted_total']) }}</td></tr></tfoot>
            </table>
        @endif
    </section>

    <section class="card mt-5 overflow-x-auto">
        <div class="card-head"><h2 class="card-title">{{ __('TDS deducted by clients') }}</h2></div>
        @if ($report['from_clients']->isEmpty())
            <x-empty :title="__('No TDS in this period')" />
        @else
            <table class="ledger min-w-[760px]">
                <thead><tr><th>{{ __('Date') }}</th><th>{{ __('Client') }}</th><th>{{ __('Receipt') }}</th><th>{{ __('RA bill') }}</th><th class="r">{{ __('Received in bank') }}</th><th class="r">{{ __('TDS') }}</th></tr></thead>
                <tbody>
                @foreach ($report['from_clients'] as $row)
                    <tr>
                        <td class="num text-xs whitespace-nowrap">{{ $row['date'] }}</td>
                        <td>{{ $row['party'] ?? '—' }}</td>
                        <td class="num text-xs">{{ $row['voucher'] }}</td>
                        <td class="num text-xs">{{ $row['document'] ?? '—' }}</td>
                        <td class="r">{{ inr($row['received']) }}</td>
                        <td class="r font-medium">{{ inr($row['tds']) }}</td>
                    </tr>
                @endforeach
                </tbody>
                <tfoot><tr class="font-semibold"><td colspan="4" class="px-3 py-2">{{ __('Total') }}</td><td class="num px-3 py-2 text-right whitespace-nowrap">{{ inr($report['client_received']) }}</td><td class="num px-3 py-2 text-right whitespace-nowrap">{{ inr($report['client_total']) }}</td></tr></tfoot>
            </table>
        @endif
    </section>
@endsection
