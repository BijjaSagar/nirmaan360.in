@extends('layouts.app')
@section('title', $drawing->number.' · '.$sheet->label())
@section('content')
    @php
        $previous = $siblings->firstWhere('page_no', $sheet->page_no - 1);
        $next = $siblings->firstWhere('page_no', $sheet->page_no + 1);
    @endphp
    <x-page-header :title="$drawing->number.' · '.$sheet->label()" :subtitle="$drawing->title.' · '.__('Rev').' '.$version->revision">
        <a href="{{ route('drawings.register.show', $drawing) }}" class="btn btn-ghost">{{ __('Back to drawing') }}</a>
        @if ($previous)<a href="{{ route('drawings.register.sheet', $previous->id) }}" class="btn btn-ghost">{{ __('Previous sheet') }}</a>@endif
        @if ($next)<a href="{{ route('drawings.register.sheet', $next->id) }}" class="btn btn-ghost">{{ __('Next sheet') }}</a>@endif
    </x-page-header>

    @unless ($version->is_current)
        <div class="mb-4 rounded-[3px] border border-haldi bg-haldi/10 px-3 py-2 text-sm font-medium text-[#8a6208]" role="status">{{ __('This is an old revision. Quantities measured here are left out of the takeoff totals.') }}</div>
    @endunless

    {{-- Alpine must leave this block alone: the canvas script owns it. --}}
    <div id="drawing-viewer" x-ignore class="grid gap-4 lg:grid-cols-[minmax(0,1fr)_340px]">
        <section class="card min-w-0 self-start">
            <div class="flex flex-wrap items-center gap-1.5 border-b border-rule p-2">
                <button type="button" class="btn btn-ink" data-tool="pan">{{ __('Move') }}</button>
                @can('drawings.takeoff')
                    <button type="button" class="btn btn-ghost" data-tool="calibrate">{{ __('Set scale') }}</button>
                    <button type="button" class="btn btn-ghost" data-tool="length">{{ __('Length') }}</button>
                    <button type="button" class="btn btn-ghost" data-tool="area">{{ __('Area') }}</button>
                    <button type="button" class="btn btn-ghost" data-tool="volume">{{ __('Volume') }}</button>
                    <button type="button" class="btn btn-ghost" data-tool="count">{{ __('Count') }}</button>
                @endcan
                @can('issue.manage')
                    <button type="button" class="btn btn-ghost" data-tool="pin">{{ __('Pin issue') }}</button>
                @endcan
                <span class="mx-1 hidden h-5 w-px bg-rule sm:inline-block"></span>
                <button type="button" class="btn btn-ghost" data-action="zoom-out" aria-label="{{ __('Zoom out') }}">−</button>
                <button type="button" class="btn btn-ghost" data-action="zoom-in" aria-label="{{ __('Zoom in') }}">+</button>
                <button type="button" class="btn btn-ghost" data-action="fit">{{ __('Fit') }}</button>
            </div>
            {{-- Fixed height, with the drawing actions over the canvas: the sheet must not move between clicks. --}}
            <p id="dv-hint" class="h-7 truncate border-b border-rule px-3 py-1.5 text-xs leading-4 text-ink-3"></p>
            <div class="relative">
                <div id="dv-stage" class="h-[62vh] min-h-[380px] touch-none overflow-hidden bg-paper-2"></div>
                <div class="absolute right-2 bottom-2 z-10 flex gap-1.5">
                    <button type="button" class="btn btn-ghost hidden" data-action="undo">{{ __('Undo point') }}</button>
                    <button type="button" class="btn btn-primary hidden" data-action="finish">{{ __('Finish') }}</button>
                    <button type="button" class="btn btn-ghost hidden" data-action="cancel">{{ __('Cancel') }}</button>
                </div>
            </div>
        </section>

        <aside class="min-w-0 space-y-4">
            <div id="dv-message" role="status" class="hidden rounded-[3px] border px-3 py-2 text-sm font-medium"></div>

            <div class="card p-3">
                <h2 class="card-title">{{ __('Scale') }}</h2>
                <p id="dv-scale-status" class="mt-1 text-sm text-ink-2"></p>
            </div>

            @can('drawings.takeoff')
                <form id="dv-calib-panel" class="card hidden space-y-3 p-3">
                    <h2 class="card-title">{{ __('Set scale') }}</h2>
                    <div>
                        <label class="label" for="dv-calib-length">{{ __('Real distance between the two points (m)') }}</label>
                        <input id="dv-calib-length" type="number" step="0.001" min="0.001" class="input num" required>
                        <p class="hint">{{ __('Use a long dimension printed on the drawing. Saving works out any existing quantities on this sheet again.') }}</p>
                    </div>
                    <button class="btn btn-primary">{{ __('Save scale') }}</button>
                </form>

                <form id="dv-draft-panel" class="card hidden space-y-3 p-3">
                    <h2 id="dv-draft-title" class="card-title"></h2>
                    <p id="dv-draft-qty" class="num text-lg"></p>
                    <p id="dv-draft-note" class="hint"></p>
                    <div>
                        <label class="label" for="dv-layer">{{ __('Layer') }}</label>
                        <select id="dv-layer" class="input" required></select>
                    </div>
                    <div>
                        <label class="label" for="dv-boq">{{ __('BOQ item') }}</label>
                        <select id="dv-boq" class="input"></select>
                        <p class="hint">{{ __('Only BOQ items in a matching unit are listed.') }}</p>
                    </div>
                    <div id="dv-depth-field">
                        <label class="label" for="dv-depth">{{ __('Depth (m)') }}</label>
                        <input id="dv-depth" type="number" step="0.001" min="0.001" class="input num">
                    </div>
                    <div>
                        <label class="label" for="dv-label">{{ __('Label') }}</label>
                        <input id="dv-label" maxlength="160" class="input">
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <button id="dv-draft-save" class="btn btn-primary">{{ __('Save') }}</button>
                        <button id="dv-draft-delete" type="button" class="btn btn-danger hidden">{{ __('Remove') }}</button>
                        <button id="dv-draft-close" type="button" class="btn btn-ghost">{{ __('Close') }}</button>
                    </div>
                </form>
            @endcan

            @can('issue.manage')
                <form id="dv-pin-panel" class="card hidden space-y-3 p-3">
                    <h2 class="card-title">{{ __('Pin issue') }}</h2>
                    <div class="flex gap-3 text-sm">
                        <label class="flex items-center gap-1.5"><input type="radio" name="dv-pin-mode" value="new" checked> {{ __('New issue') }}</label>
                        <label class="flex items-center gap-1.5"><input type="radio" name="dv-pin-mode" value="existing"> {{ __('Existing issue') }}</label>
                    </div>
                    <div id="dv-pin-new" class="space-y-3">
                        <div><label class="label" for="dv-pin-title">{{ __('Title') }}</label><input id="dv-pin-title" maxlength="160" class="input"></div>
                        <div><label class="label" for="dv-pin-priority">{{ __('Priority') }}</label><select id="dv-pin-priority" class="input"></select></div>
                        <div><label class="label" for="dv-pin-assignee">{{ __('Assign to') }}</label><select id="dv-pin-assignee" class="input"></select></div>
                        <div><label class="label" for="dv-pin-due">{{ __('Due date') }}</label><input id="dv-pin-due" type="date" class="input"></div>
                    </div>
                    <div id="dv-pin-existing" class="hidden"><label class="label" for="dv-pin-issue">{{ __('Issue') }}</label><select id="dv-pin-issue" class="input"></select></div>
                    <div class="flex flex-wrap gap-2">
                        <button id="dv-pin-save" class="btn btn-primary">{{ __('Save pin') }}</button>
                        <button id="dv-pin-close" type="button" class="btn btn-ghost">{{ __('Cancel') }}</button>
                    </div>
                </form>
            @endcan

            <div id="dv-pin-detail" class="card hidden space-y-2 p-3">
                <h2 id="dv-pin-detail-title" class="card-title"></h2>
                <p id="dv-pin-detail-meta" class="text-sm text-ink-2"></p>
                <div class="flex flex-wrap gap-2">
                    <a id="dv-pin-detail-link" class="btn btn-ghost">{{ __('Go to issues') }}</a>
                    @can('issue.manage')<button id="dv-pin-detail-remove" type="button" class="btn btn-danger">{{ __('Remove pin') }}</button>@endcan
                </div>
            </div>

            <div class="card p-3">
                <h2 class="card-title">{{ __('Layers') }}</h2>
                <div id="dv-layers" class="mt-2 space-y-1 text-sm"></div>
                @can('drawings.takeoff')
                    <form id="dv-layer-form" class="mt-3 flex gap-1.5">
                        <input id="dv-layer-name" maxlength="80" class="input" placeholder="{{ __('New layer name') }}" aria-label="{{ __('New layer name') }}" required>
                        <input id="dv-layer-colour" type="color" class="h-8 w-10 shrink-0 rounded-[3px] border border-rule" aria-label="{{ __('Colour') }}">
                        <button class="btn btn-ghost">{{ __('Add') }}</button>
                    </form>
                @endcan
            </div>

            <div class="card p-3">
                <h2 class="card-title">{{ __('Measurements') }}</h2>
                <div id="dv-measurements" class="mt-2 space-y-1 text-sm"></div>
            </div>

            <div class="card p-3">
                <h2 class="card-title">{{ __('Pinned issues') }}</h2>
                <div id="dv-pins" class="mt-2 space-y-1 text-sm"></div>
            </div>
        </aside>
    </div>

    <script type="application/json" id="drawing-viewer-data">@json($viewer)</script>
    @vite('resources/js/drawings/viewer.js')
@endsection
