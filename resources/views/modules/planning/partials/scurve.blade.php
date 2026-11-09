{{-- Lines stretch with the card, so they sit in an SVG scaled to 1000 × 100 while the axis text stays HTML and readable at any width. --}}
@php
    $points = $curve['points'];
    $first = $points[0]['day'] ?? 0;
    $span = max(1, ($points[count($points) - 1]['day'] ?? 1) - $first);
    $x = fn (int $day) => round(($day - $first) / $span * 1000, 2);
    $y = fn ($percent) => round(100 - (float) $percent, 2);

    $line = fn (string $field) => collect($points)
        ->filter(fn ($point) => $point[$field] !== null)
        ->map(fn ($point) => $x($point['day']).','.$y($point[$field]))
        ->implode(' ');

    // The curve is percentages for everyone; the rupee values behind each point need money.view.
    $seesMoney = auth()->user()->can('money.view');
    $chart = collect($points)->map(fn ($point) => [
        'x' => $x($point['day']),
        'date' => \Illuminate\Support\Carbon::parse($point['date'])->format('d M Y'),
        'planned' => $point['planned_pct'],
        'plannedValue' => $seesMoney ? inr($point['planned']) : null,
        'actual' => $point['actual_pct'],
        'actualValue' => $seesMoney && $point['actual'] !== null ? inr($point['actual']) : null,
    ])->values();

    $count = count($points);
    $ticks = collect(range(0, 4))->map(fn ($step) => $points[(int) round($step * ($count - 1) / 4)] ?? null)->filter()->unique('day')->values();
    $todayPoint = collect($points)->firstWhere('is_today', true);
@endphp
<section class="card" x-data="planCurve(@js($chart))">
    <div class="card-head flex-wrap">
        <h2 class="card-title">{{ __('S-curve') }}</h2>
        <div class="flex items-center gap-4 text-xs text-ink-2">
            <span class="inline-flex items-center gap-1.5"><svg width="22" height="6" aria-hidden="true"><line x1="0" y1="3" x2="22" y2="3" class="stroke-ink-3" stroke-width="2" stroke-dasharray="5 3"/></svg>{{ __('Planned') }}</span>
            <span class="inline-flex items-center gap-1.5"><svg width="22" height="6" aria-hidden="true"><line x1="0" y1="3" x2="22" y2="3" class="stroke-neel" stroke-width="2"/></svg>{{ __('Actual') }}</span>
        </div>
    </div>
    @if ($points === [])
        <x-empty :title="__('Nothing to chart yet')" :note="__('Link activities to BOQ items and the curve will appear here.')" />
    @else
        <div class="p-4">
            <div class="relative ml-9 h-56 sm:h-72">
                @foreach ([0, 25, 50, 75, 100] as $mark)
                    <span class="num absolute -left-9 w-7 -translate-y-1/2 text-right text-[11px] text-ink-3" style="top: {{ 100 - $mark }}%">{{ $mark }}%</span>
                @endforeach
                <svg viewBox="0 0 1000 100" preserveAspectRatio="none" class="absolute inset-0 block size-full overflow-visible" role="img" aria-label="{{ __('Cumulative planned and actual progress over time') }}">
                    @foreach ([0, 25, 50, 75, 100] as $mark)
                        <line x1="0" x2="1000" y1="{{ 100 - $mark }}" y2="{{ 100 - $mark }}" class="stroke-rule" stroke-width="1" vector-effect="non-scaling-stroke"/>
                    @endforeach
                    <polyline points="{{ $line('planned_pct') }}" fill="none" class="stroke-ink-3" stroke-width="2" stroke-dasharray="6 4" vector-effect="non-scaling-stroke"/>
                    <polyline points="{{ $line('actual_pct') }}" fill="none" class="stroke-neel" stroke-width="2" vector-effect="non-scaling-stroke"/>
                </svg>
                @if ($todayPoint)
                    <div class="absolute inset-y-0 border-l border-dashed border-laterite/70" style="left: {{ $x($todayPoint['day']) / 10 }}%" aria-hidden="true">
                        <span class="eyebrow absolute -top-1 left-1 text-[10px] text-laterite">{{ __('Today') }}</span>
                    </div>
                    <span class="absolute size-2.5 -translate-x-1/2 -translate-y-1/2 rounded-full border-2 border-sheet bg-neel" style="left: {{ $x($todayPoint['day']) / 10 }}%; top: {{ 100 - (float) $todayPoint['actual_pct'] }}%" aria-hidden="true"></span>
                @endif
                <div class="absolute inset-0 touch-pan-y" @pointermove="move($event)" @pointerdown="move($event)" @pointerleave="leave()">
                    <template x-if="point">
                        <div>
                            <div class="pointer-events-none absolute inset-y-0 border-l border-ink/40" :style="{ left: point.x / 10 + '%' }"></div>
                            <div class="pointer-events-none absolute top-2 z-10 w-44 rounded-[3px] border border-rule bg-sheet px-2.5 py-1.5 text-xs shadow-sm" :style="{ left: Math.min(point.x / 10, 58) + '%' }">
                                <p class="num font-medium" x-text="point.date"></p>
                                <p class="mt-0.5 flex justify-between gap-2 text-ink-2"><span>{{ __('Planned') }}</span><span class="num" x-text="point.planned + '%'"></span></p>
                                <p class="flex justify-between gap-2 text-ink-2" x-show="point.actual !== null"><span>{{ __('Actual') }}</span><span class="num" x-text="point.actual + '%'"></span></p>
                            </div>
                        </div>
                    </template>
                </div>
            </div>
            <div class="relative mt-1 ml-9 h-4 text-[11px] text-ink-3">
                @foreach ($ticks as $tick)
                    <span @class([
                        'num absolute whitespace-nowrap',
                        '-translate-x-1/2' => ! $loop->first && ! $loop->last,
                        '-translate-x-full' => $loop->last,
                        'hidden sm:inline' => $loop->index % 2 === 1 && ! $loop->last,
                    ]) style="left: {{ $x($tick['day']) / 10 }}%">{{ \Illuminate\Support\Carbon::parse($tick['date'])->format($curve['grain'] === 'month' ? 'M y' : 'd M') }}</span>
                @endforeach
            </div>
            <details class="mt-3 text-sm">
                <summary class="link cursor-pointer text-xs">{{ __('Show the figures') }}</summary>
                <div class="mt-2 overflow-x-auto">
                    <table class="ledger">
                        <thead><tr><th>{{ __('Date') }}</th><th class="r">{{ __('Planned') }} %</th>@if ($seesMoney)<th class="r">{{ __('Planned') }} ₹</th>@endif<th class="r">{{ __('Actual') }} %</th>@if ($seesMoney)<th class="r">{{ __('Actual') }} ₹</th>@endif</tr></thead>
                        <tbody>
                        @foreach ($points as $point)
                            <tr>
                                <td class="whitespace-nowrap">{{ \Illuminate\Support\Carbon::parse($point['date'])->format('d M Y') }}</td>
                                <td class="r">{{ $point['planned_pct'] }}</td>
                                @if ($seesMoney)<td class="r">{{ inr($point['planned']) }}</td>@endif
                                <td class="r">{{ $point['actual_pct'] ?? '—' }}</td>
                                @if ($seesMoney)<td class="r">{{ $point['actual'] === null ? '—' : inr($point['actual']) }}</td>@endif
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            </details>
        </div>
    @endif
</section>
<script>
    window.planCurve = (points) => ({
        index: null,
        get point() { return this.index === null ? null : points[this.index]; },
        move(event) {
            const box = event.currentTarget.getBoundingClientRect();
            const x = (event.clientX - box.left) / box.width * 1000;
            this.index = points.reduce((best, p, i) => Math.abs(p.x - x) < Math.abs(points[best].x - x) ? i : best, 0);
        },
        leave() { this.index = null; },
    });
</script>
