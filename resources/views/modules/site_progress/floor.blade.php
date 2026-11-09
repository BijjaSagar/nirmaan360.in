@extends('layouts.app')
@section('title', $floor->name)
@section('content')
    @php
        $show = \App\Services\SiteProgress\ProgressReport::show(...);
        $title = $floor->parent ? $floor->parent->name.' › '.$floor->name : $floor->name;
    @endphp
    <x-page-header :title="$title" :subtitle="$project->name">
        <a href="{{ route('site-stages.checklist', $floor) }}" class="btn {{ $detail['mep_pending'] > 0 ? 'btn-primary' : 'btn-ghost' }} min-h-11">
            {{ __('Before finishing') }}@if ($detail['mep_pending'] > 0) ({{ $detail['mep_pending'] }})@endif
        </a>
    </x-page-header>
    @include('modules.site_progress._tabs', ['active' => 'site-progress.floors.index'])

    <div class="mb-5 card p-4">
        <div class="flex items-end justify-between gap-3">
            <p class="eyebrow text-ink-3">{{ __('Progress on this floor') }}</p>
            <p class="num text-[32px] font-medium leading-none">{{ $detail['targets'] > 0 ? $show($detail['percent']) : '—' }}</p>
        </div>
        <div class="mt-2">@include('modules.site_progress._bar', ['percent' => $detail['percent']])</div>
        @if ($detail['targets'] === 0)
            <p class="mt-2 text-sm text-ink-3">{{ __('No targets are set for this floor yet, so a percentage cannot be worked out. Set what is planned for each BOQ line below.') }}</p>
        @endif
    </div>

    <div class="grid gap-5 lg:grid-cols-[minmax(0,5fr)_minmax(0,7fr)]">
        <div class="space-y-5">
            @can('progress.create')
                @include('modules.site_progress._entry-form', ['items' => $items, 'floorOptions' => $floorOptions, 'clientUuid' => $clientUuid, 'floorId' => $floor->id, 'lockFloor' => true])
            @endcan

            @if (count($detail['children']) > 0)
                <section class="card">
                    <div class="card-head"><h2 class="card-title">{{ __('Flats and areas') }}</h2></div>
                    @foreach ($detail['children'] as $child)
                        <a href="{{ route('site-progress.floors.show', $child['id']) }}" class="block border-b border-rule px-4 py-3 last:border-b-0 hover:bg-paper/70">
                            <div class="flex items-center justify-between gap-3">
                                <span class="min-w-0 truncate font-semibold">{{ $child['name'] }}</span>
                                <span class="num text-base font-medium">{{ $child['targets'] > 0 ? $show($child['percent']) : '—' }}</span>
                            </div>
                            <div class="mt-1.5">@include('modules.site_progress._bar', ['percent' => $child['percent']])</div>
                        </a>
                    @endforeach
                </section>
            @endif
        </div>

        <div class="space-y-5">
            <section class="card">
                <div class="card-head"><h2 class="card-title">{{ __('What is pending here') }}</h2></div>
                @forelse ($detail['pending_entries'] as $entry)
                    @include('modules.site_progress._pending-item', ['entry' => $entry])
                @empty
                    <p class="px-4 py-5 text-sm text-ink-3">{{ __('Nothing is marked pending on this floor.') }}</p>
                @endforelse
            </section>

            <section class="card">
                <div class="card-head"><h2 class="card-title">{{ __('Work on this floor') }}</h2></div>
                @forelse ($detail['lines'] as $line)
                    <div class="border-b border-rule px-4 py-3 last:border-b-0">
                        <div class="flex items-start justify-between gap-3">
                            <p class="min-w-0 text-sm"><span class="num text-xs text-ink-3">{{ $line['code'] }}</span> {{ $line['description'] }}</p>
                            <p class="num shrink-0 text-base font-medium">{{ $line['percent'] !== null ? $show($line['percent']) : '—' }}</p>
                        </div>
                        @if ($line['percent'] !== null)
                            <div class="mt-1.5">@include('modules.site_progress._bar', ['percent' => $line['percent'], 'tone' => \App\Support\Dec::of($line['percent'])->isGreaterThan(100) ? 'bg-haldi' : 'bg-moss'])</div>
                        @endif
                        <p class="num mt-1 text-xs text-ink-3">
                            {{ fmt_qty($line['done_qty']) }}
                            @if ($line['planned_qty'] !== null) / {{ fmt_qty($line['planned_qty']) }} @endif
                            {{ $line['unit'] }}
                            @if ($line['planned_qty'] === null) · <span class="font-sans text-laterite">{{ __('No target on this floor') }}</span>@endif
                        </p>
                        @can('progress.manage')
                            <details class="mt-1">
                                <summary class="cursor-pointer text-xs font-semibold text-ink-2">{{ __('Change target') }}</summary>
                                <form method="POST" action="{{ route('site-progress.floors.target', $floor) }}" class="mt-2 flex items-end gap-2">
                                    @csrf @method('PUT')
                                    <input type="hidden" name="boq_item_id" value="{{ $line['id'] }}">
                                    <x-field :label="__('Planned on this floor').' ('.$line['unit'].')'" class="flex-1"><input name="planned_qty" type="number" inputmode="decimal" step="0.001" min="0" value="{{ $line['planned_qty'] ?? '' }}" class="input num min-h-11" required></x-field>
                                    <button class="btn btn-ink min-h-11">{{ __('Save') }}</button>
                                </form>
                            </details>
                        @endcan
                    </div>
                @empty
                    <x-empty :title="__('No work yet')" :note="__('Set a target or record work to see it here.')" />
                @endforelse

                @can('progress.manage')
                    <form method="POST" action="{{ route('site-progress.floors.target', $floor) }}" class="space-y-3 border-t border-rule p-4">
                        @csrf @method('PUT')
                        <p class="font-semibold">{{ __('Set a target for a BOQ item') }}</p>
                        <x-field :label="__('BOQ item')" name="boq_item_id">
                            <select id="boq_item_id_target" name="boq_item_id" class="input min-h-12 text-base" required>
                                <option value="">{{ __('Choose the work') }}</option>
                                @foreach ($items as $item)<option value="{{ $item['id'] }}">{{ $item['code'] }} · {{ \Illuminate\Support\Str::limit($item['description'], 60) }} ({{ $item['unit'] }})</option>@endforeach
                            </select>
                        </x-field>
                        <x-field :label="__('Planned on this floor')" name="planned_qty" :hint="__('Enter 0 to remove the target.')"><input id="planned_qty" name="planned_qty" type="number" inputmode="decimal" step="0.001" min="0" class="input num min-h-12 text-lg" required></x-field>
                        <button class="btn btn-ink min-h-12 w-full text-base">{{ __('Save target') }}</button>
                    </form>
                @endcan
            </section>
        </div>
    </div>
@endsection
