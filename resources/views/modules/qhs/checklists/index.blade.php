@extends('layouts.app')
@section('title', __('Checklists'))
@section('content')
    <div x-data="{ add: {{ $errors->any() && old('name') !== null && ! old('_template') ? 'true' : 'false' }} }">
        <x-page-header :title="__('Checklists')" :subtitle="__('Reusable check lists for pours, shuttering, PPE and safety')">
            @can('checklist.manage')
                <form method="POST" action="{{ route('checklists.standard') }}">@csrf<button class="btn btn-ghost">{{ __('Add standard checklists') }}</button></form>
                <button type="button" class="btn btn-primary" @click="add = !add">{{ __('New checklist') }}</button>
            @endcan
        </x-page-header>

        @can('checklist.manage')
            <form x-show="add" x-cloak method="POST" action="{{ route('checklists.store') }}" class="card mb-5 grid gap-3 p-4 sm:grid-cols-3">
                @csrf
                <input type="hidden" name="client_uuid" value="{{ $clientUuid }}">
                <x-field :label="__('Name')" name="name"><input id="name" name="name" value="{{ old('name') }}" class="input" maxlength="120" required></x-field>
                <x-field :label="__('Category')" name="category">
                    <select id="category" name="category" class="input">
                        @foreach (config('modules.qhs.checklist_categories') as $value => $label)<option value="{{ $value }}" @selected(old('category', 'general') === $value)>{{ __($label) }}</option>@endforeach
                    </select>
                </x-field>
                <x-field :label="__('Description')" name="description"><input id="description" name="description" value="{{ old('description') }}" class="input" maxlength="255"></x-field>
                <x-field :label="__('Check points')" name="items" class="sm:col-span-3" :hint="__('One check point per line.')"><textarea id="items_text" name="items_text" rows="6" class="input">{{ old('items_text') }}</textarea></x-field>
                <div class="sm:col-span-3"><button class="btn btn-primary">{{ __('Save checklist') }}</button></div>
            </form>
        @endcan

        <div class="card">
            @forelse ($templates as $template)
                <details class="border-b border-rule px-4 py-3 last:border-b-0 {{ $template->is_active ? '' : 'opacity-60' }}" @if (old('_template') == $template->id) open @endif>
                    <summary class="flex cursor-pointer flex-wrap items-center justify-between gap-2">
                        <span class="font-semibold">{{ $template->name }}</span>
                        <span class="text-xs text-ink-3">{{ __(config('modules.qhs.checklist_categories')[$template->category] ?? $template->category) }} · {{ __(':count check points', ['count' => count($template->items)]) }} · {{ __(':count inspections', ['count' => $template->inspections_count]) }}@unless ($template->is_active) · {{ __('Inactive') }}@endunless</span>
                    </summary>
                    @can('checklist.manage')
                        <form method="POST" action="{{ route('checklists.update', $template) }}" class="mt-3 grid gap-3 sm:grid-cols-3">
                            @csrf @method('PATCH')
                            <input type="hidden" name="_template" value="{{ $template->id }}">
                            <x-field :label="__('Name')" name="name"><input name="name" value="{{ old('_template') == $template->id ? old('name') : $template->name }}" class="input" maxlength="120" required></x-field>
                            <x-field :label="__('Category')" name="category">
                                <select name="category" class="input">
                                    @foreach (config('modules.qhs.checklist_categories') as $value => $label)<option value="{{ $value }}" @selected($template->category === $value)>{{ __($label) }}</option>@endforeach
                                </select>
                            </x-field>
                            <x-field :label="__('Description')" name="description"><input name="description" value="{{ $template->description }}" class="input" maxlength="255"></x-field>
                            <x-field :label="__('Check points')" name="items" class="sm:col-span-3" :hint="__('Inspections already made keep the check points they started with.')"><textarea name="items_text" rows="8" class="input">{{ old('_template') == $template->id ? old('items_text') : implode("\n", $template->items) }}</textarea></x-field>
                            <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="is_active" value="1" @checked($template->is_active)> {{ __('In use') }}</label>
                            <div class="sm:col-span-3"><button class="btn btn-ghost">{{ __('Save changes') }}</button></div>
                        </form>
                    @else
                        <ol class="mt-3 list-decimal space-y-1 pl-5 text-sm text-ink-2">@foreach ($template->items as $text)<li>{{ $text }}</li>@endforeach</ol>
                    @endcan
                </details>
            @empty
                <x-empty :title="__('No checklists yet')" :note="__('Add the standard pour card, shuttering, reinforcement, PPE and safety checklists in one click, then edit them to suit your sites.')" />
            @endforelse
        </div>
    </div>
@endsection
