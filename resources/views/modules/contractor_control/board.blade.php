@extends('layouts.app')
@section('title', __('Work vs payment'))
@section('content')
    <x-page-header :title="__('Work vs payment')" :subtitle="$project->name" />

    @if ($money)
        <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
            @include('modules.contractor_control._stat', ['label' => __('Contract value'), 'value' => inr($summary['contract_value'])])
            @include('modules.contractor_control._stat', ['label' => __('Paid'), 'value' => inr($summary['paid'])])
            @include('modules.contractor_control._stat', ['label' => __('Balance money'), 'value' => inr($summary['balance_money'])])
            @include('modules.contractor_control._stat', ['label' => __('Paid ahead'), 'value' => $summary['ahead'], 'accent' => $summary['over_limit'] > 0, 'note' => __(':count over the limit', ['count' => $summary['over_limit']])])
        </div>
    @endif

    @can('contractor.manage')
        <details class="card mt-5">
            <summary class="card-head cursor-pointer"><span class="card-title">{{ __('Alert limit and deadline') }}</span><span class="num text-xs text-ink-3">{{ \App\Services\ContractorControl\Figures::pct($project->payment_gap_alert_pct) }}%</span></summary>
            <form method="POST" action="{{ route('contractor-control.settings', $project) }}" class="grid items-end gap-3 p-4 sm:grid-cols-3">
                @csrf @method('PATCH')
                <x-field :label="__('Alert when paid is ahead of work by more than (%)')" name="payment_gap_alert_pct">
                    <input id="payment_gap_alert_pct" name="payment_gap_alert_pct" type="number" step="0.01" min="0" max="100" value="{{ old('payment_gap_alert_pct', (float) $project->payment_gap_alert_pct) }}" class="input num" required>
                </x-field>
                <x-field :label="__('Project deadline')" name="deadline_date">
                    <input id="deadline_date" name="deadline_date" type="date" value="{{ old('deadline_date', $project->deadline_date ? \Illuminate\Support\Carbon::parse($project->deadline_date)->format('Y-m-d') : '') }}" class="input">
                </x-field>
                <div><button class="btn btn-primary">{{ __('Save') }}</button></div>
            </form>
        </details>
    @endcan

    <section class="card mt-5">
        <div class="card-head">
            <h2 class="card-title">{{ __('Contracts') }}</h2>
            <span class="text-xs text-ink-3">{{ __('Only approved payments count.') }}</span>
        </div>
        @forelse ($contracts as $row)
            <div class="border-b border-rule px-4 py-3 last:border-b-0">
                <div class="flex flex-wrap items-start justify-between gap-x-3 gap-y-1">
                    <div class="min-w-0">
                        <a href="{{ route('contractor-control.contracts.show', $row['work_order']) }}" class="link">{{ $row['work_order']->title }}</a>
                        <p class="text-xs text-ink-3"><span class="num">{{ $row['work_order']->number }}</span> · {{ $row['party']?->name }}</p>
                    </div>
                    @if ($money)
                        @include('modules.contractor_control._status', ['row' => $row])
                    @endif
                </div>

                @if ($money)
                    <p class="mt-1.5 font-display text-xl font-semibold {{ $row['status'] === 'red' ? 'text-laterite' : ($row['status'] === 'green' ? 'text-moss' : 'text-ink-3') }}">{{ $row['label'] }}</p>
                @endif

                <div class="mt-1.5">
                    @include('modules.contractor_control._bars', $money ? ['work' => $row['work_pct'], 'paid' => $row['paid_pct'], 'status' => $row['status']] : ['work' => $row['work_pct']])
                </div>

                @if ($money)
                    <dl class="mt-2 grid grid-cols-2 gap-x-4 gap-y-1 text-xs sm:grid-cols-4">
                        <div><dt class="text-ink-3">{{ __('Contract') }}</dt><dd class="num">{{ inr($row['contract_value']) }}</dd></div>
                        <div><dt class="text-ink-3">{{ __('Paid') }}</dt><dd class="num">{{ inr($row['paid']) }}</dd></div>
                        <div><dt class="text-ink-3">{{ __('Balance work') }}</dt><dd class="num">{{ inr($row['balance_work_value']) }}</dd></div>
                        <div><dt class="text-ink-3">{{ __('Balance money') }}</dt><dd class="num {{ \App\Support\Dec::of($row['short_by'])->isPositive() ? 'text-laterite' : '' }}">{{ inr($row['balance_money']) }}</dd></div>
                    </dl>
                @endif

                @if (! $row['work_order']->isClosed() && auth()->user()->can('contractor.request'))
                    <a href="{{ route('contractor-control.payments.create', $row['work_order']) }}" class="btn btn-ghost mt-2 py-1 text-xs">{{ __('Request payment') }}</a>
                @endif
            </div>
        @empty
            <x-empty :title="__('No contracts yet')" :note="__('Award a contractor tender, or issue a work order, and it shows up here.')">
                @can('contractor.tender')
                    <a href="{{ route('contractor-control.tenders.index', $project) }}" class="btn btn-primary">{{ __('Contractor tenders') }}</a>
                @endcan
            </x-empty>
        @endforelse
    </section>
@endsection
