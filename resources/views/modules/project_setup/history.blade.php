@extends('layouts.app')
@section('title', __('BOQ history'))
@section('content')
    @php
        $actions = [
            'added' => [__('Added'), 'border-moss text-moss'],
            'edited' => [__('Edited'), 'border-neel text-neel'],
            'deleted' => [__('Removed'), 'border-laterite text-laterite'],
            'restored' => [__('Restored'), 'border-moss text-moss'],
        ];
        $sources = [
            'csv_import' => __('Imported from a CSV file'),
            'excel_import' => __('Imported from an Excel file'),
            'starter' => __('Starter template'),
            'concrete' => __('Concrete'),
            'edit' => __('Edited on screen'),
            'remove' => __('Removed on screen'),
        ];
    @endphp

    <x-page-header :title="__('BOQ history')" :subtitle="$project->name">
        <a href="{{ route('boq.index', $project) }}" class="btn btn-ghost min-h-11">{{ __('Back to BOQ & ledger') }}</a>
    </x-page-header>

    <p class="mb-4 text-sm text-ink-3">{{ __('The BOQ is never locked. Every addition, change and removal is kept here, with who made it, when, what it was, what it became and why. Measurements and bills already made are not changed by a revision.') }}</p>

    @if ($filtered)
        <div class="mb-4 flex flex-wrap items-center justify-between gap-2 rounded-[3px] border border-neel/40 bg-neel/5 px-3 py-2 text-sm">
            <span>
                @if ($item)
                    {{ __('Showing only') }} <span class="num font-medium">{{ $item->code }}</span> {{ \Illuminate\Support\Str::limit($item->description, 80) }}
                @else
                    {{ __('Showing one import or batch only') }}
                @endif
            </span>
            <a href="{{ route('project-setup.history', $project) }}" class="link">{{ __('Show everything') }}</a>
        </div>
    @endif

    @if ($revisions->isEmpty())
        <div class="card"><x-empty :title="__('No changes yet')" :note="__('Changes to the BOQ will be listed here as they happen.')" /></div>
    @else
        <div class="space-y-3">
            @foreach ($revisions as $revision)
                @php [$actionLabel, $actionTone] = $actions[$revision->action] ?? [\Illuminate\Support\Str::headline($revision->action), 'border-ink-3 text-ink-3']; @endphp
                <article class="card">
                    <header class="flex flex-wrap items-center gap-x-3 gap-y-1 border-b border-rule px-4 py-2.5 text-sm">
                        <span class="stamp {{ $actionTone }}">{{ $actionLabel }}</span>
                        <span class="num font-medium">{{ $revision->item_code }}</span>
                        <span class="min-w-0 flex-1 truncate text-ink-2">{{ $revision->item_description }}</span>
                        <span class="text-ink-3">{{ $revision->user?->name ?? __('System') }} · <span class="num">{{ $revision->created_at->format('d M Y, H:i') }}</span></span>
                    </header>

                    <div class="space-y-2 px-4 py-3 text-sm">
                        @include('modules.project_setup._revision-changes', ['revision' => $revision, 'showMoney' => $showMoney])

                        @if ($revision->reason)
                            <p class="text-ink-2"><span class="eyebrow text-ink-3">{{ __('Reason') }}</span><br>{{ $revision->reason }}</p>
                        @endif

                        <p class="flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-ink-3">
                            @if ($revision->source && isset($sources[$revision->source]))<span>{{ $sources[$revision->source] }}</span>@endif
                            @if ($revision->boq_item_id && ! $filtered)
                                <a href="{{ route('project-setup.history', [$project, 'item' => $revision->boq_item_id]) }}" class="link">{{ __('History of this item') }}</a>
                            @endif
                            @if ($revision->batch_uuid && ! request('batch'))
                                <a href="{{ route('project-setup.history', [$project, 'batch' => $revision->batch_uuid]) }}" class="link">{{ __('Everything in this import') }}</a>
                            @endif
                        </p>
                    </div>

                    @if ($showMoney)
                        @php $delta = \App\Support\Dec::of($revision->valuation_after)->minus(\App\Support\Dec::of($revision->valuation_before)); @endphp
                        <footer class="flex flex-wrap items-center justify-between gap-2 border-t border-rule bg-paper/50 px-4 py-2 text-sm">
                            <span class="eyebrow text-ink-3">{{ __('Value of the BOQ') }}</span>
                            <span class="num">{{ inr($revision->valuation_before) }} → <span class="font-medium">{{ inr($revision->valuation_after) }}</span>
                                @if (! $delta->isZero())
                                    <span class="{{ $delta->isPositive() ? 'text-laterite' : 'text-moss' }}">({{ $delta->isPositive() ? '+' : '' }}{{ \App\Support\Dec::indian($delta) }})</span>
                                @endif
                            </span>
                        </footer>
                    @endif
                </article>
            @endforeach
        </div>

        <div class="mt-4">{{ $revisions->links() }}</div>
    @endif
@endsection
