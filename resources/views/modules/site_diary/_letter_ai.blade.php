{{--
    Asking the AI for the opening, the sentence before the list and the closing sentences of a draft letter.
    $ai comes from LetterDrafts::state(). Every figure, and every sentence that states one, stays the app's own.
--}}
@if ($ai['offered'] || $ai['writing'] || $ai['not_used'] || $ai['plan'])
    <section id="ai-sentences" class="card no-print mb-6">
        <div class="card-head"><h2 class="card-title">{{ __('Have the AI write the three sentences') }}</h2></div>
        <div class="space-y-2 px-4 py-3 text-sm">
            @if ($ai['writing'])
                <p role="status">{{ __('The AI is writing the opening, the sentence before the list and the closing sentences. This page looks again by itself.') }}</p>
                @include('modules.site_diary._look-again')
            @else
                @if ($ai['not_used'])
                    <p class="text-[#8a6208]" role="status">{{ $ai['not_used'] }}</p>
                @endif

                @if ($ai['offered'])
                    <p class="text-ink-2">{{ __('Every date, day count, quantity and amount, and the sentence that states it, is placed by the app from your records. Check the draft against your agreement before sending.') }}</p>
                    <p class="text-ink-3">{{ __('The AI is told what each of the three sentences is for and the names of the figures around them, never a figure. A sentence of the AI that states a figure is not used. A sentence you rewrote yourself is kept.') }}</p>
                    <form method="POST" action="{{ route('site-diary.letters.draft', $letter) }}" class="flex flex-wrap items-center gap-3 pt-1">
                        @csrf
                        <button class="btn btn-ghost min-h-11">{{ __('Write them with the AI') }}</button>
                        <span class="text-xs text-ink-3">{{ __('Uses up to :credits AI credits.', ['credits' => $ai['credits']]) }}</span>
                    </form>
                @elseif ($ai['plan'])
                    <p class="text-ink-3">{{ __('Writing these sentences with the AI is part of the Business and Enterprise plans.') }}</p>
                @endif
            @endif
        </div>
    </section>
@endif
