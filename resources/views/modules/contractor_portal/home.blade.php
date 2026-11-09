@extends('modules.contractor_portal.layout')
@section('title', __('My work orders'))
@section('content')
    @php($company = auth()->user()->organization->name)
    <x-page-header :title="$party->name" :subtitle="__('Your work orders with :company', ['company' => $company])" />

    @if ($summary['contracts'] === 0)
        <div class="card">
            <x-empty :title="__('No work orders yet')" :note="__('When :company issues you a work order, it shows here with the work done, the payments made and the bills raised against it.', ['company' => $company])" />
        </div>
    @else
        <section class="card p-4">
            <div class="flex flex-wrap items-start justify-between gap-2">
                <p class="font-display text-[28px] leading-tight font-semibold {{ $summary['status'] === 'red' ? 'text-laterite' : ($summary['status'] === 'green' ? 'text-moss' : 'text-ink-3') }}">{{ $summary['label'] }}</p>
                @include('modules.contractor_portal._status', ['row' => $summary])
            </div>
            <div class="mt-2">@include('modules.contractor_portal._bars', ['work' => $summary['work_pct'], 'paid' => $summary['paid_pct'], 'status' => $summary['status']])</div>
            <p class="mt-2 text-xs text-ink-3">{{ __('Work % is the value of work recorded on site against the contract value. Paid % is what has been paid against it.') }}</p>
        </section>

        <div class="mt-5 grid grid-cols-2 gap-3 sm:grid-cols-4">
            @include('modules.contractor_portal._stat', ['label' => __('Total contract'), 'value' => inr($summary['contract_value'])])
            @include('modules.contractor_portal._stat', ['label' => __('Paid till date'), 'value' => inr($summary['paid'])])
            @include('modules.contractor_portal._stat', ['label' => __('Of which advance'), 'value' => inr($summary['advance'])])
            @include('modules.contractor_portal._stat', ['label' => __('Balance to receive'), 'value' => inr($summary['balance_money']), 'note' => __(':amount of work still to do', ['amount' => inr($summary['balance_work_value'])])])
        </div>

        <section class="card mt-5">
            <div class="card-head">
                <h2 class="card-title">{{ __('My work orders') }}</h2>
                <span class="text-xs text-ink-3">{{ trans_choice(':count contract|:count contracts', $summary['contracts'], ['count' => $summary['contracts']]) }}</span>
            </div>
            @foreach ($contracts as $row)
                <a href="{{ route('contractor-portal.contracts.show', $row['work_order']) }}" class="block border-b border-rule px-4 py-3 last:border-b-0 hover:bg-paper/70">
                    <div class="flex flex-wrap items-start justify-between gap-x-3 gap-y-1">
                        <div class="min-w-0">
                            <p class="text-sm font-semibold">{{ $row['title'] }}</p>
                            <p class="text-xs text-ink-3"><span class="num">{{ $row['number'] }}</span> · {{ $row['project_name'] }}</p>
                        </div>
                        <div class="flex items-center gap-1.5">
                            @if ($row['contract_status'] === 'CLOSED')<x-stamp status="closed" />@endif
                            @include('modules.contractor_portal._status', ['row' => $row])
                        </div>
                    </div>
                    <p class="mt-1 text-sm font-semibold {{ $row['status'] === 'red' ? 'text-laterite' : ($row['status'] === 'green' ? 'text-moss' : 'text-ink-3') }}">{{ $row['label'] }}</p>
                    <div class="mt-1.5">@include('modules.contractor_portal._bars', ['work' => $row['work_pct'], 'paid' => $row['paid_pct'], 'status' => $row['status']])</div>
                    <dl class="mt-2 grid grid-cols-3 gap-x-4 text-xs">
                        <div><dt class="text-ink-3">{{ __('Contract') }}</dt><dd class="num">{{ inr($row['contract_value']) }}</dd></div>
                        <div><dt class="text-ink-3">{{ __('Paid') }}</dt><dd class="num">{{ inr($row['paid']) }}</dd></div>
                        <div><dt class="text-ink-3">{{ __('Balance') }}</dt><dd class="num">{{ inr($row['balance_money']) }}</dd></div>
                    </dl>
                    <div class="mt-1.5">@include('modules.contractor_portal._timing', ['row' => $row])</div>
                </a>
            @endforeach
        </section>
    @endif
@endsection
