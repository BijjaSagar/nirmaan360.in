@extends('layouts.app')
@section('title', __('Pending'))
@section('content')
    @php
        $groups = $pending->groupBy(fn ($entry) => $entry->project_floor_id ?? 0);
        $mepFloors = collect($floors)->filter(fn (array $floor) => $floor['mep_pending'] > 0);
    @endphp
    <x-page-header :title="__('Site progress')" :subtitle="$project->name" />
    @include('modules.site_progress._tabs', ['active' => 'site-progress.pending'])

    <p class="mb-4 max-w-2xl text-sm text-ink-3">{{ __('Everything left undone, from the remarks marked pending. Finish these before the next stage covers them.') }}</p>

    @if ($mepFloors->isNotEmpty())
        <section class="card mb-5 border-laterite">
            <div class="card-head"><h2 class="card-title text-laterite-dark">{{ __('Plumbing and electrical items pending') }}</h2></div>
            @foreach ($mepFloors as $floor)
                <a href="{{ route('site-stages.checklist', $floor['id']) }}" class="flex min-h-12 items-center justify-between gap-3 border-b border-rule px-4 py-2.5 text-sm last:border-b-0 hover:bg-paper/70">
                    <span><span class="font-semibold">{{ $floor['name'] }}</span> · {{ __(':count plumbing or electrical items pending', ['count' => $floor['mep_pending']]) }}</span>
                    <span class="link">{{ __('Before finishing') }}</span>
                </a>
            @endforeach
        </section>
    @endif

    @forelse ($groups as $floorId => $entries)
        @php($first = $entries->first())
        <section class="card mb-5">
            <div class="card-head">
                <h2 class="card-title">
                    @if ($floorId)
                        <a href="{{ route('site-progress.floors.show', $floorId) }}" class="hover:underline">{{ ($first->floor->parent?->name ? $first->floor->parent->name.' › ' : '').$first->floor->name }}</a>
                    @else
                        {{ __('Whole project') }}
                    @endif
                </h2>
                <span class="num text-sm text-ink-2">{{ $entries->count() }}</span>
            </div>
            @foreach ($entries as $entry)
                @include('modules.site_progress._pending-item', ['entry' => $entry])
            @endforeach
        </section>
    @empty
        <div class="card"><x-empty :title="__('Nothing pending')" :note="__('When something is left undone, mark it pending while entering the work and it will be listed here.')" /></div>
    @endforelse
@endsection
