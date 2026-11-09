@extends('layouts.app')
@section('title', __('Material returns'))
@section('content')
    <x-page-header :title="__('Material returns')" :subtitle="$project->name" />

    <div class="card overflow-x-auto">
        @if ($returns->isEmpty())
            <x-empty :title="__('No returns yet')" :note="__('Start a return from a material issue or a goods receipt.')" />
        @else
            <table class="ledger min-w-[600px]">
                <thead><tr><th>{{ __('No.') }}</th><th>{{ __('Date') }}</th><th>{{ __('Type') }}</th><th>{{ __('Against') }}</th><th>{{ __('Store') }}</th><th>{{ __('Status') }}</th></tr></thead>
                <tbody>
                @foreach ($returns as $return)
                    <tr>
                        <td><a href="{{ route('procurement.returns.show', $return) }}" class="link num text-xs">{{ $return->number }}</a></td>
                        <td class="num text-xs">{{ $return->return_date->format('d M Y') }}</td>
                        <td>{{ $return->isToVendor() ? __('Return to vendor') : __('Return to store') }}</td>
                        <td class="num text-xs">{{ $return->grn?->number ?? $return->issue?->number }}</td>
                        <td>{{ $return->store->name }}</td>
                        <td><x-stamp :status="$return->status" /></td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        @endif
    </div>
    <div class="mt-4">{{ $returns->links() }}</div>
@endsection
