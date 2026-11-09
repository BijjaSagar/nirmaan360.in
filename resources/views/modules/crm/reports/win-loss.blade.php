@extends('layouts.app')
@section('title', __('Win/loss report'))
@section('content')
    @php
        $kinds = \App\Services\Crm\Vocab::reasonKinds();
        $decided = $report['counts']['won'] + $report['counts']['lost'];
    @endphp
    <x-page-header :title="__('Win/loss report')" :subtitle="$report['label'].' · '.__('Tenders closed between 1 April and 31 March')">
        <form method="GET" action="{{ route('crm.reports.win-loss') }}" class="flex items-center gap-2">
            <select name="fy" class="input min-h-10 w-auto" aria-label="{{ __('Financial year') }}" onchange="this.form.submit()">
                @foreach ($report['years'] as $year)<option value="{{ $year }}" @selected($year === $report['year'])>{{ \App\Services\Crm\FinancialYear::label($year) }}</option>@endforeach
            </select>
            <noscript><button class="btn btn-ghost">{{ __('Show') }}</button></noscript>
        </form>
    </x-page-header>

    <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
        @include('modules.crm.partials.stat', ['label' => __('Won'), 'value' => $report['counts']['won'], 'note' => inr($report['values']['won'])])
        @include('modules.crm.partials.stat', ['label' => __('Lost'), 'value' => $report['counts']['lost'], 'note' => inr($report['values']['lost']), 'accent' => $report['counts']['lost'] > $report['counts']['won']])
        @include('modules.crm.partials.stat', ['label' => __('Win rate'), 'value' => $report['win_rate_count'] !== null ? $report['win_rate_count'].'%' : '—', 'note' => $report['win_rate_value'] !== null ? __(':rate% by value', ['rate' => $report['win_rate_value']]) : __('Nothing decided yet')])
        @include('modules.crm.partials.stat', ['label' => __('Not bid'), 'value' => $report['counts']['no_bid'], 'note' => __(':count awaiting result (:value)', ['count' => $report['awaiting']['count'], 'value' => inr($report['awaiting']['value'])])])
    </div>

    @if ($report['price_gap']['percent'] !== null)
        <p class="mt-3 rounded-[3px] border border-rule bg-sheet px-4 py-3 text-sm">
            {{ __('On :count lost tenders where the winning price is known, our price was on average :gap% :direction the winner.', [
                'count' => $report['price_gap']['tenders'],
                'gap' => ltrim($report['price_gap']['percent'], '-'),
                'direction' => str_starts_with($report['price_gap']['percent'], '-') ? __('below') : __('above'),
            ]) }}
        </p>
    @endif

    @if ($decided === 0 && $report['counts']['no_bid'] === 0)
        <div class="card mt-5"><x-empty :title="__('No closed tenders in this year')" :note="__('Close a tender as won, lost or not bid and it appears here with its reason.')" /></div>
    @else
        <div class="mt-5 grid gap-5 lg:grid-cols-3">
            @foreach ($kinds as $kind => $title)
                <section class="card self-start">
                    <div class="card-head"><h2 class="card-title">{{ $title }}</h2></div>
                    @forelse ($report['reasons'][$kind] as $row)
                        <div class="border-b border-rule px-4 py-2.5 text-sm last:border-b-0">
                            <div class="flex items-baseline justify-between gap-3">
                                <span class="min-w-0 break-words">{{ $row['label'] }}</span>
                                <span class="num shrink-0 text-xs text-ink-3">{{ $row['count'] }} · {{ $row['share'] }}%</span>
                            </div>
                            <div class="mt-1 h-1.5 rounded-full bg-paper-2"><div class="h-1.5 rounded-full {{ $kind === 'won' ? 'bg-moss' : ($kind === 'lost' ? 'bg-laterite' : 'bg-ink-3') }}" style="width: {{ min(100, max(2, (float) $row['share'])) }}%"></div></div>
                            <p class="num mt-0.5 text-xs text-ink-3">{{ inr($row['value']) }}</p>
                        </div>
                    @empty
                        <p class="px-4 py-5 text-sm text-ink-3">{{ __('None in this year.') }}</p>
                    @endforelse
                </section>
            @endforeach
        </div>

        @if (! empty($report['clients']))
            <section class="card mt-5">
                <div class="card-head"><h2 class="card-title">{{ __('By authority') }}</h2></div>
                <div class="overflow-x-auto">
                    <table class="ledger min-w-[420px]">
                        <thead><tr><th>{{ __('Authority') }}</th><th class="r">{{ __('Won') }}</th><th class="r">{{ __('Lost') }}</th><th class="r">{{ __('Win rate') }}</th></tr></thead>
                        <tbody>
                        @foreach ($report['clients'] as $row)
                            <tr><td>{{ $row['name'] }}</td><td class="r">{{ $row['won'] }}</td><td class="r">{{ $row['lost'] }}</td><td class="r">{{ $row['rate'] !== null ? $row['rate'].'%' : '—' }}</td></tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            </section>
        @endif

        <section class="card mt-5">
            <div class="card-head"><h2 class="card-title">{{ __('Closed tenders') }}</h2></div>
            <div class="overflow-x-auto">
                <table class="ledger min-w-[760px]">
                    <thead><tr><th>{{ __('Tender') }}</th><th>{{ __('Authority') }}</th><th>{{ __('Result') }}</th><th>{{ __('Reason') }}</th><th class="r">{{ __('Value') }}</th><th>{{ __('Closed on') }}</th></tr></thead>
                    <tbody>
                    @foreach ($report['tenders'] as $tender)
                        <tr>
                            <td><a href="{{ route('crm.tenders.show', $tender) }}" class="link">{{ $tender->title }}</a></td>
                            <td>{{ $tender->authority }}</td>
                            <td>@include('modules.crm.partials.stage', ['stage' => $tender->stage])</td>
                            <td>{{ $tender->reason?->label ?? '—' }}</td>
                            <td class="r">{{ inr($tender->headlineValue()) }}</td>
                            <td class="num text-xs">{{ $tender->closed_on?->format('d M Y') }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    @endif
@endsection
