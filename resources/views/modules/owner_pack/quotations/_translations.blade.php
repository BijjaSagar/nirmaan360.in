{{--
    Translations of the line descriptions of a draft quotation, for the person who manages the pack.
    $translation comes from QuotationTranslations::state(). A translation is printed only after a person
    accepted it here, and always beside the description; the AI never changes a description.
--}}
@php
    use App\Services\OwnerPack\QuotationTranslations;

    $translated = $items->filter(fn ($line) => $line->description_translated !== null)->values();
@endphp
@if ($translation['offered'] || $translation['writing'] || $translation['not_used'] || $translation['plan'] || $translated->isNotEmpty())
    <section id="translations" class="card">
        <div class="card-head"><h2 class="card-title">{{ __('Translations of the descriptions') }}</h2></div>
        <div class="space-y-2 px-4 py-3 text-sm">
            @error('translation')<p class="error" role="alert">{{ $message }}</p>@enderror
            <p class="text-ink-2">{{ __('A translation is printed only after you accept its line, and always beside the description. The app checks that its numbers, grades and ratios are those of the description, in the same order; it cannot check that the words mean the same, so read each one.') }}</p>
            @if ($translation['writing'])
                <p role="status">{{ __('The AI is translating the descriptions. This page looks again by itself.') }}</p>
                <div x-data x-init="const look = () => document.querySelector('details[open]') || document.activeElement?.matches('input, textarea, select') ? setTimeout(look, 5000) : location.reload(); setTimeout(look, 5000)"></div>
            @else
                @if ($translation['not_used'])
                    <p class="text-[#8a6208]" role="status">{{ $translation['not_used'] }}</p>
                @endif

                @if ($translation['offered'])
                    <form method="POST" action="{{ route('quotations.translate', $quotation) }}" class="flex flex-wrap items-center gap-3 pt-1">
                        @csrf
                        <button class="btn btn-ghost min-h-11">{{ trans_choice('Translate :count line with the AI|Translate :count lines with the AI', $translation['lines'], ['count' => $translation['lines']]) }}</button>
                        <span class="text-xs text-ink-3">{{ __('Uses up to :credits AI credits.', ['credits' => $translation['credits']]) }}</span>
                    </form>
                @elseif ($translation['plan'])
                    <p class="text-ink-3">{{ __('Translating the descriptions with the AI is part of the Business and Enterprise plans.') }}</p>
                @endif
            @endif
        </div>

        @if ($translated->isNotEmpty())
            <ul class="divide-y divide-rule border-t border-rule">
                @foreach ($translated as $line)
                    <li class="space-y-1.5 px-4 py-3 text-sm">
                        <p><span class="num text-xs text-ink-3">{{ $line->code }}</span> <span class="whitespace-pre-line">{{ $line->description }}</span></p>
                        <p class="whitespace-pre-line rounded-[2px] bg-paper-2/60 px-2 py-1" lang="{{ $quotation->language }}">{{ $line->description_translated }}</p>
                        @if (QuotationTranslations::printed($line))
                            <div class="flex flex-wrap items-center gap-2 text-xs">
                                <span class="stamp border-moss text-moss">{{ __('Accepted') }}</span>
                                <span class="text-ink-3">{{ $line->acceptedBy?->name ?? '—' }} · {{ \App\Services\Wording\FactFormat::display(\App\Services\Wording\Fact::DATE, $line->translation_accepted_at->toDateString(), app()->getLocale()) }}</span>
                                <form method="POST" action="{{ route('quotations.translation.withdraw', [$quotation, $line]) }}">@csrf<button class="btn btn-ghost py-1 text-xs">{{ __('Do not print it') }}</button></form>
                            </div>
                        @else
                            <form method="POST" action="{{ route('quotations.translation.accept', [$quotation, $line]) }}" class="flex flex-wrap items-center gap-2 text-xs">
                                @csrf
                                <input type="hidden" name="seen" value="{{ QuotationTranslations::seal($line) }}">
                                <span class="stamp border-ink-3 text-ink-3">{{ __('Not accepted: not printed') }}</span>
                                <button class="btn btn-ghost py-1 text-xs">{{ __('I have read it: print it beside the description') }}</button>
                            </form>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif
    </section>
@endif
