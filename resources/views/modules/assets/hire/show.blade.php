@extends('layouts.app')
@section('title', $bill->number)
@php
    $unitLabel = ['hour' => __('hours'), 'day' => __('days'), 'km' => __('km'), 'month' => __('months')][$bill->basis] ?? '';
    $calc = [
        'basis' => $bill->basis, 'rate' => $bill->rate, 'actual_quantity' => $bill->actual_quantity,
        'minimum_quantity' => \App\Support\Dec::of($bill->quantity)->isGreaterThan($bill->actual_quantity) ? $bill->quantity : null,
        'quantity' => $bill->quantity, 'gross_amount' => $bill->gross_amount, 'hire_type' => $bill->hire_type,
        'fuel_litres' => $bill->fuel_litres, 'fuel_recovery' => $bill->fuel_recovery, 'other_deduction' => $bill->other_deduction,
        'gst_pct' => $bill->gst_pct, 'gst_amount' => $bill->gst_amount, 'tds_pct' => $bill->tds_pct, 'tds_amount' => $bill->tds_amount,
        'net_payable' => $bill->net_payable,
    ];
@endphp
@section('content')
    <x-page-header :title="$bill->number" :subtitle="$bill->asset->code.' · '.$bill->asset->name.' · '.$bill->period_from->format('d M Y').' – '.$bill->period_to->format('d M Y')">
        @include('modules.field_ops._chip', ['set' => 'bill', 'value' => $bill->status])
        <button type="button" class="btn btn-ghost" onclick="window.print()">{{ __('Print') }}</button>
    </x-page-header>

    <div class="grid gap-5 lg:grid-cols-3">
        <div class="space-y-5 lg:col-span-2">
            <section class="card">
                <div class="card-head"><h2 class="card-title">{{ __('Working') }}</h2></div>
                @include('modules.assets.hire._calc', ['calc' => $calc, 'unitLabel' => $unitLabel, 'note' => $bill->other_deduction_note])
            </section>

            <section class="card overflow-x-auto">
                <div class="card-head"><h2 class="card-title">{{ __('Machine log in this bill') }}</h2><span class="num text-sm">{{ $bill->logs->count() }}</span></div>
                @if ($bill->logs->isEmpty())
                    <p class="px-4 py-4 text-sm text-ink-3">{{ __('No logs. This bill is worked out from the calendar.') }}</p>
                @else
                    <table class="ledger min-w-[480px]">
                        <thead><tr><th>{{ __('Date') }}</th><th class="r">{{ __('Hours') }}</th><th class="r">{{ __('Km') }}</th><th class="r">{{ __('Lost') }}</th></tr></thead>
                        <tbody>
                        @foreach ($bill->logs as $log)
                            <tr><td class="num">{{ $log->log_date->format('d M Y') }}</td><td class="r">{{ fmt_qty($log->hours) }}</td><td class="r">{{ fmt_qty($log->km) }}</td><td class="r">{{ fmt_qty($log->breakdown_hours) }}</td></tr>
                        @endforeach
                        </tbody>
                    </table>
                @endif
            </section>
        </div>

        <div class="space-y-5 print:hidden">
            <section class="card p-4 text-sm">
                <dl class="space-y-2">
                    <div><dt class="eyebrow text-ink-3">{{ __('Owner of the machine') }}</dt><dd>{{ $bill->vendor?->name ?? '—' }}</dd></div>
                    @if ($bill->vendor_invoice_no)<div><dt class="eyebrow text-ink-3">{{ __('Owner\'s invoice no.') }}</dt><dd class="num">{{ $bill->vendor_invoice_no }}</dd></div>@endif
                    <div><dt class="eyebrow text-ink-3">{{ __('Prepared by') }}</dt><dd>{{ $bill->creator?->name ?? '—' }}</dd></div>
                    @if ($bill->paid_on)<div><dt class="eyebrow text-ink-3">{{ __('Paid on') }}</dt><dd class="num">{{ $bill->paid_on->format('d M Y') }}@if ($bill->payment_reference) · {{ $bill->payment_reference }}@endif</dd></div>@endif
                    @if ($bill->cancel_reason)<div><dt class="eyebrow text-ink-3">{{ __('Cancelled because') }}</dt><dd>{{ $bill->cancel_reason }}</dd></div>@endif
                </dl>
            </section>

            @if ($bill->status === 'DRAFT')
                <section class="card p-4">
                    <p class="mb-3 text-sm text-ink-2">{{ __('Check the figures, then finalise. A final bill cannot be edited.') }}</p>
                    <form method="POST" action="{{ route('hire-bills.finalize', $bill) }}">@csrf<button class="btn btn-primary w-full">{{ __('Finalise bill') }}</button></form>
                </section>
            @endif

            @if ($bill->status === 'FINAL')
                <section class="card p-4">
                    <form method="POST" action="{{ route('hire-bills.paid', $bill) }}" class="space-y-3">
                        @csrf
                        <x-field :label="__('Paid on')" name="paid_on"><input id="paid_on" name="paid_on" type="date" max="{{ now()->format('Y-m-d') }}" value="{{ old('paid_on', now()->format('Y-m-d')) }}" class="input" required></x-field>
                        <x-field :label="__('Payment reference')" name="payment_reference"><input id="payment_reference" name="payment_reference" value="{{ old('payment_reference') }}" class="input" maxlength="80"></x-field>
                        <button class="btn btn-primary w-full">{{ __('Mark as paid') }}</button>
                    </form>
                </section>
            @endif

            @if (in_array($bill->status, ['DRAFT', 'FINAL'], true))
                <section class="card p-4">
                    <form method="POST" action="{{ route('hire-bills.cancel', $bill) }}" class="space-y-3">
                        @csrf
                        <x-field :label="__('Cancel this bill')" name="reason" :hint="__('Its machine logs go back to unbilled.')"><input id="reason" name="reason" value="{{ old('reason') }}" class="input" maxlength="255" required placeholder="{{ __('Why?') }}"></x-field>
                        <button class="btn btn-danger w-full">{{ __('Cancel bill') }}</button>
                    </form>
                </section>
            @endif
        </div>
    </div>
@endsection
