@extends('layouts.app')
@section('title', __('Stock card'))
@section('content')
    <x-page-header :title="$material->name" :subtitle="$store->name">
        <a href="{{ route('procurement.stock.index', $project) }}" class="btn btn-ghost">{{ __('Back to stock') }}</a>
    </x-page-header>

    <div class="mb-5 grid gap-3 sm:grid-cols-2">
        <div class="card p-4"><p class="eyebrow text-[10px] text-ink-3">{{ __('In stock') }}</p><p class="num text-lg font-medium">{{ fmt_qty($balance) }} {{ $material->baseUnit->code }}</p></div>
        <div class="card p-4"><p class="eyebrow text-[10px] text-ink-3">{{ __('Reorder level') }}</p><p class="num text-lg font-medium">{{ $level?->reorder_level ? fmt_qty($level->reorder_level) : '—' }}</p></div>
    </div>

    <section class="card overflow-x-auto">
        @if ($entries->isEmpty())
            <x-empty :title="__('No movements yet')" />
        @else
            <table class="ledger min-w-[640px]">
                <thead><tr><th>{{ __('Date') }}</th><th>{{ __('Movement') }}</th><th>{{ __('For') }}</th><th>{{ __('By') }}</th><th class="r">{{ __('Quantity') }}</th><th class="r">{{ __('Balance') }}</th></tr></thead>
                <tbody>
                @foreach ($entries as $entry)
                    <tr>
                        <td class="num text-xs">{{ $entry->movement_date->format('d M Y') }}</td>
                        <td>{{ __(\Illuminate\Support\Str::headline($entry->movement_type)) }}</td>
                        <td>{{ $entry->boqItem?->code ?? $entry->activity ?? '—' }}</td>
                        <td>{{ $entry->creator?->name ?? '—' }}</td>
                        @php $inward = \App\Support\Dec::of($entry->qty)->isPositive(); @endphp
                        <td class="r {{ $inward ? 'text-moss' : '' }}">{{ $inward ? '+' : '' }}{{ fmt_qty($entry->qty) }}</td>
                        <td class="r">{{ fmt_qty(\App\Support\Dec::qty($entry->running_balance)) }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        @endif
    </section>
    <div class="mt-4">{{ $entries->links() }}</div>
@endsection
