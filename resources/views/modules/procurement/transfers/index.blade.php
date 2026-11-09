@extends('layouts.app')
@section('title', __('Site transfers'))
@section('content')
    <x-page-header :title="__('Site transfers')" :subtitle="$project->name">
        @can('procurement.stock')<a href="{{ route('procurement.transfers.create', $project) }}" class="btn btn-primary">{{ __('New transfer') }}</a>@endcan
    </x-page-header>

    <div class="card overflow-x-auto">
        @if ($transfers->isEmpty())
            <x-empty :title="__('No transfers yet')" :note="__('Move material between stores on this or another project.')" />
        @else
            <table class="ledger min-w-[640px]">
                <thead><tr><th>{{ __('No.') }}</th><th>{{ __('Dispatched') }}</th><th>{{ __('From') }}</th><th>{{ __('To') }}</th><th class="r">{{ __('Lines') }}</th><th>{{ __('Status') }}</th></tr></thead>
                <tbody>
                @foreach ($transfers as $transfer)
                    <tr>
                        <td><a href="{{ route('procurement.transfers.show', $transfer) }}" class="link num text-xs">{{ $transfer->number }}</a></td>
                        <td class="num text-xs">{{ $transfer->dispatch_date->format('d M Y') }}</td>
                        <td>{{ $transfer->fromStore->name }}</td>
                        <td>{{ $transfer->toStore->name }}</td>
                        <td class="r">{{ $transfer->items_count }}</td>
                        <td><x-stamp :status="$transfer->status" /></td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        @endif
    </div>
    <div class="mt-4">{{ $transfers->links() }}</div>
@endsection
