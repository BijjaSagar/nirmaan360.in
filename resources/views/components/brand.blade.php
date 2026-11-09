@props(['tone' => 'light', 'full' => false])
@php
    $uploaded = site_setting('logo_path');
    $name = site_setting('site_name');
    $file = $tone === 'light' ? 'logo-on-dark.webp' : ($full ? 'logo-full.webp' : 'logo.webp');
@endphp
<span {{ $attributes->merge(['class' => 'inline-flex items-center gap-2']) }}>
    @if ($uploaded)
        <img src="{{ route('brand.logo', ['v' => substr(md5($uploaded), 0, 8)]) }}" alt="{{ config('buildos.brand') }}" class="h-8 w-auto">
    @elseif (! $name)
        <img src="{{ asset('images/'.$file) }}" alt="{{ config('buildos.brand') }}" class="{{ $full ? 'h-20' : 'h-9' }} w-auto">
    @endif
    @if ($name)
        <span class="font-display text-[23px] leading-none font-medium {{ $tone === 'light' ? 'text-paper' : 'text-ink' }}">{{ $name }}</span>
    @endif
</span>
