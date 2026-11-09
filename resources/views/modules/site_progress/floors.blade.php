@extends('layouts.app')
@section('title', __('Floors'))
@section('content')
    @php
        $show = \App\Services\SiteProgress\ProgressReport::show(...);
        $allRows = collect($floors)->flatMap(fn (array $floor) => [$floor, ...$floor['children']]);
    @endphp
    <x-page-header :title="__('Site progress')" :subtitle="$project->name" />
    @include('modules.site_progress._tabs', ['active' => 'site-progress.floors.index'])

    <div class="grid gap-5 lg:grid-cols-[minmax(0,7fr)_minmax(0,5fr)]">
        <section class="card">
            <div class="card-head"><h2 class="card-title">{{ __('Floors and segments') }}</h2></div>
            @forelse ($floors as $floor)
                @foreach ([$floor, ...$floor['children']] as $row)
                    <div class="border-b border-rule last:border-b-0 {{ $row['parent_id'] ? 'pl-5' : '' }}">
                        <a href="{{ route('site-progress.floors.show', $row['id']) }}" class="block px-4 py-3 hover:bg-paper/70">
                            <div class="flex items-center justify-between gap-3">
                                <span class="min-w-0 truncate text-base font-semibold">{{ $row['name'] }}</span>
                                <span class="flex shrink-0 items-center gap-2">
                                    @if ($row['pending'] > 0)<span class="stamp border-laterite text-laterite">{{ __(':count pending', ['count' => $row['pending']]) }}</span>@endif
                                    <span class="num text-base font-medium">{{ $row['targets'] > 0 ? $show($row['percent']) : '—' }}</span>
                                </span>
                            </div>
                            <div class="mt-1.5">@include('modules.site_progress._bar', ['percent' => $row['percent']])</div>
                        </a>
                        @can('progress.manage')
                            <details class="px-4 pb-3">
                                <summary class="cursor-pointer text-sm font-semibold text-ink-2">{{ __('Rename or remove') }}</summary>
                                <div class="mt-2 flex flex-wrap items-end gap-3">
                                    <form method="POST" action="{{ route('site-progress.floors.update', $row['id']) }}" class="flex flex-1 flex-wrap items-end gap-2">
                                        @csrf @method('PATCH')
                                        <input type="hidden" name="parent_id" value="{{ $row['parent_id'] }}">
                                        <x-field :label="__('Name')" class="min-w-40 flex-1"><input name="name" value="{{ $row['name'] }}" maxlength="80" class="input min-h-11" required></x-field>
                                        <button class="btn btn-ink min-h-11">{{ __('Save') }}</button>
                                    </form>
                                    <form method="POST" action="{{ route('site-progress.floors.destroy', $row['id']) }}" onsubmit="return confirm(@js(__('Remove this floor? This only works while no work has been recorded on it.')))">
                                        @csrf @method('DELETE')
                                        <button class="btn btn-danger min-h-11">{{ __('Remove') }}</button>
                                    </form>
                                </div>
                            </details>
                        @endcan
                    </div>
                @endforeach
            @empty
                <x-empty :title="__('No floors yet')" :note="__('Add the floors or segments of this project, for example Foundation, Ground floor, 1st floor, Terrace.')" />
            @endforelse
        </section>

        @can('progress.manage')
            <div class="space-y-5">
                <form method="POST" action="{{ route('site-progress.floors.store', $project) }}" class="card space-y-4 p-4">
                    @csrf
                    <h2 class="card-title">{{ __('Add a floor or flat') }}</h2>
                    <x-field :label="__('Name')" name="name"><input id="name" name="name" value="{{ old('name') }}" maxlength="80" class="input min-h-12 text-base" placeholder="{{ __('For example: 2nd floor') }}" required></x-field>
                    <x-field :label="__('Part of')" name="parent_id" :hint="__('Choose a floor to add a flat or an area under it, such as Flat 201 or Passage.')">
                        <select id="parent_id" name="parent_id" class="input min-h-12 text-base">
                            <option value="">{{ __('Nothing: this is a floor') }}</option>
                            @foreach ($topFloors as $top)<option value="{{ $top->id }}" @selected((string) old('parent_id') === (string) $top->id)>{{ $top->name }}</option>@endforeach
                        </select>
                    </x-field>
                    <button class="btn btn-primary min-h-12 w-full text-base">{{ __('Add') }}</button>
                </form>

                @if ($allRows->isNotEmpty() && $items->isNotEmpty())
                    <form method="POST" action="{{ route('site-progress.floors.split', $project) }}" class="card space-y-4 p-4" x-data="{ all: true }">
                        @csrf
                        <h2 class="card-title">{{ __('Share BOQ quantities across floors') }}</h2>
                        <p class="text-sm text-ink-3">{{ __('The percentage of a floor is measured against the quantity planned for it. This splits each chosen BOQ line equally over the chosen floors and replaces any target those lines had. You can change the target of one floor afterwards.') }}</p>
                        <fieldset>
                            <legend class="label">{{ __('Floors') }}</legend>
                            <div class="grid gap-1">
                                @foreach ($allRows as $row)
                                    <label class="flex min-h-11 items-center gap-3 rounded-[3px] border border-rule px-3"><input type="checkbox" name="floor_ids[]" value="{{ $row['id'] }}" class="size-5 accent-[#1b4a66]"><span>{{ $row['parent_id'] ? '↳ ' : '' }}{{ $row['name'] }}</span></label>
                                @endforeach
                            </div>
                            @error('floor_ids')<p class="error">{{ $message }}</p>@enderror
                        </fieldset>
                        <label class="flex min-h-11 items-center gap-3"><input type="checkbox" name="all_items" value="1" x-model="all" checked class="size-5 accent-[#1b4a66]"><span class="font-semibold">{{ __('All BOQ items') }}</span></label>
                        <fieldset x-show="!all" x-cloak>
                            <legend class="label">{{ __('BOQ items') }}</legend>
                            <div class="max-h-64 space-y-1 overflow-y-auto">
                                @foreach ($items as $item)
                                    <label class="flex min-h-11 items-center gap-3 rounded-[3px] border border-rule px-3 text-sm"><input type="checkbox" name="boq_item_ids[]" value="{{ $item['id'] }}" class="size-5 shrink-0 accent-[#1b4a66]"><span><span class="num text-xs text-ink-3">{{ $item['code'] }}</span> {{ \Illuminate\Support\Str::limit($item['description'], 60) }}</span></label>
                                @endforeach
                            </div>
                        </fieldset>
                        <button class="btn btn-ink min-h-12 w-full text-base" onclick="return confirm(@js(__('Replace the targets of the chosen BOQ lines?')))">{{ __('Share equally') }}</button>
                    </form>
                @endif
            </div>
        @endcan
    </div>
@endsection
