@extends('layouts.app')
@section('title', __('Request payment'))
@section('content')
    @php
        $editing = $payment->exists;
        $action = $editing ? route('contractor-control.payments.update', $payment) : route('contractor-control.payments.store', $workOrder);
        $against = old('against', $payment->boq_item_id ? 'item:'.$payment->boq_item_id : ($payment->contractor_milestone_id ? 'part:'.$payment->contractor_milestone_id : ''));
    @endphp

    <x-page-header :title="__('Request payment')" :subtitle="$workOrder->title.' · '.$workOrder->party?->name" />

    <section class="card mb-5 p-4">
        @if ($money)
            <div class="flex flex-wrap items-start justify-between gap-2">
                <p class="font-display text-xl font-semibold {{ $ratio['status'] === 'red' ? 'text-laterite' : ($ratio['status'] === 'green' ? 'text-moss' : 'text-ink-3') }}">{{ $ratio['label'] }}</p>
                @include('modules.contractor_control._status', ['row' => $ratio])
            </div>
            <div class="mt-2">@include('modules.contractor_control._bars', ['work' => $ratio['work_pct'], 'paid' => $ratio['paid_pct'], 'status' => $ratio['status']])</div>
        @else
            @include('modules.contractor_control._bars', ['work' => $ratio['work_pct']])
        @endif
    </section>

    <form method="POST" action="{{ $action }}" enctype="multipart/form-data" class="card space-y-4 p-4">
        @csrf
        @if ($editing)
            @method('PATCH')
        @else
            <input type="hidden" name="client_uuid" value="{{ old('client_uuid', (string) \Illuminate\Support\Str::uuid()) }}">
        @endif

        <div class="grid gap-4 sm:grid-cols-3">
            <x-field :label="__('Date')" name="paid_on"><input id="paid_on" name="paid_on" type="date" max="{{ today()->format('Y-m-d') }}" value="{{ old('paid_on', $payment->paid_on?->format('Y-m-d') ?? today()->format('Y-m-d')) }}" class="input" required></x-field>
            <x-field :label="__('Amount (₹)')" name="amount"><input id="amount" name="amount" type="number" inputmode="decimal" step="0.01" min="0.01" value="{{ old('amount', $money ? $payment->amount : null) }}" class="input num" required></x-field>
            <x-field :label="__('Mode')" name="mode">
                <select id="mode" name="mode" class="input" required>
                    <option value="">{{ __('Choose') }}</option>
                    @foreach (config('modules.contractor_control.payment_modes') as $value => $label)
                        <option value="{{ $value }}" @selected(old('mode', $payment->mode) === $value)>{{ __($label) }}</option>
                    @endforeach
                </select>
            </x-field>
            <x-field :label="__('Paid to')" name="paid_to" :hint="__('Leave empty to pay the contractor.')" class="sm:col-span-2"><input id="paid_to" name="paid_to" maxlength="160" value="{{ old('paid_to', $payment->paid_to) }}" placeholder="{{ $workOrder->party?->name }}" class="input"></x-field>
            <x-field :label="__('Against')" name="against">
                <select id="against" name="against" class="input">
                    <option value="">{{ __('The contract in general') }}</option>
                    <optgroup label="{{ __('Items') }}">
                        @foreach ($workOrder->items as $line)
                            <option value="item:{{ $line->boq_item_id }}" @selected($against === 'item:'.$line->boq_item_id)>{{ $line->boqItem->code }} · {{ \Illuminate\Support\Str::limit($line->boqItem->description, 40) }}</option>
                        @endforeach
                    </optgroup>
                    @if ($parts->isNotEmpty())
                        <optgroup label="{{ __('Parts of the contract') }}">
                            @foreach ($parts as $part)
                                <option value="part:{{ $part->id }}" @selected($against === 'part:'.$part->id)>{{ $part->title }}</option>
                            @endforeach
                        </optgroup>
                    @endif
                </select>
                @error('boq_item_id')<p class="error">{{ $message }}</p>@enderror
                @error('contractor_milestone_id')<p class="error">{{ $message }}</p>@enderror
            </x-field>
            <x-field :label="__('UTR or reference')" name="reference"><input id="reference" name="reference" maxlength="60" value="{{ old('reference', $payment->reference) }}" class="input"></x-field>
            <x-field :label="__('Bill or receipt')" name="bill" :hint="__('Photo or PDF, up to 8 MB.')" class="sm:col-span-2"><input id="bill" name="bill" type="file" accept="image/*,application/pdf" class="input"></x-field>
            <x-field :label="__('Notes')" name="notes" class="sm:col-span-3"><textarea id="notes" name="notes" rows="2" maxlength="1000" class="input">{{ old('notes', $money ? $payment->notes : null) }}</textarea></x-field>
        </div>

        <label class="flex items-center gap-2 text-sm"><input type="hidden" name="is_advance" value="0"><input type="checkbox" name="is_advance" value="1" @checked(old('is_advance', $payment->is_advance))> {{ __('This is an advance') }}</label>

        <div class="flex flex-wrap items-center gap-3">
            <button class="btn btn-primary">{{ __('Send for approval') }}</button>
            <p class="text-xs text-ink-3">{{ __('The project manager approves it. Until then it does not count as paid.') }}</p>
        </div>
    </form>
@endsection
