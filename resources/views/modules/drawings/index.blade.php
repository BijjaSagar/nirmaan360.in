@extends('layouts.app')
@section('title', __('Drawings'))
@section('content')
    <div x-data="{ upload: @js($errors->hasAny(['number', 'title', 'file', 'revision', 'status', 'kind', 'drawing_folder_id'])), folder: @js($errors->hasAny(['name', 'parent_id'])) }">
        <x-page-header :title="__('Drawings and documents')" :subtitle="$project->name">
            @can('drawings.manage')
                <button type="button" class="btn btn-ghost" @click="folder = !folder">{{ __('New folder') }}</button>
                <button type="button" class="btn btn-primary" @click="upload = !upload">{{ __('Add to register') }}</button>
            @endcan
        </x-page-header>

        @can('drawings.manage')
            <form x-show="folder" x-cloak method="POST" action="{{ route('drawings.register.folders.store', $project) }}" class="card mb-5 grid gap-3 p-4 sm:grid-cols-3">
                @csrf
                <x-field :label="__('Folder name')" name="name"><input id="name" name="name" value="{{ old('name') }}" maxlength="120" class="input" required></x-field>
                <x-field :label="__('Inside folder')" name="parent_id">
                    <select id="parent_id" name="parent_id" class="input">
                        <option value="">{{ __('Top level') }}</option>
                        @foreach ($folders as $option)<option value="{{ $option->id }}">{{ $option->name }}</option>@endforeach
                    </select>
                </x-field>
                <div class="flex items-end"><button class="btn btn-primary w-full">{{ __('Add folder') }}</button></div>
            </form>

            <form x-show="upload" x-cloak method="POST" action="{{ route('drawings.register.store', $project) }}" enctype="multipart/form-data" class="card mb-5">
                @csrf
                <input type="hidden" name="client_uuid" value="{{ $clientUuid }}">
                <div class="card-head"><h2 class="card-title">{{ __('Add to register') }}</h2></div>
                <div class="grid gap-3 p-4 sm:grid-cols-4">
                    <x-field :label="__('Type')" name="kind">
                        <select id="kind" name="kind" class="input">
                            <option value="drawing" @selected(old('kind', 'drawing') === 'drawing')>{{ __('Drawing') }}</option>
                            <option value="document" @selected(old('kind') === 'document')>{{ __('Document') }}</option>
                        </select>
                    </x-field>
                    <x-field :label="__('Number')" name="number"><input id="number" name="number" value="{{ old('number') }}" maxlength="64" class="input" required></x-field>
                    <x-field :label="__('Title')" name="title" class="sm:col-span-2"><input id="title" name="title" value="{{ old('title') }}" maxlength="200" class="input" required></x-field>
                    <x-field :label="__('Discipline')" name="discipline">
                        <select id="discipline" name="discipline" class="input">
                            <option value="">—</option>
                            @foreach (config('modules.drawings.disciplines') as $discipline)
                                <option value="{{ $discipline }}" @selected(old('discipline') === $discipline)>{{ __($discipline) }}</option>
                            @endforeach
                        </select>
                    </x-field>
                    <x-field :label="__('Folder')" name="drawing_folder_id" class="sm:col-span-3">
                        <select id="drawing_folder_id" name="drawing_folder_id" class="input">
                            <option value="">{{ __('No folder') }}</option>
                            @foreach ($folders as $option)<option value="{{ $option->id }}" @selected((int) old('drawing_folder_id', $folderId) === $option->id)>{{ $option->name }}</option>@endforeach
                        </select>
                    </x-field>
                    @include('modules.drawings.partials.version-fields')
                    <div class="sm:col-span-4"><button class="btn btn-primary">{{ __('Upload') }}</button></div>
                </div>
            </form>
        @endcan

        <div class="grid gap-5 lg:grid-cols-[240px_minmax(0,1fr)]">
            <aside class="card self-start p-2">
                <a href="{{ route('drawings.register.index', $project) }}" class="block rounded-[3px] px-2 py-1 text-sm hover:bg-paper {{ $folderId ? '' : 'bg-paper-2 font-semibold' }}">{{ __('All entries') }}</a>
                @include('modules.drawings.partials.folder-tree', ['parent' => 0, 'depth' => 0])

                @if ($folderId && ($selected = $folders->firstWhere('id', $folderId)))
                    @can('drawings.manage')
                        <div class="mt-2 space-y-2 border-t border-rule p-2">
                            <form method="POST" action="{{ route('drawings.register.folders.update', $selected) }}" class="flex gap-1.5">
                                @csrf @method('PATCH')
                                <input name="name" value="{{ $selected->name }}" maxlength="120" class="input" aria-label="{{ __('Folder name') }}" required>
                                <button class="btn btn-ghost">{{ __('Rename') }}</button>
                            </form>
                            <form method="POST" action="{{ route('drawings.register.folders.destroy', $selected) }}">
                                @csrf @method('DELETE')
                                <button class="link text-xs">{{ __('Remove empty folder') }}</button>
                            </form>
                        </div>
                    @endcan
                @endif
            </aside>

            <div class="min-w-0">
                <div class="mb-3 flex flex-wrap items-center gap-1.5">
                    <a href="{{ route('drawings.register.index', [$project, 'folder' => $folderId]) }}" class="btn {{ $view === 'list' ? 'btn-ink' : 'btn-ghost' }}">{{ __('Register') }}</a>
                    <a href="{{ route('drawings.register.index', [$project, 'view' => 'sheets']) }}" class="btn {{ $view === 'sheets' ? 'btn-ink' : 'btn-ghost' }}">{{ __('Sheet register') }}</a>
                    @if ($view === 'list')
                        <form method="GET" action="{{ route('drawings.register.index', $project) }}" class="flex min-w-[220px] flex-1 gap-1.5 sm:justify-end">
                            @if ($folderId)<input type="hidden" name="folder" value="{{ $folderId }}">@endif
                            <select name="kind" class="input sm:w-32" aria-label="{{ __('Type') }}" onchange="this.form.submit()">
                                <option value="">{{ __('All types') }}</option>
                                <option value="drawing" @selected($kind === 'drawing')>{{ __('Drawing') }}</option>
                                <option value="document" @selected($kind === 'document')>{{ __('Document') }}</option>
                            </select>
                            <input name="q" value="{{ $search }}" placeholder="{{ __('Search number or title') }}" class="input sm:w-56">
                        </form>
                    @endif
                </div>

                <div class="card overflow-x-auto">
                    @if ($view === 'sheets')
                        @if ($sheets->isEmpty())
                            <x-empty :title="__('No sheets yet')" :note="__('Upload a PDF or image drawing. Its pages appear here as sheets.')" />
                        @else
                            <table class="ledger min-w-[760px]">
                                <thead><tr><th>{{ __('Drawing') }}</th><th>{{ __('Sheet') }}</th><th>{{ __('Title') }}</th><th>{{ __('Rev') }}</th><th>{{ __('Scale') }}</th><th class="r">{{ __('Takeoff') }}</th><th class="r">{{ __('Pins') }}</th><th></th></tr></thead>
                                <tbody>
                                @foreach ($sheets as $sheet)
                                    <tr>
                                        <td class="num text-xs">{{ $sheet->version->drawing->number }}</td>
                                        <td>{{ $sheet->label() }}</td>
                                        <td>{{ $sheet->title ?: $sheet->version->drawing->title }}</td>
                                        <td class="num text-xs">{{ $sheet->version->revision }}</td>
                                        <td>@if ($sheet->isCalibrated())<span class="text-moss">{{ __('Set') }}</span>@else<span class="text-ink-3">{{ __('Not set') }}</span>@endif</td>
                                        <td class="r">{{ $sheet->measurements_count }}</td>
                                        <td class="r">{{ $sheet->pins_count }}</td>
                                        <td class="text-right"><a href="{{ route('drawings.register.sheet', $sheet) }}" class="link text-xs">{{ __('Open') }}</a></td>
                                    </tr>
                                @endforeach
                                </tbody>
                            </table>
                            <div class="p-3">{{ $sheets->links() }}</div>
                        @endif
                    @elseif ($drawings->isEmpty())
                        <x-empty :title="__('Nothing in the register yet')" :note="__('Add drawings and documents here to keep every revision and transmittal in one place.')" />
                    @else
                        <table class="ledger min-w-[760px]">
                            <thead><tr><th>{{ __('Number') }}</th><th class="w-[36%]">{{ __('Title') }}</th><th>{{ __('Discipline') }}</th><th>{{ __('Rev') }}</th><th>{{ __('Status') }}</th><th class="r">{{ __('Sheets') }}</th><th>{{ __('Updated') }}</th></tr></thead>
                            <tbody>
                            @foreach ($drawings as $drawing)
                                <tr>
                                    <td class="num text-xs"><a href="{{ route('drawings.register.show', $drawing) }}" class="link">{{ $drawing->number }}</a></td>
                                    <td>
                                        <p>{{ $drawing->title }}</p>
                                        <p class="text-xs text-ink-3">{{ __(ucfirst($drawing->kind)) }}@if ($drawing->folder) · {{ $drawing->folder->name }}@endif</p>
                                    </td>
                                    <td>{{ $drawing->discipline ? __($drawing->discipline) : '—' }}</td>
                                    <td class="num text-xs">{{ $drawing->currentVersion?->revision ?? '—' }}</td>
                                    <td>@if ($drawing->currentVersion)<x-stamp :status="$drawing->currentVersion->status" />@endif</td>
                                    <td class="r">{{ $drawing->currentVersion?->sheets->count() ?? 0 }}</td>
                                    <td class="num text-xs">{{ $drawing->currentVersion?->created_at?->format('d M Y') }}</td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                        <div class="p-3">{{ $drawings->links() }}</div>
                    @endif
                </div>
            </div>
        </div>
    </div>
@endsection
