{{-- Work against paid, beside the approve and reject buttons in the approvals inbox. --}}
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
    </div>
@endcan
