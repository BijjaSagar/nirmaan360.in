@props(['tone' => 'light', 'full' => false, 'lazy' => false])
@php
    $uploaded = site_setting('logo_path');
    $name = site_setting('site_name');
    $file = $tone === 'light' ? 'logo-on-dark.webp' : ($full ? 'logo-full.webp' : 'logo.webp');
    // The real pixel size of each file. With it the browser keeps the logo's space before the picture
    // arrives, so the menu beside it does not jump.
    [$width, $height] = $uploaded
        ? [site_setting('logo_width'), site_setting('logo_height')]
        : ($file === 'logo-full.webp' ? [960, 222] : [720, 166]);
    // Below the first screen (the footer) the picture can wait until it is scrolled to.
    $loading = $lazy ? 'lazy' : 'eager';
@endphp
<span {{ $attributes->merge(['class' => 'inline-flex items-center gap-2']) }}>
    @if ($uploaded)
        <img src="{{ route('brand.logo', ['v' => substr(md5($uploaded), 0, 8)]) }}" alt="{{ config('buildos.brand') }}" @if ($width && $height) width="{{ $width }}" height="{{ $height }}" @endif loading="{{ $loading }}" decoding="async" class="h-8 w-auto">
    @elseif (! $name)
        <img src="{{ asset('images/'.$file) }}" alt="{{ config('buildos.brand') }}" width="{{ $width }}" height="{{ $height }}" loading="{{ $loading }}" decoding="async" class="{{ $full ? 'h-20' : 'h-9' }} w-auto">
    @endif
    @if ($name)
        <span class="font-display text-[23px] leading-none font-medium {{ $tone === 'light' ? 'text-paper' : 'text-ink' }}">{{ $name }}</span>
    @endif
</span>
