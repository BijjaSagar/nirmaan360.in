@extends('layouts.app')
@section('title', __('BOQ budget'))
@use('App\Services\ProjectSetup\BoqCategories')
@use('App\Support\Dec')
@section('content')
    @php
        $count = fn (int $number) => $number.' '.($number === 1 ? __('item') : __('items'));
        $signed = fn ($value) => (Dec::of($value)->isPositive() ? '+' : '').Dec::indian($value);
        $statusLabels = ['within' => __('Within budget'), 'margin' => __('Over budget, inside the margin'), 'over' => __('Beyond the margin')];
        $statusTones = ['within' => 'border-moss text-moss', 'margin' => 'border-haldi text-[#8a6208]', 'over' => 'border-laterite text-laterite'];
        $present = $allLines->pluck('item.category')->filter()->unique()->values();
    @endphp

    <x-page-header :title="__('BOQ budget')" :subtitle="$project->name">
        <a href="{{ route('boq.index', $project) }}" class="btn btn-ghost min-h-11">{{ __('Back to BOQ & ledger') }}</a>
        @can('project.manage')
            <a href="{{ route('projects.edit', $project) }}" class="btn btn-ghost min-h-11">{{ __('Set budget') }}</a>
        @endcan
    </x-page-header>

    <div class="mb-5 rounded-[3px] border border-haldi bg-haldi/10 px-4 py-3 text-sm text-ink-2" role="note">
        <strong>{{ __('No live price feed.') }}</strong>
        {{ __('Nirmaan360 does not fetch market prices. The current rates on this page are typed in by hand, and are only as fresh as the date next to each one.') }}
    </div>

    {{-- Budget against the BOQ --}}
    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
        @if ($summary['budget'])
            <x-stat :label="__('Approved budget')" :value="inr($summary['budget'])" />
            <x-stat :label="__('Allowed up to')" :value="inr($summary['ceiling'])" :note="__('Budget plus :pct% margin', ['pct' => $summary['margin_pct']])" />
        @else
            <div class="card px-4 py-3 sm:col-span-2">
                <p class="eyebrow text-ink-3">{{ __('Approved budget') }}</p>
                <p class="mt-1.5 text-sm text-ink-2">{{ __('No budget has been set for this project yet.') }}
                    @can('project.manage')<a href="{{ route('projects.edit', $project) }}" class="link">{{ __('Set it now') }}</a>@endcan
                </p>
            </div>
        @endif
        <x-stat :label="__('BOQ value')" :value="inr($summary['boq'])" :note="$count($summary['line_count'])" />
        @if ($summary['budget'])
            <x-stat :label="__('BOQ against budget')" :value="$signed($summary['variance'])" :note="$signed($summary['variance_pct']).'%'" :accent="$summary['status'] === 'over'" />
        @endif
    </div>
    @if ($summary['status'])
        <p class="mt-2 text-sm"><span class="stamp {{ $statusTones[$summary['status']] }}">{{ $statusLabels[$summary['status']] }}</span>
            <span class="text-ink-3">{{ __('Variance is the BOQ value minus the approved budget.') }}</span></p>
    @endif

    <div class="mt-5 grid gap-5 lg:grid-cols-2">
        {{-- At the rates people have typed in --}}
        <section class="card">
            <div class="card-head"><h2 class="card-title">{{ __('At current rates') }}</h2></div>
            <dl class="space-y-2 p-4 text-sm">
                <div class="flex justify-between gap-3"><dt class="text-ink-3">{{ __('Items with a current rate') }}</dt><dd class="num">{{ $summary['priced_lines'] }} / {{ $summary['line_count'] }}</dd></div>
                <div class="flex justify-between gap-3"><dt class="text-ink-3">{{ __('Budget impact of the rate changes') }}</dt><dd class="num font-medium {{ Dec::of($summary['impact'])->isPositive() ? 'text-laterite' : '' }}">{{ $signed($summary['impact']) }}</dd></div>
                <div class="flex justify-between gap-3"><dt class="text-ink-3">{{ __('BOQ at current rates') }}</dt><dd class="num font-medium">{{ inr($summary['projected']) }}</dd></div>
                @if ($summary['budget'])
                    <div class="flex justify-between gap-3"><dt class="text-ink-3">{{ __('Against the approved budget') }}</dt><dd class="num font-medium">{{ $signed($summary['projected_variance']) }} ({{ $signed($summary['projected_variance_pct']) }}%)</dd></div>
                    <div class="pt-1"><span class="stamp {{ $statusTones[$summary['projected_status']] }}">{{ $statusLabels[$summary['projected_status']] }}</span></div>
                @endif
            </dl>
            <p class="border-t border-rule px-4 py-2 text-xs text-ink-3">{{ __('Each item: (current rate − BOQ rate) × BOQ quantity. Items nobody has priced are counted at their BOQ rate.') }}</p>
        </section>

        {{-- Permission fees --}}
        <section class="card">
            <div class="card-head">
                <h2 class="card-title">{{ __('Permissions and approvals') }}</h2>
                @can('permit.view')<a href="{{ route('project-setup.permits.index', $project) }}" class="link text-sm">{{ __('Open list') }}</a>@endcan
            </div>
            <dl class="space-y-2 p-4 text-sm">
                <div class="flex justify-between gap-3"><dt class="text-ink-3">{{ __('Total fees') }}</dt><dd class="num font-medium">{{ inr($summary['permit_fees']) }}</dd></div>
                <div class="flex justify-between gap-3"><dt class="text-ink-3">{{ __('Approved') }}</dt><dd class="num">{{ $summary['permits']['approved'] }} / {{ $summary['permits']['total'] }}</dd></div>
                <div class="flex justify-between gap-3"><dt class="text-ink-3">{{ __('BOQ and permission fees together') }}</dt><dd class="num">{{ inr($summary['boq_with_permits']) }}</dd></div>
            </dl>
            <p class="border-t border-rule px-4 py-2 text-xs text-ink-3">{{ __('Permission fees are a separate head. They are not added into the BOQ value or its variance above.') }}</p>
        </section>
    </div>

    {{-- RCC and brick work --}}
    <section class="mt-5">
        <h2 class="mb-2 text-2xl">{{ __('RCC and brick work') }}</h2>
        <p class="mb-3 text-sm text-ink-3">{{ __('These two heads are where most money is lost, and where most can be saved. Give each BOQ item its category so it shows up here.') }}</p>
        <div class="grid gap-3 md:grid-cols-2">
            @foreach ($watched as $head)
                <div class="card">
                    <div class="card-head">
                        <h3 class="card-title">{{ BoqCategories::label($head['category']) }}</h3>
                        <span class="text-sm text-ink-3">{{ $count($head['lines']) }}</span>
                    </div>
                    @if ($head['lines'] === 0)
                        <p class="p-4 text-sm text-ink-3">{{ __('No BOQ item has this category yet.') }}</p>
                    @else
                        <dl class="space-y-2 p-4 text-sm">
                            <div class="flex justify-between gap-3"><dt class="text-ink-3">{{ __('BOQ value') }}</dt><dd class="num font-medium">{{ inr($head['value']) }}</dd></div>
                            <div class="flex justify-between gap-3"><dt class="text-ink-3">{{ __('Share of the BOQ') }}</dt><dd class="num">{{ $head['share_pct'] }}%</dd></div>
                            <div class="flex justify-between gap-3"><dt class="text-ink-3">{{ __('Work done') }}</dt><dd class="num">{{ $head['done_pct'] }}%</dd></div>
                            <div class="flex justify-between gap-3"><dt class="text-ink-3">{{ __('Impact of current rates') }}</dt><dd class="num {{ Dec::of($head['impact'])->isPositive() ? 'text-laterite' : '' }}">{{ $head['priced'] > 0 ? $signed($head['impact']) : '—' }}</dd></div>
                            <div class="flex justify-between gap-3"><dt class="text-ink-3">{{ __('Done beyond the BOQ quantity') }}</dt>
                                <dd class="num {{ $head['over_lines'] > 0 ? 'font-medium text-laterite' : '' }}">{{ $head['over_lines'] > 0 ? inr($head['over_value']).' · '.$count($head['over_lines']) : '—' }}</dd></div>
                        </dl>
                        <div class="border-t border-rule px-4 py-2 text-sm"><a href="{{ route('project-setup.budget', [$project, 'category' => $head['category']]) }}#rates" class="link">{{ __('See these items and their rates') }}</a></div>
                    @endif
                </div>
            @endforeach
        </div>
    </section>

    {{-- Swap an item --}}
    <section class="card mt-5" id="swap">
        <div class="card-head"><h2 class="card-title">{{ __('What if we swap an item?') }}</h2></div>
        <form method="GET" action="{{ route('project-setup.budget', $project) }}#swap" class="grid gap-3 p-4 sm:grid-cols-6">
            <p class="text-sm text-ink-3 sm:col-span-6">{{ __('For example the BOQ has one make of sanitary ware and the client now wants a dearer one. See the new amount and what it does to the budget before you change anything.') }}</p>
            <x-field :label="__('Item')" name="swap_item" class="sm:col-span-6">
                <select id="swap_item" name="swap_item" class="input min-h-11" required>
                    <option value="">—</option>
                    @foreach ($allLines as $line)
                        <option value="{{ $line['item']->id }}" @selected((string) request('swap_item') === (string) $line['item']->id)>{{ $line['item']->code }} · {{ \Illuminate\Support\Str::limit($line['item']->description, 70) }} ({{ inr($line['item']->rate) }})</option>
                    @endforeach
                </select>
            </x-field>
            <x-field :label="__('New description (optional)')" name="swap_description" class="sm:col-span-4">
                <input id="swap_description" name="swap_description" value="{{ request('swap_description') }}" class="input min-h-11" maxlength="2000" placeholder="{{ __('For example Kohler wall-hung WC') }}">
            </x-field>
            <x-field :label="__('New rate (₹)')" name="swap_rate" class="sm:col-span-2">
                <input id="swap_rate" name="swap_rate" type="number" inputmode="decimal" step="0.01" min="0" value="{{ request('swap_rate') }}" class="input num min-h-11" required>
            </x-field>
            <div class="sm:col-span-6"><button class="btn btn-ghost min-h-11">{{ __('Show the effect') }}</button></div>
        </form>

        @if ($swap)
            <div class="border-t border-rule p-4">
                <h3 class="mb-2 font-display text-lg font-semibold">{{ $swap['item']->code }} · {{ \Illuminate\Support\Str::limit($swap['old_description'], 90) }}</h3>
                <div class="grid gap-3 sm:grid-cols-3">
                    <x-stat :label="__('Now')" :value="inr($swap['old_amount'])" :note="inr($swap['old_rate']).' × '.fmt_qty($swap['item']->qty).' '.$swap['item']->unit" />
                    <x-stat :label="__('After the swap')" :value="inr($swap['new_amount'])" :note="inr($swap['new_rate']).' × '.fmt_qty($swap['item']->qty).' '.$swap['item']->unit" />
                    <x-stat :label="__('Change to the budget')" :value="$signed($swap['change'])" :note="$swap['change_pct'] !== null ? $signed($swap['change_pct']).'%' : null" :accent="Dec::of($swap['change'])->isPositive()" />
                </div>
                <dl class="mt-3 space-y-1.5 text-sm">
                    <div class="flex justify-between gap-3"><dt class="text-ink-3">{{ __('BOQ value') }}</dt><dd class="num">{{ inr($swap['boq_before']) }} → <span class="font-medium">{{ inr($swap['boq_after']) }}</span></dd></div>
                    @if ($swap['budget'])
                        <div class="flex justify-between gap-3"><dt class="text-ink-3">{{ __('Against the approved budget') }}</dt><dd class="num">{{ $signed($swap['variance_after']) }} ({{ $signed($swap['variance_after_pct']) }}%)</dd></div>
                        <div class="flex items-center justify-between gap-3"><dt class="text-ink-3">{{ __('Position') }}</dt>
                            <dd>
                                <span class="stamp {{ $statusTones[$swap['status_before']] }}">{{ $statusLabels[$swap['status_before']] }}</span>
                                →
                                <span class="stamp {{ $statusTones[$swap['status_after']] }}">{{ $statusLabels[$swap['status_after']] }}</span>
                            </dd></div>
                    @endif
                </dl>

                @can('boq.manage')
                    <form method="POST" action="{{ route('project-setup.budget.swap', $project) }}" class="mt-4 space-y-3 rounded-[3px] border border-haldi/60 bg-haldi/10 p-3">
                        @csrf
                        <input type="hidden" name="boq_item_id" value="{{ $swap['item']->id }}">
                        <input type="hidden" name="rate" value="{{ $swap['new_rate'] }}">
                        <input type="hidden" name="description" value="{{ $swap['new_description'] !== $swap['old_description'] ? $swap['new_description'] : '' }}">
                        <div>
                            <label class="label" for="swap-reason">{{ __('Why is it changing?') }}</label>
                            <input id="swap-reason" name="reason" class="input min-h-11" required maxlength="500" placeholder="{{ __('For example: client asked for a higher-end make') }}">
                            <p class="hint">{{ __('Applying this revises the BOQ item and is kept in the BOQ history. Measurements and bills already made do not change.') }}</p>
                        </div>
                        <div class="flex flex-wrap gap-2">
                            <button class="btn btn-primary min-h-12 px-5 text-base">{{ __('Apply this swap to the BOQ') }}</button>
                            <a href="{{ route('project-setup.budget', $project) }}" class="btn btn-ghost min-h-12">{{ __('Not now') }}</a>
                        </div>
                    </form>
                @endcan
            </div>
        @endif
    </section>

    {{-- Current rates, typed in by hand --}}
    <section class="card mt-5" id="rates">
        <div class="card-head">
            <h2 class="card-title">{{ __('BOQ rate and current rate') }}</h2>
            <span class="text-sm text-ink-3">{{ $count($lines->count()) }}</span>
        </div>

        <div class="flex flex-wrap gap-2 border-b border-rule px-4 py-3">
            @php $chip = 'inline-flex min-h-10 items-center rounded-[3px] border px-3 text-xs font-semibold'; @endphp
            <a href="{{ route('project-setup.budget', $project) }}#rates" class="{{ $chip }} {{ $category ? 'border-rule text-ink-2' : 'border-neel bg-neel text-white' }}">{{ __('All') }}</a>
            @foreach ($present as $key)
                <a href="{{ route('project-setup.budget', [$project, 'category' => $key]) }}#rates" class="{{ $chip }} {{ $category === $key ? 'border-neel bg-neel text-white' : 'border-rule text-ink-2' }}">{{ BoqCategories::label($key) }}</a>
            @endforeach
        </div>

        @if ($lines->isEmpty())
            <x-empty :title="__('No BOQ items to show')" :note="__('Add items to the BOQ first, then type in their current rates here.')" />
        @else
            <form method="POST" action="{{ route('project-setup.budget.rates', $project) }}">
                @csrf
                <div class="hidden grid-cols-12 gap-3 border-b border-ink/70 bg-paper-2/60 px-4 py-2 font-mono text-[11px] tracking-wider text-ink-2 uppercase sm:grid">
                    <span class="col-span-4">{{ __('Item') }}</span>
                    <span class="col-span-2 text-right">{{ __('BOQ rate') }}</span>
                    <span class="col-span-2">{{ __('Current rate') }}</span>
                    <span class="col-span-2 text-right">{{ __('Difference') }}</span>
                    <span class="col-span-2 text-right">{{ __('Budget impact') }}</span>
                </div>
                @foreach ($lines as $line)
                    @php $item = $line['item']; @endphp
                    <div class="grid grid-cols-2 items-center gap-x-3 gap-y-2 border-b border-rule px-4 py-3 sm:grid-cols-12">
                        <div class="col-span-2 sm:col-span-4">
                            <p class="text-sm"><span class="num text-xs text-ink-3">{{ $item->code }}</span> {{ $item->description }}</p>
                            <p class="mt-0.5 flex flex-wrap items-center gap-2 text-xs text-ink-3">
                                <span class="num">{{ fmt_qty($item->qty) }} {{ $item->unit }}</span>
                                @if ($item->category)<span class="stamp border-ink-3 text-ink-3">{{ BoqCategories::label($item->category) }}</span>@endif
                            </p>
                        </div>
                        <div class="sm:col-span-2 sm:text-right"><span class="eyebrow block text-ink-3 sm:hidden">{{ __('BOQ rate') }}</span><span class="num text-sm">{{ Dec::indian($item->rate) }}</span></div>
                        <div class="sm:col-span-2">
                            <label class="eyebrow block text-ink-3 sm:hidden" for="rate-{{ $item->id }}">{{ __('Current rate') }}</label>
                            <input id="rate-{{ $item->id }}" name="rates[{{ $item->id }}]" type="number" inputmode="decimal" step="0.01" min="0" value="{{ $line['current_rate'] }}" class="input num min-h-11" aria-label="{{ __('Current rate') }}">
                            @if ($line['noted_on'])
                                <p class="mt-0.5 text-[11px] text-ink-3">{{ __('as on') }} {{ $line['noted_on']->format('d M Y') }}@if ($line['source']) · {{ $line['source'] }}@endif</p>
                            @endif
                        </div>
                        <div class="sm:col-span-2 sm:text-right"><span class="eyebrow block text-ink-3 sm:hidden">{{ __('Difference') }}</span><span class="num text-sm">{{ $line['difference'] === null ? '—' : $signed($line['difference']) }}</span></div>
                        <div class="sm:col-span-2 sm:text-right"><span class="eyebrow block text-ink-3 sm:hidden">{{ __('Budget impact') }}</span><span class="num text-sm font-medium {{ $line['impact'] !== null && Dec::of($line['impact'])->isPositive() ? 'text-laterite' : '' }}">{{ $line['impact'] === null ? '—' : $signed($line['impact']) }}</span></div>
                    </div>
                @endforeach

                <div class="grid gap-3 p-4 sm:grid-cols-3">
                    <x-field :label="__('Rates as on')" name="noted_on">
                        <input id="noted_on" name="noted_on" type="date" max="{{ today()->toDateString() }}" value="{{ old('noted_on', today()->toDateString()) }}" class="input min-h-11" required>
                    </x-field>
                    <x-field :label="__('Where the rates came from')" name="source" class="sm:col-span-2" :hint="__('For example a dealer quote or the local market. Kept with each rate.')">
                        <input id="source" name="source" value="{{ old('source') }}" class="input min-h-11" maxlength="160">
                    </x-field>
                    <div class="sm:col-span-3"><button class="btn btn-primary min-h-12 px-5 text-base">{{ __('Save current rates') }}</button></div>
                </div>
            </form>
        @endif
    </section>
@endsection
