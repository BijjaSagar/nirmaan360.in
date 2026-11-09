@extends('layouts.app')
@section('title', __('Cost variance'))
@section('content')
    @php
        use App\Services\ContractorControl\Figures;
        use App\Support\Dec;
    @endphp
    <x-page-header :title="__('Cost variance')" :subtitle="$project->name" />

    <div class="grid grid-cols-2 gap-3 sm:grid-cols-3">
        @include('modules.contractor_control._stat', ['label' => __('BOQ amount'), 'value' => inr($boq), 'note' => __('For items with payments')])
        @include('modules.contractor_control._stat', ['label' => __('Actual paid'), 'value' => inr($actual)])
        @include('modules.contractor_control._stat', ['label' => __('Variance'), 'value' => Figures::signedInr($variance), 'accent' => Dec::of($variance)->isPositive()])
    </div>

    <section class="card mt-5">
        <div class="card-head"><h2 class="card-title">{{ __('Item-wise') }}</h2><span class="text-xs text-ink-3">{{ __('Actual − BOQ') }}</span></div>
        @forelse ($rows as $row)
            <div class="border-b border-rule px-4 py-3 last:border-b-0">
                <div class="flex flex-wrap items-start justify-between gap-x-3 gap-y-1">
                    <p class="min-w-0 text-sm"><span class="num text-xs text-ink-3">{{ $row['item']->code }}</span> {{ $row['item']->description }}</p>
                    @if ($row['variance'] === null)
                        <span class="stamp border-ink-3 text-ink-3">{{ __('Nothing paid yet') }}</span>
                    @else
                        <span class="num font-medium {{ Dec::of($row['variance'])->isPositive() ? 'text-laterite' : 'text-moss' }}">{{ Figures::signedInr($row['variance']) }}@if ($row['variance_pct'] !== null) <span class="text-xs">({{ Figures::signedPct($row['variance_pct']) }}%)</span>@endif</span>
                    @endif
                </div>
                <dl class="mt-2 grid grid-cols-2 gap-x-4 gap-y-1 text-xs sm:grid-cols-4">
                    <div><dt class="text-ink-3">{{ __('BOQ') }}</dt><dd class="num">{{ fmt_qty($row['item']->qty) }} {{ $row['item']->unit }} · {{ inr($row['boq_amount']) }}</dd></div>
                    <div><dt class="text-ink-3">{{ __('Contract') }}</dt><dd class="num">{{ inr($row['contract_amount']) }}</dd></div>
                    <div><dt class="text-ink-3">{{ __('Actual paid') }}</dt><dd class="num">{{ inr($row['actual']) }}</dd></div>
                    <div><dt class="text-ink-3">{{ __('Quantity done') }}</dt><dd class="num">{{ fmt_qty($row['completed_qty']) }} {{ $row['item']->unit }} · {{ Figures::pct($row['work_pct']) }}%</dd></div>
                </dl>
            </div>
        @empty
            <x-empty :title="__('Nothing to compare yet')" :note="__('Items under a contract appear here once they are in a work order.')" />
        @endforelse
        @if (Dec::of($unallocated)->isPositive())
            <p class="border-t border-rule px-4 py-2 text-xs text-ink-3">{{ __('Paid but not tied to an item: :amount', ['amount' => inr($unallocated)]) }}</p>
        @endif
    </section>
@endsection
