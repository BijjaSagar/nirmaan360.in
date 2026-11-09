{{-- Fields for adding or editing an EMD or fee. $payment is null when adding. --}}
@php
    $p = $payment ? 'pay'.$payment->id.'-' : 'pay-new-';
    $show = $payment === null;
    $value = fn (string $key, $fallback = null) => $show ? old($key, $fallback) : ($payment->{$key} ?? $fallback);
    $date = fn ($d) => $d ? \Illuminate\Support\Carbon::parse($d)->toDateString() : '';
@endphp
<div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
    <div>
        <label class="label" for="{{ $p }}kind">{{ __('Type') }}</label>
        <select id="{{ $p }}kind" name="kind" class="input">
            @foreach (\App\Services\Crm\Vocab::paymentKinds() as $key => $label)<option value="{{ $key }}" @selected($value('kind', 'emd') === $key)>{{ $label }}</option>@endforeach
        </select>
        @if ($show)@error('kind')<p class="error">{{ $message }}</p>@enderror @endif
    </div>
    <div>
        <label class="label" for="{{ $p }}amount">{{ __('Amount (₹)') }}</label>
        <input id="{{ $p }}amount" name="amount" type="number" step="0.01" min="0.01" inputmode="decimal" value="{{ $value('amount') }}" class="input num" required>
        @if ($show)@error('amount')<p class="error">{{ $message }}</p>@enderror @endif
    </div>
    <div>
        <label class="label" for="{{ $p }}mode">{{ __('Paid by') }}</label>
        <select id="{{ $p }}mode" name="mode" class="input">
            @foreach (\App\Services\Crm\Vocab::paymentModes() as $key => $label)<option value="{{ $key }}" @selected($value('mode', 'online') === $key)>{{ $label }}</option>@endforeach
        </select>
    </div>
    <div>
        <label class="label" for="{{ $p }}reference">{{ __('Reference or number') }}</label>
        <input id="{{ $p }}reference" name="reference" value="{{ $value('reference') }}" class="input" maxlength="120">
    </div>
    <div>
        <label class="label" for="{{ $p }}status">{{ __('Status') }}</label>
        <select id="{{ $p }}status" name="status" class="input">
            <option value="paid" @selected($value('status', 'paid') === 'paid')>{{ __('Paid') }}</option>
            <option value="planned" @selected($value('status') === 'planned')>{{ __('To pay') }}</option>
        </select>
    </div>
    <div>
        <label class="label" for="{{ $p }}paid_on">{{ __('Paid on') }}</label>
        <input id="{{ $p }}paid_on" name="paid_on" type="date" max="{{ now()->toDateString() }}" value="{{ $date($value('paid_on')) }}" class="input">
        @if ($show)@error('paid_on')<p class="error">{{ $message }}</p>@enderror @endif
    </div>
    <div>
        <label class="label" for="{{ $p }}valid_until">{{ __('Valid until (guarantee or FDR)') }}</label>
        <input id="{{ $p }}valid_until" name="valid_until" type="date" value="{{ $date($value('valid_until')) }}" class="input">
    </div>
    <div>
        <label class="label" for="{{ $p }}refund_expected_on">{{ __('Refund expected on') }}</label>
        <input id="{{ $p }}refund_expected_on" name="refund_expected_on" type="date" value="{{ $date($value('refund_expected_on')) }}" class="input">
    </div>
    <div class="flex items-end pb-1.5">
        <input type="hidden" name="refundable" value="0">
        <label class="flex min-h-9 items-center gap-2 text-sm font-semibold text-ink-2">
            <input type="checkbox" name="refundable" value="1" class="size-4" @checked((bool) $value('refundable', true))>
            {{ __('Comes back after the tender') }}
        </label>
    </div>
    <div class="sm:col-span-2 lg:col-span-3">
        <label class="label" for="{{ $p }}notes">{{ __('Notes') }}</label>
        <input id="{{ $p }}notes" name="notes" value="{{ $value('notes') }}" class="input" maxlength="1000">
    </div>
</div>
