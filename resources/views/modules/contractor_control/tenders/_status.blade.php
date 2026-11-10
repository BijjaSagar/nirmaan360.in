{{-- A tender's state as a stamp. "Open" on its own reads as a verb in Hindi and Marathi, so the open state says what it is open for. --}}
@php
    [$tone, $label] = match ($status) {
        'OPEN' => ['border-neel text-neel', __('Open for quotes')],
        'AWARDED' => ['border-moss text-moss', __('Awarded')],
        'CANCELLED' => ['border-ink-3 text-ink-3', __('Cancelled')],
        default => ['border-ink-3 text-ink-3', __(\Illuminate\Support\Str::headline(strtolower($status)))],
    };
@endphp
<span class="stamp {{ $tone }}">{{ $label }}</span>
