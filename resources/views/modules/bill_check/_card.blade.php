{{--
    The pre-check card on the RA bill page. Needs $bill.
    Everything shown comes through CheckPresenter, which leaves rupee figures, contract percentages and
    words from contract terms out for a reader without money.view. This card is not part of the client's
    sign-off page. A bill that can no longer be checked and never was has no card (CheckPresenter::card).
--}}
@php
    $billCheck = app(\App\Services\BillCheck\CheckPresenter::class)->card($bill, auth()->user());
    $check = $billCheck['check'] ?? null;
    $sheet = $check['expected'] ?? null;
    $levels = [
        'blocking' => ['border-laterite text-laterite', __('Does not match')],
        'tick' => ['border-haldi text-[#8a6208]', __('Look at this')],
        'note' => ['border-ink-3 text-ink-3', __('For information')],
    ];
@endphp
@if ($billCheck !== null)
<section class="card" id="bill-check">
    <div class="card-head">
        <h2 class="card-title">{{ __('Bill pre-check') }}</h2>
        @if ($billCheck['can']['run'])
            <form method="POST" action="{{ route('bill-checks.run', $bill) }}">
                @csrf
                <button class="btn btn-ghost min-h-11 py-1 text-xs">{{ $check ? __('Check again') : __('Check this bill') }}</button>
            </form>
        @endif
    </div>

    @if ($check === null)
        <p class="px-4 py-3 text-sm text-ink-3">{{ __('This bill has not been checked yet.') }}</p>
    @else
        <p class="border-b border-rule px-4 py-2 text-xs text-ink-3">
            {{ __('Checked on :date by :name', ['date' => \Illuminate\Support\Carbon::parse($check['created_at'])->format('d M Y, H:i'), 'name' => $check['run_by']['name'] ?? '—']) }}
        </p>

        @if ($check['summary']['to_acknowledge'] > 0)
            <p class="border-b border-rule bg-haldi/10 px-4 py-2 text-sm font-medium text-[#8a6208]" role="status">{{ __('Findings still to be acknowledged: :count', ['count' => $check['summary']['to_acknowledge']]) }}</p>
        @endif

        @forelse ($check['flags'] as $flag)
            <div class="border-b border-rule px-4 py-3 text-sm">
                <span class="stamp {{ $levels[$flag['level']][0] }}">{{ $levels[$flag['level']][1] }}</span>
                <p class="mt-1.5">{{ $flag['text'] }}</p>

                @if ($flag['needs_ack'])
                    @if ($flag['ack'])
                        <p class="mt-1.5 text-xs text-moss">{{ __('Acknowledged by :name on :date', ['name' => $flag['ack']['user']['name'] ?? '—', 'date' => \Illuminate\Support\Carbon::parse($flag['ack']['created_at'])->format('d M Y')]) }}</p>
                        @if ($flag['ack']['remark'] !== null)
                            <p class="text-xs text-ink-2">{{ $flag['ack']['remark'] }}</p>
                        @endif
                    @elseif ($billCheck['can']['acknowledge'])
                        <form method="POST" action="{{ route('bill-checks.acks.store', $check['id']) }}" class="mt-2 flex flex-wrap items-center gap-2">
                            @csrf
                            <input type="hidden" name="flag_key" value="{{ $flag['key'] }}">
                            <input name="remark" class="input min-h-11 min-w-0 flex-1" required minlength="{{ \App\Services\BillCheck\BillChecks::REMARK_MIN }}" maxlength="500"
                                   placeholder="{{ __('Your remark on this finding') }}" aria-label="{{ __('Your remark on this finding') }}">
                            <button class="btn btn-ghost min-h-11">{{ __('Acknowledge') }}</button>
                        </form>
                    @else
                        <p class="mt-1.5 text-xs text-ink-3">{{ __('Not acknowledged') }}</p>
                    @endif
                @endif
            </div>
        @empty
            <p class="border-b border-rule px-4 py-3 text-sm">{{ __('These checks found nothing on this bill.') }}</p>
        @endforelse

        @include('modules.bill_check._explanation', ['checkId' => $check['id']])

        @if ($sheet !== null && $sheet['lines'] !== null)
            <div class="border-b border-rule">
                <h3 class="eyebrow px-4 pt-3 text-ink-3">
                    {{ $sheet['party'] === 'client' ? __('Deductions your client is expected to make that are not on this bill') : __('Deductions you are expected to make that are not on this bill') }}
                </h3>
                @foreach ($sheet['lines'] as $line)
                    <div class="border-b border-rule px-4 py-2 text-sm last:border-b-0">
                        <div class="flex items-baseline justify-between gap-3">
                            <span class="font-semibold">{{ $line['name'] }}</span>
                            <span class="num whitespace-nowrap">{{ $line['amount'] === null ? __('No figure') : inr($line['amount']) }}</span>
                        </div>
                        <p class="text-xs text-ink-3">
                            @if ($line['method'] === 'percent')
                                {{ __(':pct of :amount', ['pct' => $line['value'], 'amount' => inr($line['base_amount'])]) }}
                            @else
                                {{ $line['value'] }}
                            @endif
                            @foreach ($line['notes'] as $note) · {{ $note }} @endforeach
                            @if ($line['earlier_amount'] !== null) · {{ __('Counted on earlier bills: :amount', ['amount' => inr($line['earlier_amount'])]) }} @endif
                        </p>
                        <p class="text-xs text-ink-3">{{ $line['source_ref'] ?? '—' }} · {{ $line['period'] }}</p>
                    </div>
                @endforeach
                <dl class="border-t border-rule text-sm">
                    <div class="flex justify-between px-4 py-1.5"><dt>{{ __('Total of these') }}</dt><dd class="num whitespace-nowrap">{{ inr($sheet['total']) }}</dd></div>
                    <div class="flex justify-between px-4 py-1.5"><dt>{{ __('Net payable on this bill') }}</dt><dd class="num whitespace-nowrap">{{ inr($sheet['net_payable']) }}</dd></div>
                    <div class="flex justify-between bg-paper-2/60 px-4 py-2 font-semibold"><dt>{{ $sheet['party'] === 'client' ? __('Expected receipt') : __('Expected payment') }}</dt><dd class="num whitespace-nowrap">{{ inr($sheet['expected_receipt']) }}</dd></div>
                </dl>
                <p class="px-4 py-2 text-xs text-ink-3">
                    {{ __('Each of these is worked out on its own from this bill. One does not lower the amount another is worked out on.') }}
                    @if ($sheet['unpriced'] > 0) {{ __('Without a figure here, because the amount is worked out on each bill: :count.', ['count' => $sheet['unpriced']]) }} @endif
                </p>
            </div>
        @elseif ($sheet === null)
            <p class="border-b border-rule px-4 py-2 text-xs text-ink-3">{{ __('No other deduction heads are entered for this contract.') }}</p>
        @endif
    @endif

    <div class="space-y-1 px-4 py-3 text-xs text-ink-3">
        <p>{{ __('An empty list means these checks found nothing. It does not mean the bill will be passed.') }}</p>
        <p>{{ __('The percentages are the ones entered for this contract on the dates shown.') }}</p>
        @can('money.view')
            <p>{{ __('Every rupee in the check is worked out by the app from your records and the percentages entered for this contract.') }}</p>
        @endcan
    </div>

    @if ($billCheck['can']['set_policy'])
        <form method="POST" action="{{ route('bill-checks.policy') }}" class="flex flex-wrap items-center justify-between gap-2 border-t border-rule bg-paper/50 px-4 py-3 text-xs">
            @csrf @method('PATCH')
            <input type="hidden" name="required" value="{{ $billCheck['required'] ? 0 : 1 }}">
            <p class="min-w-0 flex-1 text-ink-3">
                {{ $billCheck['required']
                    ? __('Your company requires this check: a bill can be submitted only after every finding is acknowledged.')
                    : __('This check is optional in your company. A bill can be submitted without it.') }}
            </p>
            <button class="btn btn-ghost min-h-11 py-1 text-xs">{{ $billCheck['required'] ? __('Make it optional') : __('Require it before submitting') }}</button>
        </form>
    @elseif ($billCheck['required'])
        <p class="border-t border-rule bg-paper/50 px-4 py-3 text-xs text-ink-3">{{ __('Your company requires this check: a bill can be submitted only after every finding is acknowledged.') }}</p>
    @endif
</section>
@endif
