@extends('layouts.app')
@section('title', __('Site progress'))
@section('content')
    @php
        $show = \App\Services\SiteProgress\ProgressReport::show(...);
        $behind = $summary['status'] === 'behind';
        $mepFloors = collect($floors)->filter(fn (array $floor) => $floor['mep_pending'] > 0);
    @endphp
    <x-page-header :title="__('Site progress')" :subtitle="$project->name" />
    @include('modules.site_progress._tabs', ['active' => 'site-progress.index'])

    <div class="grid gap-5 lg:grid-cols-[minmax(0,5fr)_minmax(0,7fr)]">
        <div class="space-y-5">
            @can('progress.create')
                @include('modules.site_progress._entry-form', ['items' => $items, 'floorOptions' => $floorOptions, 'clientUuid' => $clientUuid, 'floorId' => $lastFloor])
            @endcan

            <section class="card">
                <div class="card-head">
                    <h2 class="card-title">{{ __('Latest entries') }}</h2>
                    <a href="{{ route('site-progress.entries', $project) }}" class="link text-sm">{{ __('All entries') }}</a>
                </div>
                @forelse ($recent as $entry)
                    <div class="flex items-start justify-between gap-3 border-b border-rule px-4 py-2.5 text-sm last:border-b-0">
                        <div class="min-w-0">
                            <p class="truncate font-semibold">{{ $entry->boqItem?->code }} · {{ $entry->boqItem?->description }}</p>
                            <p class="truncate text-xs text-ink-3">{{ $entry->work_date->format('d M') }} · {{ $entry->floor ? ($entry->floor->parent?->name ? $entry->floor->parent->name.' › ' : '').$entry->floor->name : __('Whole project') }} · {{ $entry->enteredBy?->name }}</p>
                            @if ($entry->remark)<p class="mt-0.5 text-xs text-ink-2">{{ $entry->remark }}</p>@endif
                        </div>
                        <div class="shrink-0 text-right">
                            <p class="num font-semibold {{ \App\Support\Dec::of($entry->qty_done)->isNegative() ? 'text-laterite' : '' }}">{{ fmt_qty($entry->qty_done) }} <span class="text-xs text-ink-3">{{ $entry->boqItem?->unit }}</span></p>
                            @if ($entry->is_pending)<span class="stamp border-laterite text-laterite">{{ __('Pending') }}</span>@endif
                            @if ($entry->corrects_entry_id)<span class="stamp border-haldi text-[#8a6208]">{{ __('Correction') }}</span>@endif
                        </div>
                    </div>
                @empty
                    <x-empty :title="__('No work entered yet')" :note="__('Enter what was done today. It takes a few taps.')" />
                @endforelse
            </section>
        </div>

        <div class="space-y-5">
            <section class="card">
                <div class="card-head"><h2 class="card-title">{{ __('Time and work') }}</h2></div>
                <div class="grid grid-cols-2 gap-3 p-4">
                    <x-stat :label="__('Work done')" :value="$show($summary['work_percent'])" />
                    <x-stat :label="__('Time used')" :value="$summary['time_percent'] !== null ? $show($summary['time_percent']) : '—'"
                            :note="$summary['planned_days'] ? __(':used of :planned days', ['used' => $summary['elapsed_days'] ?? 0, 'planned' => $summary['planned_days']]) : null" :accent="$behind" />
                </div>
                @if ($summary['status'] === 'behind')
                    <p class="mx-4 mb-4 rounded-[3px] border px-3 py-2 text-sm font-medium {{ $summary['severity'] === 'high' ? 'border-laterite bg-laterite/10 text-laterite-dark' : 'border-haldi bg-haldi/15 text-ink' }}" role="status">
                        {{ __('Behind schedule: :time of the time is used but only :work of the work is done (:points points behind).', ['time' => $show($summary['time_percent']), 'work' => $show($summary['work_percent']), 'points' => rtrim(rtrim($summary['lag'], '0'), '.')]) }}
                    </p>
                @elseif ($summary['status'] === 'on_track')
                    <p class="mx-4 mb-4 rounded-[3px] border border-moss bg-moss/10 px-3 py-2 text-sm font-medium text-moss" role="status">
                        {{ __('On track: :work of the work is done and :time of the time is used.', ['time' => $show($summary['time_percent']), 'work' => $show($summary['work_percent'])]) }}
                    </p>
                @else
                    <p class="mx-4 mb-4 text-sm text-ink-3">{{ __('Set the start date and the planned number of days to compare time with work.') }}</p>
                @endif
                @if ($summary['days_to_deadline'] !== null)
                    <p class="mx-4 mb-4 text-sm text-ink-2">
                        @if ($summary['days_to_deadline'] >= 0)
                            {{ __(':days days to the deadline, with :left of the work still to do.', ['days' => $summary['days_to_deadline'], 'left' => $show($summary['work_left_percent'])]) }}
                        @else
                            {{ __('The deadline has passed, with :left of the work still to do.', ['left' => $show($summary['work_left_percent'])]) }}
                        @endif
                    </p>
                @endif
                @can('progress.manage')
                    <details class="border-t border-rule px-4 py-3" @if ($errors->has('planned_days') || $errors->has('start_date') || $errors->has('deadline_date')) open @endif>
                        <summary class="cursor-pointer text-sm font-semibold text-ink-2">{{ __('Change the time frame') }}</summary>
                        <form method="POST" action="{{ route('site-progress.schedule', $project) }}" class="mt-3 grid gap-3 sm:grid-cols-3">
                            @csrf @method('PATCH')
                            <x-field :label="__('Start date')" name="start_date"><input id="start_date" name="start_date" type="date" value="{{ old('start_date', $project->start_date?->format('Y-m-d')) }}" class="input min-h-11"></x-field>
                            <x-field :label="__('Planned days')" name="planned_days"><input id="planned_days" name="planned_days" type="number" min="1" inputmode="numeric" value="{{ old('planned_days', $project->planned_days) }}" class="input num min-h-11"></x-field>
                            <x-field :label="__('Deadline')" name="deadline_date"><input id="deadline_date" name="deadline_date" type="date" value="{{ old('deadline_date', $project->deadline_date ? \Illuminate\Support\Carbon::parse($project->deadline_date)->format('Y-m-d') : '') }}" class="input min-h-11"></x-field>
                            <div class="sm:col-span-3"><button class="btn btn-ink min-h-11">{{ __('Save') }}</button></div>
                        </form>
                    </details>
                @endcan
            </section>

            @if ($summary['open_pending'] > 0 || $mepFloors->isNotEmpty())
                <section class="card border-laterite">
                    <div class="card-head"><h2 class="card-title text-laterite-dark">{{ __('Needs attention') }}</h2></div>
                    @if ($summary['open_pending'] > 0)
                        <a href="{{ route('site-progress.pending', $project) }}" class="flex min-h-12 items-center justify-between gap-3 border-b border-rule px-4 py-2.5 text-sm last:border-b-0 hover:bg-paper/70">
                            <span class="font-semibold">{{ __(':count pending items to finish', ['count' => $summary['open_pending']]) }}</span>
                            <span class="link">{{ __('Open list') }}</span>
                        </a>
                    @endif
                    @foreach ($mepFloors as $floor)
                        <a href="{{ route('site-stages.checklist', $floor['id']) }}" class="flex min-h-12 items-center justify-between gap-3 border-b border-rule px-4 py-2.5 text-sm last:border-b-0 hover:bg-paper/70">
                            <span><span class="font-semibold">{{ $floor['name'] }}</span> · {{ __(':count plumbing or electrical items pending', ['count' => $floor['mep_pending']]) }}</span>
                            <span class="link">{{ __('Before finishing') }}</span>
                        </a>
                    @endforeach
                </section>
            @endif

            <section class="card">
                <div class="card-head">
                    <h2 class="card-title">{{ __('Floors') }}</h2>
                    <a href="{{ route('site-progress.floors.index', $project) }}" class="link text-sm">{{ __('Manage floors') }}</a>
                </div>
                @forelse ($floors as $floor)
                    @foreach ([$floor, ...$floor['children']] as $row)
                        <a href="{{ route('site-progress.floors.show', $row['id']) }}" class="block border-b border-rule px-4 py-3 last:border-b-0 hover:bg-paper/70 {{ $row['parent_id'] ? 'pl-9' : '' }}">
                            <div class="flex items-center justify-between gap-3">
                                <span class="min-w-0 truncate font-semibold">{{ $row['name'] }}</span>
                                <span class="flex shrink-0 items-center gap-2">
                                    @if ($row['pending'] > 0)<span class="stamp border-laterite text-laterite">{{ __(':count pending', ['count' => $row['pending']]) }}</span>@endif
                                    <span class="num text-base font-medium">{{ $row['targets'] > 0 ? $show($row['percent']) : '—' }}</span>
                                </span>
                            </div>
                            <div class="mt-1.5">@include('modules.site_progress._bar', ['percent' => $row['percent']])</div>
                            @if ($row['targets'] === 0)<p class="mt-1 text-xs text-ink-3">{{ __('No targets set for this floor yet.') }}</p>@endif
                        </a>
                    @endforeach
                @empty
                    <x-empty :title="__('No floors yet')" :note="__('Add the floors or segments of this project to see progress part by part.')">
                        @can('progress.manage')<a href="{{ route('site-progress.floors.index', $project) }}" class="btn btn-primary min-h-11">{{ __('Add floors') }}</a>@endcan
                    </x-empty>
                @endforelse
            </section>

            <section class="card" x-data="{ q: '' }">
                <div class="card-head">
                    <h2 class="card-title">{{ __('Progress by BOQ item') }}</h2>
                    <span class="num text-sm text-ink-2">{{ __('Project') }} {{ $show($summary['work_percent']) }}</span>
                </div>
                @if ($items->isEmpty())
                    <x-empty :title="__('No BOQ items')" :note="__('Progress is measured against the BOQ. Add the BOQ first.')" />
                @else
                    @if ($items->count() > 10)
                        <div class="border-b border-rule p-3"><input type="search" x-model="q" class="input min-h-11" placeholder="{{ __('Search by code or name') }}" aria-label="{{ __('Search by code or name') }}"></div>
                    @endif
                    @foreach ($items as $item)
                        <div class="border-b border-rule px-4 py-3 last:border-b-0" data-text="{{ mb_strtolower($item['code'].' '.$item['description']) }}"
                             x-show="q === '' || $el.dataset.text.includes(q.toLowerCase())">
                            <div class="flex items-start justify-between gap-3">
                                <p class="min-w-0 text-sm"><span class="num text-xs text-ink-3">{{ $item['code'] }}</span> {{ $item['description'] }}</p>
                                <p class="num shrink-0 text-base font-medium">{{ $show($item['percent']) }}</p>
                            </div>
                            <div class="mt-1.5">@include('modules.site_progress._bar', ['percent' => $item['percent'], 'tone' => \App\Support\Dec::of($item['percent'])->isGreaterThan(100) ? 'bg-haldi' : 'bg-moss'])</div>
                            <p class="num mt-1 text-xs text-ink-3">{{ fmt_qty($item['done_qty']) }} / {{ fmt_qty($item['qty']) }} {{ $item['unit'] }}</p>
                        </div>
                    @endforeach
                @endif
            </section>
        </div>
    </div>
@endsection
