<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    {{-- The head include marks this page noindex. It names no company, project or document. --}}
    @include('layouts.partials.head')
    <meta name="referrer" content="no-referrer">
    <title>{{ __('This link is no longer active') }} · {{ config('buildos.brand') }}</title>
</head>
<body class="font-sans antialiased">
<header class="bg-neel-deep px-5 py-4 text-paper"><x-brand /></header>
<main class="mx-auto max-w-xl px-5 py-16 text-center">
    <h1 class="text-3xl">{{ __('This link is no longer active') }}</h1>
    <p class="mt-3 text-sm text-ink-2">{{ __('Ask the person who sent it to you for a new link.') }}</p>
</main>
</body>
</html>
