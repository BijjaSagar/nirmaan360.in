{{-- A figure tile whose whole amount stays readable on a phone. --}}
<div class="card px-4 py-3">
    <p class="eyebrow text-ink-3">{{ $label }}</p>
    <p class="num mt-1.5 text-[17px] font-medium [overflow-wrap:anywhere] sm:text-[22px] {{ ($accent ?? false) ? 'text-laterite' : 'text-ink' }}">{{ $value }}</p>
    @if ($note ?? null)
        <p class="mt-0.5 text-xs text-ink-3">{{ $note }}</p>
    @endif
</div>
