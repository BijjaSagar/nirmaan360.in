{{-- Work against paid, beside the approve and reject buttons in the approvals inbox. $check is PaymentAheadGuard::assess(). --}}
@can('money.view')
    @php($impact = app(\App\Services\ContractorControl\WorkPaymentRatio::class)->impact($payment))
    <div class="mt-2 rounded-[3px] border border-rule bg-paper/60 px-3 py-2">
        <div class="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-1">
            <p class="text-sm font-semibold {{ $impact['now']['status'] === 'red' ? 'text-laterite' : 'text-moss' }}">{{ $impact['now']['label'] }}</p>
            <p class="num text-sm">{{ inr($payment->amount) }}</p>
        </div>
        <div class="mt-1.5">@include('modules.contractor_control._bars', ['work' => $impact['now']['work_pct'], 'paid' => $impact['now']['paid_pct'], 'status' => $impact['now']['status']])</div>
        @if ($impact['after'])
            <p class="mt-1.5 text-xs {{ $impact['after']['status'] === 'red' ? 'text-laterite' : 'text-ink-3' }}">{{ __('If approved: :label', ['label' => $impact['after']['label']]) }}</p>
        @endif
        <dl class="mt-2 grid grid-cols-1 gap-x-4 gap-y-1 text-xs sm:grid-cols-3">
            <div><dt class="text-ink-3">{{ __('Value of work done') }}</dt><dd class="num">{{ inr($check['work_done']) }}</dd></div>
            <div><dt class="text-ink-3">{{ __('Paid so far') }}</dt><dd class="num">{{ inr($check['paid']) }}</dd></div>
            <div><dt class="text-ink-3">{{ __('Paid if approved') }}</dt><dd class="num {{ $check['blocked'] ? 'text-laterite' : '' }}">{{ inr($check['paid_after']) }}</dd></div>
        </dl>
    </div>
@endcan
@if ($check['blocked'])
    <p class="mt-2 rounded-[3px] border border-laterite bg-laterite/10 px-3 py-2 text-sm font-medium text-laterite-dark" role="note">
        @if ($finalLevel)
            {{-- Paid % and the gap are money: without money.view the reason comes with no figure. --}}
            {{ \App\Services\ContractorControl\PaymentAheadGuard::blockedMessage($check, auth()->user()->can('money.view')) }}
        @else
            {{ __('As it stands, the last approval of this payment will need the owner, with a written reason: it would put payment ahead of the work done beyond this project\'s limit.') }}
        @endif
    </p>
@endif
