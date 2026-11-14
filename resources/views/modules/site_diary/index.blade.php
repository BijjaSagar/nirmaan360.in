@extends('layouts.app')
@section('title', __('Hindrance register'))
@section('content')
    @php
        $counting = $entries->where('counts', true)->values();
        $proposed = $entries->filter(fn ($entry) => $entry['hindrance']->status === \App\Models\Hindrance::PROPOSED)->values();
        $rest = $entries->filter(fn ($entry) => ! $entry['counts'] && $entry['hindrance']->status !== \App\Models\Hindrance::PROPOSED)->values();
        $days = fn (int $count) => $count === 1 ? __('1 day') : __(':days days', ['days' => $count]);
        // The form is open when it is the thing in hand: an entry is being changed or corrected, a save was
        // refused, the reports are being filtered, or the register is still empty.
        // A refusal from the AI is about the grouping card, not the form.
        // Held: what loading the page again would lose, an entry being changed or corrected or a refused save with what was typed.
        $formHeld = $editing || $correcting || ($errors->any() && ! $errors->has('ai_reader'));
        $formOpen = $formHeld || $entries->isEmpty() || $shown['from'] || $shown['to'];
    @endphp
    <div x-data="{ form: {{ $formOpen ? 'true' : 'false' }} }">
    <x-page-header :title="__('Hindrance register')" :subtitle="$project->name">
        @can('site_diary.letters')
            <a href="{{ route('site-diary.letters.index', $project) }}" class="btn btn-ghost min-h-11">{{ __('Letters') }}</a>
        @endcan
        @if ($canMake && ! $editing && ! $correcting)
            <button type="button" class="btn btn-primary min-h-11" @click="form = ! form"><x-icon name="plus" class="size-4" />{{ __('Make an entry') }}</button>
        @endif
    </x-page-header>

    <div class="mb-5 space-y-1 text-sm text-ink-3">
        <p>{{ __('Each entry is made from daily reports that mention the hindrance, and keeps what each report said.') }}</p>
        <p>{{ __('Days are calendar days, and both the first and the last day are counted. The app has no holiday calendar, so Sundays and holidays are counted too.') }}</p>
        <p>{{ __('A notice date is worked out only from a notice period that someone typed and confirmed under Contract terms. The app knows no notice period of its own.') }}</p>
    </div>

    @if ($noticeTerms['confirmed'] === 0)
        <p class="mb-4 rounded-[3px] border border-haldi bg-haldi/10 px-3 py-2 text-sm font-medium text-[#8a6208]" role="status">
            @if ($noticeTerms['unconfirmed'] > 0)
                {{ __('A notice period is typed under Contract terms, but nobody has confirmed it, so it does not count. Confirm it there to see deadlines.') }}
            @else
                {{ __('No notice period is entered for this contract. Enter it under Contract terms to see deadlines.') }}
            @endif
            @if ($termsLink)
                <a href="{{ $termsLink }}" class="link">{{ __('Contract terms') }}</a>
            @endif
        </p>
    @endif

    <div class="mb-5 grid gap-3 sm:grid-cols-3">
        <x-stat :label="__('Entries that count')" :value="$total['entries']" :note="__('Confirmed, and not corrected by a later entry.')" />
        <x-stat :label="__('Days in all')" :value="$total['days']" :note="$total['ongoing'] > 0 ? __('A day two entries share is counted once. Entries still going on are counted up to today.') : __('A day two entries share is counted once.')" />
        <x-stat :label="__('Still going on')" :value="$total['ongoing']" />
    </div>

    @include('modules.site_diary._grouping')

    @if ($canMake)
        <div x-show="form" x-cloak>
            @include('modules.site_diary._form')
        </div>
    @endif

    @if ($entries->isEmpty())
        <div class="card">
            <x-empty :title="__('No entries in the register')" :note="__('Tick the daily reports that mention a hindrance and make an entry from them. Nothing is entered for you.')" />
        </div>
    @else
        @if ($proposed->isNotEmpty())
            <h2 class="eyebrow mb-2 text-ink-3">{{ __('Waiting to be confirmed') }}</h2>
            <div class="mb-6 space-y-4">
                @foreach ($proposed as $entry)
                    @include('modules.site_diary._entry', ['entry' => $entry])
                @endforeach
            </div>
        @endif

        <h2 class="eyebrow mb-2 text-ink-3">{{ __('Entries that count') }}</h2>
        @if ($counting->isEmpty())
            <p class="card mb-6 px-4 py-4 text-sm text-ink-3">{{ __('No confirmed entry yet.') }}</p>
        @else
            <div class="mb-6 space-y-4">
                @foreach ($counting as $entry)
                    @include('modules.site_diary._entry', ['entry' => $entry])
                @endforeach
            </div>
        @endif

        @if ($rest->isNotEmpty())
            <details class="mb-6" @if ($rest->contains(fn ($entry) => $editing && $entry['hindrance']->is($editing))) open @endif>
                <summary class="eyebrow mb-2 cursor-pointer text-ink-3">{{ __('Dismissed or corrected: :count', ['count' => $rest->count()]) }}</summary>
                <div class="space-y-4">
                    @foreach ($rest as $entry)
                        @include('modules.site_diary._entry', ['entry' => $entry])
                    @endforeach
                </div>
            </details>
        @endif
    @endif
    </div>
@endsection
