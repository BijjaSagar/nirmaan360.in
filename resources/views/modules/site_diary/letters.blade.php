@extends('layouts.app')
@section('title', __('Letters'))
@section('content')
    @php
        $payment = \App\Models\SiteLetter::PAYMENT_FOLLOWUP;
        $offersBills = in_array($payment, $types, true);
        $chosenType = in_array(old('type'), $types, true) ? old('type') : '';
        // The form is open when it is the thing in hand: a draft was refused, or there is no letter yet.
        $formOpen = $errors->any() || $letters->isEmpty();
    @endphp
    <div x-data="{ form: {{ $formOpen ? 'true' : 'false' }}, type: @js($chosenType) }">
        <x-page-header :title="__('Letters')" :subtitle="$project->name">
            @can('site_diary.view')
                <a href="{{ route('site-diary.hindrances.index', $project) }}" class="btn btn-ghost min-h-11">{{ __('Hindrance register') }}</a>
            @endcan
            <button type="button" class="btn btn-primary min-h-11" @click="form = ! form"><x-icon name="plus" class="size-4" />{{ __('Prepare a letter') }}</button>
        </x-page-header>

        <div class="mb-5 space-y-1 text-sm text-ink-3">
            <p>{{ __('Every date, day count, quantity and amount, and the sentence that states it, is placed by the app from your records. Check the draft against your agreement before sending.') }}</p>
            <p>{{ __('You tick every figure before a letter is finalised. A finalised letter cannot be changed, and the register entries it cites are locked.') }}</p>
            <p>{{ __('The app sends nothing. You print the letter or copy its text.') }}</p>
        </div>

        <form method="POST" action="{{ route('site-diary.letters.store', $project) }}" x-show="form" x-cloak class="card mb-6">
            @csrf
            <input type="hidden" name="client_uuid" value="{{ old('client_uuid', (string) \Illuminate\Support\Str::uuid()) }}">
            <div class="card-head"><h2 class="card-title">{{ __('Prepare a letter') }}</h2></div>

            <div class="grid gap-3 p-4 sm:grid-cols-2">
                <x-field :label="__('Kind of letter')" name="type">
                    <select id="type" name="type" class="input min-h-11" x-model="type" required>
                        <option value="">{{ __('Choose') }}</option>
                        @foreach ($types as $key)
                            <option value="{{ $key }}">{{ __($typeNames[$key]) }}</option>
                        @endforeach
                    </select>
                </x-field>

                <x-field :label="__('Language of the letter')" name="language">
                    <select id="language" name="language" class="input min-h-11" required>
                        <option value="">{{ __('Choose') }}</option>
                        @foreach (array_intersect_key($languages, array_flip($offered)) as $code => $name)
                            <option value="{{ $code }}" @selected(old('language') === $code)>{{ $name }}</option>
                        @endforeach
                    </select>
                </x-field>

                <x-field :label="__('Addressed to')" name="addressed_to" :hint="__('Typed by you. The letter prints it as you type it.')" class="sm:col-span-2">
                    <input id="addressed_to" name="addressed_to" value="{{ old('addressed_to') }}" maxlength="200" class="input min-h-11">
                </x-field>

                <div class="sm:col-span-2" x-show="type === 'eot_request'" x-cloak>
                    <x-field :label="__('Completion date to ask for')" name="proposed_completion" :hint="__('Leave blank and the app adds the days of the chosen hindrances to the latest of the stipulated completion date and the extensions granted, as entered under Contract terms. A date you type is marked as entered by you.')">
                        <input id="proposed_completion" name="proposed_completion" type="date" value="{{ old('proposed_completion') }}" class="input min-h-11" :disabled="type !== 'eot_request'">
                    </x-field>
                </div>
            </div>

            @include('modules.site_diary._letter_choice', [
                'kind' => null,
                'withBills' => $offersBills,
                'tickedEntries' => array_map('intval', (array) old('hindrance_ids', [])),
                'tickedBills' => array_map('intval', (array) old('bill_ids', [])),
            ])

            <div class="flex flex-wrap items-center justify-between gap-2 border-t border-rule px-4 py-3">
                <p class="min-w-0 flex-1 text-xs text-ink-3">{{ __('Preparing a draft changes nothing in your records, and nothing is sent.') }}</p>
                <button class="btn btn-primary min-h-11">{{ __('Prepare the draft') }}</button>
            </div>
        </form>

        <div class="card">
            @forelse ($letters as $letter)
                @php $opens = $letter->type !== $payment || $seesMoney; @endphp
                <div class="flex flex-wrap items-center gap-x-3 gap-y-1 border-b border-rule px-4 py-3 text-sm last:border-b-0">
                    <div class="min-w-0 flex-1">
                        @if ($opens)
                            <a href="{{ route('site-diary.letters.show', $letter) }}" class="link font-semibold">{{ __($typeNames[$letter->type] ?? $letter->type) }}</a>
                            <p class="truncate text-ink-2">{{ $letter->subject }}</p>
                        @else
                            <span class="font-semibold">{{ __($typeNames[$letter->type] ?? $letter->type) }}</span>
                            <p class="text-ink-3">{{ \App\Support\Money::refusal() }}</p>
                        @endif
                        <p class="text-xs text-ink-3">
                            {{ $languages[$letter->language] ?? $letter->language }} ·
                            {{ __('Prepared on :date', ['date' => $letter->created_at->format('d M Y')]) }}
                            @if ($letter->isFinalised())
                                · {{ __('Finalised by :name on :date', ['name' => $letter->finalisedBy?->name ?? '—', 'date' => $letter->finalised_at?->format('d M Y')]) }}
                            @endif
                        </p>
                    </div>
                    @if ($letter->isFinalised())
                        <span class="stamp border-moss text-moss">{{ __($statusNames['finalised']) }}</span>
                    @else
                        <span class="stamp border-haldi text-[#8a6208]">{{ __($statusNames['draft']) }}</span>
                    @endif
                </div>
            @empty
                <x-empty :title="__('No letters yet')" :note="__('Prepare a letter from the hindrance register or from unpaid client bills. Nothing is drafted until you ask for it.')" />
            @endforelse
        </div>
        <div class="mt-4">{{ $letters->links() }}</div>
    </div>
@endsection
