@extends('layouts.app')
@section('title', __('Material issues'))
@section('content')
    <x-page-header :title="__('Material issues')" :subtitle="$project->name">
        @can('procurement.stock')<a href="{{ route('procurement.issues.create', $project) }}" class="btn btn-primary">{{ __('Issue material') }}</a>@endcan
    </x-page-header>

    <div class="card overflow-x-auto">
        @if ($issues->isEmpty())
            <x-empty :title="__('No issues yet')" :note="__('Issue material from a store to a BOQ item or an activity.')" />
        @else
            <table class="ledger min-w-[600px]">
                <thead><tr><th>{{ __('No.') }}</th><th>{{ __('Date') }}</th><th>{{ __('Store') }}</th><th>{{ __('Issued to') }}</th><th class="r">{{ __('Lines') }}</th><th>{{ __('Status') }}</th></tr></thead>
                <tbody>
                @foreach ($issues as $issue)
                    <tr>
                        <td><a href="{{ route('procurement.issues.show', $issue) }}" class="link num text-xs">{{ $issue->number }}</a></td>
                        <td class="num text-xs">{{ $issue->issue_date->format('d M Y') }}</td>
                        <td>{{ $issue->store->name }}</td>
                        <td>{{ $issue->issued_to ?? '—' }}</td>
                        <td class="r">{{ $issue->items_count }}</td>
                        <td><x-stamp :status="$issue->status" /></td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        @endif
    </div>
    <div class="mt-4">{{ $issues->links() }}</div>
@endsection
