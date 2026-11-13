@extends('layouts.app')
@section('title', __('Rate suggestions'))
@use('App\Models\RateSuggestionLine')
@use('App\Services\RateHelper\TextGuards')
@use('App\Support\Dec')
@use('Illuminate\Support\Str')
@section('content')
    @php
        $modes = [RateSuggestionLine::SET_BOQ_RATE => __('Set as the BOQ rate'), RateSuggestionLine::NOTE_CURRENT_RATE => __('Note as today\'s current rate')];
        $values = fn (string $kind, array $list) => $list === [] ? __('not stated') : implode(', ', array_map(fn (string $value) => TextGuards::shown($kind, $value), $list));
        $waiting = collect($lines)->filter(fn (array $entry) => $entry['line']->isOpen())->count();
    @endphp

    <x-page-header :title="__('Rate suggestions')" :subtitle="$project->name">
        <a href="{{ route('boq.index', $project) }}" class="btn btn-ghost min-h-11">{{ __('Back to BOQ & ledger') }}</a>
    </x-page-header>

    <div class="mb-5 rounded-[3px] border border-haldi bg-haldi/10 px-4 py-3 text-sm text-ink-2" role="note">
        <strong>{{ __('From your own records.') }}</strong>
        {{ __('Every rate on this page is read by the app from your own records, and is shown with its source and date. Nothing is estimated. You accept one line at a time, and nothing changes until you do.') }}
    </div>

    <section class="card mb-5">
        <div class="card-head">
            <h2 class="card-title">{{ __('Where these rates come from') }}</h2>
            <span class="text-sm text-ink-3">{{ __(':open of :total lines still to decide', ['open' => $waiting, 'total' => count($lines)]) }}</span>
        </div>
        <ul class="divide-y divide-rule text-sm">
            @foreach ($sources as $source)
                <li class="flex flex-wrap items-center justify-between gap-2 px-4 py-2">
                    @if (! $source['connected'])
                        <span class="text-ink-2">{{ __(':source: none loaded.', ['source' => __($source['label'])]) }}</span>
                        <span class="stamp border-ink-3 text-ink-3">{{ __('Not connected') }}</span>
                    @elseif (! $source['allowed'])
                        <span class="text-ink-2">{{ __($source['label']) }}</span>
                        <span class="stamp border-ink-3 text-ink-3">{{ __('Not open to your role') }}</span>
                    @else
                        <span>{{ __($source['label']) }}</span>
                        <span class="stamp border-moss text-moss">{{ __('Searched') }}</span>
                    @endif
                </li>
            @endforeach
        </ul>
    </section>

    @foreach ($lines as $entry)
        @php
            $line = $entry['line'];
            $item = $entry['item'];
            $long = $item && mb_strlen((string) $item->description) > 160;
            $warned = collect($entry['candidates'])->contains(fn (array $candidate) => $candidate['warnings'] !== []);
        @endphp
        <section class="card mb-4" id="line-{{ $line->id }}">
            <div class="card-head">
                <h2 class="card-title min-w-0">
                    @if ($item)
                        <span class="num text-xs text-ink-3">{{ $item->code }}</span> {{ Str::limit($item->description, 160) }}
                    @else
                        {{ __('A BOQ line that was removed') }}
                    @endif
                </h2>
                @if ($line->isAccepted())
                    <span class="stamp border-moss text-moss">{{ __('Accepted') }}</span>
                @elseif ($line->isSkipped())
                    <span class="stamp border-ink-3 text-ink-3">{{ __('Skipped') }}</span>
                @else
                    <span class="stamp border-neel text-neel">{{ __('To decide') }}</span>
                @endif
            </div>

            @if ((int) session('refused_line') === $line->id && $errors->any())
                <p class="border-b border-laterite bg-laterite/10 px-4 py-3 text-sm font-medium text-laterite-dark" role="alert">{{ $errors->first() }}</p>
            @endif

            @if ($long)
                {{-- The warnings are read from the whole description, so the whole of it is here to check them against. --}}
                <details class="border-b border-rule px-4 text-sm" @if ($warned && ! $line->isAccepted()) open @endif>
                    <summary class="cursor-pointer py-3 text-ink-3">{{ __('Full description of this line') }}</summary>
                    <p class="pb-2 text-ink-2">{{ $item->description }}</p>
                </details>
            @endif

            @if (! $item)
                <p class="p-4 text-sm text-ink-3">{{ __('This BOQ line is no longer in the BOQ.') }}</p>
            @else
                <p class="border-b border-rule px-4 py-2 text-sm text-ink-2">
                    {{ __('Quantity') }} <span class="num">{{ fmt_qty($item->qty) }} {{ $item->unit }}</span>
                    · {{ __('BOQ rate now') }} <span class="num">{{ inr($item->rate) }}</span>
                </p>

                @if ($line->isAccepted())
                    <p class="border-b border-rule bg-moss/10 px-4 py-3 text-sm">
                        {{ __('Accepted :rate per :unit.', ['rate' => inr($line->accepted_rate), 'unit' => $item->unit]) }}
                        @if ($entry['accepted_source_label']){{ __('Source: :source.', ['source' => __($entry['accepted_source_label'])]) }}@endif
                        {{ $line->accepted_mode === RateSuggestionLine::NOTE_CURRENT_RATE ? __('Noted as the current rate on the budget screen. The BOQ rate was not changed.') : __('Set as the BOQ rate, and kept in the BOQ history.') }}
                        <span class="text-ink-3">{{ $line->accepted_at->format('d M Y') }}</span>
                    </p>
                @endif

                @if ($entry['lump_sum'])
                    <p class="p-4 text-sm text-ink-3">{{ __('This is a lump-sum line. Another job\'s lump sum is not a rate, so nothing is suggested for it.') }}</p>
                @elseif ($entry['candidates'] === [] && ! $line->isAccepted())
                    <p class="p-4 text-sm text-ink-3">{{ __('Nothing alike enough was found in your records for this line, in the same unit.') }}</p>
                @endif

                @if (! $line->isAccepted())
                    @foreach ($entry['candidates'] as $candidate)
                        @php
                            $figure = $candidate['figure'];
                            $field = 'accept-'.$line->id.'-'.$candidate['ref'];
                        @endphp
                        <div class="grid gap-3 border-b border-rule px-4 py-3 sm:grid-cols-6">
                            <div class="min-w-0 sm:col-span-4">
                                <p class="eyebrow text-ink-3">{{ __($candidate['source_label']) }}@if ($figure->origin) · {{ $figure->origin }}@endif</p>
                                <p class="mt-1 text-sm">{{ $figure->description }}</p>
                                <p class="mt-1 flex flex-wrap items-center gap-1.5 text-xs text-ink-3">
                                    <span>{{ __($figure->basis) }}</span>
                                    <span>· {{ __($figure->dateLabel) }} {{ $figure->date?->format('d M Y') ?? __('no date') }}</span>
                                    @if ($candidate['old'])<span class="stamp border-haldi text-[#8a6208]">{{ __('old') }}</span>@endif
                                </p>
                                @foreach ($candidate['warnings'] as $warning)
                                    <p class="mt-1.5 text-xs">
                                        <span class="stamp border-laterite text-laterite">{{ __($warning['label']) }}</span>
                                        <span class="text-ink-2">{{ __('this line: :ours · that rate: :theirs', ['ours' => $values($warning['kind'], $warning['line']), 'theirs' => $values($warning['kind'], $warning['candidate'])]) }}</span>
                                    </p>
                                @endforeach
                                @if ($figure->parts !== [])
                                    <p class="mt-1.5 text-xs text-ink-3">
                                        @foreach ($figure->parts as $part){{ __($part['label']) }} <span class="num">{{ Dec::indian($part['amount']) }}</span>@if (! $loop->last) · @endif @endforeach
                                    </p>
                                @endif
                            </div>
                            <form method="POST" action="{{ route('rate-suggestions.lines.accept', [$run, $line]) }}" class="space-y-2 sm:col-span-2">
                                @csrf
                                <input type="hidden" name="candidate" value="{{ $candidate['ref'] }}">
                                <input type="hidden" name="shown" value="{{ $figure->fingerprint() }}">
                                <p class="num text-lg font-semibold">{{ inr($figure->rate) }} <span class="text-sm font-normal text-ink-3">/ {{ $figure->unit }}</span></p>
                                @if ($can_note)
                                    <label class="label" for="{{ $field }}-mode">{{ __('What to do with it') }}</label>
                                    <select id="{{ $field }}-mode" name="mode" class="input min-h-11">
                                        @foreach ($modes as $value => $label)
                                            <option value="{{ $value }}">{{ $label }}</option>
                                        @endforeach
                                    </select>
                                @else
                                    <input type="hidden" name="mode" value="{{ RateSuggestionLine::SET_BOQ_RATE }}">
                                @endif
                                @if ($candidate['warnings'] !== [])
                                    <label class="flex min-h-11 items-center gap-2 text-sm" for="{{ $field }}-checked">
                                        <input id="{{ $field }}-checked" type="checkbox" name="checked_warnings" value="1" required>
                                        {{ __('I checked grade, mix and thickness') }}
                                    </label>
                                @endif
                                <button class="btn btn-primary min-h-11">{{ __('Accept this rate') }}</button>
                            </form>
                        </div>
                    @endforeach
                @endif

                @foreach ($entry['reference'] as $candidate)
                    @php $figure = $candidate['figure']; @endphp
                    <div class="border-b border-rule bg-paper-2/40 px-4 py-3 text-sm">
                        <p class="eyebrow text-ink-3">{{ __($candidate['source_label']) }}</p>
                        <p class="mt-1">
                            {{ __('Material cost for one :unit:', ['unit' => $figure->unit]) }} <span class="num font-semibold">{{ inr($figure->rate) }}</span>
                            @if ($figure->incomplete)<span class="stamp border-laterite text-laterite">{{ __('incomplete') }}</span>@endif
                            @if ($candidate['old'])<span class="stamp border-haldi text-[#8a6208]">{{ __('old') }}</span>@endif
                        </p>
                        <ul class="mt-1.5 space-y-1 text-xs text-ink-2">
                            @foreach ($figure->parts as $part)
                                <li><span class="font-medium">{{ $part['label'] }}</span>@if ($part['amount'] !== null) <span class="num">{{ inr($part['amount']) }}</span>@endif @if ($part['note'])<span class="text-ink-3">{{ $part['note'] }}</span>@endif</li>
                            @endforeach
                        </ul>
                        <p class="mt-1.5 text-xs text-ink-3">{{ __('Materials only: labour, plant, overheads and profit are not in this figure. It cannot be accepted as the rate.') }}</p>
                    </div>
                @endforeach

                @if ($line->isOpen())
                    <form method="POST" action="{{ route('rate-suggestions.lines.skip', [$run, $line]) }}" class="flex justify-end px-4 py-3">
                        @csrf
                        <button class="btn btn-ghost min-h-11">{{ __('Skip this line') }}</button>
                    </form>
                @endif
            @endif
        </section>
    @endforeach
@endsection
