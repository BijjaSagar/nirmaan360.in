@extends('layouts.app')
@section('title', __('Planning'))
@section('content')
    <x-page-header :title="__('Planning')" :subtitle="$project->name">
        @can('planning.manage')
            <form method="POST" action="{{ route('planning.alerts.scan', $project) }}">@csrf<button class="btn btn-ghost">{{ __('Check delays now') }}</button></form>
            {{-- The baseline freezes the budget as well as the dates, so it needs money.overview too. --}}
            @can('money.overview')
                <form method="POST" action="{{ route('planning.baseline.store', $project) }}" @if ($baseline) onsubmit="return confirm(@js(__('Replace the saved baseline with the current plan and budget?')))" @endif>
                    @csrf
                    <button class="btn btn-primary">{{ $baseline ? __('Update baseline') : __('Save baseline') }}</button>
                </form>
            @endcan
        @endcan
    </x-page-header>

    @include('modules.planning.partials.tabs')

    <div class="grid gap-5 lg:grid-cols-2">
        @include('modules.planning.partials.panel')

        <section class="card">
            <div class="card-head">
                <h2 class="card-title">{{ __('Delayed activities') }}</h2>
                <a href="{{ route('planning.schedule', $project) }}" class="link text-sm">{{ __('Open schedule') }}</a>
            </div>
            @forelse ($delayed as $activity)
                @php $progress = $plan->progress[$activity->id]; @endphp
                <a href="{{ route('planning.activities.show', $activity) }}" class="flex items-center justify-between gap-3 border-b border-rule px-4 py-2.5 text-sm last:border-b-0 hover:bg-paper/70">
                    <span class="min-w-0">
                        <span class="block truncate font-medium">{{ $activity->name }}</span>
                        <span class="num block text-xs text-ink-3">{{ $activity->code }} · {{ __(':days days late', ['days' => $progress->delayDays]) }}</span>
                    </span>
                    @include('modules.planning.partials.state')
                </a>
            @empty
                <p class="px-4 py-5 text-sm text-ink-3">{{ __('Nothing is delayed.') }}</p>
            @endforelse
        </section>

        <div class="lg:col-span-2">@include('modules.planning.partials.scurve')</div>

        <section class="card">
            <div class="card-head">
                <h2 class="card-title">{{ __('Starting soon') }}</h2>
            </div>
            @forelse ($upcoming as $activity)
                <a href="{{ route('planning.activities.show', $activity) }}" class="flex items-center justify-between gap-3 border-b border-rule px-4 py-2.5 text-sm last:border-b-0 hover:bg-paper/70">
                    <span class="min-w-0 truncate">{{ $activity->name }}</span>
                    <span class="num shrink-0 text-xs text-ink-3">{{ $activity->planned_start->format('d M') }}</span>
                </a>
            @empty
                <p class="px-4 py-5 text-sm text-ink-3">{{ __('Nothing starts in the next two weeks.') }}</p>
            @endforelse
        </section>

        <section class="card">
            <div class="card-head">
                <h2 class="card-title">@can('money.overview'){{ __('Budget and baseline') }}@else{{ __('Baseline') }}@endcan</h2>
                @can('money.overview')<a href="{{ route('planning.budget', $project) }}" class="link text-sm">{{ __('Open budget') }}</a>@endcan
            </div>
            <dl class="divide-y divide-rule text-sm">
                @can('money.overview')
                    <div class="flex justify-between gap-3 px-4 py-2.5"><dt class="text-ink-2">{{ __('Current budget') }}</dt><dd class="num">{{ inr($budget['budget']) }}</dd></div>
                    <div class="flex justify-between gap-3 px-4 py-2.5"><dt class="text-ink-2">{{ __('Actual cost') }}</dt><dd class="num {{ $budget['over'] ? 'text-laterite' : '' }}">{{ inr($budget['actual']) }}</dd></div>
                    <div class="flex justify-between gap-3 px-4 py-2.5"><dt class="text-ink-2">{{ __('Budget used') }}</dt><dd class="num">{{ $budget['used_pct'] === null ? '—' : $budget['used_pct'].'%' }}</dd></div>
                @endcan
                <div class="flex justify-between gap-3 px-4 py-2.5">
                    <dt class="text-ink-2">{{ __('Baseline') }}</dt>
                    <dd class="text-right text-xs text-ink-3">
                        @if ($baseline)
                            {{ __('Saved on :date by :name', ['date' => $baseline->taken_at->format('d M Y'), 'name' => $baseline->taker?->name ?? '—']) }}
                        @else
                            {{ __('Not saved yet') }}
                        @endif
                    </dd>
                </div>
            </dl>
        </section>
    </div>
@endsection
