@extends('layouts.app')
@section('title', __('Price impact'))
@use('App\Support\Dec')
@use('App\Support\Figure')
@section('content')
    @php
        $canManage = auth()->user()->can('price_watch.manage');
        $signedMoney = fn ($value) => (Dec::of($value)->isPositive() ? '+' : '').Dec::indian($value);
        $signedPct = fn ($value) => (Dec::of($value)->isPositive() ? '+' : '').Figure::trim($value).'%';
        $why = [
            'no_price' => __('No price on the board yet'),
            'no_boq_rate' => __('Enter the BOQ rate'),
            'no_qty' => __('No quantity: link a catalogue material with BOQ norms, or type the BOQ quantity'),
            'unit_mismatch' => __('The price unit does not match the catalogue unit and no conversion is known'),
        ];
        $rise = Dec::of($summary['rise_pct']);
    @endphp

    <x-page-header :title="__('Price impact')" :subtitle="$project->name">
        <a href="{{ route('price-watch.index') }}" class="btn btn-ghost min-h-11">{{ __('Price board') }}</a>
        <a href="{{ route('project-setup.budget', $project) }}" class="btn btn-ghost min-h-11">{{ __('BOQ budget') }}</a>
    </x-page-header>

    <div class="mb-5 rounded-[3px] border border-haldi bg-haldi/10 px-4 py-3 text-sm text-ink-2" role="note">
        <strong>{{ __('No live price feed.') }}</strong>
        {{ __('The current prices come from the company price board, each with its date and source. A price older than :days days is out of date and raises no alert.', ['days' => $summary['stale_days']]) }}
    </div>

    <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
        <x-stat :label="__('Impact on the budget')" :value="$signedMoney($summary['impact'])" :note="$summary['impact_pct'] === null ? __('No budget or BOQ value to compare with') : __(':pct of the :basis', ['pct' => $signedPct($summary['impact_pct']), 'basis' => $summary['basis'] === 'budget' ? __('approved budget') : __('BOQ value')])" :accent="Dec::of($summary['impact'])->isPositive()" />
        <x-stat :label="__('Materials with an impact')" :value="$summary['with_impact'].' / '.$summary['materials']" :note="__(':count with a BOQ rate', ['count' => $summary['with_boq_rate']])" />
        <x-stat :label="__('Above the BOQ rate')" :value="$summary['rising']" :note="__('By :pct% or more', ['pct' => Figure::trim($summary['rise_pct'])])" :accent="$summary['rising'] > 0" />
        <x-stat :label="__('Out of date')" :value="$summary['stale']" :note="__('Older than :days days', ['days' => $summary['stale_days']])" :accent="$summary['stale'] > 0" />
    </div>

    @if ($summary['budget'] !== null)
        <p class="mt-2 text-sm text-ink-3">
            {{ __('Approved budget :budget, allowed up to :ceiling. BOQ :boq; with the rest of the material at today\'s prices, :projected.', ['budget' => inr($summary['budget']), 'ceiling' => inr($summary['ceiling']), 'boq' => inr($summary['boq']), 'projected' => inr($summary['projected'])]) }}
            @if ($summary['beyond_margin'])<span class="stamp ml-1 border-laterite text-laterite">{{ __('Beyond the margin') }}</span>@endif
        </p>
    @endif

    <section class="card mt-5 overflow-x-auto">
        <div class="card-head">
            <h2 class="card-title">{{ __('BOQ rate against the current price') }}</h2>
            <span class="text-sm text-ink-3">{{ __('Impact = (current price − BOQ rate) × quantity still to buy') }}</span>
        </div>
        @if ($rows->isEmpty())
            <x-empty :title="__('No materials on the board')" :note="__('Put materials on the company price board first.')" />
        @else
            <form method="POST" action="{{ route('price-watch.project.rates', $project) }}">
                @csrf
                <table class="ledger min-w-[880px]">
                    <thead>
                        <tr>
                            <th>{{ __('Material') }}</th>
                            <th>{{ __('Current price') }}</th>
                            <th class="r">{{ __('BOQ rate') }}</th>
                            <th class="r">{{ __('Against BOQ') }}</th>
                            <th class="r">{{ __('Still to buy') }}</th>
                            <th class="r">{{ __('Impact') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rows as $row)
                            <tr>
                                <td>
                                    <a href="{{ route('price-watch.materials.show', $row['id']) }}" class="link font-medium">{{ $row['name'] }}</a>
                                    <span class="block text-[11px] text-ink-3">{{ __('per') }} {{ $row['unit'] }}</span>
                                </td>
                                <td>@include('modules.price_watch._price', ['price' => $row['current']])</td>
                                <td class="r">
                                    @if ($canManage)
                                        <label class="sr-only" for="rate-{{ $row['id'] }}">{{ __('BOQ rate') }}</label>
                                        <input id="rate-{{ $row['id'] }}" name="rates[{{ $row['id'] }}]" type="number" inputmode="decimal" step="0.01" min="0" value="{{ old('rates.'.$row['id'], $row['boq_rate']) }}" class="input num min-h-11 w-32 text-right" placeholder="₹">
                                    @else
                                        <span class="num text-sm">{{ $row['boq_rate'] === null ? '—' : inr($row['boq_rate']) }}</span>
                                    @endif
                                </td>
                                <td class="r num text-sm font-medium {{ $row['above_boq_pct'] !== null && Dec::of($row['above_boq_pct'])->isGreaterThanOrEqualTo($rise) ? 'text-laterite' : ($row['above_boq_pct'] !== null && Dec::of($row['above_boq_pct'])->isNegative() ? 'text-moss' : '') }}">
                                    {{ $row['above_boq_pct'] === null ? '—' : $signedPct($row['above_boq_pct']) }}
                                    @if ($row['difference'] !== null)<span class="block text-[11px] font-normal text-ink-3">{{ $signedMoney($row['difference']) }} {{ __('per') }} {{ $row['unit'] }}</span>@endif
                                </td>
                                <td class="r text-sm">
                                    @if ($row['need_basis'] === 'norms')
                                        <span class="num">{{ fmt_qty($row['remaining']) }}</span> {{ $row['unit'] }}
                                        <span class="block text-[11px] text-ink-3">{{ __('BOQ need :need, received :received', ['need' => fmt_qty($row['need']), 'received' => fmt_qty($row['received'])]) }}</span>
                                    @else
                                        @if ($canManage)
                                            <label class="sr-only" for="qty-{{ $row['id'] }}">{{ __('BOQ quantity') }}</label>
                                            <input id="qty-{{ $row['id'] }}" name="qtys[{{ $row['id'] }}]" type="number" inputmode="decimal" step="0.001" min="0" value="{{ old('qtys.'.$row['id'], $row['boq_qty']) }}" class="input num min-h-11 w-32 text-right" placeholder="{{ $row['unit'] }}">
                                            <span class="block text-[11px] text-ink-3">{{ $row['need'] === null ? __('Type the BOQ quantity') : __('Typed, received :received', ['received' => fmt_qty($row['received'])]) }}</span>
                                        @else
                                            <span class="num">{{ $row['remaining'] === null ? '—' : fmt_qty($row['remaining']).' '.$row['unit'] }}</span>
                                        @endif
                                    @endif
                                </td>
                                <td class="r">
                                    @if ($row['impact'] === null)
                                        <span class="block max-w-48 text-[11px] leading-tight text-ink-3">{{ $why[$row['why']] ?? '' }}</span>
                                    @else
                                        <span class="num text-sm font-medium {{ Dec::of($row['impact'])->isPositive() ? 'text-laterite' : (Dec::of($row['impact'])->isNegative() ? 'text-moss' : '') }}">{{ $signedMoney($row['impact']) }}</span>
                                        @if ($row['stale'])<span class="block text-[11px] text-ink-3">{{ __('From an out-of-date price') }}</span>@endif
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                @if ($canManage)
                    <div class="flex flex-wrap items-center gap-3 border-t border-rule p-4">
                        <button class="btn btn-primary min-h-12 px-5 text-base">{{ __('Save BOQ rates') }}</button>
                        <p class="text-xs text-ink-3">{{ __('The BOQ rate is what this project\'s BOQ or quotation assumed for the material. Blank means not set.') }}</p>
                    </div>
                @endif
            </form>
        @endif
        <p class="border-t border-rule px-4 py-2 text-xs text-ink-3">{{ __('Quantity still to buy is the BOQ need from the material norms less what has been received, as material control works it out, in the unit the price is quoted per. Where the BOQ has no norm for a material, type the BOQ quantity.') }}</p>
    </section>
@endsection
