@extends('layouts.app')
@section('title', $drawing->number)
@section('content')
    <div x-data="{ revise: @js($errors->hasAny(['revision', 'file', 'status'])), edit: @js($errors->hasAny(['number', 'title', 'kind', 'drawing_folder_id'])) }">
        <x-page-header :title="$drawing->number" :subtitle="$drawing->title">
            <a href="{{ route('drawings.register.index', $project) }}" class="btn btn-ghost">{{ __('Back to register') }}</a>
            @can('drawings.manage')
                <button type="button" class="btn btn-ghost" @click="edit = !edit">{{ __('Edit') }}</button>
                <button type="button" class="btn btn-primary" @click="revise = !revise">{{ __('Add revision') }}</button>
            @endcan
        </x-page-header>

        @can('drawings.manage')
            <form x-show="edit" x-cloak method="POST" action="{{ route('drawings.register.update', $drawing) }}" class="card mb-5 grid gap-3 p-4 sm:grid-cols-4">
                @csrf @method('PATCH')
                <x-field :label="__('Type')" name="kind">
                    <select id="kind" name="kind" class="input">
                        <option value="drawing" @selected(old('kind', $drawing->kind) === 'drawing')>{{ __('Drawing') }}</option>
                        <option value="document" @selected(old('kind', $drawing->kind) === 'document')>{{ __('Document') }}</option>
                    </select>
                </x-field>
                <x-field :label="__('Number')" name="number"><input id="number" name="number" value="{{ old('number', $drawing->number) }}" maxlength="64" class="input" required></x-field>
                <x-field :label="__('Title')" name="title" class="sm:col-span-2"><input id="title" name="title" value="{{ old('title', $drawing->title) }}" maxlength="200" class="input" required></x-field>
                <x-field :label="__('Discipline')" name="discipline">
                    <select id="discipline" name="discipline" class="input">
                        <option value="">—</option>
                        @foreach (config('modules.drawings.disciplines') as $discipline)
                            <option value="{{ $discipline }}" @selected(old('discipline', $drawing->discipline) === $discipline)>{{ __($discipline) }}</option>
                        @endforeach
                    </select>
                </x-field>
                <x-field :label="__('Folder')" name="drawing_folder_id" class="sm:col-span-3">
                    <select id="drawing_folder_id" name="drawing_folder_id" class="input">
                        <option value="">{{ __('No folder') }}</option>
                        @foreach ($folders as $option)<option value="{{ $option->id }}" @selected((int) old('drawing_folder_id', $drawing->drawing_folder_id) === $option->id)>{{ $option->name }}</option>@endforeach
                    </select>
                </x-field>
                <div class="flex flex-wrap items-center gap-3 sm:col-span-4">
                    <button class="btn btn-primary">{{ __('Save changes') }}</button>
                </div>
            </form>
            <form x-show="edit" x-cloak method="POST" action="{{ route('drawings.register.destroy', $drawing) }}" class="mb-5" onsubmit="return confirm(@js(__('Remove this entry from the register?')))">
                @csrf @method('DELETE')
                <button class="btn btn-danger">{{ __('Remove from register') }}</button>
            </form>

            <form x-show="revise" x-cloak method="POST" action="{{ route('drawings.register.versions.store', $drawing) }}" enctype="multipart/form-data" class="card mb-5">
                @csrf
                <input type="hidden" name="client_uuid" value="{{ $clientUuid }}">
                <div class="card-head"><h2 class="card-title">{{ __('Add revision') }}</h2></div>
                <div class="grid gap-3 p-4 sm:grid-cols-4">
                    @include('modules.drawings.partials.version-fields', ['defaultRevision' => ''])
                    <div class="sm:col-span-4"><button class="btn btn-primary">{{ __('Upload revision') }}</button></div>
                </div>
            </form>
        @endcan

        @if ($current && $current->isViewable() && $sheets->isEmpty())
            @can('drawings.manage')
                <div id="drawing-sheet-register" class="card mb-5 p-4 text-sm text-ink-2"
                     data-file="{{ route('drawings.register.file', $current) }}"
                     data-kind="{{ $current->isPdf() ? 'pdf' : 'image' }}"
                     data-url="{{ route('drawings.register.sheets.store', $current) }}"
                     data-failed="{{ __('This file could not be opened.') }}"
                     data-too-many="{{ __('This file has more than 500 pages. Split it before uploading.') }}">
                    {{ __('Reading the sheets from this file...') }}
                </div>
                @vite('resources/js/drawings/viewer.js')
            @else
                <div class="card mb-5"><x-empty :title="__('Sheets not read yet')" :note="__('Someone who manages drawings needs to open this entry once to read its sheets.')" /></div>
            @endcan
        @endif

        @if ($sheets->isNotEmpty())
            <div class="card mb-5 overflow-x-auto">
                <div class="card-head"><h2 class="card-title">{{ __('Sheets of the current revision') }}</h2></div>
                <table class="ledger min-w-[720px]">
                    <thead><tr><th>{{ __('Page') }}</th><th>{{ __('Sheet no. and title') }}</th><th>{{ __('Scale') }}</th><th class="r">{{ __('Takeoff') }}</th><th class="r">{{ __('Pins') }}</th><th></th></tr></thead>
                    <tbody>
                    @foreach ($sheets as $sheet)
                        <tr>
                            <td class="num text-xs">{{ $sheet->page_no }}</td>
                            <td>
                                @can('drawings.manage')
                                    <form method="POST" action="{{ route('drawings.register.sheets.update', $sheet) }}" class="flex flex-wrap gap-1.5">
                                        @csrf @method('PATCH')
                                        <input name="sheet_number" value="{{ $sheet->sheet_number }}" maxlength="64" placeholder="{{ __('Sheet no.') }}" class="input w-28" aria-label="{{ __('Sheet no.') }}">
                                        <input name="title" value="{{ $sheet->title }}" maxlength="200" placeholder="{{ __('Title') }}" class="input min-w-40 flex-1" aria-label="{{ __('Title') }}">
                                        <button class="btn btn-ghost">{{ __('Save') }}</button>
                                    </form>
                                @else
                                    {{ $sheet->label() }}@if ($sheet->title) · {{ $sheet->title }}@endif
                                @endcan
                            </td>
                            <td>@if ($sheet->isCalibrated())<span class="text-moss">{{ __('Set') }}</span>@else<span class="text-ink-3">{{ __('Not set') }}</span>@endif</td>
                            <td class="r">{{ $sheet->measurements_count }}</td>
                            <td class="r">{{ $sheet->pins_count }}</td>
                            <td class="text-right"><a href="{{ route('drawings.register.sheet', $sheet) }}" class="btn btn-ghost">{{ __('Open viewer') }}</a></td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        <div class="card mb-5 overflow-x-auto">
            <div class="card-head"><h2 class="card-title">{{ __('Revisions') }}</h2></div>
            <table class="ledger min-w-[720px]">
                <thead><tr><th>{{ __('Rev') }}</th><th>{{ __('Status') }}</th><th>{{ __('File') }}</th><th>{{ __('Note') }}</th><th>{{ __('Uploaded') }}</th><th></th></tr></thead>
                <tbody>
                @foreach ($drawing->versions as $version)
                    <tr>
                        <td class="num text-xs">{{ $version->revision }}@if ($version->is_current) <span class="stamp border-moss text-moss">{{ __('Current') }}</span>@endif</td>
                        <td><x-stamp :status="$version->status" /></td>
                        <td class="break-all">{{ $version->original_name }} <span class="num text-xs text-ink-3">{{ number_format($version->size_bytes / 1048576, 1) }} MB</span></td>
                        <td>{{ $version->note ?: '—' }}</td>
                        <td class="text-xs"><span class="num">{{ $version->created_at->format('d M Y') }}</span><br>{{ $version->uploader?->name }}</td>
                        <td class="text-right whitespace-nowrap"><a href="{{ route('drawings.register.file', [$version, 'download' => 1]) }}" class="link text-xs">{{ __('Download') }}</a></td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>

        @if ($transmittalItems->isNotEmpty())
            <div class="card overflow-x-auto">
                <div class="card-head"><h2 class="card-title">{{ __('Issued on transmittals') }}</h2></div>
                <table class="ledger min-w-[560px]">
                    <thead><tr><th>{{ __('Transmittal') }}</th><th>{{ __('Rev') }}</th><th>{{ __('To') }}</th><th>{{ __('Date') }}</th><th>{{ __('Status') }}</th></tr></thead>
                    <tbody>
                    @foreach ($transmittalItems as $item)
                        <tr>
                            <td class="num text-xs"><a class="link" href="{{ route('drawings.transmittals.show', $item->transmittal) }}">{{ $item->transmittal->number }}</a></td>
                            <td class="num text-xs">{{ $item->version->revision }}</td>
                            <td>{{ $item->transmittal->recipient_name }}</td>
                            <td class="num text-xs">{{ $item->transmittal->issued_at->format('d M Y') }}</td>
                            <td><x-stamp :status="$item->transmittal->status" /></td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
@endsection
