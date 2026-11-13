@extends('layouts.app')
@section('title', __('Scanned BOQ').' #'.$read->id)
@section('content')
    @php
        $notStarted = in_array($read->status, [\App\Models\DocumentRead::DRAFT, \App\Models\DocumentRead::QUOTED], true);
        $moving = in_array($read->status, [\App\Models\DocumentRead::PREPARING, \App\Models\DocumentRead::PREPARED, \App\Models\DocumentRead::RUNNING, \App\Models\DocumentRead::PAUSED], true);
        $ready = $read->status === \App\Models\DocumentRead::READY && $page !== null;
        $failed = $read->status === \App\Models\DocumentRead::FAILED;
    @endphp

    <x-page-header :title="__('Scanned BOQ').' #'.$read->id" :subtitle="collect([$project?->name, $read->source_name, \App\Services\BoqScan\BoqScanScreen::statusLabel($read->status)])->filter()->implode(' · ')">
        @if ($project)
            <a href="{{ route('boq.excel.index', $project) }}" class="btn btn-ghost">{{ __('Back to BOQ in Excel') }}</a>
        @endif
    </x-page-header>

    <div @class(['space-y-5', 'max-w-5xl' => ! $ready])>
        @if ($notStarted)
            @include('modules.boq_scan.partials.upload', ['read' => $read, 'stored' => $status['stored_pages']])
            <div x-data="boqScanDiscard(@js(route('boq-scans.discard', $read, false)), @js(__('Cancel this scan? Nothing more is sent.')))" class="space-y-2">
                <p class="error" x-show="error" x-cloak x-text="error"></p>
                <button type="button" class="btn btn-ghost" :disabled="busy" @click="go()">{{ __('Cancel this scan') }}</button>
            </div>
        @elseif ($moving)
            @include('modules.boq_scan.partials.progress')
        @elseif ($ready)
            @include('modules.boq_scan.partials.review')
        @elseif ($failed)
            <section class="card border-laterite">
                <div class="card-head"><h2 class="card-title">{{ __('No page of this document could be read') }}</h2></div>
                <div class="space-y-2 p-4 text-sm">
                    @if ($status['message'])
                        <p>{{ $status['message'] }}</p>
                    @endif
                    <p class="text-ink-3">{{ __('Failed pages are not charged. Read them again, read them as two halves, or discard this scan.') }}</p>
                </div>
            </section>
            @include('modules.boq_scan.partials.progress')
            <div x-data="boqScanDiscard(@js(route('boq-scans.discard', $read, false)), @js(__('Discard this scan? Nothing is written to the BOQ, and the scan cannot be opened again.')))" class="space-y-2">
                <p class="error" x-show="error" x-cloak x-text="error"></p>
                <button type="button" class="btn btn-ghost" :disabled="busy" @click="go()">{{ __('Discard this scan') }}</button>
            </div>
        @elseif ($read->status === \App\Models\DocumentRead::CONFIRMED)
            <section class="card border-moss">
                <div class="card-head"><h2 class="card-title text-moss">{{ __('Written to the BOQ') }}</h2></div>
                <div class="space-y-4 p-4">
                    <div class="grid gap-3 sm:grid-cols-3">
                        <x-stat :label="__('Lines')" :value="(int) ($result['lines'] ?? 0)" />
                        <x-stat :label="__('Added')" :value="(int) ($result['created'] ?? 0)" />
                        <x-stat :label="__('Updated')" :value="(int) ($result['updated'] ?? 0)" />
                    </div>
                    <dl class="grid gap-2 text-sm sm:grid-cols-2">
                        <div class="flex justify-between gap-3"><dt class="text-ink-3">{{ __('Unchanged') }}</dt><dd class="num">{{ (int) ($result['unchanged'] ?? 0) }}</dd></div>
                        <div class="flex justify-between gap-3"><dt class="text-ink-3">{{ __('Confirmed one by one') }}</dt><dd class="num">{{ (int) ($result['confirmed_by_row'] ?? 0) }}</dd></div>
                        <div class="flex justify-between gap-3"><dt class="text-ink-3">{{ __('Confirmed with the page') }}</dt><dd class="num">{{ (int) ($result['confirmed_by_page'] ?? 0) }}</dd></div>
                        <div class="flex justify-between gap-3"><dt class="text-ink-3">{{ __('Values typed by a person') }}</dt><dd class="num">{{ (int) ($result['cells_typed'] ?? 0) }}</dd></div>
                        <div class="flex justify-between gap-3"><dt class="text-ink-3">{{ __('Rows added by hand') }}</dt><dd class="num">{{ (int) ($result['rows_added_by_hand'] ?? 0) }}</dd></div>
                        <div class="flex justify-between gap-3"><dt class="text-ink-3">{{ __('Rows left out') }}</dt><dd class="num">{{ (int) ($result['rows_left_out'] ?? 0) }}</dd></div>
                    </dl>
                    @if ($project)
                        <a href="{{ route('boq.index', $project) }}" class="btn btn-primary">{{ __('Open the BOQ') }}</a>
                    @endif
                </div>
            </section>
        @else
            <section class="card">
                <div class="space-y-3 p-4 text-sm">
                    <p>{{ match ($read->status) {
                        \App\Models\DocumentRead::DISCARDED => __('This scan was discarded. Nothing was written to the BOQ.'),
                        \App\Models\DocumentRead::EXPIRED => __('This scan was not confirmed in time and has expired. Nothing was written to the BOQ.'),
                        default => __('This scan was cancelled. Nothing was written to the BOQ.'),
                    } }}</p>
                    @if ($status['message'])
                        <p class="text-ink-3">{{ $status['message'] }}</p>
                    @endif
                    @if ($project && \Illuminate\Support\Facades\Route::has('boq-scans.create') && app(\App\Services\BoqScan\BoqScanScreen::class)->open(auth()->user()))
                        <a href="{{ route('boq-scans.create', $project) }}" class="btn btn-primary">{{ __('Start a new scan') }}</a>
                    @endif
                </div>
            </section>
        @endif
    </div>

    @if ($notStarted || $moving || $ready || $failed)
        @include('modules.boq_scan.partials.http')
        @include('modules.boq_scan.partials.kept-pdf', ['minutes' => $limits['minutes']])
        @include('modules.document_reading._capture')
    @endif
@endsection
