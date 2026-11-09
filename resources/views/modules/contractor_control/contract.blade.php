@extends('layouts.app')
@section('title', $workOrder->number)
@section('content')
    <x-page-header :title="$workOrder->title" :subtitle="$workOrder->number.' · '.$workOrder->party?->name">
        @if (! $workOrder->isClosed() && auth()->user()->can('contractor.request'))
            <a href="{{ route('contractor-control.payments.create', $workOrder) }}" class="btn btn-primary">{{ __('Request payment') }}</a>
        @endif
    </x-page-header>

    <section class="card p-4">
        @if ($money)
            <div class="flex flex-wrap items-start justify-between gap-2">
                <p class="font-display text-[28px] leading-tight font-semibold {{ $ratio['status'] === 'red' ? 'text-laterite' : ($ratio['status'] === 'green' ? 'text-moss' : 'text-ink-3') }}">{{ $ratio['label'] }}</p>
                @include('modules.contractor_control._status', ['row' => $ratio])
            </div>
            <div class="mt-2">@include('modules.contractor_control._bars', ['work' => $ratio['work_pct'], 'paid' => $ratio['paid_pct'], 'status' => $ratio['status']])</div>
            @if ($ratio['status'] === 'red')
                <p class="mt-2 text-sm font-medium text-laterite">{{ __('Payment is ahead of work by :gap%. Finish the work first.', ['gap' => \App\Services\ContractorControl\Figures::pct($ratio['gap_pct'])]) }}</p>
            @elseif ($ratio['status'] === 'none')
                <p class="mt-2 text-sm text-ink-3">{{ __('This work order has no quantities, so it has no contract value to compare against.') }}</p>
            @endif
        @else
            @include('modules.contractor_control._bars', ['work' => $ratio['work_pct']])
        @endif
    </section>

    @if ($money)
        <div class="mt-5 grid grid-cols-2 gap-3 sm:grid-cols-5">
            @include('modules.contractor_control._stat', ['label' => __('Contract value'), 'value' => inr($ratio['contract_value'])])
            @include('modules.contractor_control._stat', ['label' => __('Paid till date'), 'value' => inr($ratio['paid'])])
            @include('modules.contractor_control._stat', ['label' => __('Of which advance'), 'value' => inr($ratio['advance'])])
            @include('modules.contractor_control._stat', ['label' => __('Balance money'), 'value' => inr($ratio['balance_money'])])
            @include('modules.contractor_control._stat', ['label' => __('Balance work'), 'value' => inr($ratio['balance_work_value']), 'accent' => \App\Support\Dec::of($ratio['short_by'])->isPositive(), 'note' => \App\Support\Dec::of($ratio['short_by'])->isPositive() ? __('Short by :amount', ['amount' => inr($ratio['short_by'])]) : __(':pct% of work left', ['pct' => \App\Services\ContractorControl\Figures::pct($ratio['balance_work_pct'])])])
        </div>
        @if (\App\Support\Dec::of($financePaid)->isPositive())
            <p class="mt-2 text-xs text-ink-3">{{ __(':amount paid against this contractor\'s RA bills in Finance is a separate record and is not counted here.', ['amount' => inr($financePaid)]) }}</p>
        @endif
    @endif

    <section class="card mt-5">
        <div class="card-head"><h2 class="card-title">{{ __('Item-wise') }}</h2></div>
        @foreach ($lines as $line)
            <div class="border-b border-rule px-4 py-3 last:border-b-0">
                <div class="flex flex-wrap items-start justify-between gap-x-3 gap-y-1">
                    <div class="min-w-0">
                        <p class="text-sm"><span class="num text-xs text-ink-3">{{ $line['boq_item']->code }}</span> {{ $line['boq_item']->description }}</p>
                        @if ($money)
                            <p class="num mt-0.5 text-xs text-ink-3">{{ fmt_qty($line['line']->qty) }} {{ $line['boq_item']->unit }} × {{ inr($line['rate']) }} = {{ inr($line['contract_value']) }}</p>
                        @endif
                    </div>
                    @if ($money)
                        @include('modules.contractor_control._status', ['row' => $line])
                    @endif
                </div>
                <div class="mt-1.5">
                    @include('modules.contractor_control._bars', $money ? ['work' => $line['work_pct'], 'paid' => $line['paid_pct'], 'status' => $line['status']] : ['work' => $line['work_pct']])
                </div>
            </div>
        @endforeach
        @if ($money && \App\Support\Dec::of($unallocated)->isPositive())
            <p class="border-t border-rule px-4 py-2 text-xs text-ink-3">{{ __('Paid but not tied to an item: :amount', ['amount' => inr($unallocated)]) }}</p>
        @endif
    </section>

    <section class="card mt-5">
        <div class="card-head">
            <h2 class="card-title">{{ __('Parts of the contract') }}</h2>
            @if ($money && $parts !== [])
                <span class="num text-xs text-ink-3">{{ inr($partsTotal) }}</span>
            @endif
        </div>
        @forelse ($parts as $row)
            <div class="flex flex-wrap items-center justify-between gap-x-3 gap-y-1 border-b border-rule px-4 py-2.5 last:border-b-0">
                <div class="min-w-0">
                    <p class="text-sm">{{ $row['part']->title }}</p>
                    @if ($money)
                        <p class="num text-xs text-ink-3">{{ __('Pay :amount', ['amount' => inr($row['amount'])]) }} · {{ __('Paid :amount', ['amount' => inr($row['paid'])]) }}
                            @if ($row['state'] === 'over')
                                <span class="font-medium text-laterite">· {{ __('Over by :amount', ['amount' => inr(\App\Support\Dec::of($row['balance'])->abs())]) }}</span>
                            @endif
                        </p>
                        @if ($row['paid_before_done'])
                            <p class="text-xs font-medium text-laterite">{{ __('Paid before this part was marked done.') }}</p>
                        @endif
                    @endif
                </div>
                <div class="flex items-center gap-2">
                    @if ($row['part']->isDone())
                        <span class="stamp border-moss text-moss">{{ __('Done') }}</span>
                    @else
                        <span class="stamp border-ink-3 text-ink-3">{{ __('Not done') }}</span>
                    @endif
                    @can('contractor.manage')
                        <form method="POST" action="{{ route('contractor-control.milestones.toggle', $row['part']) }}">@csrf @method('PATCH')<button class="btn btn-ghost py-1 text-xs">{{ $row['part']->isDone() ? __('Undo') : __('Mark done') }}</button></form>
                        @if ($money && \App\Support\Dec::of($row['paid'])->isZero())
                            <form method="POST" action="{{ route('contractor-control.milestones.destroy', $row['part']) }}" onsubmit="return confirm(@js(__('Remove this part?')))">@csrf @method('DELETE')<button class="btn btn-ghost py-1 text-xs">{{ __('Remove') }}</button></form>
                        @endif
                    @endcan
                </div>
            </div>
        @empty
            <p class="px-4 py-5 text-sm text-ink-3">{{ __('No parts yet. Split the contract into parts and say how much to pay for each.') }}</p>
        @endforelse
        @can('contractor.manage')
            <form method="POST" action="{{ route('contractor-control.milestones.store', $workOrder) }}" class="grid items-end gap-3 border-t border-rule p-4 sm:grid-cols-4">
                @csrf
                <input type="hidden" name="client_uuid" value="{{ \Illuminate\Support\Str::uuid() }}">
                <x-field :label="__('Part')" name="title" class="sm:col-span-2"><input id="title" name="title" value="{{ old('title') }}" maxlength="160" class="input" placeholder="{{ __('For example: Plinth complete') }}" required></x-field>
                <x-field :label="__('Pay for this part (₹)')" name="amount"><input id="amount" name="amount" type="number" step="0.01" min="0.01" value="{{ old('amount') }}" class="input num" required></x-field>
                <div><button class="btn btn-ghost">{{ __('Add part') }}</button></div>
            </form>
        @endcan
    </section>

    @if ($money)
        <section class="card mt-5">
            <div class="card-head"><h2 class="card-title">{{ __('Payment history') }}</h2></div>
            @forelse ($history as $entry)
                @include('modules.contractor_control.payments._row', ['payment' => $entry['payment'], 'running' => $entry])
            @empty
                <x-empty :title="__('No payments yet')" :note="__('Nothing has been approved for this contract.')" />
            @endforelse
        </section>

        @if ($notCounted->isNotEmpty())
            <section class="card mt-5">
                <div class="card-head"><h2 class="card-title">{{ __('Not counted as paid') }}</h2></div>
                @foreach ($notCounted as $payment)
                    @include('modules.contractor_control.payments._row', ['payment' => $payment])
                @endforeach
            </section>
        @endif
    @endif

    @can('contractor.manage')
        <section class="card mt-5">
            <div class="card-head"><h2 class="card-title">{{ __('Fixed deadline') }}</h2></div>
            <form method="POST" action="{{ route('contractor-control.contracts.deadline', $workOrder) }}" class="grid items-end gap-3 p-4 sm:grid-cols-3">
                @csrf @method('PATCH')
                <x-field :label="__('Finish by')" name="end_date"><input id="end_date" name="end_date" type="date" value="{{ old('end_date', $workOrder->end_date?->format('Y-m-d')) }}" class="input"></x-field>
                <x-field :label="__('Why this date')" name="deadline_label"><input id="deadline_label" name="deadline_label" value="{{ old('deadline_label', $workOrder->deadline_label) }}" maxlength="80" class="input" placeholder="{{ __('For example: before Lakshmi Puja') }}"></x-field>
                <div><button class="btn btn-ghost">{{ __('Save') }}</button></div>
            </form>
        </section>
    @endcan
@endsection
