@extends('layouts.app')
@section('title', __('RFIs'))
@section('content')
    <x-page-header :title="__('Requests for information')" :subtitle="$project->name">
        @can('rfi.manage')<a href="{{ route('rfis.create', $project) }}" class="btn btn-primary">{{ __('New RFI') }}</a>@endcan
    </x-page-header>

    <div class="card overflow-x-auto">
        @if ($rfis->isEmpty())
            <x-empty :title="__('No RFIs yet')" :note="__('Ask the designer or client a question in writing and keep the answer on record.')" />
        @else
            <table class="ledger min-w-[780px]">
                <thead><tr><th>{{ __('RFI') }}</th><th>{{ __('Addressed to') }}</th><th>{{ __('Priority') }}</th><th>{{ __('Needed by') }}</th><th>{{ __('Raised by') }}</th><th>{{ __('Status') }}</th></tr></thead>
                <tbody>
                @foreach ($rfis as $rfi)
                    <tr>
                        <td>
                            <a href="{{ route('rfis.show', $rfi) }}" class="link num">{{ $rfi->number }}</a>
                            <p class="font-semibold">{{ $rfi->subject }}</p>
                            @if ($rfi->drawing_ref)<p class="text-xs text-ink-3">{{ $rfi->drawing_ref }}</p>@endif
                        </td>
                        <td>{{ $rfi->addressed_to ?? '—' }}</td>
                        <td>@include('modules.field_ops._chip', ['set' => 'severity', 'value' => $rfi->priority])</td>
                        <td class="num text-xs {{ $rfi->isOverdue() ? 'font-medium text-laterite' : '' }}">{{ $rfi->required_by?->format('d M Y') ?? '—' }}</td>
                        <td>{{ $rfi->raiser?->name ?? '—' }}</td>
                        <td>@include('modules.field_ops._chip', ['set' => 'rfi', 'value' => $rfi->stage()])</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        @endif
    </div>
    <div class="mt-4">{{ $rfis->links() }}</div>
@endsection
