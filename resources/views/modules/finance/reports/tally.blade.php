@extends('layouts.app')
@section('title', __('Tally export'))
@section('content')
    @php $query = array_filter($filters, fn ($value) => $value !== null && $value !== ''); @endphp
    <x-page-header :title="__('Tally export')" :subtitle="__('Files to import into Tally by hand')" />

    <div class="mb-4 rounded-[3px] border border-neel bg-neel/8 px-3 py-2 text-sm font-medium text-neel" role="note">{{ __('Live two-way sync with Tally is not connected. It needs software running beside Tally on your PC. Use these files instead, and have your accountant check the first import.') }}</div>

    <form method="GET" class="card mb-5 grid items-end gap-3 p-4 sm:grid-cols-5">
        <x-field :label="__('From')" name="from"><input id="from" name="from" type="date" value="{{ $filters['from'] }}" class="input"></x-field>
        <x-field :label="__('To')" name="to"><input id="to" name="to" type="date" value="{{ $filters['to'] }}" class="input"></x-field>
        <x-field :label="__('Project')" name="project_id">
            <select id="project_id" name="project_id" class="input">
                <option value="">{{ __('All projects') }}</option>
                @foreach ($projects as $project)<option value="{{ $project->id }}" @selected($filters['project_id'] === $project->id)>{{ $project->code }} · {{ $project->name }}</option>@endforeach
            </select>
        </x-field>
        <x-field :label="__('Bank ledger name in Tally')" name="bank_ledger"><input id="bank_ledger" name="bank_ledger" value="{{ $filters['bank_ledger'] }}" placeholder="{{ $defaultBank }}" maxlength="100" class="input"></x-field>
        <div><button class="btn btn-ghost">{{ __('Show') }}</button></div>
    </form>

    <section class="card">
        <div class="card-head"><h2 class="card-title">{{ __('What will be exported') }}</h2></div>
        @if ($counts->isEmpty())
            <x-empty :title="__('Nothing to export in this period')" />
        @else
            <dl class="text-sm">
                @foreach ($counts as $type => $count)
                    <div class="flex justify-between border-b border-rule px-4 py-2"><dt>{{ __($type) }}</dt><dd class="num">{{ $count }}</dd></div>
                @endforeach
                <div class="flex justify-between border-b border-rule px-4 py-2 font-semibold"><dt>{{ __('Ledgers') }}</dt><dd class="num">{{ $ledgerCount }}</dd></div>
            </dl>
            <div class="flex flex-wrap gap-2 p-4">
                <a href="{{ route('finance.reports.tally.ledgers', $query) }}" class="btn btn-ghost">{{ __('1. Download ledgers') }}</a>
                <a href="{{ route('finance.reports.tally.vouchers', $query) }}" class="btn btn-primary">{{ __('2. Download vouchers') }}</a>
            </div>
            <p class="px-4 pb-4 text-xs text-ink-3">{{ __('Import ledgers first, then vouchers, from Gateway of Tally: Import Data.') }}</p>
        @endif
    </section>
@endsection
