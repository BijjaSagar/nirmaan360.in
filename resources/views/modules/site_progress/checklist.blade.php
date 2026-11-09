@extends('layouts.app')
@section('title', __('Before finishing'))
@section('content')
    @php
        $works = config('modules.site_progress.finishing_works');
        $workLabel = __($works[$work]);
        $pendingCount = count($checklist['pending']);
        $unfinishedCount = count($checklist['unfinished']);
    @endphp
    <x-page-header :title="__('Before finishing')" :subtitle="$floor->name.' · '.$project->name">
        <a href="{{ route('site-progress.floors.show', $floor) }}" class="btn btn-ghost min-h-11">{{ __('Floor progress') }}</a>
    </x-page-header>
    @include('modules.site_progress._tabs', ['active' => 'site-stages.index'])

    <div class="mb-4 flex flex-wrap items-center gap-2">
        <span class="text-sm font-semibold text-ink-2">{{ __('About to start') }}</span>
        @foreach ($works as $key => $label)
            <a href="{{ route('site-stages.checklist', [$floor, 'for' => $key]) }}" class="btn min-h-11 px-4 {{ $work === $key ? 'btn-ink' : 'btn-ghost' }}" @if ($work === $key) aria-current="page" @endif>{{ __($label) }}</a>
        @endforeach
    </div>

    @if ($pendingCount > 0)
        <p class="mb-5 rounded-[3px] border border-laterite bg-laterite/10 px-4 py-3 text-base font-semibold text-laterite-dark" role="alert">
            {{ __(':count plumbing or electrical items are pending on :floor. Finish them before :work starts, or walls will have to be broken again.', ['count' => $pendingCount, 'floor' => $floor->name, 'work' => $workLabel]) }}
        </p>
    @elseif ($unfinishedCount > 0)
        <p class="mb-5 rounded-[3px] border border-haldi bg-haldi/15 px-4 py-3 text-base font-semibold" role="status">
            {{ __('Nothing is marked pending, but :count plumbing or electrical stages are not done yet. Check them before :work starts.', ['count' => $unfinishedCount, 'work' => $workLabel]) }}
        </p>
    @else
        <p class="mb-5 rounded-[3px] border border-moss bg-moss/10 px-4 py-3 text-base font-semibold text-moss" role="status">
            {{ __('All plumbing and electrical stages are done on :floor. It is clear to start :work.', ['floor' => $floor->name, 'work' => $workLabel]) }}
        </p>
    @endif

    @if ($pendingCount > 0)
        <section class="card mb-5">
            <div class="card-head"><h2 class="card-title text-laterite-dark">{{ __('Pending') }}</h2><span class="num text-sm">{{ $pendingCount }}</span></div>
            @foreach ($checklist['pending'] as $item)
                <div class="flex items-start justify-between gap-3 border-b border-rule px-4 py-3 last:border-b-0">
                    <div class="min-w-0">
                        <p class="text-sm font-semibold">{{ __($trades[$item['trade']]['stages'][$item['stage']]['label']) }}</p>
                        <p class="text-xs text-ink-3">{{ __($trades[$item['trade']]['label']) }} · {{ $item['row'] }}@if ($item['since']) · <span class="num">{{ \Illuminate\Support\Carbon::parse($item['since'])->format('d M') }}</span>@endif</p>
                        @if ($item['remark'])<p class="mt-1 text-[15px]">{{ $item['remark'] }}</p>@endif
                        @if ($item['photos'] > 0)<p class="mt-0.5 text-xs text-ink-3">{{ __(':count photos', ['count' => $item['photos']]) }}</p>@endif
                    </div>
                    @can('progress.create')
                        <a href="{{ route('site-stages.index', [$project, 'trade' => $item['trade']]) }}#row-{{ $item['floor_id'] }}" class="btn btn-primary min-h-11 shrink-0">{{ __('Update') }}</a>
                    @endcan
                </div>
            @endforeach
        </section>
    @endif

    @if ($unfinishedCount > 0)
        <details class="card mb-5">
            <summary class="card-head cursor-pointer"><span class="card-title">{{ __('Stages not done yet') }}</span><span class="num text-sm">{{ $unfinishedCount }}</span></summary>
            @foreach (collect($checklist['unfinished'])->groupBy('row') as $row => $items)
                <div class="border-b border-rule px-4 py-3 last:border-b-0">
                    <p class="text-sm font-semibold">{{ $row }}</p>
                    <ul class="mt-1 space-y-0.5 text-sm text-ink-2">
                        @foreach ($items as $item)
                            <li>{{ __($trades[$item['trade']]['label']) }} · {{ __($trades[$item['trade']]['stages'][$item['stage']]['label']) }} <span class="text-xs text-ink-3">({{ __($statuses[$item['status']]) }})</span></li>
                        @endforeach
                    </ul>
                </div>
            @endforeach
        </details>
    @endif
@endsection
