@extends('layouts.app')
@section('title', __('Deadlines'))
@section('content')
    @php
        use App\Services\ContractorControl\Figures;
        $badge = fn (string $state) => match ($state) {
            'done' => ['border-moss text-moss', __('Finished')],
            'overdue' => ['border-laterite bg-laterite text-white', __('Overdue')],
            'due_today' => ['border-laterite text-laterite', __('Due today')],
            'behind' => ['border-laterite text-laterite', __('Behind')],
            'on_track' => ['border-moss text-moss', __('On track')],
            default => ['border-ink-3 text-ink-3', __('No date set')],
        };
        $days = function (?int $days) {
            if ($days === null) return '—';
            return $days < 0 ? trans_choice(':count day late|:count days late', -$days, ['count' => -$days]) : trans_choice(':count day left|:count days left', $days, ['count' => $days]);
        };
    @endphp

    <x-page-header :title="__('Deadlines')" :subtitle="$project->name" />

    <section class="card p-4">
        @php([$tone, $text] = $badge($overview['state']))
        <div class="flex flex-wrap items-start justify-between gap-2">
            <div>
                <p class="eyebrow text-ink-3">{{ __('Whole project') }}</p>
                <p class="mt-0.5 font-display text-2xl font-semibold">{{ __(':pct% of work left', ['pct' => Figures::pct($overview['work_left_pct'])]) }}</p>
            </div>
            <span class="stamp {{ $tone }}">{{ $text }}</span>
        </div>
        <p class="mt-1 text-sm text-ink-3">
            @if ($overview['deadline'])
                <span class="num">{{ $overview['deadline']->format('d M Y') }}</span> · {{ $days($overview['days_left']) }}
            @else
                {{ __('Set a project deadline on the Work vs payment page.') }}
            @endif
        </p>
        <div class="mt-2">@include('modules.contractor_control._bars', ['work' => $overview['work_pct']])</div>
    </section>

    <section class="card mt-5">
        <div class="card-head"><h2 class="card-title">{{ __('Contracts') }}</h2></div>
        @forelse ($contracts as $row)
            @php([$tone, $text] = $badge($row['state']))
            <a href="{{ route('contractor-control.contracts.show', $row['work_order']) }}" class="block border-b border-rule px-4 py-3 last:border-b-0 hover:bg-paper/70">
                <div class="flex flex-wrap items-start justify-between gap-x-3 gap-y-1">
                    <div class="min-w-0">
                        <p class="text-sm font-semibold">{{ $row['work_order']->title }}</p>
                        <p class="text-xs text-ink-3">{{ $row['party']?->name }}@if ($row['deadline_label']) · {{ $row['deadline_label'] }}@endif</p>
                    </div>
                    <span class="stamp {{ $tone }}">{{ $text }}</span>
                </div>
                <dl class="mt-2 grid grid-cols-3 gap-x-3 text-sm">
                    <div><dt class="text-xs text-ink-3">{{ __('Work left') }}</dt><dd class="num font-medium">{{ Figures::pct($row['work_left_pct']) }}%</dd></div>
                    <div><dt class="text-xs text-ink-3">{{ __('Days left') }}</dt><dd class="num font-medium">{{ $row['days_left'] ?? '—' }}</dd></div>
                    <div><dt class="text-xs text-ink-3">{{ __('Needed per day') }}</dt><dd class="num font-medium">{{ $row['needed_per_day'] !== null ? Figures::pct($row['needed_per_day']).'%' : '—' }}</dd></div>
                </dl>
                @if ($row['deadline'])
                    <p class="num mt-1 text-xs text-ink-3">{{ $row['deadline']->format('d M Y') }}</p>
                @endif
            </a>
        @empty
            <x-empty :title="__('No running contracts')" />
        @endforelse
    </section>
@endsection
