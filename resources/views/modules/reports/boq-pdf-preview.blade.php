@extends('layouts.app')
@section('title', __('Check the PDF before importing'))
@section('content')
    <x-page-header :title="__('Check the PDF before importing')" :subtitle="$project->name">
        <a href="{{ route('boq.excel.index', $project) }}" class="btn btn-ghost">{{ __('Back to import') }}</a>
    </x-page-header>

    <p class="mb-4 text-sm text-ink-3">{{ __('This is what was read from :file: :pages pages and :lines table lines. Reading a PDF is not exact, so please check the rows below against the file. Nothing has been saved yet.', ['file' => $preview['file'], 'pages' => $preview['pages'], 'lines' => $preview['lines']]) }}</p>

    @if ($preview['errors'] !== [])
        <section class="card mb-5 border-laterite" role="alert">
            <div class="card-head">
                <h2 class="card-title text-laterite-dark">{{ __('This PDF cannot be imported as it is') }}</h2>
                <span class="num text-sm text-ink-3">{{ count($preview['errors']) }} {{ __('problems') }}</span>
            </div>
            <p class="px-4 pt-3 text-sm text-ink-2">{{ __('Lines are numbered as in the table read from the PDF, with the heading as line 1. If the PDF was read wrongly, upload the Excel file instead.') }}</p>
            <div class="overflow-x-auto p-4">
                <table class="ledger">
                    <thead><tr><th class="w-20">{{ __('Line') }}</th><th class="w-40">{{ __('Column') }}</th><th>{{ __('Problem') }}</th></tr></thead>
                    <tbody>
                    @foreach (array_slice($preview['errors'], 0, $shownErrors) as $error)
                        <tr>
                            <td class="num">{{ $error['row'] > 0 ? $error['row'] : '—' }}</td>
                            <td>{{ $error['field'] ? __(['section' => 'Section', 'code' => 'Item code', 'description' => 'Description', 'unit' => 'Unit', 'qty' => 'Quantity', 'rate' => 'Rate', 'category' => 'Category', 'details' => 'Details'][$error['field']] ?? $error['field']) : __('File') }}</td>
                            <td>{{ $error['message'] }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
                @if (count($preview['errors']) > $shownErrors)
                    <p class="mt-2 text-sm text-ink-3">{{ __('and :count more problems. Fix these first and upload again.', ['count' => count($preview['errors']) - $shownErrors]) }}</p>
                @endif
            </div>
        </section>
    @else
        <section class="card mb-5">
            <div class="card-head"><h2 class="card-title">{{ __('What would be imported') }}</h2></div>
            <div class="grid gap-3 p-4 sm:grid-cols-3">
                <x-stat :label="__('Rows to import')" :value="count($preview['rows'])" />
                @if ($money)
                    <x-stat :label="__('The lines add up to')" :value="inr($preview['total'])" />
                    @if ($preview['file_total'] !== null)
                        <x-stat :label="__('Total written in the file')" :value="inr($preview['file_total'])" :note="$preview['matches'] ? __('Matches the lines.') : __('Does not match the lines. Check the rows below.')" :accent="! $preview['matches']" />
                    @endif
                @endif
            </div>
        </section>
    @endif

    @if (! empty($preview['notes']))
        <section class="card mb-5">
            <div class="card-head"><h2 class="card-title">{{ __('Please check these') }}</h2></div>
            <ul class="space-y-1 p-4 text-sm text-ink-2">
                @foreach ($preview['notes'] as $note)
                    <li>@if ($note['row'] > 0)<span class="num text-ink-3">{{ __('Line') }} {{ $note['row'] }}:</span> @endif{{ $note['message'] }}</li>
                @endforeach
            </ul>
        </section>
    @endif

    @if ($preview['rows'] !== [])
        <section class="card mb-5">
            <div class="card-head">
                <h2 class="card-title">{{ __('Rows that would be imported') }}</h2>
                <span class="num text-sm text-ink-3">{{ count($preview['rows']) }} {{ __('rows') }}</span>
            </div>
            <p class="px-4 pt-3 text-sm text-ink-3">{{ __('A row whose item code already exists in the project updates that item. Other rows are added.') }}</p>
            <div class="overflow-x-auto p-4">
                <table class="ledger">
                    <thead>
                    <tr>
                        <th class="w-16">{{ __('Line') }}</th>
                        <th>{{ __('Section') }}</th>
                        <th>{{ __('Item code') }}</th>
                        <th>{{ __('Description') }}</th>
                        <th>{{ __('Unit') }}</th>
                        <th class="r">{{ __('Quantity') }}</th>
                        @if ($money)
                            <th class="r">{{ __('Rate') }}</th>
                            <th class="r">{{ __('Amount') }}</th>
                        @endif
                        <th>{{ __('Category') }}</th>
                    </tr>
                    </thead>
                    <tbody>
                    @foreach ($preview['rows'] as $row)
                        <tr>
                            <td class="num">{{ $row['row'] }}</td>
                            <td>{{ $row['section'] }}</td>
                            <td class="num">{{ $row['code'] }}</td>
                            <td>
                                {{ $row['description'] }}
                                @if (! empty($row['details']))
                                    <span class="block text-xs text-ink-3">{{ \Illuminate\Support\Str::limit($row['details'], 160) }}</span>
                                @endif
                            </td>
                            <td>{{ $row['unit'] }}</td>
                            <td class="num r">{{ fmt_qty($row['qty']) }}</td>
                            @if ($money)
                                <td class="num r">{{ inr($row['rate']) }}</td>
                                <td class="num r">{{ inr($row['amount']) }}</td>
                            @endif
                            <td class="text-ink-3">{{ empty($row['category']) ? '' : \App\Services\ProjectSetup\BoqCategories::label($row['category']) }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    @endif

    <div class="flex flex-wrap items-center justify-between gap-3">
        <form method="POST" action="{{ route('boq.pdf.discard', $project) }}">
            @csrf
            <input type="hidden" name="token" value="{{ $token }}">
            <button class="btn btn-ghost">{{ __('Discard this PDF') }}</button>
        </form>
        @if ($preview['errors'] === [])
            <form method="POST" action="{{ route('boq.pdf.confirm', $project) }}">
                @csrf
                <input type="hidden" name="token" value="{{ $token }}">
                <button class="btn btn-primary">{{ __('Import these :count rows', ['count' => count($preview['rows'])]) }}</button>
            </form>
        @else
            <a href="{{ route('boq.excel.index', $project) }}" class="btn btn-primary">{{ __('Upload another file') }}</a>
        @endif
    </div>
@endsection
