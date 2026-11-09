@extends('layouts.app')
@section('title', __('RA bills'))
@section('content')
    <x-page-header :title="__('RA bills')" :subtitle="$project->name">
        @can('ra_bill.generate')<a href="{{ route('ra-bills.create', $project) }}" class="btn btn-primary">{{ __('Generate bill') }}</a>@endcan
    </x-page-header>

    <div class="card overflow-x-auto">
        @if ($bills->isEmpty())
            <x-empty :title="__('No bills yet')" :note="__('Bills are built from approved measurements that have not been billed yet.')" />
        @else
            <table class="ledger min-w-[820px]">
                <thead><tr><th>{{ __('Bill no.') }}</th><th>{{ __('Type') }}</th><th>{{ __('Party') }}</th><th>{{ __('Period') }}</th>@can('money.view')<th class="r">{{ __('Gross') }}</th><th class="r">{{ __('Net payable') }}</th>@endcan<th>{{ __('Status') }}</th></tr></thead>
                <tbody>
                @foreach ($bills as $bill)
                    <tr>
                        <td><a href="{{ route('ra-bills.show', $bill) }}" class="link num text-xs">{{ $bill->number }}</a></td>
                        <td>{{ $bill->type === 'client' ? __('Client') : __('Subcontractor') }}</td>
                        <td>{{ $bill->party?->name ?? '—' }}</td>
                        <td class="num text-xs">{{ $bill->period_from->format('d M') }} – {{ $bill->period_to->format('d M Y') }}</td>
                        @can('money.view')
                            <td class="r">{{ inr($bill->gross_amount) }}</td>
                            <td class="r font-medium">{{ inr($bill->net_payable) }}</td>
                        @endcan
                        <td><x-stamp :status="$bill->status" /></td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        @endif
    </div>
    <div class="mt-4">{{ $bills->links() }}</div>
@endsection
