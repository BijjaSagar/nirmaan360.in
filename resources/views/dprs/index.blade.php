@extends('layouts.app')
@section('title', __('Daily reports'))
@section('content')
    <x-page-header :title="__('Daily reports')" :subtitle="$project->name">
        @can('dpr.create')<a href="{{ route('dprs.create', $project) }}" class="btn btn-primary">{{ __('New DPR') }}</a>@endcan
    </x-page-header>

    <div class="card overflow-x-auto">
        @if ($dprs->isEmpty())
            <x-empty :title="__('No daily reports yet')" :note="__('Log manpower, work done and photos for each day on site.')" />
        @else
            <table class="ledger">
                <thead><tr><th>{{ __('Date') }}</th><th>{{ __('Site') }}</th><th>{{ __('Weather') }}</th><th class="r">{{ __('Manpower') }}</th><th>{{ __('By') }}</th><th>{{ __('Status') }}</th></tr></thead>
                <tbody>
                @foreach ($dprs as $dpr)
                    <tr>
                        <td><a href="{{ route('dprs.show', $dpr) }}" class="link num">{{ $dpr->report_date->format('d M Y') }}</a></td>
                        <td>{{ $dpr->site?->name ?? '—' }}</td>
                        <td>{{ $dpr->weather ?? '—' }}</td>
                        <td class="r">{{ (int) $dpr->manpower_sum_count }}</td>
                        <td>{{ $dpr->creator?->name ?? '—' }}</td>
                        <td><x-stamp :status="$dpr->status" /></td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        @endif
    </div>
    <div class="mt-4">{{ $dprs->links() }}</div>
@endsection
