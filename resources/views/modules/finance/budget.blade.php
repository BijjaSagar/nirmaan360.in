@extends('layouts.app')
@section('title', __('Budget versus actual'))
@section('content')
    <x-page-header :title="__('Budget versus actual')" :subtitle="$project->name" />
    @include('modules.finance._tabs')

    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <x-stat :label="__('Contract value')" :value="inr($report['contract_value'])" />
        <x-stat :label="__('Billed to client')" :value="inr($report['billed'])" :note="__('Before GST and deductions')" />
        <x-stat :label="__('Actual cost')" :value="inr($report['total_actual'])" :note="__('Before GST')" />
        <x-stat :label="__('Billed less cost')" :value="inr($report['margin'])" :accent="\App\Support\Dec::of($report['margin'])->isNegative()" />
    </div>

    <form method="POST" action="{{ route('finance.project.budget.update', $project) }}" class="card mt-5">
        @csrf
        @method('PATCH')
        <div class="overflow-x-auto">
            <table class="ledger min-w-[640px]">
                <thead><tr><th>{{ __('Cost head') }}</th><th class="r">{{ __('Budget (₹)') }}</th><th class="r">{{ __('Actual') }}</th><th class="r">{{ __('Remaining') }}</th><th class="r">{{ __('Used') }}</th></tr></thead>
                <tbody>
                @foreach ($report['rows'] as $row)
                    <tr>
                        <td>{{ __($row['head']) }}</td>
                        <td class="r">
                            @can('finance.manage')
                                <input name="budget[{{ $row['head'] }}]" type="number" step="0.01" min="0" value="{{ \App\Support\Dec::of($row['budget'])->isZero() ? '' : $row['budget'] }}" class="input num ml-auto max-w-40 text-right" aria-label="{{ __('Budget for :head', ['head' => __($row['head'])]) }}">
                            @else
                                {{ inr($row['budget']) }}
                            @endcan
                        </td>
                        <td class="r">{{ inr($row['actual']) }}</td>
                        <td class="r {{ \App\Support\Dec::of($row['variance'])->isNegative() ? 'text-laterite' : '' }}">{{ inr($row['variance']) }}</td>
                        <td class="r">{{ $row['used_pct'] !== null ? $row['used_pct'].'%' : '—' }}</td>
                    </tr>
                @endforeach
                </tbody>
                <tfoot>
                <tr class="font-semibold"><td class="px-3 py-2">{{ __('Total') }}</td><td class="num px-3 py-2 text-right whitespace-nowrap">{{ inr($report['total_budget']) }}</td><td class="num px-3 py-2 text-right whitespace-nowrap">{{ inr($report['total_actual']) }}</td><td class="num px-3 py-2 text-right whitespace-nowrap">{{ inr($report['total_variance']) }}</td><td></td></tr>
                </tfoot>
            </table>
        </div>
        @can('finance.manage')
            <div class="p-4"><button class="btn btn-primary">{{ __('Save budget') }}</button></div>
        @endcan
    </form>
    <p class="mt-2 text-xs text-ink-3">{{ __('Actual is approved vendor bills, approved subcontractor RA bills and petty cash spent.') }}</p>
@endsection
