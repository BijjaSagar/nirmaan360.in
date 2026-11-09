@extends('layouts.app')
@section('title', $grn->number)
@section('content')
    <x-page-header :title="$grn->number" :subtitle="$grn->purchaseOrder->number.' · '.$grn->purchaseOrder->vendor->name">
        <x-stamp :status="$grn->status" />
        @can('procurement.stock')
            @unless ($grn->isReversed())
                <a href="{{ route('procurement.returns.create-from-grn', $grn) }}" class="btn btn-ghost">{{ __('Return to vendor') }}</a>
            @endunless
        @endcan
    </x-page-header>

    <section class="card">
        <div class="overflow-x-auto">
            <table class="ledger min-w-[560px]">
                <thead><tr><th>{{ __('Material') }}</th><th class="r">{{ __('Accepted') }}</th><th class="r">{{ __('Rejected') }}</th><th class="r">{{ __('Added to stock') }}</th></tr></thead>
                <tbody>
                @foreach ($grn->items as $line)
                    <tr>
                        <td>{{ $line->material->name }}</td>
                        <td class="r">{{ fmt_qty($line->qty) }} {{ $line->unit->code }}</td>
                        <td class="r">{{ fmt_qty($line->rejected_qty) }}</td>
                        <td class="r">{{ fmt_qty($line->base_qty) }} {{ $line->material->baseUnit->code }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        <dl class="grid gap-2 border-t border-rule p-4 text-sm sm:grid-cols-3">
            <div><dt class="text-ink-3">{{ __('Date received') }}</dt><dd>{{ $grn->grn_date->format('d M Y') }}</dd></div>
            <div><dt class="text-ink-3">{{ __('Store') }}</dt><dd>{{ $grn->store->name }}</dd></div>
            <div><dt class="text-ink-3">{{ __('Received by') }}</dt><dd>{{ $grn->creator?->name ?? '—' }}</dd></div>
            <div><dt class="text-ink-3">{{ __('Challan no.') }}</dt><dd>{{ $grn->challan_no ?? '—' }}</dd></div>
            <div><dt class="text-ink-3">{{ __('Vehicle no.') }}</dt><dd>{{ $grn->vehicle_no ?? '—' }}</dd></div>
            @if ($grn->isReversed())<div><dt class="text-ink-3">{{ __('Reversal reason') }}</dt><dd>{{ $grn->reversal_reason }}</dd></div>@endif
        </dl>
    </section>

    @unless ($grn->isReversed())
        @include('modules.procurement.partials.reverse', ['action' => route('procurement.grns.reverse', $grn), 'hint' => __('Takes this receipt back out of stock. Refused if the stock has already been used.')])
    @endunless
@endsection
