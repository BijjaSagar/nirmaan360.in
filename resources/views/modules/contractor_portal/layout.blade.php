{{-- The contractor's own frame: no company menu, no project switcher, just their contracts, payments and bills. --}}
@php
    $organization = auth()->user()->organization;
    $tab = fn (string ...$patterns) => request()->routeIs(...$patterns) ? 'btn btn-ink' : 'btn btn-ghost';
@endphp
<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    @include('layouts.partials.head')
    <title>@yield('title', __('Contractor portal')) · {{ config('buildos.brand') }}</title>
</head>
<body class="font-sans antialiased">
<div class="flex min-h-screen flex-col">
    <header class="bg-neel-deep text-paper">
        <div class="mx-auto flex w-full max-w-5xl items-center justify-between gap-3 px-4 py-3 sm:px-6">
            <a href="{{ route('contractor-portal.home') }}" class="min-w-0">
                <x-brand />
                <p class="mt-1 truncate text-[13px] text-paper/70">{{ $organization->name }} · {{ __('Contractor portal') }}</p>
            </a>
            <div class="flex shrink-0 items-center gap-2">
                <div class="hidden text-right leading-tight sm:block">
                    <p class="text-[13px] font-semibold">{{ auth()->user()->name }}</p>
                    <p class="max-w-48 truncate text-[11px] text-paper/70">{{ $party->name }}</p>
                </div>
                <form method="POST" action="{{ route('logout') }}">@csrf<button class="btn btn-ghost">{{ __('Sign out') }}</button></form>
            </div>
        </div>
    </header>

    <nav class="border-b border-rule bg-sheet" aria-label="{{ __('Contractor portal') }}">
        <div class="mx-auto flex w-full max-w-5xl flex-wrap items-center gap-1.5 px-4 py-2 sm:px-6">
            <a href="{{ route('contractor-portal.home') }}" class="{{ $tab('contractor-portal.home', 'contractor-portal.contracts.*') }}">{{ __('My work orders') }}</a>
            <a href="{{ route('contractor-portal.payments') }}" class="{{ $tab('contractor-portal.payments') }}">{{ __('My payments') }}</a>
            <a href="{{ route('contractor-portal.bills') }}" class="{{ $tab('contractor-portal.bills', 'contractor-portal.bills.*') }}">{{ __('My bills') }}</a>
            <x-locale-switch class="ml-auto" />
        </div>
    </nav>

    <main class="mx-auto w-full max-w-5xl flex-1 px-4 py-5 sm:px-6">
        @if (session('success'))
            <div class="mb-4 rounded-[3px] border border-moss bg-moss/10 px-3 py-2 text-sm font-medium text-moss" role="status">{{ session('success') }}</div>
        @endif
        @yield('content')
    </main>

    <footer class="mx-auto w-full max-w-5xl px-4 py-4 text-xs text-ink-3 sm:px-6">
        {{ __('Figures as recorded by :company. This view is read-only: for any question, speak to their site team.', ['company' => $organization->name]) }}
    </footer>
</div>
</body>
</html>
