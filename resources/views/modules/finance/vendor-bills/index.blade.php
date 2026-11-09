@extends('layouts.app')
@section('title', __('Vendor bills'))
@section('content')
    <x-page-header :title="__('Vendor bills')" :subtitle="$project->name">
        @can('finance.manage')<a href="{{ route('finance.project.vendor-bills.create', $project) }}" class="btn btn-primary">{{ __('Add vendor bill') }}</a>@endcan
    </x-page-header>
    @include('modules.finance._tabs')

    <div class="card overflow-x-auto">
        @if ($bills->isEmpty())
            <x-empty :title="__('No vendor bills yet')" :note="__('Add a bill from a vendor or subcontractor and send it for approval.')" />
        @else
            <table class="ledger min-w-[820px]">
                <thead><tr><th>{{ __('Bill no.') }}</th><th>{{ __('Vendor') }}</th><th>{{ __('Bill date') }}</th><th>{{ __('Cost head') }}</th><th class="r">{{ __('Total') }}</th><th class="r">{{ __('TDS') }}</th><th class="r">{{ __('Net payable') }}</th><th>{{ __('Status') }}</th></tr></thead>
                <tbody>
                @foreach ($bills as $bill)
                    <tr>
                        <td><a href="{{ route('finance.project.vendor-bills.show', $bill) }}" class="link num text-xs">{{ $bill->bill_no }}</a></td>
                        <td>{{ $bill->party?->name ?? '—' }}</td>
                        <td class="num text-xs whitespace-nowrap">{{ $bill->bill_date->format('d M Y') }}</td>
                        <td>{{ __($bill->cost_head) }}</td>
                        <td class="r">{{ inr($bill->total_amount) }}</td>
                        <td class="r">{{ inr($bill->tds_amount) }}</td>
                        <td class="r font-medium">{{ inr($bill->net_payable) }}</td>
                        <td><x-stamp :status="$bill->status" /></td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        @endif
    </div>
    <div class="mt-4">{{ $bills->links() }}</div>
@endsection
