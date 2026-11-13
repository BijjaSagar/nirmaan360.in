@extends('layouts.app')
@section('title', __($typeNames[$letter->type] ?? $letter->type))
@section('content')
    {{-- On paper only the letter is left: the menu, the buttons, the list of figures and any message stay on the screen. --}}
    <style>
        @media print {
            aside, header, .no-print, main > [role="status"], main > [role="alert"] { display: none !important; }
            main { padding: 0 !important; }
            .letter-sheet { border: 0 !important; }
        }
    </style>
    @php
        $draft = ! $letter->isFinalised();
        $typedLabel = \App\Services\Wording\FinalCheck::typedLabel();
        // One sentence of the draft, with every figure the app placed marked with what it is, and every number typed by hand marked as such.
        $marked = function (array $slot) use ($typedLabel) {
            $html = '';

            foreach ($slot['parts'] as $part) {
                $text = e($part['text']);
                $html .= match (true) {
                    $part['fact'] !== null => '<mark class="rounded-[2px] bg-haldi/20 px-1 text-ink" title="'.e($part['label']).'">'.$text.'</mark>',
                    $part['typed'] !== null => '<mark class="rounded-[2px] bg-laterite/10 px-1 text-ink" title="'.e($typedLabel).'">'.$text.'</mark>',
                    default => $text,
                };
            }

            return new \Illuminate\Support\HtmlString($html);
        };
        $heading = fn (string $id) => $id === 'subject' || str_ends_with($id, '_title');
        $ticked = (array) old('number_ticks', []);
    @endphp
    <div x-data="{ copied: false }">
        <div class="no-print">
            <x-page-header :title="__($typeNames[$letter->type] ?? $letter->type)" :subtitle="$project->name">
                <a href="{{ route('site-diary.letters.index', $project) }}" class="btn btn-ghost min-h-11">{{ __('All letters') }}</a>
                @unless ($draft)
                    <button type="button" class="btn btn-ghost min-h-11" @click="navigator.clipboard.writeText($refs.body.textContent).then(() => { copied = true; setTimeout(() => copied = false, 1500) })" x-text="copied ? @js(__('Copied')) : @js(__('Copy text'))">{{ __('Copy text') }}</button>
                    <button type="button" class="btn btn-primary min-h-11" onclick="window.print()">{{ __('Print') }}</button>
                @endunless
            </x-page-header>
        </div>

        {{-- Carried by every letter. On paper it stays on a draft and is left off a finalised letter. --}}
        <p class="{{ $draft ? '' : 'no-print' }} mb-4 rounded-[3px] border border-haldi bg-haldi/10 px-3 py-2 text-sm font-medium text-[#8a6208]" role="note">{{ __($caution) }}</p>

        <div class="no-print mb-4 flex flex-wrap items-center gap-2 text-sm text-ink-3">
            @if ($draft)
                <span class="stamp border-haldi text-[#8a6208]">{{ __($statusNames['draft']) }}</span>
            @else
                <span class="stamp border-moss text-moss">{{ __($statusNames['finalised']) }}</span>
                <span>{{ __('Finalised by :name on :date', ['name' => $letter->finalisedBy?->name ?? '—', 'date' => $letter->finalised_at?->format('d M Y')]) }}</span>
            @endif
            <span>{{ $languages[$letter->language] ?? $letter->language }}</span>
            <span>{{ __('Prepared on :date', ['date' => $letter->created_at->format('d M Y')]) }}</span>
        </div>

        @if ($draft && $review['changed'])
            <div class="no-print mb-4 rounded-[3px] border border-laterite bg-laterite/10 px-3 py-2 text-sm font-medium text-laterite-dark" role="alert">
                <p>{{ __('The records behind this draft have changed since it was prepared. Prepare the draft again and check it.') }}</p>
                @if ($review['problem'])
                    <p class="mt-1">{{ $review['problem'] }}</p>
                @else
                    {{-- The same choices, read from the records as they are now. The three sentences stay as they are. --}}
                    <form method="POST" action="{{ route('site-diary.letters.update', $letter) }}" class="mt-2">
                        @csrf
                        @method('PATCH')
                        <input type="hidden" name="addressed_to" value="{{ $chosen['addressed_to'] }}">
                        <input type="hidden" name="proposed_completion" value="{{ $chosen['proposed_completion'] }}">
                        @foreach ($chosen['hindrance_ids'] as $id)
                            <input type="hidden" name="hindrance_ids[]" value="{{ $id }}">
                        @endforeach
                        @foreach ($chosen['bill_ids'] as $id)
                            <input type="hidden" name="bill_ids[]" value="{{ $id }}">
                        @endforeach
                        <button class="btn btn-primary min-h-11">{{ __('Prepare again from the records') }}</button>
                    </form>
                @endif
            </div>
        @endif

        @if ($draft && $review['replaced'] !== [])
            <p class="no-print mb-4 rounded-[3px] border border-haldi bg-haldi/10 px-3 py-2 text-sm text-[#8a6208]" role="status">{{ __('A sentence written by the AI stated a figure, so the app left it out and put its own sentence there.') }}</p>
        @endif

        <article class="card letter-sheet mb-6" lang="{{ $letter->language }}">
            <div class="card-head no-print"><h2 class="card-title">{{ __('The letter') }}</h2></div>
            @if ($draft)
                <p class="no-print border-b border-rule px-4 py-2 text-xs text-ink-3">{{ __('Marked: a figure the app placed from your records. Hold the pointer on it to see what it is.') }}</p>
            @endif
            <div class="space-y-4 px-4 py-5 text-[15px] leading-relaxed text-ink sm:px-8">
                @if ($draft)
                    @foreach ($review['blocks'] as $block)
                        <div>
                            @foreach ($block as $slot)
                                <p class="whitespace-pre-line {{ $heading($slot['id']) ? 'font-semibold' : '' }}">{{ $marked($slot) }}</p>
                            @endforeach
                        </div>
                    @endforeach
                @else
                    <div x-ref="body" class="whitespace-pre-line">{{ $letter->body_rendered }}</div>
                @endif
            </div>
        </article>

        @if ($draft)
            <form method="POST" action="{{ route('site-diary.letters.finalise', $letter) }}" class="card no-print mb-6">
                @csrf
                <input type="hidden" name="seen" value="{{ $letter->seal() }}">
                <div class="card-head"><h2 class="card-title">{{ __('Figures in this letter') }}</h2></div>
                <p class="px-4 py-3 text-sm text-ink-2">{{ __('The app placed each figure below in the letter from the record named with it. Tick a figure once you have checked it against that record and against your agreement.') }}</p>
                @foreach (['number_ticks', 'facts', 'seen'] as $field)
                    @error($field)
                        <p class="error px-4 pb-2">{{ $message }}</p>
                    @enderror
                @endforeach

                <div class="border-t border-rule">
                    @foreach ($review['figures'] as $figure)
                        <label class="flex min-h-11 cursor-pointer gap-3 border-b border-rule px-4 py-2 text-sm">
                            <input type="checkbox" name="number_ticks[]" value="{{ $figure['key'] }}" class="mt-0.5 size-5 shrink-0" @checked(in_array($figure['key'], $ticked, true)) @disabled($review['changed'])>
                            <span class="min-w-0 flex-1">
                                <span class="block text-xs text-ink-3">{{ $figure['label'] }}</span>
                                <span class="block whitespace-pre-line font-semibold">{{ $figure['value'] }}</span>
                                <span class="block text-xs text-ink-3">{{ __('Read from: :source', ['source' => $figure['source']]) }}</span>
                            </span>
                        </label>
                    @endforeach
                    @foreach ($review['typed'] as $number)
                        <label class="flex min-h-11 cursor-pointer gap-3 border-b border-rule px-4 py-2 text-sm">
                            <input type="checkbox" name="number_ticks[]" value="{{ $number['id'] }}" class="mt-0.5 size-5 shrink-0" @checked(in_array($number['id'], $ticked, true)) @disabled($review['changed'])>
                            <span class="min-w-0 flex-1">
                                <span class="block text-xs text-[#8a6208]">{{ $typedLabel }}</span>
                                <span class="num block font-semibold">{{ $number['text'] }}</span>
                                <span class="block text-xs text-ink-3">{{ __('Where it stands: :words', ['words' => $number['context']]) }}</span>
                            </span>
                        </label>
                    @endforeach
                </div>

                <div class="flex flex-wrap items-center justify-between gap-2 px-4 py-3">
                    <p class="min-w-0 flex-1 text-xs text-ink-3">{{ __('Finalising cannot be undone. The letter can no longer be changed, and the register entries it cites are locked. Nothing is sent.') }}</p>
                    <button class="btn btn-primary min-h-11" @disabled($review['changed'])>{{ __('Finalise the letter') }}</button>
                </div>
            </form>

            <details class="card no-print mb-6" @if ($errors->hasAny(['addressed_to', 'proposed_completion', 'hindrance_ids', 'hindrance_ids.*', 'bill_ids', 'bill_ids.*', 'slots.*', 'type']) || $review['problem']) open @endif>
                <summary class="flex min-h-11 cursor-pointer items-center px-4 py-2 font-semibold">{{ __('Change the draft') }}</summary>
                <form method="POST" action="{{ route('site-diary.letters.update', $letter) }}" class="border-t border-rule">
                    @csrf
                    @method('PATCH')
                    @error('type')
                        <p class="error px-4 pt-3">{{ $message }}</p>
                    @enderror
                    <div class="grid gap-3 p-4 sm:grid-cols-2">
                        <x-field :label="__('Addressed to')" name="addressed_to" :hint="__('Typed by you. The letter prints it as you type it.')" class="sm:col-span-2">
                            <input id="addressed_to" name="addressed_to" value="{{ old('addressed_to', $chosen['addressed_to']) }}" maxlength="200" class="input min-h-11">
                        </x-field>

                        @if ($letter->type === \App\Models\SiteLetter::EOT_REQUEST)
                            <x-field :label="__('Completion date to ask for')" name="proposed_completion" :hint="__('Leave blank and the app adds the days of the chosen hindrances to the latest of the stipulated completion date and the extensions granted, as entered under Contract terms. A date you type is marked as entered by you.')" class="sm:col-span-2">
                                <input id="proposed_completion" name="proposed_completion" type="date" value="{{ old('proposed_completion', $chosen['proposed_completion']) }}" class="input min-h-11">
                            </x-field>
                        @endif

                        <p class="text-sm text-ink-2 sm:col-span-2">{{ __('These sentences state no figure, and you may rewrite them. A number you type in them is listed above as typed by you and needs its own tick. Empty a box to get the sentence of the app back.') }}</p>
                        @foreach ($review['free'] as $id => $sentence)
                            <x-field :label="__($sentence['name'])" name="slots.{{ $id }}" class="sm:col-span-2">
                                <textarea id="slots.{{ $id }}" name="slots[{{ $id }}]" rows="3" maxlength="2000" class="input" lang="{{ $letter->language }}">{{ old('slots.'.$id, $sentence['text']) }}</textarea>
                            </x-field>
                        @endforeach
                    </div>

                    @include('modules.site_diary._letter_choice', [
                        'kind' => $letter->type,
                        'withBills' => $letter->carriesMoney(),
                        'tickedEntries' => array_map('intval', (array) old('hindrance_ids', $chosen['hindrance_ids'])),
                        'tickedBills' => array_map('intval', (array) old('bill_ids', $chosen['bill_ids'])),
                    ])

                    <div class="flex flex-wrap items-center justify-between gap-2 border-t border-rule px-4 py-3">
                        <p class="min-w-0 flex-1 text-xs text-ink-3">{{ __('Saving reads every figure from your records again, and every tick starts afresh.') }}</p>
                        <button class="btn btn-primary min-h-11">{{ __('Save and prepare again') }}</button>
                    </div>
                </form>
            </details>
        @else
            <section class="card no-print mb-6">
                <div class="card-head"><h2 class="card-title">{{ __('Figures ticked when it was finalised') }}</h2></div>
                @foreach ($review['figures'] as $figure)
                    <div class="border-b border-rule px-4 py-2 text-sm last:border-b-0">
                        <p class="text-xs text-ink-3">{{ $figure['label'] }}</p>
                        <p class="whitespace-pre-line font-semibold">{{ $figure['value'] }}</p>
                        <p class="text-xs text-ink-3">{{ __('Read from: :source', ['source' => $figure['source']]) }}</p>
                    </div>
                @endforeach
                @foreach ($review['typed'] as $number)
                    <div class="border-b border-rule px-4 py-2 text-sm last:border-b-0">
                        <p class="text-xs text-[#8a6208]">{{ $typedLabel }}</p>
                        <p class="num font-semibold">{{ $number['text'] ?? '' }}</p>
                        <p class="text-xs text-ink-3">{{ __('Where it stands: :words', ['words' => $number['context'] ?? '']) }}</p>
                    </div>
                @endforeach
            </section>
        @endif
    </div>
@endsection
