<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    @include('layouts.partials.head')
    <title>{{ __('Asset tags') }} · {{ config('buildos.brand') }}</title>
    <style>@media print { .no-print { display: none !important; } @page { margin: 10mm; } body { background: #fff; } }</style>
</head>
<body class="font-sans antialiased">
    <div class="no-print flex flex-wrap items-center justify-between gap-3 border-b border-rule bg-sheet px-4 py-2.5">
        <a href="{{ route('assets.index') }}" class="link text-sm">← {{ __('Assets') }}</a>
        <p class="text-sm text-ink-3">{{ __('Print on sticker paper or card, then laminate. Scanning a tag opens the asset after sign-in.') }}</p>
        <button type="button" class="btn btn-primary" onclick="window.print()">{{ __('Print') }}</button>
    </div>

    @if (count($tags) === 0)
        <x-empty :title="__('No assets to print')" />
    @endif

    <div class="grid gap-4 p-4 sm:grid-cols-2 lg:grid-cols-3 print:grid-cols-3">
        @foreach ($tags as $tag)
            <div class="break-inside-avoid rounded-[4px] border-2 border-dashed border-ink/60 bg-white p-4 text-center">
                <p class="eyebrow text-[10px] text-ink-3">{{ org()->name }}</p>
                <div class="mx-auto my-2 w-44 max-w-full">{!! $tag['svg'] !!}</div>
                <p class="num text-2xl font-semibold tracking-wide">{{ $tag['asset']->code }}</p>
                <p class="mt-0.5 font-semibold">{{ $tag['asset']->name }}</p>
                <p class="text-xs text-ink-3">{{ collect([__(config('modules.assets.categories')[$tag['asset']->category] ?? ''), $tag['asset']->registration_no])->filter()->join(' · ') }}</p>
            </div>
        @endforeach
    </div>
</body>
</html>
