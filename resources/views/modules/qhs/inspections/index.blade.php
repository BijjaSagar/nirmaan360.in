@extends('layouts.app')
@section('title', __('Inspections'))
@php($preferred = $templates->firstWhere('category', $category) ?? $templates->first())
@section('content')
    <div x-data="{ add: {{ $errors->any() && old('checklist_template_id') !== null ? 'true' : 'false' }} }">
        <x-page-header :title="__('Inspections')" :subtitle="$project->name">
            @can('checklist.fill')<button type="button" class="btn btn-primary" @click="add = !add">{{ __('Start inspection') }}</button>@endcan
        </x-page-header>

        @can('checklist.fill')
            <div x-show="add" x-cloak class="card mb-5 p-4">
                @if ($templates->isEmpty())
                    <p class="text-sm text-ink-2">{{ __('There are no checklists yet.') }} @can('checklist.manage')<a href="{{ route('checklists.index') }}" class="link">{{ __('Add the standard checklists') }}</a>@endcan</p>
                @else
                    <form method="POST" action="{{ route('inspections.store', $project) }}" class="grid gap-3 sm:grid-cols-4">
                        @csrf
                        <input type="hidden" name="client_uuid" value="{{ $clientUuid }}">
                        <x-field :label="__('Checklist')" name="checklist_template_id" class="sm:col-span-2">
                            <select id="checklist_template_id" name="checklist_template_id" class="input" required>
                                @foreach ($templates as $template)<option value="{{ $template->id }}" @selected((string) old('checklist_template_id', $preferred?->id) === (string) $template->id)>{{ $template->name }}</option>@endforeach
                            </select>
                        </x-field>
                        <x-field :label="__('Location')" name="location" class="sm:col-span-2"><input id="location" name="location" value="{{ old('location') }}" class="input" maxlength="160" placeholder="{{ __('For example slab, third floor, grid B-D') }}"></x-field>
                        <x-field :label="__('Site')" name="site_id">
                            <select id="site_id" name="site_id" class="input"><option value="">—</option>@foreach ($sites as $site)<option value="{{ $site->id }}" @selected((string) old('site_id') === (string) $site->id)>{{ $site->name }}</option>@endforeach</select>
                        </x-field>
                        <x-field :label="__('Date')" name="inspected_on"><input id="inspected_on" name="inspected_on" type="date" max="{{ now()->format('Y-m-d') }}" value="{{ old('inspected_on', now()->format('Y-m-d')) }}" class="input"></x-field>
                        <div class="flex items-end sm:col-span-2"><button class="btn btn-primary">{{ __('Start') }}</button></div>
                    </form>
                @endif
            </div>
        @endcan

        <div class="mb-3 flex flex-wrap gap-1.5 text-sm">
            <a href="{{ route('inspections.index', $project) }}" class="btn {{ $category ? 'btn-ghost' : 'btn-ink' }}">{{ __('All') }}</a>
            @foreach (config('modules.qhs.checklist_categories') as $value => $label)
                <a href="{{ route('inspections.index', [$project, 'category' => $value]) }}" class="btn {{ $category === $value ? 'btn-ink' : 'btn-ghost' }}">{{ __($label) }}</a>
            @endforeach
        </div>

        <div class="card overflow-x-auto">
            @if ($inspections->isEmpty())
                <x-empty :title="__('No inspections here')" />
            @else
                <table class="ledger min-w-[720px]">
                    <thead><tr><th>{{ __('Inspection') }}</th><th>{{ __('Category') }}</th><th>{{ __('Date') }}</th><th>{{ __('Inspector') }}</th><th class="r">{{ __('Failed') }}</th><th>{{ __('Result') }}</th></tr></thead>
                    <tbody>
                    @foreach ($inspections as $inspection)
                        <tr>
                            <td>
                                <a href="{{ route('inspections.show', $inspection) }}" class="link">{{ $inspection->title }}</a>
                                @if ($inspection->location)<p class="text-xs text-ink-3">{{ $inspection->location }}</p>@endif
                            </td>
                            <td>{{ __(config('modules.qhs.checklist_categories')[$inspection->category] ?? $inspection->category) }}</td>
                            <td class="num">{{ $inspection->inspected_on->format('d M Y') }}</td>
                            <td>{{ $inspection->inspector?->name ?? '—' }}</td>
                            <td class="r {{ $inspection->failed_count > 0 ? 'text-laterite' : '' }}">{{ $inspection->failed_count }} / {{ $inspection->items_count }}</td>
                            <td>@include('modules.field_ops._chip', ['set' => 'inspection', 'value' => $inspection->status])</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            @endif
        </div>
        <div class="mt-4">{{ $inspections->links() }}</div>
    </div>
@endsection
