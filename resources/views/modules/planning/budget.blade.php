@extends('layouts.app')
@section('title', __('Budget'))
@section('content')
    <x-page-header :title="__('Budget')" :subtitle="$project->name" />

    @include('modules.planning.partials.tabs')

    <section class="card">
        <div class="card-head"><h2 class="card-title">{{ __('Budget against actual') }}</h2></div>
        <div class="overflow-x-auto">
            <table class="ledger min-w-[720px]">
                <thead>
                <tr>
                    <th>{{ __('Cost head') }}</th><th class="r">{{ __('Baseline') }}</th><th class="r">{{ __('Current budget') }}</th><th class="r">{{ __('Actual') }}</th><th class="r">{{ __('Variance') }}</th><th class="r">{{ __('Used') }}</th>
                    @can('planning.manage')<th></th>@endcan
                </tr>
                </thead>
                <tbody>
                @foreach ($report['rows'] as $row)
                    <tr x-data="{ edit: false }">
                        <td>{{ __($row['label']) }} @if ($row['notes'])<span class="block text-xs text-ink-3">{{ $row['notes'] }}</span>@endif</td>
                        <td class="r">{{ $row['baseline'] === null ? '—' : inr($row['baseline']) }}</td>
                        <td class="r">
                            <span x-show="!edit">{{ inr($row['budget']) }}</span>
                            @can('planning.manage')
                                <form x-show="edit" x-cloak method="POST" action="{{ route('planning.budget.store', $project) }}" class="flex justify-end gap-1">
                                    @csrf
                                    <input type="hidden" name="head" value="{{ $row['head'] }}">
                                    <input name="amount" type="number" step="0.01" min="0" value="{{ $row['budget'] }}" class="input num w-32 text-right" aria-label="{{ __('Current budget') }}" required>
                                    <button class="btn btn-primary">{{ __('Save') }}</button>
                                </form>
                            @endcan
                        </td>
                        <td class="r">{{ inr($row['actual']) }}</td>
                        <td class="r {{ $row['over'] ? 'text-laterite' : '' }}">{{ inr($row['variance']) }}</td>
                        <td class="r">{{ $row['used_pct'] === null ? '—' : $row['used_pct'].'%' }}</td>
                        @can('planning.manage')
                            <td class="text-right"><button type="button" class="link text-xs" @click="edit = !edit">{{ __('Edit') }}</button></td>
                        @endcan
                    </tr>
                @endforeach
                <tr class="font-semibold">
                    <td>{{ __('Total') }}</td>
                    <td class="r">{{ $report['totals']['baseline'] === null ? '—' : inr($report['totals']['baseline']) }}</td>
                    <td class="r">{{ inr($report['totals']['budget']) }}</td>
                    <td class="r">{{ inr($report['totals']['actual']) }}</td>
                    <td class="r {{ $report['totals']['over'] ? 'text-laterite' : '' }}">{{ inr($report['totals']['variance']) }}</td>
                    <td class="r">{{ $report['totals']['used_pct'] === null ? '—' : $report['totals']['used_pct'].'%' }}</td>
                    @can('planning.manage')<td></td>@endcan
                </tr>
                </tbody>
            </table>
        </div>
        <p class="border-t border-rule px-4 py-2 text-xs text-ink-3">{{ __('Actual cost adds approved subcontractor bills, wages of your own workers from attendance, petty cash expenses, and the costs entered below. Variance is against the baseline once one is saved.') }}</p>
    </section>

    <section class="card mt-5">
        <div class="card-head"><h2 class="card-title">{{ __('Other costs') }}</h2></div>
        @can('planning.manage')
            <form method="POST" action="{{ route('planning.costs.store', $project) }}" class="grid gap-3 border-b border-rule p-4 sm:grid-cols-6">
                @csrf
                <input type="hidden" name="client_uuid" value="{{ \Illuminate\Support\Str::uuid() }}">
                <x-field :label="__('Cost head')" name="head" class="sm:col-span-2">
                    <select id="head" name="head" class="input" required>
                        @foreach ($heads as $key => $label)<option value="{{ $key }}">{{ __($label) }}</option>@endforeach
                    </select>
                </x-field>
                <x-field :label="__('Date')" name="entry_date" class="sm:col-span-2"><input id="entry_date" name="entry_date" type="date" max="{{ now()->toDateString() }}" value="{{ old('entry_date', now()->toDateString()) }}" class="input num" required></x-field>
                <x-field :label="__('Amount (₹)')" name="amount" class="sm:col-span-2"><input id="amount" name="amount" type="number" step="0.01" min="0.01" value="{{ old('amount') }}" class="input num" required></x-field>
                <x-field :label="__('Description')" name="description" class="sm:col-span-4"><input id="description" name="description" value="{{ old('description') }}" class="input" maxlength="255"></x-field>
                <div class="flex items-end sm:col-span-2"><button class="btn btn-primary w-full">{{ __('Record cost') }}</button></div>
            </form>
        @endcan
        @if ($entries->isEmpty())
            <p class="px-4 py-5 text-sm text-ink-3">{{ __('No other costs recorded yet.') }}</p>
        @else
            <div class="overflow-x-auto">
                <table class="ledger min-w-[560px]">
                    <thead><tr><th>{{ __('Date') }}</th><th>{{ __('Cost head') }}</th><th class="w-[40%]">{{ __('Description') }}</th><th class="r">{{ __('Amount') }}</th>@can('planning.manage')<th></th>@endcan</tr></thead>
                    <tbody>
                    @foreach ($entries as $entry)
                        <tr>
                            <td class="num whitespace-nowrap">{{ $entry->entry_date->format('d M Y') }}</td>
                            <td>{{ __($heads[$entry->head] ?? $entry->head) }}</td>
                            <td>
                                {{ $entry->description ?? '—' }}
                                @if ($entry->isReversal())
                                    <span class="block text-xs text-laterite">{{ __('Reverses the entry of :date. Reason: :reason', ['date' => $entry->reversalOf?->entry_date->format('d M Y') ?? '—', 'reason' => $entry->reversal_reason]) }}</span>
                                @elseif ($entry->reversal)
                                    <span class="block text-xs text-ink-3">{{ __('Reversed on :date', ['date' => $entry->reversal->entry_date->format('d M Y')]) }}</span>
                                @endif
                            </td>
                            <td class="r {{ $entry->isReversal() ? 'text-laterite' : '' }}">{{ inr($entry->amount) }}</td>
                            @can('planning.manage')
                                <td class="text-right">
                                    @if (! $entry->isReversal() && ! $entry->reversal)
                                        <details class="text-left">
                                            <summary class="link cursor-pointer text-xs">{{ __('Reverse') }}</summary>
                                            <form method="POST" action="{{ route('planning.costs.reverse', $entry) }}" class="mt-2 flex min-w-56 flex-col gap-2" onsubmit="return confirm(@js(__('Reverse this cost? A second entry for the opposite amount is added, and both stay on record.')))">
                                                @csrf
                                                <textarea name="reason" rows="2" maxlength="500" class="input" required aria-label="{{ __('Reason') }}" placeholder="{{ __('Why is this cost being reversed?') }}"></textarea>
                                                <button class="btn btn-danger">{{ __('Reverse') }}</button>
                                            </form>
                                        </details>
                                    @endif
                                </td>
                            @endcan
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            <div class="px-4 py-3">{{ $entries->links() }}</div>
        @endif
    </section>
@endsection
