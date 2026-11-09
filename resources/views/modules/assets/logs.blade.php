@extends('layouts.app')
@section('title', __('Machine log'))
@section('content')
    <x-page-header :title="__('Machine log')" :subtitle="__('Hours and km worked, day by day')" />

    @can('asset.log')
        <form method="POST" action="{{ route('machine-logs.store') }}" class="card mb-5 grid gap-3 p-4 sm:grid-cols-4 lg:grid-cols-6">
            @csrf
            <input type="hidden" name="client_uuid" value="{{ \Illuminate\Support\Str::uuid() }}">
            <x-field :label="__('Machine')" name="asset_id" class="sm:col-span-2">
                <select id="asset_id" name="asset_id" class="input" required>
                    <option value="">—</option>
                    @foreach ($assets as $asset)<option value="{{ $asset->id }}" @selected((string) old('asset_id') === (string) $asset->id)>{{ $asset->code }} · {{ $asset->name }}</option>@endforeach
                </select>
            </x-field>
            <x-field :label="__('Date')" name="log_date"><input id="log_date" name="log_date" type="date" max="{{ now()->format('Y-m-d') }}" value="{{ old('log_date', now()->format('Y-m-d')) }}" class="input" required></x-field>
            <x-field :label="__('Project')" name="project_id">
                <select id="project_id" name="project_id" class="input">
                    <option value="">—</option>
                    @foreach ($projects as $project)<option value="{{ $project->id }}" @selected((string) old('project_id') === (string) $project->id)>{{ $project->name }}</option>@endforeach
                </select>
            </x-field>
            <x-field :label="__('Hours worked')" name="hours"><input id="hours" name="hours" type="number" step="0.5" min="0" max="24" inputmode="decimal" value="{{ old('hours') }}" class="input num"></x-field>
            <x-field :label="__('Km run')" name="km"><input id="km" name="km" type="number" step="0.1" min="0" inputmode="decimal" value="{{ old('km') }}" class="input num"></x-field>
            <x-field :label="__('Opening reading')" name="opening_reading"><input id="opening_reading" name="opening_reading" type="number" step="0.1" min="0" value="{{ old('opening_reading') }}" class="input num"></x-field>
            <x-field :label="__('Closing reading')" name="closing_reading"><input id="closing_reading" name="closing_reading" type="number" step="0.1" min="0" value="{{ old('closing_reading') }}" class="input num"></x-field>
            <x-field :label="__('Hours lost to breakdown')" name="breakdown_hours"><input id="breakdown_hours" name="breakdown_hours" type="number" step="0.5" min="0" max="24" value="{{ old('breakdown_hours') }}" class="input num"></x-field>
            <x-field :label="__('Operator')" name="operator"><input id="operator" name="operator" value="{{ old('operator') }}" class="input" maxlength="80"></x-field>
            <x-field :label="__('Remarks')" name="remarks" class="sm:col-span-2"><input id="remarks" name="remarks" value="{{ old('remarks') }}" class="input" maxlength="255"></x-field>
            <div class="flex items-end"><button class="btn btn-primary w-full">{{ __('Save log') }}</button></div>
        </form>
    @endcan

    <form method="GET" action="{{ route('machine-logs.index') }}" class="mb-3 flex flex-wrap items-end gap-3">
        <x-field :label="__('Machine')" name="filter_asset" class="min-w-52">
            <select id="filter_asset" name="asset_id" class="input">
                <option value="">{{ __('All') }}</option>
                @foreach ($assets as $asset)<option value="{{ $asset->id }}" @selected((string) $filters['asset_id'] === (string) $asset->id)>{{ $asset->code }} · {{ $asset->name }}</option>@endforeach
            </select>
        </x-field>
        <x-field :label="__('From')" name="from"><input id="from" name="from" type="date" value="{{ $filters['from'] }}" class="input"></x-field>
        <x-field :label="__('To')" name="to"><input id="to" name="to" type="date" value="{{ $filters['to'] }}" class="input"></x-field>
        <button class="btn btn-ink">{{ __('Filter') }}</button>
    </form>

    <div class="mb-3 grid grid-cols-3 gap-3">
        <x-stat :label="__('Hours worked')" :value="fmt_qty($totals['hours'])" />
        <x-stat :label="__('Km run')" :value="fmt_qty($totals['km'])" />
        <x-stat :label="__('Hours lost to breakdown')" :value="fmt_qty($totals['breakdown_hours'])" :accent="$totals['breakdown_hours'] !== '0.000'" />
    </div>

    <div class="card overflow-x-auto">
        @if ($logs->isEmpty())
            <x-empty :title="__('No logs in this period')" />
        @else
            <table class="ledger min-w-[720px]">
                <thead><tr><th>{{ __('Date') }}</th><th>{{ __('Machine') }}</th><th>{{ __('Project') }}</th><th class="r">{{ __('Hours') }}</th><th class="r">{{ __('Km') }}</th><th class="r">{{ __('Lost') }}</th><th>{{ __('Operator') }}</th></tr></thead>
                <tbody>
                @foreach ($logs as $log)
                    <tr>
                        <td class="num">{{ $log->log_date->format('d M Y') }}</td>
                        <td><a href="{{ route('assets.show', $log->asset_id) }}" class="link">{{ $log->asset->code }}</a> <span class="text-ink-3">{{ $log->asset->name }}</span></td>
                        <td>{{ $log->project?->name ?? '—' }}</td>
                        <td class="r">{{ fmt_qty($log->hours) }}</td>
                        <td class="r">{{ fmt_qty($log->km) }}</td>
                        <td class="r">{{ fmt_qty($log->breakdown_hours) }}</td>
                        <td>{{ $log->operator ?? '—' }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        @endif
    </div>
    <div class="mt-4">{{ $logs->links() }}</div>
@endsection
