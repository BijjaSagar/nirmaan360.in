@extends('layouts.app')
@section('title', __('Sales overview'))
@section('content')
    @php
        $stages = \App\Services\Crm\Vocab::stages();
        $money = $summary['money'];
        $top = max(1, ...array_column($summary['pipeline'], 'count'));
    @endphp
    <x-page-header :title="__('Sales overview')" :subtitle="__('Tenders, follow-ups and money held')">
        @can('crm.manage')
            <a href="{{ route('crm.leads.create') }}" class="btn btn-ghost">{{ __('Add lead') }}</a>
            <a href="{{ route('crm.tenders.create') }}" class="btn btn-primary">{{ __('Add tender') }}</a>
        @endcan
    </x-page-header>

    <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
        @include('modules.crm.partials.stat', ['label' => __('Open tenders'), 'value' => $summary['open_count'], 'note' => '₹'.\App\Support\Dec::indian($summary['open_value'], 0)])
        @include('modules.crm.partials.stat', ['label' => __('Your follow-ups'), 'value' => $summary['task_counts']['overdue'] + $summary['task_counts']['today'], 'note' => __(':overdue overdue, :today today', ['overdue' => $summary['task_counts']['overdue'], 'today' => $summary['task_counts']['today']]), 'accent' => $summary['task_counts']['overdue'] > 0])
        @include('modules.crm.partials.stat', ['label' => __('EMD held'), 'amount' => $money['held'], 'note' => $summary['refunds_due'] > 0 ? __('Refunds due: :count', ['count' => $summary['refunds_due']]) : __('Refundable'), 'accent' => $summary['refunds_due'] > 0])
        @include('modules.crm.partials.stat', ['label' => __('Open leads'), 'value' => $summary['open_leads'], 'note' => __(':count new', ['count' => $summary['new_leads']])])
    </div>

    <div class="mt-5 grid gap-5 lg:grid-cols-2">
        <section class="card">
            <div class="card-head"><h2 class="card-title">{{ __('Pipeline') }}</h2><a href="{{ route('crm.tenders.index') }}" class="link text-xs">{{ __('Open board') }}</a></div>
            <div class="space-y-3 p-4">
                @foreach ($summary['pipeline'] as $stage => $row)
                    <a href="{{ route('crm.tenders.index') }}#{{ $stage }}" class="block">
                        <div class="flex items-baseline justify-between text-sm"><span class="font-semibold">{{ $stages[$stage] }}</span><span class="num text-xs text-ink-3">{{ $row['count'] }} · {{ inr($row['value']) }}</span></div>
                        <div class="mt-1 h-2 rounded-full bg-paper-2"><div class="h-2 rounded-full bg-neel" style="width: {{ $row['count'] === 0 ? 0 : max(4, round($row['count'] / $top * 100)) }}%"></div></div>
                    </a>
                @endforeach
            </div>
        </section>

        <section class="card">
            <div class="card-head"><h2 class="card-title">{{ __('Deadlines in the next 14 days') }}</h2></div>
            @forelse ($summary['deadlines'] as $tender)
                @php $state = $tender->deadlineState(); @endphp
                <a href="{{ route('crm.tenders.show', $tender) }}" class="flex items-center justify-between gap-3 border-b border-rule px-4 py-2.5 text-sm last:border-b-0 hover:bg-paper/70">
                    <span class="min-w-0"><span class="block truncate font-semibold">{{ $tender->title }}</span><span class="block truncate text-xs text-ink-3">{{ $tender->authority }}</span></span>
                    <span class="num shrink-0 text-xs {{ $state === 'overdue' ? 'font-semibold text-laterite' : ($state === 'soon' ? 'font-semibold text-[#8a6208]' : 'text-ink-3') }}">{{ $state === 'overdue' ? __('Overdue') : '' }} {{ $tender->due_at->format('d M, h:i A') }}</span>
                </a>
            @empty
                <p class="px-4 py-5 text-sm text-ink-3">{{ __('No submission deadlines coming up.') }}</p>
            @endforelse
            @foreach ($summary['pre_bids'] as $tender)
                <a href="{{ route('crm.tenders.show', $tender) }}" class="flex items-center justify-between gap-3 border-b border-rule bg-paper-2/30 px-4 py-2.5 text-sm last:border-b-0 hover:bg-paper/70">
                    <span class="min-w-0 truncate"><span class="eyebrow mr-1 text-ink-3">{{ __('Pre-bid') }}</span>{{ $tender->title }}</span>
                    <span class="num shrink-0 text-xs text-ink-3">{{ $tender->pre_bid_at->format('d M, h:i A') }}</span>
                </a>
            @endforeach
        </section>

        <section class="card">
            <div class="card-head"><h2 class="card-title">{{ __('Your follow-ups') }}</h2><a href="{{ route('crm.tasks.index') }}" class="link text-xs">{{ __('All follow-ups') }}</a></div>
            @forelse ($summary['due_tasks'] as $task)
                <div class="flex items-center justify-between gap-3 border-b border-rule px-4 py-2.5 text-sm last:border-b-0">
                    <span class="min-w-0 truncate">{{ $task->title }}</span>
                    <span class="num shrink-0 text-xs {{ $task->isOverdue() ? 'font-semibold text-laterite' : 'text-ink-3' }}">{{ $task->due_on->format('d M') }}</span>
                </div>
            @empty
                <p class="px-4 py-5 text-sm text-ink-3">{{ __('Nothing due in the next two days.') }}</p>
            @endforelse
        </section>

        <section class="card">
            <div class="card-head"><h2 class="card-title">{{ __('Money with authorities') }}</h2><a href="{{ route('crm.payments.index') }}" class="link text-xs">{{ __('EMD & fees') }}</a></div>
            <dl class="grid grid-cols-2 gap-px bg-rule text-sm">
                <div class="bg-sheet px-4 py-2.5"><dt class="eyebrow text-ink-3">{{ __('Held') }}</dt><dd class="num">{{ inr($money['held']) }}</dd></div>
                <div class="bg-sheet px-4 py-2.5"><dt class="eyebrow text-ink-3">{{ __('Refund due') }}</dt><dd class="num {{ \App\Support\Dec::of($money['refund_due'])->isPositive() ? 'font-semibold text-laterite' : '' }}">{{ inr($money['refund_due']) }}</dd></div>
            </dl>
            @foreach ($summary['expiring'] as $payment)
                <a href="{{ route('crm.tenders.show', $payment->tender) }}#payments" class="flex items-center justify-between gap-3 border-t border-rule px-4 py-2.5 text-sm hover:bg-paper/70">
                    <span class="min-w-0 truncate">{{ \App\Services\Crm\Vocab::paymentModes()[$payment->mode] ?? $payment->mode }} {{ inr($payment->amount) }} · {{ $payment->tender->title }}</span>
                    <span class="num shrink-0 text-xs {{ $payment->valid_until->isPast() ? 'font-semibold text-laterite' : 'text-[#8a6208]' }}">{{ __('Valid until') }} {{ $payment->valid_until->format('d M') }}</span>
                </a>
            @endforeach
        </section>
    </div>
@endsection
