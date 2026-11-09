@extends('layouts.app')
@php $editing = $asset->exists; @endphp
@section('title', $editing ? __('Edit asset') : __('Add asset'))
@section('content')
    <x-page-header :title="$editing ? __('Edit asset') : __('Add asset')" :subtitle="$editing ? $asset->code.' · '.$asset->name : null" />

    <form method="POST" action="{{ $editing ? route('assets.update', $asset) : route('assets.store') }}" class="max-w-4xl space-y-5"
          x-data="{ ownership: @js(old('ownership', $asset->ownership)), project: @js((string) old('project_id', $asset->project_id)), basis: @js(old('hire_basis', $asset->hire_basis)), sites: @js($sites) }">
        @csrf
        @if ($editing) @method('PATCH') @else <input type="hidden" name="client_uuid" value="{{ $clientUuid }}"> @endif

        <section class="card">
            <div class="card-head"><h2 class="card-title">{{ __('Details') }}</h2></div>
            <div class="grid gap-4 p-4 sm:grid-cols-3">
                <x-field :label="__('Name')" name="name" class="sm:col-span-2"><input id="name" name="name" value="{{ old('name', $asset->name) }}" class="input" required placeholder="{{ __('For example JCB 3DX') }}"></x-field>
                <x-field :label="__('Code')" name="code" :hint="$editing ? null : __('Leave empty to number it automatically.')"><input id="code" name="code" value="{{ old('code', $asset->code) }}" class="input num" maxlength="40"></x-field>
                <x-field :label="__('Category')" name="category">
                    <select id="category" name="category" class="input" required>
                        @foreach (config('modules.assets.categories') as $value => $label)<option value="{{ $value }}" @selected(old('category', $asset->category) === $value)>{{ __($label) }}</option>@endforeach
                    </select>
                </x-field>
                <x-field :label="__('Make')" name="make"><input id="make" name="make" value="{{ old('make', $asset->make) }}" class="input" maxlength="80"></x-field>
                <x-field :label="__('Model')" name="model"><input id="model" name="model" value="{{ old('model', $asset->model) }}" class="input" maxlength="80"></x-field>
                <x-field :label="__('Registration no.')" name="registration_no"><input id="registration_no" name="registration_no" value="{{ old('registration_no', $asset->registration_no) }}" class="input num" maxlength="40"></x-field>
                <x-field :label="__('Serial / chassis no.')" name="serial_no"><input id="serial_no" name="serial_no" value="{{ old('serial_no', $asset->serial_no) }}" class="input num" maxlength="60"></x-field>
                <x-field :label="__('Meter')" name="meter_unit" :hint="__('What the machine is logged in. Services can fall due by hours or km worked.')">
                    <select id="meter_unit" name="meter_unit" class="input">
                        <option value="">{{ __('No meter') }}</option>
                        @foreach (config('modules.assets.meter_units') as $value => $label)<option value="{{ $value }}" @selected(old('meter_unit', $asset->meter_unit) === $value)>{{ __($label) }}</option>@endforeach
                    </select>
                </x-field>
            </div>
        </section>

        <section class="card">
            <div class="card-head"><h2 class="card-title">{{ __('Ownership') }}</h2></div>
            <div class="grid gap-4 p-4 sm:grid-cols-3">
                <x-field :label="__('Owned or hired')" name="ownership">
                    <select id="ownership" name="ownership" class="input" x-model="ownership">
                        @foreach (config('modules.assets.ownerships') as $value => $label)<option value="{{ $value }}">{{ __($label) }}</option>@endforeach
                    </select>
                </x-field>
                <template x-if="ownership === 'owned'">
                    <div class="grid gap-4 sm:col-span-2 sm:grid-cols-2">
                        <x-field :label="__('Purchase date')" name="purchase_date"><input id="purchase_date" name="purchase_date" type="date" max="{{ now()->format('Y-m-d') }}" value="{{ old('purchase_date', $asset->purchase_date?->format('Y-m-d')) }}" class="input"></x-field>
                        <x-field :label="__('Purchase cost (₹)')" name="purchase_cost"><input id="purchase_cost" name="purchase_cost" type="number" step="0.01" min="0" value="{{ old('purchase_cost', $asset->purchase_cost) }}" class="input num"></x-field>
                    </div>
                </template>
                <template x-if="ownership === 'hired'">
                    <div class="grid gap-4 sm:col-span-3 sm:grid-cols-3">
                        <x-field :label="__('Owner of the machine')" name="vendor_id">
                            <select id="vendor_id" name="vendor_id" class="input">
                                <option value="">—</option>
                                @foreach ($vendors as $vendor)<option value="{{ $vendor->id }}" @selected((string) old('vendor_id', $asset->vendor_id) === (string) $vendor->id)>{{ $vendor->name }}</option>@endforeach
                            </select>
                        </x-field>
                        <x-field :label="__('Charged')" name="hire_basis">
                            <select id="hire_basis" name="hire_basis" class="input" x-model="basis">
                                <option value="">—</option>
                                @foreach (config('modules.assets.hire_bases') as $value => $label)<option value="{{ $value }}">{{ __($label) }}</option>@endforeach
                            </select>
                        </x-field>
                        <x-field :label="__('Hire rate (₹)')" name="hire_rate"><input id="hire_rate" name="hire_rate" type="number" step="0.01" min="0" value="{{ old('hire_rate', $asset->hire_rate) }}" class="input num"></x-field>
                        <x-field :label="__('Fuel')" name="hire_type">
                            <select id="hire_type" name="hire_type" class="input">
                                @foreach (config('modules.assets.hire_types') as $value => $label)<option value="{{ $value }}" @selected(old('hire_type', $asset->hire_type) === $value)>{{ __($label) }}</option>@endforeach
                            </select>
                        </x-field>
                        <div x-show="basis === 'hour'">
                            <x-field :label="__('Minimum hours per month')" name="min_hours_per_month" :hint="__('The owner bills at least this much for a full month.')"><input id="min_hours_per_month" name="min_hours_per_month" type="number" step="0.001" min="0" value="{{ old('min_hours_per_month', $asset->min_hours_per_month) }}" class="input num"></x-field>
                        </div>
                        <x-field :label="__('Hired from')" name="hire_from"><input id="hire_from" name="hire_from" type="date" value="{{ old('hire_from', $asset->hire_from?->format('Y-m-d')) }}" class="input"></x-field>
                        <x-field :label="__('Hired until')" name="hire_to"><input id="hire_to" name="hire_to" type="date" value="{{ old('hire_to', $asset->hire_to?->format('Y-m-d')) }}" class="input"></x-field>
                    </div>
                </template>
            </div>
        </section>

        @unless ($editing)
            <section class="card">
                <div class="card-head"><h2 class="card-title">{{ __('Where it is now') }}</h2></div>
                <div class="grid gap-4 p-4 sm:grid-cols-3">
                    <x-field :label="__('Project')" name="project_id">
                        <select id="project_id" name="project_id" class="input" x-model="project">
                            <option value="">—</option>
                            @foreach ($projects as $project)<option value="{{ $project->id }}">{{ $project->name }}</option>@endforeach
                        </select>
                    </x-field>
                    <x-field :label="__('Site')" name="site_id">
                        <select id="site_id" name="site_id" class="input">
                            <option value="">—</option>
                            <template x-for="site in sites.filter(s => String(s.project_id) === project)" :key="site.id"><option :value="site.id" x-text="site.name" :selected="String(site.id) === @js((string) old('site_id'))"></option></template>
                        </select>
                    </x-field>
                    <x-field :label="__('Yard, store or other place')" name="location_note"><input id="location_note" name="location_note" value="{{ old('location_note') }}" class="input" maxlength="160"></x-field>
                </div>
            </section>
        @endunless

        <section class="card">
            <div class="grid gap-4 p-4 sm:grid-cols-3">
                @if ($editing)
                    <x-field :label="__('Status')" name="status" :hint="__('Retire an asset that has been sold or sent back. Its history stays.')">
                        <select id="status" name="status" class="input">
                            <option value="active" @selected($asset->status !== 'retired')>{{ __('Active') }}</option>
                            <option value="retired" @selected($asset->status === 'retired')>{{ __('Retired') }}</option>
                        </select>
                    </x-field>
                @endif
                <x-field :label="__('Notes')" name="notes" class="sm:col-span-2"><textarea id="notes" name="notes" rows="2" class="input">{{ old('notes', $asset->notes) }}</textarea></x-field>
            </div>
        </section>

        <div class="flex gap-2">
            <button class="btn btn-primary">{{ __('Save') }}</button>
            <a href="{{ $editing ? route('assets.show', $asset) : route('assets.index') }}" class="btn btn-ghost">{{ __('Cancel') }}</a>
        </div>
    </form>
@endsection
