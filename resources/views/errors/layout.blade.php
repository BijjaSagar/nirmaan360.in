<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    @include('layouts.partials.head')
    <title>{{ $code }} · {{ config('buildos.brand') }}</title>
</head>
<body class="font-sans antialiased">
<div class="graph-paper grid min-h-screen place-items-center bg-neel-deep px-6 text-paper">
    <div class="max-w-lg text-center">
        <p class="num text-sm text-haldi">{{ $code }}</p>
        <h1 class="mt-2 text-6xl">{{ $title }}</h1>
        <p class="mt-3 text-paper/75">{{ $message }}</p>
        <a href="{{ auth()->check() ? route('dashboard') : route('home') }}" class="btn btn-primary mt-6">{{ __('Go back') }}</a>
    </div>
</div>
</body>
</html>
