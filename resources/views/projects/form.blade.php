@extends('layouts.app')
@section('title', $project->exists ? __('Edit project') : __('New project'))
@section('content')
    <x-page-header :title="$project->exists ? __('Edit project') : __('New project')" />

    <form method="POST" action="{{ $project->exists ? route('projects.update', $project) : route('projects.store') }}" class="max-w-3xl space-y-5">
        @csrf
        @if ($project->exists) @method('PATCH') @endif

        <section class="card">
            <div class="card-head"><h2 class="card-title">{{ __('Project details') }}</h2></div>
            <div class="grid gap-4 p-4 sm:grid-cols-2">
                <x-field :label="__('Project code')" name="code" :hint="__('Short and unique, used on bill numbers.')">
                    <input id="code" name="code" value="{{ old('code', $project->code) }}" class="input" required maxlength="32">
                </x-field>
                <x-field :label="__('Project name')" name="name">
                    <input id="name" name="name" value="{{ old('name', $project->name) }}" class="input" required>
                </x-field>
                <x-field :label="__('Client')" name="client_id">
                    <select id="client_id" name="client_id" class="input">
                        <option value="">—</option>
                        @foreach ($clients as $client)
                            <option value="{{ $client->id }}" @selected(old('client_id', $project->client_id) == $client->id)>{{ $client->name }}</option>
                        @endforeach
                    </select>
                </x-field>
                <x-field :label="__('Contract value (₹)')" name="contract_value">
                    <input id="contract_value" name="contract_value" type="number" step="0.01" min="0" value="{{ old('contract_value', $project->contract_value ?? 0) }}" class="input num" required>
                </x-field>
                <x-field :label="__('Location')" name="location">
                    <input id="location" name="location" value="{{ old('location', $project->location) }}" class="input">
                </x-field>
                <x-field :label="__('State')" name="state">
                    <input id="state" name="state" value="{{ old('state', $project->state ?? 'Maharashtra') }}" class="input">
                </x-field>
                <x-field :label="__('Start date')" name="start_date">
                    <input id="start_date" name="start_date" type="date" value="{{ old('start_date', $project->start_date?->format('Y-m-d')) }}" class="input">
                </x-field>
                <x-field :label="__('End date')" name="end_date">
                    <input id="end_date" name="end_date" type="date" value="{{ old('end_date', $project->end_date?->format('Y-m-d')) }}" class="input">
                </x-field>
                @if ($project->exists)
                    <x-field :label="__('Status')" name="status">
                        <select id="status" name="status" class="input">
                            @foreach (config('buildos.project_statuses') as $status)
                                <option value="{{ $status }}" @selected(old('status', $project->status) === $status)>{{ __(\Illuminate\Support\Str::headline($status)) }}</option>
                            @endforeach
                        </select>
                    </x-field>
                @endif
            </div>
        </section>

        <section class="card">
            <div class="card-head"><h2 class="card-title">{{ __('Client billing terms') }}</h2></div>
            <div class="grid gap-4 p-4 sm:grid-cols-3">
                <x-field :label="__('Retention %')" name="retention_pct">
                    <input id="retention_pct" name="retention_pct" type="number" step="0.01" value="{{ old('retention_pct', $project->retention_pct ?? 5) }}" class="input num" required>
                </x-field>
                <x-field :label="__('TDS %')" name="tds_pct">
                    <input id="tds_pct" name="tds_pct" type="number" step="0.01" value="{{ old('tds_pct', $project->tds_pct ?? 2) }}" class="input num" required>
                </x-field>
                <x-field :label="__('GST %')" name="gst_pct" :hint="__('Enter 0 if this contract has no GST.')">
                    <input id="gst_pct" name="gst_pct" type="number" step="0.01" value="{{ old('gst_pct', $project->gst_pct ?? 18) }}" class="input num" required>
                </x-field>
                <x-field :label="__('Mobilisation advance (₹)')" name="advance_amount">
                    <input id="advance_amount" name="advance_amount" type="number" step="0.01" value="{{ old('advance_amount', $project->advance_amount ?? 0) }}" class="input num" required>
                </x-field>
                <x-field :label="__('Advance recovery %')" name="advance_recovery_pct">
                    <input id="advance_recovery_pct" name="advance_recovery_pct" type="number" step="0.01" value="{{ old('advance_recovery_pct', $project->advance_recovery_pct ?? 0) }}" class="input num" required>
                </x-field>
                <x-field :label="__('Measurement tolerance %')" name="measurement_tolerance_pct" :hint="__('Above this, entries are flagged as deviation.')">
                    <input id="measurement_tolerance_pct" name="measurement_tolerance_pct" type="number" step="0.01" value="{{ old('measurement_tolerance_pct', $project->measurement_tolerance_pct ?? 5) }}" class="input num" required>
                </x-field>
                <x-field :label="__('Deductions calculated on')" name="deduction_basis">
                    <select id="deduction_basis" name="deduction_basis" class="input">
                        <option value="gross" @selected(old('deduction_basis', $project->deduction_basis ?? 'gross') === 'gross')>{{ __('Gross bill value') }}</option>
                        <option value="running" @selected(old('deduction_basis', $project->deduction_basis) === 'running')>{{ __('Balance after earlier deductions') }}</option>
                    </select>
                </x-field>
                <x-field :label="__('Deduction order')" name="deduction_order" class="sm:col-span-2">
                    <select id="deduction_order" name="deduction_order" class="input">
                        @foreach (config('buildos.deduction_orders') as $value => $label)
                            <option value="{{ $value }}" @selected(old('deduction_order', implode(',', $project->deduction_order ?? config('buildos.default_deduction_order'))) === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                </x-field>
            </div>
        </section>

        <button class="btn btn-primary">{{ $project->exists ? __('Save changes') : __('Create project') }}</button>
    </form>
@endsection
