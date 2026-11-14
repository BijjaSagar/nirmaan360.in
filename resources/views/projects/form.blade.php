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
                @if (($phases ?? []) !== [])
                    {{-- A phased value is the total of its phases and changes only with them, in the field app. --}}
                    <x-field :label="__('Contract value (₹)')" name="contract_value" :hint="__('The total of :count phases. Change the phases in the field app to change it.', ['count' => count($phases)])">
                        <input id="contract_value" name="contract_value" type="number" step="0.01" min="0" value="{{ $project->contract_value }}" class="input num" readonly>
                    </x-field>
                @else
                    <x-field :label="__('Contract value (₹)')" name="contract_value">
                        <input id="contract_value" name="contract_value" type="number" step="0.01" min="0" value="{{ old('contract_value', $project->contract_value ?? 0) }}" class="input num" required>
                    </x-field>
                @endif
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

        @php
            // The advance lines the form starts with: what came back with errors, else the saved lines
            // (a project saved with one figure shows it as its first line), else one empty line.
            $sentAdvances = old('advances');
            $advanceLines = collect(is_array($sentAdvances) ? $sentAdvances : $advances)
                ->map(fn ($line, $key) => [
                    'amount' => (string) (is_array($line) ? ($line['amount'] ?? '') : ''),
                    'received_on' => (string) (is_array($line) ? ($line['received_on'] ?? '') : ''),
                    'note' => (string) (is_array($line) ? ($line['note'] ?? '') : ''),
                    'errors' => array_filter([
                        'amount' => $errors->first("advances.{$key}.amount"),
                        'received_on' => $errors->first("advances.{$key}.received_on"),
                        'note' => $errors->first("advances.{$key}.note"),
                    ]),
                ])->values()->all() ?: [['amount' => '', 'received_on' => '', 'note' => '', 'errors' => []]];
            $advanceTotal = collect($advanceLines)->reduce(fn ($sum, $line) => is_numeric($line['amount']) ? $sum->plus(\App\Support\Dec::money($line['amount'])) : $sum, \App\Support\Dec::of(0));
            // Worked out by default on a new project. A saved project keeps what it had, so no percentage changes by itself.
            $recoveryAuto = (bool) old('advance_recovery_auto', $project->exists ? $project->advance_recovery_auto : true);
            $recoveryPct = (string) old('advance_recovery_pct', $project->advance_recovery_pct ?? 0);
            $maxAdvances = \App\Services\ProjectService::MAX_ADVANCES;
        @endphp

        <script>
            /**
             * The advance lines and the recovery percentage. Amounts are counted in whole paise and the
             * percentage is rounded half up to two places, as the server works it out, so what shows is what is saved.
             */
            function advanceTerms(lines, auto, pct, max) {
                const toPaise = (text) => {
                    const match = /^\s*(\d*)(?:\.(\d*))?\s*$/.exec(String(text ?? ''));

                    if (! match || (match[1] === '' && ! match[2])) return null;

                    const digits = ((match[2] ?? '') + '000').slice(0, 3);
                    const paise = BigInt(match[1] || '0') * 100n + BigInt(digits.slice(0, 2));

                    return digits[2] >= '5' ? paise + 1n : paise;
                };
                const percentOf = (advance, value) => {
                    if (advance <= 0n || value === null || value <= 0n) return '0.00';

                    const scaled = advance * 10000n;
                    let hundredths = scaled / value;

                    if ((scaled % value) * 2n >= value) hundredths += 1n;
                    if (hundredths > 10000n) hundredths = 10000n;

                    return `${hundredths / 100n}.${String(hundredths % 100n).padStart(2, '0')}`;
                };
                const rupees = (paise) => {
                    const whole = String(paise / 100n);
                    const grouped = whole.length > 3 ? whole.slice(0, -3).replace(/\B(?=(\d{2})+(?!\d))/g, ',') + ',' + whole.slice(-3) : whole;

                    return `₹${grouped}.${String(paise % 100n).padStart(2, '0')}`;
                };
                let next = 0;
                const makeLine = (values = {}) => ({ amount: '', received_on: '', note: '', errors: {}, ...values, key: next++ });

                return {
                    lines: lines.map((values) => makeLine(values)),
                    auto,
                    pct,
                    max,
                    contract: '',
                    init() {
                        // These rows are for a browser without JavaScript. Here the rows below take their place.
                        this.$refs.plainAdvances.remove();

                        const value = document.getElementById('contract_value');
                        this.contract = value?.value ?? '';
                        value?.addEventListener('input', () => (this.contract = value.value));
                    },
                    get total() {
                        return this.lines.reduce((sum, line) => sum + (toPaise(line.amount) ?? 0n), 0n);
                    },
                    get totalText() {
                        return rupees(this.total);
                    },
                    get worked() {
                        return percentOf(this.total, toPaise(this.contract));
                    },
                    add() {
                        if (this.lines.length < this.max) this.lines.push(makeLine());
                    },
                    remove(index) {
                        this.lines.splice(index, 1);

                        // The form always sends at least one line, so taking the last one away empties it instead.
                        if (this.lines.length === 0) this.lines.push(makeLine());
                    },
                };
            }
        </script>

        <section class="card" x-data="advanceTerms(@js($advanceLines), @js($recoveryAuto), @js($recoveryPct), @js($maxAdvances))" x-effect="if (auto) pct = worked">
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
                <div class="sm:col-span-3" role="group" aria-labelledby="advances-label">
                    <p class="label" id="advances-label">{{ __('Mobilisation advance (₹)') }}</p>
                    <div class="mb-1 hidden gap-2 text-xs text-ink-3 sm:grid sm:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_minmax(0,2fr)_2.5rem]" aria-hidden="true">
                        <span>{{ __('Amount (₹)') }}</span><span>{{ __('Date received') }}</span><span>{{ __('Note') }}</span><span></span>
                    </div>
                    {{-- The lines as plain fields, so the form saves them without JavaScript. Alpine removes these and draws the rows below. --}}
                    <div x-ref="plainAdvances" class="space-y-2">
                        @foreach ($advanceLines as $index => $line)
                            <div class="grid items-start gap-2 sm:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_minmax(0,2fr)_2.5rem]">
                                <div>
                                    <input name="advances[{{ $index }}][amount]" value="{{ $line['amount'] }}" type="number" inputmode="decimal" step="0.01" min="0.01" class="input num" aria-label="{{ __('Amount (₹)') }}" placeholder="{{ __('Amount (₹)') }}">
                                    @isset($line['errors']['amount'])<p class="error">{{ $line['errors']['amount'] }}</p>@endisset
                                </div>
                                <div>
                                    <input name="advances[{{ $index }}][received_on]" value="{{ $line['received_on'] }}" type="date" class="input" aria-label="{{ __('Date received') }}">
                                    @isset($line['errors']['received_on'])<p class="error">{{ $line['errors']['received_on'] }}</p>@endisset
                                </div>
                                <div>
                                    <input name="advances[{{ $index }}][note]" value="{{ $line['note'] }}" maxlength="255" class="input" aria-label="{{ __('Note') }}" placeholder="{{ __('For example the cheque or transfer number') }}">
                                    @isset($line['errors']['note'])<p class="error">{{ $line['errors']['note'] }}</p>@endisset
                                </div>
                            </div>
                        @endforeach
                        <p class="text-sm">{{ __('Total advance') }} <span class="num font-medium">{{ inr($advanceTotal) }}</span></p>
                    </div>
                    <div x-cloak class="space-y-2">
                        <template x-for="(line, index) in lines" :key="line.key">
                            <div class="grid items-start gap-2 sm:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_minmax(0,2fr)_2.5rem]">
                                <div>
                                    <input :name="`advances[${index}][amount]`" x-model="line.amount" type="number" inputmode="decimal" step="0.01" min="0.01" class="input num" aria-label="{{ __('Amount (₹)') }}" placeholder="{{ __('Amount (₹)') }}">
                                    <p class="error" x-show="line.errors.amount" x-text="line.errors.amount"></p>
                                </div>
                                <div>
                                    <input :name="`advances[${index}][received_on]`" x-model="line.received_on" type="date" class="input" aria-label="{{ __('Date received') }}">
                                    <p class="error" x-show="line.errors.received_on" x-text="line.errors.received_on"></p>
                                </div>
                                <div>
                                    <input :name="`advances[${index}][note]`" x-model="line.note" maxlength="255" class="input" aria-label="{{ __('Note') }}" placeholder="{{ __('For example the cheque or transfer number') }}">
                                    <p class="error" x-show="line.errors.note" x-text="line.errors.note"></p>
                                </div>
                                <button type="button" class="btn btn-ghost w-10 justify-self-end px-0 sm:w-full" @click="remove(index)" aria-label="{{ __('Remove this advance') }}" title="{{ __('Remove this advance') }}"><x-icon name="x" class="size-4" /></button>
                            </div>
                        </template>
                        <div class="flex flex-wrap items-center justify-between gap-3">
                            <button type="button" class="btn btn-ghost" @click="add()" :disabled="lines.length >= max"><x-icon name="plus" class="size-4" />{{ __('Add advance') }}</button>
                            <p class="text-sm">{{ __('Total advance') }} <span class="num font-medium" x-text="totalText"></span></p>
                        </div>
                        <p class="hint" x-show="lines.length >= max">{{ __('A project can have at most :max advances.', ['max' => $maxAdvances]) }}</p>
                    </div>
                    @error('advances')<p class="error">{{ $message }}</p>@enderror
                    @error('advance_amount')<p class="error">{{ $message }}</p>@enderror
                </div>
                <x-field :label="__('Advance recovery %')" name="advance_recovery_pct" :hint="__('This much of each RA bill is held back until the advance is recovered.')">
                    <input id="advance_recovery_pct" name="advance_recovery_pct" type="number" step="0.01" min="0" max="100" value="{{ $recoveryPct }}" class="input num" required @readonly($recoveryAuto) x-model="pct" :readonly="auto">
                    <input type="hidden" name="advance_recovery_auto" value="0">
                    <label class="mt-2 flex items-start gap-2 text-sm">
                        <input type="checkbox" name="advance_recovery_auto" value="1" class="mt-0.5" @checked($recoveryAuto) x-model="auto">
                        <span>{{ __('Work it out from the advance and the contract value') }}</span>
                    </label>
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
