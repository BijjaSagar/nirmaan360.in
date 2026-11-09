@extends('layouts.app')
@section('title', __('DPR').' '.$dpr->report_date->format('d M Y'))
@section('content')
    <x-page-header :title="__('DPR').' · '.$dpr->report_date->format('d M Y')" :subtitle="$project->name.($dpr->site ? ' · '.$dpr->site->name : '').' · '.($dpr->creator?->name ?? '')">
        <x-stamp :status="$dpr->status" />
        @include('modules.reports._dpr-actions', ['dpr' => $dpr])
        @if ($dpr->isEditable())
            @can('dpr.create')
                <form method="POST" action="{{ route('dprs.submit', $dpr) }}">@csrf<button class="btn btn-primary">{{ __('Submit for approval') }}</button></form>
            @endcan
        @endif
    </x-page-header>

    <div class="grid gap-5 lg:grid-cols-3">
        <div class="space-y-5 lg:col-span-2">
            <section class="card p-4">
                <p class="eyebrow text-ink-3">{{ __('Weather') }}</p>
                <p class="mt-0.5">{{ $dpr->weather ?? '—' }}</p>
                <p class="eyebrow mt-3 text-ink-3">{{ __('Summary of work') }}</p>
                <p class="mt-0.5 whitespace-pre-line">{{ $dpr->summary ?? '—' }}</p>
                <p class="eyebrow mt-3 text-ink-3">{{ __('Hindrances') }}</p>
                <p class="mt-0.5 whitespace-pre-line">{{ $dpr->hindrances ?? '—' }}</p>
            </section>

            <section class="card overflow-x-auto">
                <div class="card-head"><h2 class="card-title">{{ __('Work done') }}</h2></div>
                @if ($dpr->workItems->isEmpty())
                    <p class="px-4 py-5 text-sm text-ink-3">{{ __('No work items recorded.') }}</p>
                @else
                    <table class="ledger">
                        <thead><tr><th>{{ __('BOQ item') }}</th><th>{{ __('Description') }}</th><th class="r">{{ __('Quantity') }}</th></tr></thead>
                        <tbody>
                        @foreach ($dpr->workItems as $work)
                            <tr>
                                <td>{{ $work->boqItem?->code ?? '—' }}</td>
                                <td>{{ $work->description ?? $work->boqItem?->description }}</td>
                                <td class="r">{{ fmt_qty($work->qty) }} {{ $work->boqItem?->unit }}</td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                @endif
            </section>

            @if ($dpr->photos->isNotEmpty())
                <section class="card">
                    <div class="card-head"><h2 class="card-title">{{ __('Photos') }}</h2></div>
                    <div class="grid grid-cols-2 gap-3 p-4 sm:grid-cols-3">
                        @foreach ($dpr->photos as $photo)
                            <a href="{{ route('dprs.photo', $photo) }}" target="_blank" rel="noopener"><img src="{{ route('dprs.photo', $photo) }}" alt="{{ __('Site photo') }}" class="aspect-[4/3] w-full rounded-[3px] object-cover"></a>
                        @endforeach
                    </div>
                </section>
            @endif
        </div>

        <div class="space-y-5">
            <section class="card">
                <div class="card-head"><h2 class="card-title">{{ __('Manpower') }}</h2><span class="num text-sm">{{ $dpr->manpower->sum('count') }}</span></div>
                @forelse ($dpr->manpower as $row)
                    <div class="flex justify-between border-b border-rule px-4 py-1.5 text-sm last:border-b-0"><span>{{ __($row->trade) }}</span><span class="num">{{ $row->count }}</span></div>
                @empty
                    <p class="px-4 py-4 text-sm text-ink-3">{{ __('None recorded.') }}</p>
                @endforelse
            </section>

            @if ($dpr->machinery->isNotEmpty())
                <section class="card">
                    <div class="card-head"><h2 class="card-title">{{ __('Machinery') }}</h2></div>
                    @foreach ($dpr->machinery as $row)
                        <div class="flex justify-between border-b border-rule px-4 py-1.5 text-sm last:border-b-0"><span>{{ $row->name }}</span><span class="num">{{ $row->hours }} h</span></div>
                    @endforeach
                </section>
            @endif

            <x-approval-trail :approvals="$dpr->approvals" />
        </div>
    </div>
@endsection
