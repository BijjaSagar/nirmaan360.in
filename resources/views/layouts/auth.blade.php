<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    @include('layouts.partials.head', ['siteFonts' => true])
    <title>@yield('title') · {{ config('buildos.brand') }}</title>
</head>
<body class="site font-sans text-ink antialiased">
<div class="grid min-h-screen lg:grid-cols-[1fr_minmax(0,520px)]">
    <div class="relative m-3 hidden flex-col justify-between overflow-hidden rounded-[28px] bg-sand p-10 lg:flex">
        <a href="{{ route('home') }}"><x-brand tone="dark" full /></a>
        <div class="relative z-10 pb-24">
            <p class="text-[15px] text-laterite">{{ __('Site to bill') }}</p>
            <p class="mt-3 max-w-xl font-serif text-[clamp(2.75rem,4.6vw,4.25rem)] leading-[1.06]">{{ __('The quantity you measure is the quantity you bill.') }}</p>
            <p class="num mt-6 text-xs text-ink-3">2 × 10.000 × 5.000 × 0.500 = 50.000 cum</p>
        </div>
        <div class="absolute inset-x-0 bottom-0 h-28 text-ink/15 [&>svg]:h-full [&>svg]:w-full">@include('public.partials.skyline')</div>
    </div>
    <div class="flex flex-col px-6 py-8 sm:px-12">
        <div class="flex items-center justify-between lg:justify-end">
            <a href="{{ route('home') }}" class="lg:hidden"><x-brand tone="dark" /></a>
            <x-locale-switch />
        </div>
        <div class="my-auto w-full max-w-sm self-center py-8">
            @if (session('success'))
                <div class="mb-4 rounded-lg border border-moss bg-moss/10 px-3 py-2 text-sm font-medium text-moss" role="status">{{ session('success') }}</div>
            @endif
            @yield('content')
        </div>
    </div>
</div>
</body>
</html>
