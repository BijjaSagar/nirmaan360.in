{{--
    "Read details from the photo" on the new-challan form. Included inside the form, after the photo
    field, by resources/views/modules/material_control/challans/create.blade.php. It draws nothing
    unless the challan reader is on for the company and this person may use it.

    It only fills fields. Each one it fills carries a "read from photo" tag and must be changed or
    confirmed with "Matches the photo" before the form is saved; the server checks the same
    (SlipLink). The counted quantity is never filled: it is what was counted on site.
--}}
@php
    $slipUser = auth()->user();
    $slipService = app(\App\Services\SlipReading\SlipReadService::class);
    $slipOn = $slipUser !== null && $slipUser->can('agents.use') && $slipUser->can('material_control.challan')
        && $slipUser->organization !== null && $slipService->offered($slipUser->organization, \App\Models\SlipRead::CHALLAN);
@endphp
@if ($slipOn)
    @include('modules.document_reading._capture')
    @php
        $slipConfig = [
            'urls' => [
                'store' => route('slip-reading.store', $project),
                'show' => route('slip-reading.show', ['slip' => '__SLIP__']),
                'recheck' => route('slip-reading.recheck', ['slip' => '__SLIP__']),
                'discard' => route('slip-reading.discard', ['slip' => '__SLIP__']),
            ],
            'words' => [
                'choose' => __('Choose the photo of the challan first.'),
                'reading' => __('Reading the photo…'),
                'slow' => __('Still working. You can keep filling the form.'),
                'stopped' => __('Still working. Reload the page to see the reading.'),
                'failed' => __('The photo could not be read. You were not charged for it. Type the details yourself.'),
                'network' => __('Your request did not reach the server. Check your connection and try again.'),
                'refused' => __('This could not be done. Reload the page and try again.'),
                'unit_differs' => __('The slip says :slip; this material is kept in :base. Check the unit and the quantity.'),
                'unit_not_of_material' => __('The unit on the slip, :slip, is not a unit of this material, so it is not filled.'),
            ],
        ];
        $slipFieldWords = [
            'unclear' => __('The reader was not sure of this value.'),
            'date_old' => __('This date is more than :days days old.', ['days' => (int) config('modules.slip_reading.old_after_days', 30)]),
            'date_other_year' => __('This date is in another financial year.'),
            'date_in_future' => __('The date on the photo is after today, so it is not filled.'),
            'date_unreadable' => __('This could not be read as a date, so it is not filled.'),
            'not_a_number' => __('This does not read as one number, so it is not filled.'),
            'unit_unknown' => __('This unit is not one of your units, so it is not filled.'),
            'gstin_check_failed' => __('The GSTIN did not pass the check, so no supplier is picked by it.'),
            'weights_do_not_add_up' => __('Gross less tare is not the net weight on this slip, so no weight is filled.'),
            'reads_disagree' => __('The two readings did not agree on this, so it is not filled.'),
        ];
        $slipWarningWords = [
            'possible_duplicate' => __('A posted challan from this supplier has the same number: :number.'),
            'same_delivery' => __('A posted challan of this project has the same vehicle, date and quantity: :number.'),
            'same_photo' => __('This photo was already used for a saved record.'),
            'not_a_challan' => __('This paper does not look like a challan or a weighbridge slip.'),
            'nothing_read' => __('Nothing could be read from this photo.'),
            'reads_disagree' => __('The two readings did not agree on some values. Those are not filled.'),
            'weights_do_not_add_up' => __('Gross less tare is not the net weight on this slip, so no weight is filled.'),
        ];
    @endphp
    <script>
        // Reads the chosen photo once, then fills the challan form from what the server made of it.
        // Nothing is saved here: the form's own Save button does that, and the server checks every filled field again.
        function slipChallanReader(config) {
            const address = (name, id) => config.urls[name].replace('__SLIP__', encodeURIComponent(id));

            return {
                slipState: 'idle',
                slipMessage: '',
                slipData: null,
                slipConfirmed: [],
                slipFilled: {},
                slipLine: null,
                slipPhoto: null,
                slipStarted: 0,

                slipInit() {
                    this.$watch('material', () => this.slipApplyUnit());
                    const form = this.$root.closest('form');
                    form.addEventListener('input', (event) => this.slipEdited(event.target));
                    form.addEventListener('change', (event) => this.slipEdited(event.target));
                },

                async slipCall(method, url, body = undefined) {
                    let response;

                    try {
                        response = await fetch(url, {
                            method,
                            credentials: 'same-origin',
                            headers: {
                                Accept: 'application/json',
                                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
                                'X-Requested-With': 'XMLHttpRequest',
                            },
                            body,
                        });
                    } catch {
                        throw new Error(config.words.network);
                    }

                    const data = await response.json().catch(() => ({}));

                    if (!response.ok) {
                        throw new Error(typeof data.message === 'string' && data.code ? data.message : config.words.refused);
                    }

                    return data;
                },

                async slipRead() {
                    const file = document.getElementById('photo')?.files?.[0];

                    if (!file) {
                        this.slipMessage = config.words.choose;

                        return;
                    }

                    this.slipForget();
                    this.slipState = 'sending';
                    this.slipMessage = config.words.reading;
                    this.slipPhoto = URL.createObjectURL(file);

                    try {
                        const page = await window.ReaderCapture.imageToPage(file);
                        const body = new FormData();
                        body.append('client_uuid', crypto.randomUUID());
                        body.append('kind', 'challan');
                        body.append('image', page.blob, 'photo.jpg');
                        const created = await this.slipCall('POST', config.urls.store, body);
                        this.slipData = { id: created.id, status: created.status };
                        this.slipWatch();
                    } catch (error) {
                        this.slipState = 'idle';
                        this.slipMessage = error.message;
                    }
                },

                slipWatch() {
                    this.slipState = 'reading';
                    this.slipStarted = Date.now();
                    this.slipPoll();
                },

                // Every 3 seconds for half a minute, then every 10 seconds, and not after 10 minutes.
                slipPoll() {
                    const elapsed = Date.now() - this.slipStarted;

                    if (elapsed > 600000) {
                        this.slipMessage = config.words.stopped;

                        return;
                    }

                    setTimeout(() => this.slipRefresh(), elapsed < 30000 ? 3000 : 10000);
                },

                async slipRefresh() {
                    try {
                        const data = await this.slipCall('GET', address('show', this.slipData.id));
                        this.slipData = data;

                        if (data.status === 'ready') {
                            this.slipState = 'ready';
                            this.slipMessage = '';
                            this.slipFill();
                        } else if (data.status === 'reading') {
                            this.slipMessage = data.read?.message || (Date.now() - this.slipStarted > 30000 ? config.words.slow : config.words.reading);
                            this.slipPoll();
                        } else {
                            this.slipState = 'idle';
                            this.slipMessage = data.read?.message || config.words.failed;
                        }
                    } catch (error) {
                        this.slipMessage = error.message;
                        this.slipPoll();
                    }
                },

                async slipRecheck() {
                    try {
                        this.slipForgetFilled();
                        this.slipData = await this.slipCall('POST', address('recheck', this.slipData.id), new FormData());
                        this.slipWatch();
                    } catch (error) {
                        this.slipMessage = error.message;
                    }
                },

                async slipDiscard() {
                    const id = this.slipData?.id;
                    this.slipForget();

                    if (id) {
                        await this.slipCall('POST', address('discard', id), new FormData()).catch(() => null);
                    }
                },

                slipField(name) {
                    return this.slipData?.fields?.[name] ?? null;
                },

                slipInput(name) {
                    return this.$root.closest('form').querySelector(`[name="${name}"]`);
                },

                // Fills one input and remembers what was filled, so a change by the person can be told apart.
                slipPut(name, value) {
                    const input = this.slipInput(name);

                    if (!input || value === null || value === undefined || value === '') {
                        return;
                    }

                    input.value = value;
                    this.slipFilled[name] = String(value);
                    this.slipUnconfirm(name);
                },

                slipFill() {
                    this.slipPut('challan_no', this.slipField('challan_no')?.value);
                    this.slipPut('challan_date', this.slipField('challan_date')?.value);
                    this.slipPut('vehicle_no', this.slipField('vehicle_no')?.value);

                    const lines = this.slipData.lines ?? [];

                    if (lines.length === 1) {
                        this.slipUseLine(0);
                    } else if (lines.length === 0 && this.slipField('net_weight')?.value) {
                        this.slipUseLine('weight');
                    }
                },

                slipLineOf(which) {
                    if (which === 'weight') {
                        return { qty: this.slipField('net_weight'), unit: this.slipField('weight_unit') };
                    }

                    return this.slipData?.lines?.[which] ?? null;
                },

                slipUseLine(which) {
                    const line = this.slipLineOf(which);

                    if (!line) {
                        return;
                    }

                    this.slipLine = which;
                    this.slipPut('challan_qty', line.qty?.value);
                    this.slipApplyUnit();
                },

                // A unit is filled only when it is one of the chosen material's units.
                slipUnitNote: '',

                slipApplyUnit() {
                    this.slipUnitNote = '';
                    const unit = this.slipLine === null ? null : this.slipLineOf(this.slipLine)?.unit;

                    if (!unit?.unit_id || !this.chosen) {
                        delete this.slipFilled.unit_id;

                        return;
                    }

                    const base = this.materials.find((m) => String(m.id) === String(this.material))?.base_unit;
                    const conversion = (this.chosen.units ?? []).find((u) => String(u.id) === String(unit.unit_id));
                    const isBase = !conversion && String(unit.value).toLowerCase() === String(base).toLowerCase();

                    if (!conversion && !isBase) {
                        delete this.slipFilled.unit_id;
                        this.slipUnitNote = config.words.unit_not_of_material.replace(':slip', unit.raw);

                        return;
                    }

                    this.unit = conversion ? String(conversion.id) : '';
                    this.slipFilled.unit_id = this.unit;
                    this.slipUnconfirm('unit_id');

                    if (conversion) {
                        this.slipUnitNote = config.words.unit_differs.replace(':slip', unit.raw).replace(':base', base);
                    }
                },

                slipUseSupplier() {
                    const party = this.slipData?.suggestions?.party;

                    if (party) {
                        this.newSupplier = false;
                        this.party = String(party.id);
                        this.slipConfirm('party_id');
                    }
                },

                slipNewSupplier() {
                    const name = this.slipField('supplier_name')?.value;

                    if (name) {
                        this.newSupplier = true;
                        this.$nextTick(() => {
                            this.slipInput('supplier_name').value = name;
                            this.slipFilled.supplier_name = name;
                            this.slipConfirm('supplier_name');
                        });
                    }
                },

                slipUseMaterial(id) {
                    this.material = String(id);
                    this.slipConfirm('material_id');
                },

                slipConfirm(name) {
                    if (!this.slipConfirmed.includes(name)) {
                        this.slipConfirmed.push(name);
                    }
                },

                slipUnconfirm(name) {
                    this.slipConfirmed = this.slipConfirmed.filter((field) => field !== name);
                },

                slipIsConfirmed(name) {
                    return this.slipConfirmed.includes(name);
                },

                // A filled field the person changed is theirs now: its confirmation no longer applies.
                slipEdited(input) {
                    const name = input?.name;

                    if (name && this.slipFilled[name] !== undefined && String(input.value) !== this.slipFilled[name]) {
                        this.slipUnconfirm(name);
                    }
                },

                slipChanged(name) {
                    const input = this.slipInput(name);

                    return input && this.slipFilled[name] !== undefined && String(input.value) !== this.slipFilled[name];
                },

                slipForgetFilled() {
                    this.slipConfirmed = [];
                    this.slipFilled = {};
                    this.slipLine = null;
                    this.slipUnitNote = '';
                },

                slipForget() {
                    this.slipForgetFilled();
                    this.slipState = 'idle';
                    this.slipData = null;
                    this.slipMessage = '';
                },
            };
        }
    </script>

    <div x-data="slipChallanReader(@js($slipConfig))" x-init="slipInit()" class="rounded-[3px] border border-rule p-3 text-sm">
        <div class="flex flex-wrap items-center gap-2">
            <button type="button" class="btn btn-ink min-h-11" @click="slipRead()" :disabled="slipState === 'sending' || slipState === 'reading'">
                {{ __('Read details from the photo (up to :n credits)', ['n' => $slipService->upToWhole(\App\Models\SlipRead::CHALLAN)]) }}
            </button>
            <button type="button" class="link text-xs" x-show="slipData" x-cloak @click="slipDiscard()">{{ __('Do not use this reading') }}</button>
        </div>
        <p class="hint mt-1">{{ __('Each field shows the text as read, beside the photo. You confirm each field against the photo.') }}</p>
        <p class="mt-2" x-show="slipMessage" x-cloak x-text="slipMessage" role="status"></p>

        <template x-if="slipState === 'ready' && slipData">
            <div class="mt-3 grid gap-3 sm:grid-cols-[minmax(0,12rem)_1fr]">
                <img :src="slipPhoto" alt="{{ __('Photo of challan') }}" class="max-h-72 w-full rounded-[3px] border border-rule object-contain" x-show="slipPhoto">
                <div class="space-y-3">
                    <input type="hidden" name="read_id" :value="slipData.id">
                    <template x-for="field in slipConfirmed" :key="field"><input type="hidden" name="read_confirmed[]" :value="field"></template>

                    <template x-for="warning in (slipData.warnings ?? [])" :key="warning.code">
                        <div class="rounded-[3px] border border-haldi bg-haldi/10 px-3 py-2">
                            <p x-text="(@js($slipWarningWords)[warning.code] ?? warning.code).replace(':number', warning.params?.number ?? '')"></p>
                            <label class="mt-1 flex items-center gap-2" x-show="warning.tick">
                                <input type="checkbox" name="read_confirmed[]" :value="'warning:' + warning.code">
                                <span>{{ __('I have checked this') }}</span>
                            </label>
                        </div>
                    </template>

                    <p class="font-medium">{{ __('Filled from the photo. Change each field or confirm it against the photo:') }}</p>
                    <template x-for="[name, label] in Object.entries(@js(['challan_no' => __('Challan no.'), 'challan_date' => __('Date'), 'vehicle_no' => __('Vehicle no.'), 'challan_qty' => __('Quantity written on challan'), 'unit_id' => __('Unit')]))" :key="name">
                        <div class="flex flex-wrap items-center justify-between gap-2 border-b border-rule pb-2" x-show="slipFilled[name] !== undefined">
                            <span>
                                <span class="stamp border-haldi text-[#8a6208]">{{ __('Read from photo') }}</span>
                                <span x-text="label"></span>
                                <span class="text-ink-3" x-show="name !== 'challan_qty' && name !== 'unit_id' && slipField(name)?.raw" x-text="@js(__('As printed:')) + ' ' + (slipField(name)?.raw ?? '')"></span>
                            </span>
                            <span x-show="slipChanged(name)" class="text-ink-3">{{ __('Changed by you') }}</span>
                            <button type="button" class="btn btn-ghost min-h-10" x-show="!slipChanged(name)" @click="slipIsConfirmed(name) ? slipUnconfirm(name) : slipConfirm(name)"
                                    x-text="slipIsConfirmed(name) ? @js(__('Confirmed against the photo')) : @js(__('Matches the photo'))"></button>
                        </div>
                    </template>
                    <p x-show="slipUnitNote" x-text="slipUnitNote" class="text-laterite"></p>

                    <template x-for="name in ['challan_no', 'challan_date', 'vehicle_no', 'supplier_gstin', 'gross_weight', 'tare_weight', 'net_weight']" :key="'w' + name">
                        <div>
                            <template x-for="code in (slipField(name)?.warnings ?? [])" :key="name + code">
                                <p class="text-ink-2" x-text="(slipField(name)?.raw ? slipField(name).raw + ': ' : '') + (@js($slipFieldWords)[code] ?? code)"></p>
                            </template>
                        </div>
                    </template>

                    <div class="border-b border-rule pb-2" x-show="slipData.suggestions?.party || slipField('supplier_name')?.value">
                        <p class="font-medium">{{ __('Supplier') }}</p>
                        <template x-if="slipData.suggestions?.party">
                            <div class="flex flex-wrap items-center gap-2">
                                <span x-text="@js(__('Found by its GSTIN:')) + ' ' + slipData.suggestions.party.name"></span>
                                <button type="button" class="btn btn-ghost min-h-10" @click="slipUseSupplier()" x-text="slipIsConfirmed('party_id') ? @js(__('Confirmed against the photo')) : @js(__('Use this supplier'))"></button>
                            </div>
                        </template>
                        <template x-if="!slipData.suggestions?.party && slipField('supplier_name')?.value">
                            <div class="flex flex-wrap items-center gap-2">
                                <span x-text="@js(__('As printed:')) + ' ' + slipField('supplier_name').value"></span>
                                <button type="button" class="btn btn-ghost min-h-10" @click="newSupplier = false; $nextTick(() => document.getElementById('party_id')?.focus())">{{ __('Pick a supplier') }}</button>
                                <button type="button" class="btn btn-ghost min-h-10" @click="slipNewSupplier()" x-text="slipIsConfirmed('supplier_name') ? @js(__('Confirmed against the photo')) : @js(__('Use as a new supplier'))"></button>
                            </div>
                        </template>
                    </div>

                    <div class="border-b border-rule pb-2" x-show="(slipData.lines ?? []).length > 1 || ((slipData.lines ?? []).length && slipField('net_weight')?.value)">
                        <p class="font-medium">{{ __('Lines on the challan. Pick the one this delivery is for:') }}</p>
                        <template x-for="(line, index) in (slipData.lines ?? [])" :key="'l' + index">
                            <label class="mt-1 flex items-center gap-2">
                                <input type="radio" name="slip_line" :checked="slipLine === index" @change="slipUseLine(index)">
                                <span x-text="[line.description?.raw, line.qty?.raw, line.unit?.raw].filter(Boolean).join(' · ')"></span>
                                <span class="text-ink-3" x-show="!line.qty?.value" x-text="@js($slipFieldWords['not_a_number'])"></span>
                            </label>
                        </template>
                        <label class="mt-1 flex items-center gap-2" x-show="slipField('net_weight')?.value">
                            <input type="radio" name="slip_line" :checked="slipLine === 'weight'" @change="slipUseLine('weight')">
                            <span x-text="@js(__('Net weight')) + ' · ' + (slipField('net_weight')?.raw ?? '') + ' ' + (slipField('weight_unit')?.raw ?? '')"></span>
                        </label>
                    </div>

                    <div class="border-b border-rule pb-2" x-show="(slipData.suggestions?.materials ?? []).length">
                        <p class="font-medium">{{ __('Materials whose names share words with the challan:') }}</p>
                        <div class="mt-1 flex flex-wrap gap-2">
                            <template x-for="option in (slipData.suggestions?.materials ?? [])" :key="'m' + option.id">
                                <button type="button" class="btn btn-ghost min-h-10" @click="slipUseMaterial(option.id)"
                                        :class="String(material) === String(option.id) && slipIsConfirmed('material_id') ? 'border-moss text-moss' : ''" x-text="option.name"></button>
                            </template>
                        </div>
                    </div>

                    <p class="hint">{{ __('Type the quantity you counted yourself. The photo fills only the quantity written on the challan.') }}</p>

                    <button type="button" class="btn btn-ghost min-h-10" x-show="slipData.can_recheck" @click="slipRecheck()"
                            x-text="@js(__('Read again with the stronger model (up to :n credits)')).replace(':n', slipData.recheck_credits ?? '')"></button>
                </div>
            </div>
        </template>
    </div>
@endif
