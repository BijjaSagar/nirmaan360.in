@props(['tone' => 'light', 'serif' => false])
@php
    $logo = site_setting('logo_path');
    $name = site_setting('site_name');
    $textTone = $tone === 'light' ? 'text-paper' : 'text-ink';
    $nameClass = $serif ? 'font-serif text-[25px] font-medium tracking-[-0.01em]' : 'font-display text-[22px] font-semibold tracking-wide';
    $brandFull = ! $logo && ! $name && $tone !== 'light';
@endphp
<span {{ $attributes->merge(['class' => 'inline-flex items-center gap-2']) }}>
    @if ($logo)
        <img src="{{ route('brand.logo', ['v' => substr(md5($logo), 0, 8)]) }}" alt="{{ config('buildos.brand') }}" class="h-8 w-auto">
    @elseif ($brandFull)
        <img src="{{ asset('brand/logo.png') }}" alt="{{ config('buildos.brand') }}" class="h-9 w-auto">
    @elseif ($tone === 'light')
        <span class="inline-flex size-8 shrink-0 items-center justify-center rounded-md bg-white"><img src="{{ asset('brand/mark.png') }}" alt="" class="h-6 w-auto"></span>
    @else
        <img src="{{ asset('brand/mark.png') }}" alt="" class="h-8 w-auto">
    @endif
    @if ($name)
        <span class="{{ $nameClass }} leading-none {{ $textTone }}">{{ $name }}</span>
    @elseif (! $logo && ! $brandFull && $serif)
        <span class="{{ $nameClass }} leading-none {{ $textTone }}">Nirmaan<span class="{{ $tone === 'light' ? 'text-haldi' : 'text-laterite' }}">360</span></span>
    @elseif (! $logo && ! $brandFull)
        <span class="{{ $nameClass }} leading-none {{ $textTone }}">NIRMAAN<span class="text-laterite {{ $tone === 'light' ? 'brightness-150' : '' }}">360</span></span>
    @endif
</span>
