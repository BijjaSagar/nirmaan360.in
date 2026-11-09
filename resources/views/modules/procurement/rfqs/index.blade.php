@extends('layouts.app')
@section('title', __('RFQs'))
@section('content')
    <x-page-header :title="__('RFQs')" :subtitle="$project->name" />

    <div class="card overflow-x-auto">
        @if ($rfqs->isEmpty())
            <x-empty :title="__('No RFQs yet')" :note="__('Open an approved indent to ask vendors for quotations.')" />
        @else
            <table class="ledger min-w-[600px]">
                <thead><tr><th>{{ __('No.') }}</th><th>{{ __('Indent') }}</th><th>{{ __('Quotes due') }}</th><th class="r">{{ __('Vendors') }}</th><th class="r">{{ __('Lines') }}</th><th>{{ __('Status') }}</th></tr></thead>
                <tbody>
                @foreach ($rfqs as $rfq)
                    <tr>
                        <td><a href="{{ route('procurement.rfqs.show', $rfq) }}" class="link num text-xs">{{ $rfq->number }}</a></td>
                        <td class="num text-xs">{{ $rfq->indent->number }}</td>
                        <td class="num text-xs">{{ $rfq->due_date?->format('d M Y') ?? '—' }}</td>
                        <td class="r">{{ $rfq->quotations_count }}</td>
                        <td class="r">{{ $rfq->items_count }}</td>
                        <td><x-stamp :status="$rfq->status" /></td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        @endif
    </div>
    <div class="mt-4">{{ $rfqs->links() }}</div>
@endsection
