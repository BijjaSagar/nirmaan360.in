@props(['align' => 'right'])
@php
    $locales = config('buildos.locales');
    $current = app()->getLocale();
@endphp
<div {{ $attributes->merge(['class' => 'relative w-fit text-[13px]']) }} x-data="{ open: false }" @click.outside="open = false" @keydown.escape.window="open = false">
    <button type="button" class="flex items-center gap-1.5 rounded-md border border-rule bg-sheet px-2 py-1 text-ink-2 hover:border-ink-3" @click="open = !open" :aria-expanded="open" aria-haspopup="true" aria-label="{{ __('Change language') }}">
        <x-icon name="language" class="size-4" />
        <span>{{ $locales[$current] ?? $current }}</span>
        <x-icon name="chevron" class="size-3.5" />
    </button>
    <div x-show="open" x-cloak class="absolute {{ $align === 'left' ? 'left-0' : 'right-0' }} z-50 mt-1 w-36 overflow-hidden rounded-md border border-rule bg-sheet py-1 text-ink shadow-lg">
        @foreach ($locales as $code => $label)
            <a href="{{ route('locale', $code) }}" lang="{{ $code }}" class="flex items-center justify-between gap-3 px-3 py-1.5 hover:bg-paper-2 {{ $current === $code ? 'font-semibold' : '' }}">{{ $label }}@if ($current === $code)<x-icon name="check" class="size-4" />@endif</a>
        @endforeach
    </div>
</div>
