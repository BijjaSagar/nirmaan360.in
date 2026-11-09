{{-- A figure tile that fits two across on a phone. Pass amount (rounded to whole rupees here, shown in full elsewhere) or a ready value. --}}
@php
    $shown = isset($amount) ? '₹'.\App\Support\Dec::indian($amount, 0) : $value;
@endphp
<div class="card min-w-0 px-4 py-3">
    <p class="eyebrow text-ink-3">{{ $label }}</p>
    <p class="num mt-1.5 text-[19px] leading-tight font-medium break-words sm:text-[22px] {{ ! empty($accent) ? 'text-laterite' : 'text-ink' }}">{{ $shown }}</p>
    @if (! empty($note))
        <p class="mt-0.5 text-xs text-ink-3">{{ $note }}</p>
    @endif
</div>
