{{-- One EMD or fee with what can be done to it. $payment has its tender loaded. --}}
@php
    $tone = match ($payment->status) {
        'paid' => 'border-neel text-neel',
        'refunded' => 'border-moss text-moss',
        'forfeited' => 'border-laterite text-laterite',
        'adjusted' => 'border-haldi text-[#8a6208]',
        default => 'border-ink-3 text-ink-3',
    };
    $settled = in_array($payment->status, ['refunded', 'forfeited', 'adjusted'], true);
    $showTender = $showTender ?? false;
@endphp
<div class="border-b border-rule px-4 py-3 text-sm last:border-b-0" x-data="{ edit: false }">
    <div class="flex flex-wrap items-start justify-between gap-x-4 gap-y-1">
        <div class="min-w-0">
            <p class="font-semibold">
                {{ \App\Services\Crm\Vocab::paymentKinds()[$payment->kind] ?? $payment->kind }}
                <span class="num">{{ inr($payment->amount) }}</span>
                <span class="stamp {{ $tone }} ml-1">{{ \App\Services\Crm\Vocab::paymentStatuses()[$payment->status] ?? $payment->status }}</span>
                @if ($payment->refundIsOverdue())<span class="stamp ml-1 border-laterite text-laterite">{{ __('Refund overdue') }}</span>
                @elseif ($payment->refundIsDue())<span class="stamp ml-1 border-haldi text-[#8a6208]">{{ __('Refund due') }}</span>@endif
            </p>
            @if ($showTender && $payment->tender)<p class="text-xs"><a href="{{ route('crm.tenders.show', $payment->tender) }}" class="link">{{ $payment->tender->title }}</a></p>@endif
            <p class="text-xs text-ink-3">
                {{ \App\Services\Crm\Vocab::paymentModes()[$payment->mode] ?? $payment->mode }}@if ($payment->reference) · {{ $payment->reference }}@endif
                @if ($payment->paid_on) · {{ __('Paid') }} {{ $payment->paid_on->format('d M Y') }}@endif
                @if ($payment->valid_until) · {{ __('Valid until') }} <span class="{{ $payment->status === 'paid' && $payment->valid_until->lte(today()->addDays(30)) ? 'font-semibold text-laterite' : '' }}">{{ $payment->valid_until->format('d M Y') }}</span>@endif
                @if ($payment->refund_expected_on && $payment->status === 'paid') · {{ __('Refund expected') }} {{ $payment->refund_expected_on->format('d M Y') }}@endif
                @if ($settled && $payment->settled_on) · {{ $payment->settled_on->format('d M Y') }}@endif
                @if ($payment->status === 'refunded') · {{ __('Refunded') }} {{ inr($payment->refunded_amount) }}@endif
            </p>
            @if ($payment->notes)<p class="whitespace-pre-line text-xs text-ink-2">{{ $payment->notes }}</p>@endif
        </div>
        @can('crm.emd')
            <div class="flex items-center gap-3">
                @unless ($settled)<button type="button" class="link text-xs" @click="edit = !edit">{{ __('Edit') }}</button>@endunless
                @if ($settled)
                    <form method="POST" action="{{ route('crm.payments.undo', $payment) }}" onsubmit="return confirm('{{ __('Put this payment back to paid?') }}')">@csrf<button class="link text-xs">{{ __('Undo') }}</button></form>
                @else
                    <form method="POST" action="{{ route('crm.payments.destroy', $payment) }}" onsubmit="return confirm('{{ __('Remove this payment?') }}')">@csrf @method('DELETE')<button class="link text-xs text-laterite">{{ __('Remove') }}</button></form>
                @endif
            </div>
        @endcan
    </div>

    @can('crm.emd')
        {{-- Fees that never come back have nothing to settle. Tick "Comes back after the tender" in Edit if one does. --}}
        @if ($payment->status === 'paid' && $payment->refundable)
            <details class="mt-2">
                <summary class="link cursor-pointer text-xs">{{ __('Refunded, forfeited or adjusted?') }}</summary>
                <form method="POST" action="{{ route('crm.payments.settle', $payment) }}" x-data="{ outcome: 'refunded' }" class="mt-2 grid gap-2 rounded-[3px] bg-paper-2/40 p-3 sm:grid-cols-2">
                    @csrf
                    <div>
                        <label class="label" for="settle-{{ $payment->id }}">{{ __('What happened') }}</label>
                        <select id="settle-{{ $payment->id }}" name="outcome" x-model="outcome" class="input">
                            <option value="refunded">{{ __('Refunded') }}</option>
                            <option value="forfeited">{{ __('Forfeited') }}</option>
                            <option value="adjusted">{{ __('Adjusted against a security deposit') }}</option>
                        </select>
                    </div>
                    <div>
                        <label class="label" for="settle-on-{{ $payment->id }}">{{ __('On') }}</label>
                        <input id="settle-on-{{ $payment->id }}" name="settled_on" type="date" max="{{ now()->toDateString() }}" value="{{ now()->toDateString() }}" class="input">
                    </div>
                    <div x-show="outcome === 'refunded'">
                        <label class="label" for="settle-amt-{{ $payment->id }}">{{ __('Amount that came back (₹)') }}</label>
                        <input id="settle-amt-{{ $payment->id }}" name="refunded_amount" type="number" step="0.01" min="0" inputmode="decimal" value="{{ $payment->amount }}" class="input num" :disabled="outcome !== 'refunded'">
                    </div>
                    <div class="sm:col-span-2">
                        <label class="label" for="settle-note-{{ $payment->id }}">{{ __('Notes') }}</label>
                        <input id="settle-note-{{ $payment->id }}" name="notes" class="input" maxlength="1000">
                    </div>
                    <div class="sm:col-span-2"><button class="btn btn-primary min-h-10">{{ __('Save') }}</button></div>
                </form>
            </details>
        @endif
        @unless ($settled)
            <form x-show="edit" x-cloak method="POST" action="{{ route('crm.payments.update', $payment) }}" class="mt-2 rounded-[3px] bg-paper-2/40 p-3">
                @csrf @method('PATCH')
                @include('modules.crm.payments.fields', ['payment' => $payment])
                <div class="mt-3 flex gap-2"><button class="btn btn-primary min-h-10">{{ __('Save') }}</button><button type="button" class="btn btn-ghost min-h-10" @click="edit = false">{{ __('Cancel') }}</button></div>
            </form>
        @endunless
    @endcan
</div>
