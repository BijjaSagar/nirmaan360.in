@props(['label', 'value', 'note' => null, 'accent' => false])
<div class="card px-4 py-3">
    <p class="eyebrow text-ink-3">{{ $label }}</p>
    <p class="num mt-1.5 truncate text-[22px] font-medium {{ $accent ? 'text-laterite' : 'text-ink' }}">{{ $value }}</p>
    @if ($note)
        <p class="mt-0.5 text-xs text-ink-3">{{ $note }}</p>
    @endif
</div>
