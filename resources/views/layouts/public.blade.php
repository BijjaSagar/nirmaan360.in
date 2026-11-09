<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    @include('layouts.partials.head')
    <title>@yield('title') · {{ config('buildos.brand') }}</title>
    <meta name="description" content="@yield('description', __('Construction software for Indian contractors. BOQ, daily reports, measurement book and RA bills on one record.'))">
    <meta property="og:title" content="@yield('title') · {{ config('buildos.brand') }}">
    <meta property="og:description" content="@yield('description', __('Construction software for Indian contractors. BOQ, daily reports, measurement book and RA bills on one record.'))">
    <meta property="og:type" content="website">
    <meta property="og:url" content="{{ url()->current() }}">
    <link rel="canonical" href="{{ url()->current() }}">
</head>
<body class="font-sans antialiased">
<header class="bg-neel-deep text-paper" x-data="{ open: false }">
    <div class="mx-auto flex max-w-7xl items-center justify-between gap-4 px-5 py-4">
        <a href="{{ route('home') }}"><x-brand /></a>
        <nav class="hidden items-center gap-6 text-[15px] text-paper/80 md:flex">
            <a href="{{ route('home') }}#how" class="hover:text-paper">{{ __('How it works') }}</a>
            <a href="{{ route('home') }}#schedule" class="hover:text-paper">{{ __('What is included') }}</a>
            <a href="{{ route('calculators') }}" class="hover:text-paper">{{ __('Calculators') }}</a>
            <a href="{{ route('home') }}#plans" class="hover:text-paper">{{ __('Plans') }}</a>
        </nav>
        <div class="hidden items-center gap-3 md:flex">
            <x-locale-switch tone="light" />
            @auth
                <a href="{{ route('dashboard') }}" class="btn btn-primary">{{ __('Open app') }}</a>
            @else
                <a href="{{ route('login') }}" class="text-[15px] text-paper/80 hover:text-paper">{{ __('Sign in') }}</a>
                <a href="{{ route('register') }}" class="btn btn-primary">{{ __('Start free') }}</a>
            @endauth
        </div>
        <button type="button" class="p-1 text-2xl leading-none md:hidden" @click="open = !open" aria-label="{{ __('Open menu') }}">☰</button>
    </div>
    <div x-show="open" x-cloak class="space-y-3 border-t border-white/10 px-5 py-4 text-paper/85 md:hidden">
        <a href="{{ route('home') }}#how" class="block">{{ __('How it works') }}</a>
        <a href="{{ route('home') }}#schedule" class="block">{{ __('What is included') }}</a>
        <a href="{{ route('calculators') }}" class="block">{{ __('Calculators') }}</a>
        <a href="{{ route('home') }}#plans" class="block">{{ __('Plans') }}</a>
        <x-locale-switch tone="light" />
        <div class="flex gap-2 pt-1">
            @auth
                <a href="{{ route('dashboard') }}" class="btn btn-primary">{{ __('Open app') }}</a>
            @else
                <a href="{{ route('login') }}" class="btn btn-ghost">{{ __('Sign in') }}</a>
                <a href="{{ route('register') }}" class="btn btn-primary">{{ __('Start free') }}</a>
            @endauth
        </div>
    </div>
</header>

@yield('content')

<footer class="bg-ink text-paper/70">
    <div class="mx-auto flex max-w-7xl flex-wrap items-center justify-between gap-4 px-5 py-8 text-sm">
        <x-brand />
        <p class="num text-xs">© {{ now()->year }} {{ config('buildos.brand') }}</p>
    </div>
</footer>
</body>
</html>
