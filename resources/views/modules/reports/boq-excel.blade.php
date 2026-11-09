@extends('layouts.app')
@section('title', __('BOQ in Excel'))
@section('content')
    <x-page-header :title="__('BOQ in Excel')" :subtitle="$project->name">
        <a href="{{ route('boq.index', $project) }}" class="btn btn-ghost">{{ __('Back to BOQ & ledger') }}</a>
    </x-page-header>

    @if ($import && ! empty($import['errors']))
        <section class="card mb-5 border-laterite" role="alert">
            <div class="card-head">
                <h2 class="card-title text-laterite-dark">{{ __('Nothing was imported') }}</h2>
                <span class="num text-sm text-ink-3">{{ count($import['errors']) + ($import['more'] ?? 0) }} {{ __('problems') }}</span>
            </div>
            <p class="px-4 pt-3 text-sm text-ink-2">{{ __('Fix these in the file and upload it again. Rows are numbered as in Excel.') }}</p>
            <div class="overflow-x-auto p-4">
                <table class="ledger">
                    <thead><tr><th class="w-20">{{ __('Row') }}</th><th class="w-40">{{ __('Column') }}</th><th>{{ __('Problem') }}</th></tr></thead>
                    <tbody>
                    @foreach ($import['errors'] as $error)
                        <tr>
                            <td class="num">{{ $error['row'] > 0 ? $error['row'] : '—' }}</td>
                            <td>{{ $error['field'] ? __(['section' => 'Section', 'code' => 'Item code', 'description' => 'Description', 'unit' => 'Unit', 'qty' => 'Quantity', 'rate' => 'Rate', 'category' => 'Category', 'details' => 'Details'][$error['field']] ?? $error['field']) : __('File') }}</td>
                            <td>{{ $error['message'] }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
                @if (! empty($import['more']))
                    <p class="mt-2 text-sm text-ink-3">{{ __('and :count more problems. Fix these first and upload again.', ['count' => $import['more']]) }}</p>
                @endif
            </div>
        </section>
    @elseif ($import)
        <section class="card mb-5 border-moss">
            <div class="card-head"><h2 class="card-title text-moss">{{ __('Import finished') }}</h2></div>
            <div class="grid gap-3 p-4 sm:grid-cols-3">
                <x-stat :label="__('Added')" :value="$import['created']" />
                <x-stat :label="__('Updated')" :value="$import['updated']" />
                <x-stat :label="__('Unchanged')" :value="$import['unchanged']" />
            </div>
        </section>
    @endif

    <div class="grid gap-5 lg:grid-cols-2">
        <section class="card">
            <div class="card-head"><h2 class="card-title">{{ __('Export') }}</h2></div>
            <div class="space-y-3 p-4">
                <p class="text-sm text-ink-3">{{ __('Download the BOQ as an Excel workbook. It uses the same columns the import reads, so you can edit it and upload it again.') }}</p>
                <p class="text-sm"><span class="num font-medium">{{ $itemCount }}</span> {{ __('BOQ items') }}</p>
                <a href="{{ route('boq.excel.export', $project) }}" class="btn btn-primary">{{ __('Download Excel') }}</a>
            </div>
        </section>

        @can('boq.manage')
            <form method="POST" action="{{ route('boq.excel.import', $project) }}" enctype="multipart/form-data" class="card">
                @csrf
                <div class="card-head"><h2 class="card-title">{{ __('Import') }}</h2></div>
                <div class="space-y-3 p-4">
                    <p class="text-sm text-ink-3">{{ __('Rows with an existing item code are updated. If any row has a problem, nothing is imported.') }}</p>
                    <p class="text-sm text-ink-3">{{ __('Quantity cannot go below what is already approved. Use values, not formulas.') }}</p>
                    <x-field :label="__('Excel file (.xlsx)')" name="file"><input id="file" name="file" type="file" accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" class="input" required></x-field>
                    <div class="flex items-center justify-between gap-2">
                        <a href="{{ route('boq.excel.template') }}" class="link text-sm">{{ __('Download template') }}</a>
                        <button class="btn btn-primary">{{ __('Import') }}</button>
                    </div>
                </div>
            </form>
        @endcan
    </div>
@endsection
