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
<body class="site font-sans text-ink antialiased">
<header class="sticky top-0 z-40 border-b border-black/8 bg-white/95 backdrop-blur" x-data="{ open: false }">
    <div class="site-wrap flex h-[68px] items-center justify-between gap-6">
        <a href="{{ route('home') }}" aria-label="{{ config('buildos.brand') }}"><x-brand tone="dark" /></a>
        <nav class="hidden items-center gap-7 text-[15px] font-medium text-ink-2 lg:flex">
            <a href="{{ route('home') }}#product" class="hover:text-ink">{{ __('Product') }}</a>
            <a href="{{ route('ai') }}" class="hover:text-ink">{{ __('AI agents') }}</a>
            <a href="{{ route('pricing') }}" class="hover:text-ink">{{ __('Pricing') }}</a>
            <a href="{{ route('calculators') }}" class="hover:text-ink">{{ __('Calculators') }}</a>
            <a href="{{ route('contact') }}" class="hover:text-ink">{{ __('Contact') }}</a>
        </nav>
        <div class="hidden items-center gap-4 lg:flex">
            <x-locale-switch />
            @auth
                <a href="{{ route('dashboard') }}" class="site-btn site-btn-primary py-2">{{ __('Open app') }}</a>
            @else
                <a href="{{ route('login') }}" class="text-[15px] font-medium text-ink-2 hover:text-ink">{{ __('Sign in') }}</a>
                <a href="{{ route('register') }}" class="site-btn site-btn-primary py-2">{{ __('Start free') }}</a>
            @endauth
        </div>
        <button type="button" class="p-1 lg:hidden" @click="open = !open" aria-label="{{ __('Open menu') }}"><x-icon name="menu" class="size-6" /></button>
    </div>
    <div x-show="open" x-cloak class="border-t border-black/8 bg-white lg:hidden">
        <div class="site-wrap space-y-3 py-4 text-[16px] font-medium">
            <a href="{{ route('home') }}#product" class="block">{{ __('Product') }}</a>
            <a href="{{ route('ai') }}" class="block">{{ __('AI agents') }}</a>
            <a href="{{ route('pricing') }}" class="block">{{ __('Pricing') }}</a>
            <a href="{{ route('calculators') }}" class="block">{{ __('Calculators') }}</a>
            <a href="{{ route('contact') }}" class="block">{{ __('Contact') }}</a>
            <x-locale-switch />
            <div class="flex gap-2 pt-1">
                @auth
                    <a href="{{ route('dashboard') }}" class="site-btn site-btn-primary">{{ __('Open app') }}</a>
                @else
                    <a href="{{ route('login') }}" class="site-btn site-btn-outline">{{ __('Sign in') }}</a>
                    <a href="{{ route('register') }}" class="site-btn site-btn-primary">{{ __('Start free') }}</a>
                @endauth
            </div>
        </div>
    </div>
</header>

@yield('content')

<footer class="bg-[#12181c] text-white/70">
    <div class="site-wrap grid gap-10 py-14 md:grid-cols-[1.5fr_1fr_1fr_1fr]">
        <div>
            <x-brand />
            <p class="mt-4 max-w-xs text-[15px] leading-relaxed">{{ __('Site-to-bill software for Indian contractors, builders and consultants.') }}</p>
        </div>
        <div>
            <p class="text-sm font-semibold text-white">{{ __('Product') }}</p>
            <ul class="mt-3 space-y-2 text-[15px]">
                <li><a href="{{ route('home') }}#product" class="hover:text-white">{{ __('Features') }}</a></li>
                <li><a href="{{ route('pricing') }}" class="hover:text-white">{{ __('Pricing') }}</a></li>
                <li><a href="{{ route('ai') }}" class="hover:text-white">{{ __('AI agents') }}</a></li>
            </ul>
        </div>
        <div>
            <p class="text-sm font-semibold text-white">{{ __('Free tools') }}</p>
            <ul class="mt-3 space-y-2 text-[15px]">
                <li><a href="{{ route('calculators') }}" class="hover:text-white">{{ __('Concrete and steel') }}</a></li>
                <li><a href="{{ route('calculators') }}" class="hover:text-white">{{ __('Brickwork and plaster') }}</a></li>
                <li><a href="{{ route('calculators') }}" class="hover:text-white">{{ __('Cut and fill') }}</a></li>
            </ul>
        </div>
        <div>
            <p class="text-sm font-semibold text-white">{{ __('Company') }}</p>
            <ul class="mt-3 space-y-2 text-[15px]">
                <li><a href="{{ route('contact') }}" class="hover:text-white">{{ __('Contact') }}</a></li>
                <li><a href="{{ route('privacy') }}" class="hover:text-white">{{ __('Privacy') }}</a></li>
                <li><a href="{{ route('terms') }}" class="hover:text-white">{{ __('Terms') }}</a></li>
            </ul>
        </div>
    </div>
    <div class="border-t border-white/10">
        <div class="site-wrap flex flex-wrap items-center justify-between gap-3 py-5 text-sm">
            <p>© {{ now()->year }} {{ config('buildos.brand') }}</p>
            <p lang="hi">निर्माण का पूरा हिसाब, एक जगह।</p>
        </div>
    </div>
</footer>
</body>
</html>
