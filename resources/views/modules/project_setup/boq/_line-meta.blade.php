{{-- Under a BOQ line's description: its category, a nudge when quantity or rate is still empty, and its details. --}}
@php
    $unfilled = ! \App\Support\Dec::of($item->qty)->isPositive() || ! \App\Support\Dec::of($item->rate)->isPositive();
@endphp
@if ($item->category || $unfilled)
    <div class="mt-1 flex flex-wrap items-center gap-1.5">
        @if ($item->category)
            <span class="stamp border-ink-3 text-ink-3">{{ \App\Services\ProjectSetup\BoqCategories::label($item->category) }}</span>
        @endif
        @if ($unfilled)
            <span class="stamp border-haldi text-[#8a6208]">{{ __('To fill in') }}</span>
        @endif
    </div>
@endif
@if (filled($item->details))
    <details class="mt-1 text-xs text-ink-3">
        <summary class="cursor-pointer">{{ __('Details') }}</summary>
        <p class="mt-1 whitespace-pre-line text-ink-2">{{ $item->details }}</p>
    </details>
@endif
