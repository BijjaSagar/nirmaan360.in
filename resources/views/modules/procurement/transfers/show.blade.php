@extends('layouts.app')
@section('title', $transfer->number)
@section('content')
    <x-page-header :title="$transfer->number" :subtitle="$transfer->fromStore->name.' → '.$transfer->toStore->name">
        <x-stamp :status="$transfer->status" />
    </x-page-header>

    <section class="card">
        <div class="overflow-x-auto">
            <table class="ledger min-w-[560px]">
                <thead><tr><th>{{ __('Material') }}</th><th class="r">{{ __('Dispatched') }}</th><th class="r">{{ __('Received') }}</th><th class="r">{{ __('Short') }}</th></tr></thead>
                <tbody>
                @foreach ($transfer->items as $line)
                    <tr>
                        <td>{{ $line->material->name }}</td>
                        <td class="r">{{ fmt_qty($line->base_qty) }} {{ $line->material->baseUnit->code }}</td>
                        <td class="r">{{ $line->received_base_qty === null ? '—' : fmt_qty($line->received_base_qty) }}</td>
                        <td class="r {{ \App\Support\Dec::of($line->shortQty())->isPositive() ? 'text-laterite' : '' }}">{{ fmt_qty($line->shortQty()) }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        <dl class="grid gap-2 border-t border-rule p-4 text-sm sm:grid-cols-3">
            <div><dt class="text-ink-3">{{ __('Dispatch date') }}</dt><dd>{{ $transfer->dispatch_date->format('d M Y') }}</dd></div>
            <div><dt class="text-ink-3">{{ __('Received on') }}</dt><dd>{{ $transfer->receive_date?->format('d M Y') ?? '—' }}</dd></div>
            <div><dt class="text-ink-3">{{ __('Vehicle no.') }}</dt><dd>{{ $transfer->vehicle_no ?? '—' }}</dd></div>
            <div><dt class="text-ink-3">{{ __('From') }}</dt><dd>{{ $transfer->fromStore->name }} ({{ $transfer->fromProject->code }})</dd></div>
            <div><dt class="text-ink-3">{{ __('To') }}</dt><dd>{{ $transfer->toStore->name }} ({{ $transfer->toProject->code }})</dd></div>
            @if ($transfer->isReversed())<div><dt class="text-ink-3">{{ __('Reversal reason') }}</dt><dd>{{ $transfer->reversal_reason }}</dd></div>@endif
        </dl>
    </section>

    @if ($transfer->status === 'DISPATCHED')
        @can('procurement.stock')
            <form method="POST" action="{{ route('procurement.transfers.receive', $transfer) }}" class="card mt-5 space-y-3 p-4">
                @csrf
                <h2 class="card-title">{{ __('Book in at destination') }}</h2>
                <x-field :label="__('Received on')" name="receive_date"><input id="receive_date" name="receive_date" type="date" max="{{ today()->format('Y-m-d') }}" value="{{ today()->format('Y-m-d') }}" class="input sm:max-w-56" required></x-field>
                <div class="overflow-x-auto">
                    <table class="ledger min-w-[440px]">
                        <thead><tr><th>{{ __('Material') }}</th><th class="r">{{ __('Dispatched') }}</th><th>{{ __('Received') }}</th></tr></thead>
                        <tbody>
                        @foreach ($transfer->items as $line)
                            <tr>
                                <td>{{ $line->material->name }}<input type="hidden" name="items[{{ $loop->index }}][line_id]" value="{{ $line->id }}"></td>
                                <td class="r">{{ fmt_qty($line->base_qty) }} {{ $line->material->baseUnit->code }}</td>
                                <td><input name="items[{{ $loop->index }}][received_qty]" type="number" step="0.001" min="0" placeholder="{{ $line->base_qty }}" class="input num w-32" aria-label="{{ __('Received') }}"></td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
                <p class="hint">{{ __('Leave blank to book in everything dispatched. Anything less is recorded as short.') }}</p>
                <button class="btn btn-primary">{{ __('Book in') }}</button>
            </form>
        @endcan
    @endif

    @unless ($transfer->isReversed())
        @include('modules.procurement.partials.reverse', ['action' => route('procurement.transfers.reverse', $transfer), 'hint' => __('Puts the stock back in the sending store. Once received, it is also taken out of the receiving store.')])
    @endunless
@endsection
