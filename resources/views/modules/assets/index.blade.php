@extends('layouts.app')
@section('title', __('Assets'))
@section('content')
    <x-page-header :title="__('Assets')" :subtitle="__('Plant, machines and vehicles')">
        <a href="{{ route('assets.tags') }}" target="_blank" rel="noopener" class="btn btn-ghost"><x-icon name="qr" class="size-4" />{{ __('Print all tags') }}</a>
        @can('asset.manage')<a href="{{ route('assets.create') }}" class="btn btn-primary">{{ __('Add asset') }}</a>@endcan
    </x-page-header>

    <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
        <x-stat :label="__('Assets')" :value="$counts['total']" />
        <x-stat :label="__('On hire')" :value="$counts['hired']" />
        <x-stat :label="__('Under repair')" :value="$counts['under_repair']" :accent="$counts['under_repair'] > 0" />
        <x-stat :label="__('Services due')" :value="$counts['service_due']" :accent="$counts['service_due'] > 0" />
    </div>

    <form method="GET" action="{{ route('assets.index') }}" class="card mt-5 grid gap-3 p-4 sm:grid-cols-3 lg:grid-cols-6">
        <x-field :label="__('Search')" name="q" class="lg:col-span-2"><input id="q" name="q" value="{{ $filters['q'] ?? '' }}" class="input" placeholder="{{ __('Name, code or registration') }}"></x-field>
        <x-field :label="__('Ownership')" name="ownership">
            <select id="ownership" name="ownership" class="input">
                <option value="">{{ __('All') }}</option>
                @foreach (config('modules.assets.ownerships') as $value => $label)<option value="{{ $value }}" @selected(($filters['ownership'] ?? '') === $value)>{{ __($label) }}</option>@endforeach
            </select>
        </x-field>
        <x-field :label="__('Category')" name="category">
            <select id="category" name="category" class="input">
                <option value="">{{ __('All') }}</option>
                @foreach (config('modules.assets.categories') as $value => $label)<option value="{{ $value }}" @selected(($filters['category'] ?? '') === $value)>{{ __($label) }}</option>@endforeach
            </select>
        </x-field>
        <x-field :label="__('Status')" name="status">
            <select id="status" name="status" class="input">
                <option value="">{{ __('All') }}</option>
                @foreach (config('modules.assets.statuses') as $value => $label)<option value="{{ $value }}" @selected(($filters['status'] ?? '') === $value)>{{ __($label) }}</option>@endforeach
            </select>
        </x-field>
        <x-field :label="__('Project')" name="project_id">
            <select id="project_id" name="project_id" class="input">
                <option value="">{{ __('All') }}</option>
                @foreach ($projects as $project)<option value="{{ $project->id }}" @selected((string) ($filters['project_id'] ?? '') === (string) $project->id)>{{ $project->name }}</option>@endforeach
            </select>
        </x-field>
        <div class="flex items-end gap-2 sm:col-span-3 lg:col-span-6">
            <button class="btn btn-ink">{{ __('Filter') }}</button>
            <a href="{{ route('assets.index') }}" class="btn btn-ghost">{{ __('Clear') }}</a>
        </div>
    </form>

    <div class="card mt-5 overflow-x-auto">
        @if ($assets->isEmpty())
            <x-empty :title="__('No assets yet')" :note="__('Register your machines and vehicles to log their work, fuel and servicing.')" />
        @else
            <table class="ledger min-w-[820px]">
                <thead><tr><th>{{ __('Code') }}</th><th>{{ __('Asset') }}</th><th>{{ __('Category') }}</th><th>{{ __('Ownership') }}</th><th>{{ __('Location') }}</th><th>{{ __('Status') }}</th></tr></thead>
                <tbody>
                @foreach ($assets as $asset)
                    <tr>
                        <td><a href="{{ route('assets.show', $asset) }}" class="link num">{{ $asset->code }}</a></td>
                        <td>
                            <p class="font-semibold">{{ $asset->name }}</p>
                            <p class="text-xs text-ink-3">{{ collect([$asset->make, $asset->model, $asset->registration_no])->filter()->join(' · ') }}</p>
                        </td>
                        <td>{{ __(config('modules.assets.categories')[$asset->category] ?? $asset->category) }}</td>
                        <td>@include('modules.field_ops._chip', ['set' => 'ownership', 'value' => $asset->ownership])</td>
                        <td>{{ collect([$asset->project?->name, $asset->location_note])->filter()->join(' · ') ?: '—' }}</td>
                        <td>@include('modules.field_ops._chip', ['set' => 'asset_status', 'value' => $asset->status])</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        @endif
    </div>
    <div class="mt-4">{{ $assets->links() }}</div>
@endsection
