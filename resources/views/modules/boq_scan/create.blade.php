@extends('layouts.app')
@section('title', __('Read a scanned BOQ'))
@section('content')
    <x-page-header :title="__('Read a scanned BOQ')" :subtitle="$project->name">
        <a href="{{ route('boq.excel.index', $project) }}" class="btn btn-ghost">{{ __('Back to BOQ in Excel') }}</a>
    </x-page-header>

    <div class="max-w-5xl space-y-5">
        <div class="space-y-2 text-sm text-ink-2">
            <p>{{ __('The AI reads each page of a scanned or photographed BOQ or estimate: first the rows with quantity and rate, then, in a separate reading, the amount column alone. The app multiplies and adds every line itself.') }}</p>
            <p>{{ __('The app does every calculation itself and stops when a line or a printed total does not add up. What the AI prepares is a draft until a named person confirms it. It does not promise that the AI read every figure correctly.') }}</p>
        </div>

        @error('ai_reader')
            <p class="rounded-[3px] border border-laterite bg-laterite/10 px-3 py-2 text-sm font-medium text-laterite-dark" role="alert">{{ $message }}</p>
        @enderror

        @if ($blocker)
            {{-- Never an error page: what stands in the way, and what to do about it. --}}
            <section class="card border-haldi" data-boq-scan-blocked="{{ $blocker['code'] }}">
                <div class="card-head"><h2 class="card-title">{{ __('A scan cannot be started now') }}</h2></div>
                <div class="space-y-3 p-4 text-sm">
                    <p class="text-ink">{{ $blocker['message'] }}</p>
                    @if ($blocker['settings'])
                        <a href="{{ route('ai-documents.edit') }}" class="btn btn-primary">{{ __('Read the notice and switch the readers on') }}</a>
                    @endif
                    <p class="text-ink-3">{{ __('Meanwhile the Excel file of the estimate can be uploaded on the BOQ in Excel page.') }}</p>
                </div>
            </section>
        @else
            @include('modules.boq_scan.partials.upload', ['read' => null])
        @endif

        @if ($reads !== [])
            <section class="card">
                <div class="card-head"><h2 class="card-title">{{ __('Scans of this project still open') }}</h2></div>
                <div class="overflow-x-auto p-4">
                    <table class="ledger">
                        <thead><tr><th class="w-16">#</th><th>{{ __('File') }}</th><th>{{ __('Status') }}</th><th class="r">{{ __('Pages') }}</th><th></th></tr></thead>
                        <tbody>
                        @foreach ($reads as $open)
                            <tr>
                                <td class="num">{{ $open->id }}</td>
                                <td>{{ $open->source_name ?: '—' }}</td>
                                <td>{{ \App\Services\BoqScan\BoqScanScreen::statusLabel($open->status) }}</td>
                                <td class="num r">{{ (int) $open->pages_read }} / {{ (int) $open->pages_total }}</td>
                                <td class="r"><a href="{{ route('boq-scans.show', $open) }}" class="link">{{ __('Open') }}</a></td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            </section>
        @endif
    </div>

    @unless ($blocker)
        @include('modules.boq_scan.partials.http')
        @include('modules.boq_scan.partials.kept-pdf', ['minutes' => $limits['minutes']])
        @include('modules.document_reading._capture')
    @endunless
@endsection
