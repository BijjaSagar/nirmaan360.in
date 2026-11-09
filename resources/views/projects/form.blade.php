@extends('layouts.app')
@section('title', $project->exists ? __('Edit project') : __('New project'))
@section('content')
    <x-page-header :title="$project->exists ? __('Edit project') : __('New project')" />

    @php $canAddClient = auth()->user()->can('party.manage') && auth()->user()->organization->allowsFeature('parties'); @endphp

    @if ($canAddClient)
        <script>
            function clientPopup() {
                const blank = { name: '', contact_person: '', phone: '', email: '', gstin: '', address: '' };

                return {
                    open: false,
                    saving: false,
                    failed: false,
                    errors: {},
                    client: { ...blank },
                    show() {
                        this.client = { ...blank };
                        this.errors = {};
                        this.failed = false;
                        this.open = true;
                        this.$nextTick(() => this.$refs.clientName.focus());
                    },
                    hide() {
                        if (this.open && ! this.saving) {
                            this.open = false;
                            this.$nextTick(() => this.$refs.addClient.focus());
                        }
                    },
                    async save() {
                        if (this.saving) return;

                        this.saving = true;
                        this.errors = {};
                        this.failed = false;

                        try {
                            const response = await fetch(@js(route('parties.store')), {
                                method: 'POST',
                                headers: {
                                    'Content-Type': 'application/json',
                                    Accept: 'application/json',
                                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                                },
                                body: JSON.stringify({ ...this.client, gstin: this.client.gstin.toUpperCase(), type: 'client' }),
                            });

                            if (response.status === 422) {
                                this.errors = (await response.json()).errors;
                            } else if (response.ok) {
                                const created = await response.json();
                                this.$refs.clientSelect.add(new Option(created.name, created.id, true, true));
                                this.saving = false;
                                this.hide();
                            } else {
                                this.failed = true;
                            }
                        } catch {
                            this.failed = true;
                        } finally {
                            this.saving = false;
                        }
                    },
                };
            }
        </script>
    @endif

    <div @if ($canAddClient) x-data="clientPopup()" @endif>
    <form method="POST" action="{{ $project->exists ? route('projects.update', $project) : route('projects.store') }}" enctype="multipart/form-data" class="max-w-3xl space-y-5" @if ($canAddClient) :inert="open" @endif>
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
                <x-field :label="__('Project photo')" name="image" :hint="__('Optional. JPG, PNG or WebP, up to 8 MB. It shows on the project list and in the app.')" class="sm:col-span-2">
                    <div class="flex items-center gap-3">
                        @if ($project->image_path)<x-project-thumb :project="$project" size="h-14 w-14" />@endif
                        <input id="image" name="image" type="file" accept="image/jpeg,image/png,image/webp" class="input min-w-0">
                    </div>
                    @if ($project->image_path)
                        <label class="mt-2 inline-flex items-center gap-2 text-sm"><input type="checkbox" name="remove_image" value="1"> {{ __('Remove this photo') }}</label>
                    @endif
                </x-field>
                <x-field :label="__('Client')" name="client_id">
                    <div class="flex gap-2">
                        <select id="client_id" name="client_id" class="input min-w-0" x-ref="clientSelect">
                            <option value="">—</option>
                            @foreach ($clients as $client)
                                <option value="{{ $client->id }}" @selected(old('client_id', $project->client_id) == $client->id)>{{ $client->name }}</option>
                            @endforeach
                        </select>
                        @if ($canAddClient)
                            <button type="button" class="btn btn-ghost shrink-0" x-ref="addClient" @click="show()"><x-icon name="plus" class="size-4" />{{ __('Add client') }}</button>
                        @endif
                    </div>
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

        @include('modules.project_setup._project-fields')

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

    @if ($canAddClient)
        <div x-show="open" x-cloak class="fixed inset-0 z-50 grid place-items-center overflow-y-auto bg-ink/50 p-4" role="dialog" aria-modal="true" aria-labelledby="add-client-title" @keydown.escape.window="hide()">
            <form class="card w-full max-w-lg" @submit.prevent="save()" novalidate>
                <div class="card-head">
                    <h2 id="add-client-title" class="card-title">{{ __('Add client') }}</h2>
                    <button type="button" class="p-1 text-ink-3 hover:text-ink" @click="hide()" aria-label="{{ __('Close') }}"><x-icon name="x" /></button>
                </div>
                <div class="grid gap-3 p-4 sm:grid-cols-2">
                    <div class="sm:col-span-2">
                        <label class="label" for="client-name">{{ __('Name') }}</label>
                        <input id="client-name" x-ref="clientName" x-model="client.name" class="input" maxlength="160" autocomplete="organization">
                        <p class="error" x-show="errors.name" x-text="errors.name?.[0]"></p>
                    </div>
                    <div>
                        <label class="label" for="client-contact">{{ __('Contact person') }}</label>
                        <input id="client-contact" x-model="client.contact_person" class="input" maxlength="120">
                        <p class="error" x-show="errors.contact_person" x-text="errors.contact_person?.[0]"></p>
                    </div>
                    <div>
                        <label class="label" for="client-phone">{{ __('Mobile') }}</label>
                        <input id="client-phone" x-model="client.phone" class="input" inputmode="tel" maxlength="20">
                        <p class="error" x-show="errors.phone" x-text="errors.phone?.[0]"></p>
                    </div>
                    <div>
                        <label class="label" for="client-email">{{ __('Email') }}</label>
                        <input id="client-email" x-model="client.email" type="email" class="input" maxlength="190">
                        <p class="error" x-show="errors.email" x-text="errors.email?.[0]"></p>
                    </div>
                    <div>
                        <label class="label" for="client-gstin">{{ __('GSTIN') }}</label>
                        <input id="client-gstin" x-model="client.gstin" class="input num uppercase" maxlength="15">
                        <p class="error" x-show="errors.gstin" x-text="errors.gstin?.[0]"></p>
                    </div>
                    <div class="sm:col-span-2">
                        <label class="label" for="client-address">{{ __('Address') }}</label>
                        <input id="client-address" x-model="client.address" class="input" maxlength="500">
                        <p class="error" x-show="errors.address" x-text="errors.address?.[0]"></p>
                    </div>
                </div>
                <p class="error px-4 pb-3" x-show="failed" role="alert">{{ __('Could not save. Check your connection and try again.') }}</p>
                <div class="flex justify-end gap-2 border-t border-rule px-4 py-3">
                    <button type="button" class="btn btn-ghost" @click="hide()">{{ __('Cancel') }}</button>
                    <button class="btn btn-primary" :disabled="saving">{{ __('Add client') }}</button>
                </div>
            </form>
        </div>
    @endif
    </div>
@endsection
