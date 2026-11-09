@extends('layouts.app')
@section('title', __('Raise note'))
@section('content')
    <x-page-header :title="__('Raise note')" :subtitle="$bill->number.' · '.($bill->party?->name ?? '—')" />

    <form method="POST" action="{{ route('bill-notes.store', $bill) }}" class="card max-w-2xl">
        @csrf
        <input type="hidden" name="client_uuid" value="{{ $clientUuid }}">
        <div class="grid gap-4 p-4 sm:grid-cols-2">
            <fieldset class="sm:col-span-2">
                <legend class="label">{{ __('Type of note') }}</legend>
                <div class="grid gap-2 sm:grid-cols-2">
                    <label class="flex items-start gap-2 rounded-[3px] border border-rule p-3 text-sm has-checked:border-laterite">
                        <input type="radio" name="kind" value="credit" class="mt-0.5" required @checked(old('kind', 'credit') === 'credit')>
                        <span><span class="block font-semibold">{{ __('Credit note') }}</span><span class="block text-xs text-ink-3">{{ $bill->type === 'client' ? __('Lowers what the client owes us.') : __('Raises what we owe the subcontractor.') }}</span></span>
                    </label>
                    <label class="flex items-start gap-2 rounded-[3px] border border-rule p-3 text-sm has-checked:border-laterite">
                        <input type="radio" name="kind" value="debit" class="mt-0.5" required @checked(old('kind') === 'debit')>
                        <span><span class="block font-semibold">{{ __('Debit note') }}</span><span class="block text-xs text-ink-3">{{ $bill->type === 'client' ? __('Raises what the client owes us.') : __('Lowers what we owe the subcontractor.') }}</span></span>
                    </label>
                </div>
                @error('kind')<p class="error">{{ $message }}</p>@enderror
            </fieldset>
            <x-field :label="__('Note date')" name="note_date"><input id="note_date" name="note_date" type="date" max="{{ now()->format('Y-m-d') }}" value="{{ old('note_date', now()->format('Y-m-d')) }}" class="input" required></x-field>
            <x-field :label="__('Amount before GST (₹)')" name="taxable_amount" :hint="(float) $gstPct > 0 ? __('GST at :pct% is added, as on the bill.', ['pct' => (float) $gstPct]) : __('The bill carries no GST, so none is added.')">
                <input id="taxable_amount" name="taxable_amount" type="number" step="0.01" min="0.01" value="{{ old('taxable_amount') }}" class="input num" required>
            </x-field>
            <x-field :label="__('Reason')" name="reason" class="sm:col-span-2" :hint="__('For a change in measured quantity, reverse the measurement instead.')"><textarea id="reason" name="reason" rows="3" maxlength="1000" class="input" required>{{ old('reason') }}</textarea></x-field>
        </div>
        <div class="flex flex-wrap items-center justify-between gap-3 border-t border-rule px-4 py-3">
            <p class="text-sm text-ink-3">{{ __('This bill can be reduced by up to :amount, with GST.', ['amount' => inr($reducible)]) }}</p>
            <button class="btn btn-primary">{{ __('Save as draft') }}</button>
        </div>
    </form>
@endsection
