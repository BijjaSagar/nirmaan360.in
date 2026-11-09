@extends('layouts.app')
@section('title', $return->number)
@section('content')
    <x-page-header :title="$return->number" :subtitle="$return->isToVendor() ? __('Return to vendor') : __('Return to store')">
        <x-stamp :status="$return->status" />
    </x-page-header>

    <section class="card">
        <div class="overflow-x-auto">
            <table class="ledger min-w-[440px]">
                <thead><tr><th>{{ __('Material') }}</th><th class="r">{{ __('Quantity') }}</th><th class="r">{{ __('Stock change') }}</th></tr></thead>
                <tbody>
                @foreach ($return->items as $line)
                    <tr>
                        <td>{{ $line->material->name }}</td>
                        <td class="r">{{ fmt_qty($line->qty) }} {{ $line->unit->code }}</td>
                        <td class="r">{{ $return->isToVendor() ? '−' : '+' }}{{ fmt_qty($line->base_qty) }} {{ $line->material->baseUnit->code }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        <dl class="grid gap-2 border-t border-rule p-4 text-sm sm:grid-cols-3">
            <div><dt class="text-ink-3">{{ __('Date') }}</dt><dd>{{ $return->return_date->format('d M Y') }}</dd></div>
            <div><dt class="text-ink-3">{{ __('Store') }}</dt><dd>{{ $return->store->name }}</dd></div>
            <div>
                <dt class="text-ink-3">{{ __('Against') }}</dt>
                <dd>
                    @if ($return->grn)
                        <a href="{{ route('procurement.grns.show', $return->grn) }}" class="link num text-xs">{{ $return->grn->number }}</a>
                    @elseif ($return->issue)
                        <a href="{{ route('procurement.issues.show', $return->issue) }}" class="link num text-xs">{{ $return->issue->number }}</a>
                    @endif
                </dd>
            </div>
            <div><dt class="text-ink-3">{{ __('Reason') }}</dt><dd>{{ $return->reason ?? '—' }}</dd></div>
            <div><dt class="text-ink-3">{{ __('Posted by') }}</dt><dd>{{ $return->creator?->name ?? '—' }}</dd></div>
            @if ($return->isReversed())<div><dt class="text-ink-3">{{ __('Reversal reason') }}</dt><dd>{{ $return->reversal_reason }}</dd></div>@endif
        </dl>
    </section>

    @unless ($return->isReversed())
        @include('modules.procurement.partials.reverse', ['action' => route('procurement.returns.reverse', $return), 'hint' => __('Undoes this return in the stock ledger.')])
    @endunless
@endsection
