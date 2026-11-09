@extends('layouts.app')
@section('title', $payment->number)
@section('content')
    @php($modes = config('modules.contractor_control.payment_modes'))

    <x-page-header :title="$payment->number" :subtitle="$workOrder->title.' · '.$payment->party?->name">
        <x-stamp :status="$payment->status" />
        @if ($payment->isReversal())<span class="stamp border-laterite text-laterite">{{ __('Reversal') }}</span>@endif
    </x-page-header>

    <div class="grid gap-5 lg:grid-cols-2">
        <section class="card">
            <div class="card-head"><h2 class="card-title">{{ __('Payment') }}</h2></div>
            <dl class="grid grid-cols-2 gap-x-4 gap-y-3 p-4 text-sm">
                @if ($money)
                    <div class="col-span-2"><dt class="text-xs text-ink-3">{{ __('Amount') }}</dt><dd class="num text-2xl font-medium {{ $payment->isReversal() ? 'text-laterite' : '' }}">{{ inr($payment->amount) }}</dd></div>
                    <div><dt class="text-xs text-ink-3">{{ __('Mode') }}</dt><dd>{{ __($modes[$payment->mode] ?? $payment->mode) }}</dd></div>
                    <div><dt class="text-xs text-ink-3">{{ __('Paid to') }}</dt><dd>{{ $payment->paid_to }}</dd></div>
                    @if ($payment->reference)<div><dt class="text-xs text-ink-3">{{ __('UTR or reference') }}</dt><dd class="num">{{ $payment->reference }}</dd></div>@endif
                    <div><dt class="text-xs text-ink-3">{{ __('Type') }}</dt><dd>{{ $payment->is_advance ? __('Advance') : __('Against work') }}</dd></div>
                @endif
                <div><dt class="text-xs text-ink-3">{{ __('Date') }}</dt><dd class="num">{{ $payment->paid_on->format('d M Y') }}</dd></div>
                <div><dt class="text-xs text-ink-3">{{ __('Entered by') }}</dt><dd>{{ $payment->enteredBy?->name ?? '—' }}</dd></div>
                <div class="col-span-2"><dt class="text-xs text-ink-3">{{ __('Against') }}</dt>
                    <dd>
                        @if ($payment->boqItem){{ $payment->boqItem->code }} · {{ $payment->boqItem->description }}
                        @elseif ($payment->milestone){{ $payment->milestone->title }}
                        @else {{ __('The contract in general') }}
                        @endif
                    </dd>
                </div>
                @if ($money && $payment->notes)
                    <div class="col-span-2"><dt class="text-xs text-ink-3">{{ __('Notes') }}</dt><dd class="whitespace-pre-line">{{ $payment->notes }}</dd></div>
                @endif
                @if ($money && $payment->bill_path)
                    <div class="col-span-2"><a href="{{ route('contractor-control.payments.bill', $payment) }}" target="_blank" rel="noopener" class="link">{{ __('Open the bill or receipt') }}</a></div>
                @endif
            </dl>
            @if ($canEdit)
                <div class="border-t border-rule p-4"><a href="{{ route('contractor-control.payments.edit', $payment) }}" class="btn btn-primary">{{ __('Correct and send again') }}</a></div>
            @endif
        </section>

        <div class="space-y-5">
            <section class="card p-4">
                @if ($money)
                    <p class="eyebrow text-ink-3">{{ __('Work against paid') }}</p>
                    <div class="mt-1 flex flex-wrap items-start justify-between gap-2">
                        <p class="font-display text-[26px] leading-tight font-semibold {{ $impact['now']['status'] === 'red' ? 'text-laterite' : ($impact['now']['status'] === 'green' ? 'text-moss' : 'text-ink-3') }}">{{ $impact['now']['label'] }}</p>
                        @include('modules.contractor_control._status', ['row' => $impact['now']])
                    </div>
                    <div class="mt-2">@include('modules.contractor_control._bars', ['work' => $impact['now']['work_pct'], 'paid' => $impact['now']['paid_pct'], 'status' => $impact['now']['status']])</div>
                    @if ($impact['after'])
                        <p class="mt-2 text-sm font-medium {{ $impact['after']['status'] === 'red' ? 'text-laterite' : 'text-moss' }}">{{ __('If approved: :label', ['label' => $impact['after']['label']]) }}</p>
                    @endif
                    <dl class="mt-3 grid grid-cols-2 gap-x-4 gap-y-1 text-xs">
                        <div><dt class="text-ink-3">{{ __('Balance work') }}</dt><dd class="num">{{ inr($impact['now']['balance_work_value']) }}</dd></div>
                        <div><dt class="text-ink-3">{{ __('Balance money') }}</dt><dd class="num">{{ inr($impact['now']['balance_money']) }}</dd></div>
                    </dl>
                    @if ($impact['item'])
                        <div class="mt-3 border-t border-rule pt-2">
                            <p class="text-xs text-ink-3">{{ __('This item') }} · {{ $impact['item']['boq_item']->code }}</p>
                            <p class="text-sm font-semibold {{ $impact['item']['status'] === 'red' ? 'text-laterite' : 'text-moss' }}">{{ $impact['item']['label'] }}</p>
                        </div>
                    @endif
                @else
                    <p class="eyebrow text-ink-3">{{ __('Work done') }}</p>
                    <div class="mt-2">@include('modules.contractor_control._bars', ['work' => $ratio['work_pct']])</div>
                @endif
            </section>

            <x-approval-trail :approvals="$payment->approvals" />
        </div>
    </div>

    @can('contractor.approve')
        @if ($payment->status === 'APPROVED' && ! $payment->isReversal() && ! $payment->reversal)
            <details class="card mt-5">
                <summary class="card-head cursor-pointer"><span class="card-title">{{ __('Reverse this payment') }}</span></summary>
                <form method="POST" action="{{ route('contractor-control.payments.reverse', $payment) }}" class="space-y-3 p-4" onsubmit="return confirm(@js(__('Reverse this payment?')))">
                    @csrf
                    <x-field :label="__('Reason')" name="reason"><textarea id="reason" name="reason" rows="2" maxlength="500" class="input" required>{{ old('reason') }}</textarea></x-field>
                    <button class="btn btn-danger">{{ __('Reverse') }}</button>
                </form>
            </details>
        @endif
    @endcan
    @if ($payment->reversal)
        <p class="mt-4 text-sm text-ink-3">{{ __('Reversed by :number.', ['number' => $payment->reversal->number]) }} <a href="{{ route('contractor-control.payments.show', $payment->reversal) }}" class="link">{{ __('Open') }}</a></p>
    @endif
@endsection
