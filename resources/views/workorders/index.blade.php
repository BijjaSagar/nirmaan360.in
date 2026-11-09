@extends('layouts.app')
@section('title', __('Work orders'))
@section('content')
    <x-page-header :title="__('Work orders')" :subtitle="$project->name">
        @can('work_order.manage')<a href="{{ route('work-orders.create', $project) }}" class="btn btn-primary">{{ __('New work order') }}</a>@endcan
    </x-page-header>

    <div class="card overflow-x-auto">
        @if ($workOrders->isEmpty())
            <x-empty :title="__('No work orders yet')" :note="__('Give a subcontractor BOQ items and rates, then bill their approved measurements.')" />
        @else
            <table class="ledger">
                <thead><tr><th>{{ __('Number') }}</th><th>{{ __('Title') }}</th><th>{{ __('Subcontractor') }}</th>@can('money.view')<th class="r">{{ __('Value') }}</th>@endcan<th>{{ __('Status') }}</th></tr></thead>
                <tbody>
                @foreach ($workOrders as $workOrder)
                    <tr>
                        <td><a href="{{ route('work-orders.show', $workOrder) }}" class="link num text-xs">{{ $workOrder->number }}</a></td>
                        <td>{{ $workOrder->title }}</td>
                        <td>{{ $workOrder->party->name }}</td>
                        @can('money.view')<td class="r">{{ inr($workOrder->totalValue()) }}</td>@endcan
                        <td><x-stamp :status="$workOrder->status" /></td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        @endif
    </div>
@endsection
