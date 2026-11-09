@php
    $variance = (float) $summary['variance_pct'];
    [$verdict, $tone] = match (true) {
        (float) $summary['total'] === 0.0 => [__('No plan to compare yet'), 'text-ink-3'],
        $variance >= 0 => [__('On or ahead of plan'), 'text-moss'],
        $variance > -5 => [__('Slightly behind plan'), 'text-[#8a6208]'],
        default => [__('Behind plan'), 'text-laterite'],
    };
@endphp
<section class="card">
    <div class="card-head">
        <h2 class="card-title">{{ __('Planned vs actual') }}</h2>
        <span class="text-sm font-semibold {{ $tone }}">{{ $verdict }}</span>
    </div>
    <div class="space-y-3 p-4">
        <div>
            <div class="flex items-baseline justify-between text-sm">
                <span class="text-ink-2">{{ __('Planned to date') }}</span>
                <span class="num">{{ $summary['planned_pct'] }}%</span>
            </div>
            <div class="mt-1 h-3 rounded-[2px] bg-paper-2"><div class="h-3 rounded-[2px] bg-ink-3" style="width: {{ min(100, (float) $summary['planned_pct']) }}%"></div></div>
            @can('money.view')<p class="num mt-0.5 text-xs text-ink-3">{{ inr($summary['planned']) }}</p>@endcan
        </div>
        <div>
            <div class="flex items-baseline justify-between text-sm">
                <span class="text-ink-2">{{ __('Actual, from approved measurements') }}</span>
                <span class="num">{{ $summary['actual_pct'] }}%</span>
            </div>
            <div class="mt-1 h-3 rounded-[2px] bg-paper-2"><div class="h-3 rounded-[2px] bg-neel" style="width: {{ min(100, (float) $summary['actual_pct']) }}%"></div></div>
            @can('money.view')<p class="num mt-0.5 text-xs text-ink-3">{{ __(':earned of :total', ['earned' => inr($summary['earned']), 'total' => inr($summary['total'])]) }}</p>@endcan
        </div>
    </div>
    <dl class="grid grid-cols-2 border-t border-rule text-sm sm:grid-cols-4">
        <div class="border-r border-b border-rule p-3 sm:border-b-0">
            <dt class="eyebrow text-ink-3">{{ __('Difference') }}</dt>
            <dd class="num mt-1 text-lg {{ $variance < 0 ? 'text-laterite' : '' }}">{{ $variance > 0 ? '+' : '' }}{{ $summary['variance_pct'] }}</dd>
        </div>
        <div class="border-b border-rule p-3 sm:border-r sm:border-b-0">
            <dt class="eyebrow text-ink-3">{{ __('SPI') }}</dt>
            <dd class="num mt-1 text-lg">{{ $summary['spi'] ?? '—' }}</dd>
        </div>
        <div class="border-r border-rule p-3">
            <dt class="eyebrow text-ink-3">{{ __('Delayed') }}</dt>
            <dd class="num mt-1 text-lg {{ $summary['delayed'] > 0 ? 'text-laterite' : '' }}">{{ $summary['delayed'] }}</dd>
        </div>
        <div class="p-3">
            <dt class="eyebrow text-ink-3">{{ __('Complete') }}</dt>
            <dd class="num mt-1 text-lg">{{ $summary['complete'] }} / {{ $summary['activities'] - $summary['unlinked'] }}</dd>
        </div>
    </dl>
    @if ($summary['unlinked'] > 0)
        <p class="border-t border-rule bg-haldi/10 px-4 py-2 text-xs text-ink-2">{{ __(':count activities have no BOQ item linked, so their progress is not counted.', ['count' => $summary['unlinked']]) }}</p>
    @endif
</section>
