@extends('layouts.app')
@section('title', __('Contractor ledger'))
@section('content')
    <x-page-header :title="__('Contractor ledger')" :subtitle="__('Every contractor, across every project you can see')" />

    <section class="card">
        @forelse ($contractors as $row)
            <a href="{{ route('contractor-control.contractors.show', $row['party']) }}" class="block border-b border-rule px-4 py-3 last:border-b-0 hover:bg-paper/70">
                <div class="flex flex-wrap items-start justify-between gap-x-3 gap-y-1">
                    <div class="min-w-0">
                        <p class="font-semibold">{{ $row['party']?->name }}</p>
                        <p class="text-xs text-ink-3">{{ trans_choice(':count contract|:count contracts', $row['contracts'], ['count' => $row['contracts']]) }}@if ($row['contracts_ahead'] > 0) · <span class="font-medium text-laterite">{{ trans_choice(':count ahead of work|:count ahead of work', $row['contracts_ahead'], ['count' => $row['contracts_ahead']]) }}</span>@endif</p>
                    </div>
                    @include('modules.contractor_control._status', ['row' => $row])
                </div>
                <p class="mt-1.5 font-display text-xl font-semibold {{ $row['status'] === 'red' ? 'text-laterite' : ($row['status'] === 'green' ? 'text-moss' : 'text-ink-3') }}">{{ $row['label'] }}</p>
                <dl class="mt-1.5 grid grid-cols-2 gap-x-4 gap-y-1 text-xs sm:grid-cols-4">
                    <div><dt class="text-ink-3">{{ __('Contract') }}</dt><dd class="num">{{ inr($row['contract_value']) }}</dd></div>
                    <div><dt class="text-ink-3">{{ __('Paid') }}</dt><dd class="num">{{ inr($row['paid']) }}</dd></div>
                    <div><dt class="text-ink-3">{{ __('Advance') }}</dt><dd class="num">{{ inr($row['advance']) }}</dd></div>
                    <div><dt class="text-ink-3">{{ __('Balance') }}</dt><dd class="num">{{ inr($row['balance_money']) }}</dd></div>
                </dl>
            </a>
        @empty
            <x-empty :title="__('No contractors yet')" :note="__('A contractor appears here once they hold a work order.')" />
        @endforelse
    </section>
@endsection
