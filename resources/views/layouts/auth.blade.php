<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    @include('layouts.partials.head')
    <title>@yield('title') · {{ config('buildos.brand') }}</title>
</head>
<body class="font-sans antialiased">
<div class="grid min-h-screen lg:grid-cols-[1fr_minmax(0,520px)]">
    <div class="graph-paper relative hidden flex-col justify-between bg-neel-deep p-10 text-paper lg:flex">
        <a href="{{ route('home') }}"><x-brand /></a>
        <div>
            <p class="eyebrow text-haldi">{{ __('Site to bill') }}</p>
            <p class="mt-3 max-w-xl font-display text-6xl leading-[0.98] font-semibold">{{ __('The quantity you measure is the quantity you bill.') }}</p>
        </div>
        <p class="num text-xs text-paper/50">2 × 10.000 × 5.000 × 0.500 = 50.000 cum</p>
    </div>
    <div class="flex flex-col px-6 py-8 sm:px-12">
        <div class="flex items-center justify-between lg:justify-end">
            <a href="{{ route('home') }}" class="lg:hidden"><x-brand tone="dark" /></a>
            <x-locale-switch />
        </div>
        <div class="my-auto w-full max-w-sm self-center py-8">
            @if (session('success'))
                <div class="mb-4 rounded-[3px] border border-moss bg-moss/10 px-3 py-2 text-sm font-medium text-moss" role="status">{{ session('success') }}</div>
            @endif
            @yield('content')
        </div>
    </div>
</div>
</body>
</html>
