@extends('layouts.app')
@section('title', __('Weekly'))
@section('content')
    @php
        $show = \App\Services\SiteProgress\ProgressReport::show(...);
    @endphp
    <x-page-header :title="__('Site progress')" :subtitle="$project->name" />
    @include('modules.site_progress._tabs', ['active' => 'site-progress.week'])

    <div class="mb-5 flex items-center justify-between gap-2">
        <a href="{{ route('site-progress.week', [$project, 'date' => $week['start']->copy()->subWeek()->toDateString()]) }}" class="btn btn-ghost min-h-12 min-w-12" aria-label="{{ __('Previous week') }}">‹</a>
        <div class="text-center">
            <p class="font-display text-xl font-semibold">{{ $week['start']->format('d M') }} – {{ $week['end']->format('d M Y') }}</p>
            <p class="text-xs text-ink-3">{{ $week['is_current'] ? __('This week') : __('Week review') }} · {{ __(':count entries', ['count' => $week['entry_count']]) }}</p>
        </div>
        <a href="{{ route('site-progress.week', [$project, 'date' => $week['start']->copy()->addWeek()->toDateString()]) }}" class="btn btn-ghost min-h-12 min-w-12" aria-label="{{ __('Next week') }}">›</a>
    </div>

    <div class="grid gap-5 lg:grid-cols-3">
        <section class="card">
            <div class="card-head"><h2 class="card-title text-moss">{{ __('Done this week') }}</h2></div>
            @forelse ($week['done'] as $row)
                <div class="border-b border-rule px-4 py-3 last:border-b-0">
                    <div class="flex items-start justify-between gap-3">
                        <p class="min-w-0 text-sm"><span class="num text-xs text-ink-3">{{ $row['code'] }}</span> {{ $row['description'] }}</p>
                        <p class="num shrink-0 font-semibold">{{ fmt_qty($row['week_qty']) }} <span class="text-xs font-normal text-ink-3">{{ $row['unit'] }}</span></p>
                    </div>
                    @if (count($row['floors']) > 0)
                        <p class="mt-0.5 text-xs text-ink-3">{{ collect($row['floors'])->map(fn ($floor) => $floor['name'].' '.fmt_qty($floor['qty']))->implode(' · ') }}</p>
                    @endif
                    <p class="mt-0.5 text-xs text-ink-3">{{ __('Item now :percent done', ['percent' => $show($row['percent'])]) }}</p>
                </div>
            @empty
                <p class="px-4 py-5 text-sm text-ink-3">{{ __('No work was recorded this week.') }}</p>
            @endforelse
        </section>

        <section class="card">
            <div class="card-head"><h2 class="card-title text-laterite-dark">{{ __('Pending') }}</h2><span class="num text-sm text-ink-2">{{ $week['pending']->count() }}</span></div>
            @forelse ($week['pending'] as $entry)
                <div class="border-b border-rule px-4 py-3 text-sm last:border-b-0">
                    <p class="font-semibold">{{ $entry->boqItem?->code }} · {{ $entry->boqItem?->description }}</p>
                    <p class="text-xs text-ink-3">{{ $entry->floor ? ($entry->floor->parent?->name ? $entry->floor->parent->name.' › ' : '').$entry->floor->name : __('Whole project') }} · <span class="num">{{ $entry->work_date->format('d M') }}</span></p>
                    <p class="mt-0.5">{{ $entry->remark }}</p>
                </div>
            @empty
                <p class="px-4 py-5 text-sm text-ink-3">{{ __('Nothing is pending.') }}</p>
            @endforelse
        </section>

        <section class="card">
            <div class="card-head"><h2 class="card-title text-neel">{{ __('Next') }}</h2></div>
            <p class="border-b border-rule px-4 py-2.5 text-xs text-ink-3">{{ __('Work already under way, with what is left of it. Clear the pending items first.') }}</p>
            @forelse ($week['next'] as $item)
                <div class="border-b border-rule px-4 py-3 last:border-b-0">
                    <div class="flex items-start justify-between gap-3">
                        <p class="min-w-0 text-sm"><span class="num text-xs text-ink-3">{{ $item['code'] }}</span> {{ $item['description'] }}</p>
                        <p class="num shrink-0 font-medium">{{ $show($item['percent']) }}</p>
                    </div>
                    <div class="mt-1.5">@include('modules.site_progress._bar', ['percent' => $item['percent']])</div>
                    <p class="num mt-1 text-xs text-ink-3">{{ __('Left') }}: {{ fmt_qty($item['balance_qty']) }} {{ $item['unit'] }}</p>
                </div>
            @empty
                <p class="px-4 py-5 text-sm text-ink-3">{{ __('No work is under way yet.') }}</p>
            @endforelse
            @if ($week['next_more'] > 0)<p class="px-4 py-3 text-xs text-ink-3">{{ __('And :count more items under way.', ['count' => $week['next_more']]) }}</p>@endif
            @if ($week['not_started'] > 0)<p class="border-t border-rule px-4 py-3 text-xs text-ink-3">{{ __(':count BOQ items have not been started.', ['count' => $week['not_started']]) }}</p>@endif
        </section>
    </div>
@endsection
