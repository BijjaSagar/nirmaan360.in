@props(['tone' => 'light'])
@php
    $logo = site_setting('logo_path');
    $name = site_setting('site_name');
    $textTone = $tone === 'light' ? 'text-paper' : 'text-ink';
@endphp
<span {{ $attributes->merge(['class' => 'inline-flex items-center gap-2']) }}>
    @if ($logo)
        <img src="{{ route('brand.logo', ['v' => substr(md5($logo), 0, 8)]) }}" alt="{{ config('buildos.brand') }}" class="h-8 w-auto">
    @else
        <svg viewBox="0 0 32 32" class="size-6 shrink-0" aria-hidden="true"><rect width="32" height="32" rx="4" fill="var(--color-laterite)"/><path d="M8 24V8h3.4l9.2 10.4V8H24v16h-3.4l-9.2-10.4V24z" fill="#f5f1e8"/></svg>
    @endif
    @if ($name)
        <span class="font-display text-[22px] leading-none font-semibold tracking-wide {{ $textTone }}">{{ $name }}</span>
    @elseif (! $logo)
        <span class="font-display text-[22px] leading-none font-semibold tracking-wide {{ $textTone }}">NIRMAAN<span class="text-laterite {{ $tone === 'light' ? 'brightness-150' : '' }}">360</span></span>
    @endif
</span>
