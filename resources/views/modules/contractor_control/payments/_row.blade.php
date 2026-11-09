{{-- For people with money.view. $running is optional. --}}
<a href="{{ route('contractor-control.payments.show', $payment) }}" class="block border-b border-rule px-4 py-2.5 last:border-b-0 hover:bg-paper/70">
    <div class="flex flex-wrap items-center justify-between gap-x-3 gap-y-1">
        <span class="text-sm"><span class="num text-xs text-ink-3">{{ $payment->number }}</span> {{ $payment->paid_on->format('d M Y') }}@if ($payment->is_advance) · <span class="text-xs text-haldi">{{ __('Advance') }}</span>@endif @if ($payment->isReversal()) · <span class="text-xs text-laterite">{{ __('Reversal') }}</span>@endif</span>
        <span class="num text-sm font-medium {{ $payment->isReversal() ? 'text-laterite' : '' }}">{{ inr($payment->amount) }}</span>
    </div>
    <div class="mt-0.5 flex flex-wrap items-center justify-between gap-x-3 gap-y-1 text-xs text-ink-3">
        <span>{{ $payment->paid_to }} · {{ __(config('modules.contractor_control.payment_modes.'.$payment->mode, $payment->mode)) }}@if ($payment->boqItem) · {{ $payment->boqItem->code }}@elseif ($payment->milestone) · {{ $payment->milestone->title }}@endif @if ($payment->enteredBy) · {{ $payment->enteredBy->name }}@endif</span>
        @isset($running)
            <span class="num">{{ __('Balance :amount', ['amount' => inr($running['balance'])]) }}</span>
        @else
            <x-stamp :status="$payment->status" />
        @endisset
    </div>
</a>
