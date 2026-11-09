@props(['tone' => 'light', 'serif' => false])
@php
    $logo = site_setting('logo_path');
    $name = site_setting('site_name');
    $textTone = $tone === 'light' ? 'text-paper' : 'text-ink';
    $nameClass = $serif ? 'font-serif text-[25px] font-medium tracking-[-0.01em]' : 'font-display text-[22px] font-semibold tracking-wide';
@endphp
<span {{ $attributes->merge(['class' => 'inline-flex items-center gap-2']) }}>
    @if ($logo)
        <img src="{{ route('brand.logo', ['v' => substr(md5($logo), 0, 8)]) }}" alt="{{ config('buildos.brand') }}" class="h-8 w-auto">
    @elseif ($serif)
        <svg viewBox="0 0 28 28" class="size-[22px] shrink-0" fill="var(--color-laterite)" aria-hidden="true"><rect x="10" y="4" width="8" height="6" rx="1.2"/><rect x="5.5" y="11" width="8" height="6" rx="1.2"/><rect x="14.5" y="11" width="8" height="6" rx="1.2"/><rect x="1" y="18" width="8" height="6" rx="1.2"/><rect x="10" y="18" width="8" height="6" rx="1.2"/><rect x="19" y="18" width="8" height="6" rx="1.2"/></svg>
    @else
        <svg viewBox="0 0 32 32" class="size-6 shrink-0" aria-hidden="true"><rect width="32" height="32" rx="4" fill="var(--color-laterite)"/><path d="M8 24V8h3.4l9.2 10.4V8H24v16h-3.4l-9.2-10.4V24z" fill="#f5f1e8"/></svg>
    @endif
    @if ($name)
        <span class="{{ $nameClass }} leading-none {{ $textTone }}">{{ $name }}</span>
    @elseif (! $logo && $serif)
        <span class="{{ $nameClass }} leading-none {{ $textTone }}">Nirmaan<span class="{{ $tone === 'light' ? 'text-haldi' : 'text-laterite' }}">360</span></span>
    @elseif (! $logo)
        <span class="{{ $nameClass }} leading-none {{ $textTone }}">NIRMAAN<span class="text-laterite {{ $tone === 'light' ? 'brightness-150' : '' }}">360</span></span>
    @endif
</span>
