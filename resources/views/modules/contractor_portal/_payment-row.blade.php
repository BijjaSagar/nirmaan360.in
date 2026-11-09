{{-- One approved payment, as an array from ContractorPortalService::payments(). $withContract names the contract when the list spans several. --}}
<div class="border-b border-rule px-4 py-2.5 last:border-b-0">
    <div class="flex flex-wrap items-center justify-between gap-x-3 gap-y-1">
        <span class="text-sm">
            <span class="num text-xs text-ink-3">{{ $payment['number'] }}</span>
            {{ \Illuminate\Support\Carbon::parse($payment['paid_on'])->format('d M Y') }}
            @if ($payment['is_advance']) · <span class="text-xs text-haldi">{{ __('Advance') }}</span>@endif
            @if ($payment['is_reversal']) · <span class="text-xs text-laterite">{{ __('Reversal') }}</span>@endif
        </span>
        <span class="num text-sm font-medium {{ $payment['is_reversal'] ? 'text-laterite' : '' }}">{{ inr($payment['amount']) }}</span>
    </div>
    <p class="mt-0.5 text-xs text-ink-3">
        {{ $payment['mode_label'] }}
        @if ($payment['reference']) · {{ $payment['reference'] }}@endif
        @if ($payment['against']) · {{ __('Against :item', ['item' => $payment['against']]) }}@endif
        @if ($withContract ?? false)
            · <a href="{{ route('contractor-portal.contracts.show', $payment['work_order_id']) }}" class="link">{{ $payment['work_order_number'] }}</a> · {{ $payment['project'] }}
        @endif
    </p>
</div>
