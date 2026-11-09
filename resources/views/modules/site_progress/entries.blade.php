@extends('layouts.app')
@section('title', __('Entries'))
@section('content')
    <x-page-header :title="__('Site progress')" :subtitle="$project->name" />
    @include('modules.site_progress._tabs', ['active' => 'site-progress.entries'])

    <p class="mb-4 max-w-2xl text-sm text-ink-3">{{ __('Entries are never edited or deleted. If a quantity was wrong, add a correction: the first entry stays in the history and the difference is recorded beside it.') }}</p>

    <form method="GET" action="{{ route('site-progress.entries', $project) }}" class="mb-4 grid gap-3 sm:grid-cols-[1fr_1fr_auto]">
        <select name="boq_item_id" class="input min-h-11" aria-label="{{ __('BOQ item') }}" onchange="this.form.submit()">
            <option value="">{{ __('All BOQ items') }}</option>
            @foreach ($items as $item)<option value="{{ $item->id }}" @selected((string) ($filters['boq_item_id'] ?? '') === (string) $item->id)>{{ $item->code }} · {{ \Illuminate\Support\Str::limit($item->description, 50) }}</option>@endforeach
        </select>
        <select name="project_floor_id" class="input min-h-11" aria-label="{{ __('Floor') }}" onchange="this.form.submit()">
            <option value="">{{ __('All floors') }}</option>
            @foreach ($floorOptions as $option)<option value="{{ $option['id'] }}" @selected((string) ($filters['project_floor_id'] ?? '') === (string) $option['id'])>{{ $option['label'] }}</option>@endforeach
        </select>
        <noscript><button class="btn btn-ink min-h-11">{{ __('Filter') }}</button></noscript>
    </form>

    <div class="card">
        @forelse ($entries as $entry)
            @php
                $net = $nets[$entry->id] ?? $entry->qty_done;
                $isOriginal = ! $entry->isCorrection() && ! $entry->isResolution();
                $changed = $isOriginal && $entry->corrections->isNotEmpty();
                $openPending = $entry->is_pending && $entry->resolutions_count === 0;
            @endphp
            <article class="border-b border-rule px-4 py-3 last:border-b-0">
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <p class="text-sm font-semibold">{{ $entry->boqItem?->code }} · {{ $entry->boqItem?->description }}</p>
                        <p class="text-xs text-ink-3">
                            <span class="num">{{ $entry->work_date->format('d M Y') }}</span>
                            · {{ $entry->floor ? ($entry->floor->parent?->name ? $entry->floor->parent->name.' › ' : '').$entry->floor->name : __('Whole project') }}
                            · {{ $entry->enteredBy?->name }}
                            · <span class="num">{{ $entry->created_at->format('d M, H:i') }}</span>
                        </p>
                    </div>
                    <div class="shrink-0 text-right">
                        <p class="num text-base font-semibold {{ \App\Support\Dec::of($entry->qty_done)->isNegative() ? 'text-laterite' : '' }} {{ $changed ? 'text-ink-3 line-through' : '' }}">{{ fmt_qty($entry->qty_done) }} <span class="text-xs font-normal text-ink-3">{{ $entry->boqItem?->unit }}</span></p>
                        @if ($changed)<p class="num text-sm font-semibold">{{ fmt_qty($net) }} <span class="text-xs font-normal text-ink-3">{{ $entry->boqItem?->unit }}</span></p>@endif
                    </div>
                </div>

                <div class="mt-1.5 flex flex-wrap gap-1.5">
                    @if ($openPending)<span class="stamp border-laterite text-laterite">{{ __('Pending') }}</span>@endif
                    @if ($entry->is_pending && $entry->resolutions_count > 0)<span class="stamp border-moss text-moss">{{ __('Finished later') }}</span>@endif
                    @if ($entry->isCorrection())<span class="stamp border-haldi text-[#8a6208]">{{ __('Correction') }}</span>@endif
                    @if ($entry->isResolution())<span class="stamp border-moss text-moss">{{ __('Marked finished') }}</span>@endif
                    @if ($changed)<span class="stamp border-haldi text-[#8a6208]">{{ __('Corrected') }}</span>@endif
                </div>

                @if ($entry->remark)<p class="mt-1.5 text-sm text-ink-2">{{ $entry->remark }}</p>@endif
                @include('modules.site_progress._photos', ['photos' => $entry->photos])

                @foreach ($entry->corrections as $correction)
                    <p class="mt-1.5 border-l-2 border-haldi pl-2 text-xs text-ink-2">
                        <span class="num font-semibold">{{ fmt_qty($correction->qty_done) }}</span> · {{ $correction->remark }} · {{ $correction->enteredBy?->name }}, <span class="num">{{ $correction->created_at->format('d M, H:i') }}</span>
                    </p>
                @endforeach

                @can('progress.create')
                    <div class="mt-2 flex flex-wrap gap-2">
                        @if ($isOriginal && ! \App\Support\Dec::of($entry->qty_done)->isZero())
                            <details class="w-full sm:w-auto">
                                <summary class="btn btn-ghost min-h-11 cursor-pointer">{{ __('Correct quantity') }}</summary>
                                <form method="POST" action="{{ route('site-progress.entries.correct', $entry) }}" class="mt-2 grid gap-3 rounded-[3px] border border-rule bg-paper p-3 sm:w-96">
                                    @csrf
                                    <input type="hidden" name="client_uuid" value="{{ \Illuminate\Support\Str::uuid() }}">
                                    <x-field :label="__('The right quantity is')"><input id="qty_done-{{ $entry->id }}" name="qty_done" type="number" inputmode="decimal" step="0.001" min="0" value="{{ $net }}" class="input num min-h-12 text-lg" required></x-field>
                                    <x-field :label="__('Why is it being corrected?')"><textarea id="remark-{{ $entry->id }}" name="remark" rows="2" maxlength="1000" class="input text-base" required></textarea></x-field>
                                    <button class="btn btn-ink min-h-11">{{ __('Add correction') }}</button>
                                </form>
                            </details>
                        @endif
                        @if ($openPending)
                            <details class="w-full sm:w-auto">
                                <summary class="btn btn-ghost min-h-11 cursor-pointer">{{ __('Mark finished') }}</summary>
                                <form method="POST" action="{{ route('site-progress.entries.resolve', $entry) }}" class="mt-2 grid gap-3 rounded-[3px] border border-rule bg-paper p-3 sm:w-96">
                                    @csrf
                                    <input type="hidden" name="client_uuid" value="{{ \Illuminate\Support\Str::uuid() }}">
                                    <x-field :label="__('Note (optional)')"><textarea id="resolve-{{ $entry->id }}" name="remark" rows="2" maxlength="1000" class="input text-base"></textarea></x-field>
                                    <button class="btn btn-primary min-h-11">{{ __('Mark finished') }}</button>
                                </form>
                            </details>
                        @endif
                    </div>
                @endcan
            </article>
        @empty
            <x-empty :title="__('No entries')" :note="__('Entries appear here as soon as work is recorded.')" />
        @endforelse
    </div>
    <div class="mt-4">{{ $entries->links() }}</div>
@endsection
