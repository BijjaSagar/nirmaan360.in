<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf-token" content="{{ csrf_token() }}">
<link rel="icon" href="{{ asset('favicon.png') }}" type="image/png">
<link rel="apple-touch-icon" href="{{ asset('apple-touch-icon.png') }}">
<link rel="preconnect" href="https://fonts.bunny.net">
<link href="https://fonts.bunny.net/css?family=khand:500,600,700|mukta:400,500,600,700,800|dm-mono:400,500" rel="stylesheet">
@if ($siteFonts ?? false)
    <link href="https://fonts.bunny.net/css?family=newsreader:400,500|noto-serif-devanagari:400,500|instrument-sans:400,500,600" rel="stylesheet">
@endif
@vite(['resources/css/app.css', 'resources/js/app.js'])
@php
    $brandColors = array_filter([
        'laterite' => site_setting('color_primary'),
        'laterite-dark' => site_setting('color_primary_dark'),
        'haldi' => site_setting('color_accent'),
    ]);
@endphp
@if ($brandColors)
    <style>:root,.site{ @foreach ($brandColors as $name => $value)--color-{{ $name }}:{{ $value }};@endforeach @if (isset($brandColors['laterite']))--site-cta:{{ $brandColors['laterite'] }};--site-cta-hover:{{ $brandColors['laterite-dark'] ?? $brandColors['laterite'] }};@endif }</style>
@endif
