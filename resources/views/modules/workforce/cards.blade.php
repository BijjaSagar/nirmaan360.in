@extends('layouts.app')
@section('title', __('Worker QR cards'))
@section('content')
    <style>
        @media print {
            aside, header, .no-print { display: none !important; }
            main { padding: 0 !important; }
            .card-sheet { break-inside: avoid; }
        }
    </style>

    <x-page-header :title="__('Worker QR cards')" :subtitle="$single ? $workers->first()?->name : __('Print these and give one to each worker. A site user scans the card to mark the worker present.')">
        <div class="no-print flex flex-wrap items-center gap-2">
            @if ($single)
                <a href="{{ route('workforce.cards') }}" class="btn btn-ghost">{{ __('All workers') }}</a>
            @endif
            @if ($workers->isNotEmpty())
                <button type="button" onclick="window.print()" class="btn btn-primary">{{ __('Print') }}</button>
            @endif
        </div>
    </x-page-header>

    @if ($workers->isEmpty())
        <div class="card"><x-empty :title="__('No active workers')" :note="__('Add workers first, then print their cards.')" /></div>
    @else
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
            @foreach ($workers as $worker)
                <div class="card card-sheet flex items-center gap-4 p-4">
                    <div class="size-32 shrink-0 [&>svg]:size-full" role="img" aria-label="{{ __('QR code for :name', ['name' => $worker->name]) }}">{!! $cards[$worker->id] !!}</div>
                    <div class="min-w-0">
                        <p class="eyebrow text-[10px] text-ink-3">{{ $worker->organization?->name ?? org()?->name }}</p>
                        <p class="font-display text-xl font-semibold leading-tight">{{ $worker->name }}</p>
                        <p class="text-sm text-ink-2">{{ __($worker->trade) }}</p>
                        <p class="num mt-1 text-xs text-ink-3">{{ __('Worker') }} #{{ $worker->id }}</p>
                        @can('worker.manage')
                            <form method="POST" action="{{ route('workforce.cards.reissue', $worker) }}" class="no-print mt-2" onsubmit="return confirm(@js(__('Cancel this card and make a new one? The printed card will stop working.')))">
                                @csrf
                                <button class="link text-xs">{{ __('Lost card? Replace it') }}</button>
                            </form>
                        @endcan
                    </div>
                </div>
            @endforeach
        </div>
    @endif
@endsection
