{{--
    "Read details from the photo" on the new-challan form. Included inside the form, after the photo
    field, by resources/views/modules/material_control/challans/create.blade.php. It draws nothing
    unless the challan reader is on for the company and this person may use it.

    It only fills fields. The form and the server (SlipLink) agree on what counts as filled from the
    photo: every field whose value is one the reading offered, compared the way the server compares
    it, with the quantity and the unit taken from the one line the form names in read_line. Each of
    those must be changed or confirmed with "Matches the photo" before the form is saved, and every
    warning that asks for a tick must have one. The counted quantity is never filled: it is what was
    counted on site.

    A form the server sends back shows the same reading again, with what was typed. Until it is shown
    again, the form still names the reading in inputs the server draws, so a Save meanwhile, or after
    the reading could not be fetched, is checked by the server as before. A reading that can no longer
    be used takes back what it filled. Reading again, a new reading and "Do not use this reading" put
    back what the fields held before the reading filled them, unless the person has changed them since.
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
            'old' => filled(old('read_id')) ? [
                'id' => (int) old('read_id'),
                'confirmed' => array_values(array_filter((array) old('read_confirmed', []), 'is_string')),
                'line' => is_scalar(old('read_line')) ? (string) old('read_line') : null,
            ] : null,
            // Each material's base unit: a unit read from the photo is one of the material's when it is this or one of its conversions.
            'bases' => \App\Models\Material::query()->where('is_active', true)->pluck('base_unit_id', 'id')->map(fn ($unit) => (string) $unit)->all(),
            'words' => [
                'choose' => __('Choose the photo of the challan first.'),
                'reading' => __('Reading the photo…'),
                'slow' => __('Still working. You can keep filling the form.'),
                'stopped' => __('Still working. Reload the page to see the reading.'),
                'failed' => __('The photo could not be read. You were not charged for it. Type the details yourself.'),
                'failed_again' => __('The second reading could not be used. Type the details yourself.'),
                'network' => __('Your request did not reach the server. Check your connection and try again.'),
                'refused' => __('This could not be done. Reload the page and try again.'),
                'gone' => __('This photo reading can no longer be used. Read the photo again or type the details yourself.'),
                'unit_differs' => __('The slip says :slip; this material is kept in :base. Check the unit and the quantity.'),
                'unit_not_of_material' => __('The unit on the slip, :slip, is not a unit of this material, so it is not filled.'),
                'confirm_all' => __('Confirm each field filled from the photo, or change it, and tick each warning, before saving.'),
            ],
            'fieldWords' => [
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
            ],
            'warningWords' => [
                'possible_duplicate' => __('A posted challan from this supplier has the same number: :number.'),
                'possible_duplicate_unseen' => __('A posted challan from this supplier has the same number, in a project you do not have access to.'),
                'same_delivery' => __('A posted challan of this project has the same vehicle, date and quantity: :number.'),
                'same_photo' => __('This photo was already used for a saved record.'),
                'not_a_challan' => __('This paper does not look like a challan or a weighbridge slip.'),
                'nothing_read' => __('Nothing could be read from this photo.'),
                'reads_disagree' => __('The two readings did not agree on some values. Those are not filled.'),
                'weights_do_not_add_up' => __('Gross less tare is not the net weight on this slip, so no weight is filled.'),
            ],
        ];
        $slipRows = ['challan_no' => __('Challan no.'), 'challan_date' => __('Date'), 'vehicle_no' => __('Vehicle no.'), 'challan_qty' => __('Quantity written on challan'), 'unit_id' => __('Unit')];
    @endphp
    <script>
        // Reads the chosen photo once, then fills the challan form from what the server made of it.
        // Nothing is saved here: the form's own Save button does that, and the server checks every filled field again.
        function slipChallanReader(config) {
            const address = (name, id) => config.urls[name].replace('__SLIP__', encodeURIComponent(id));

            // Values as the server compares them (SlipLink): text in upper case with single spaces, a vehicle number
            // without spaces or hyphens, a quantity to three places, ids as whole numbers. Nothing is null.
            const plain = (value) => String(value ?? '').replace(/[\s\p{Z}]+/gu, ' ').trim().toUpperCase() || null;
            const vehicle = (value) => String(value ?? '').trim().toUpperCase().replace(/[\s\p{Z}\-‐-―]+/gu, '').slice(0, 30) || null;
            const quantity = (value) => {
                const text = String(value ?? '').trim();

                return text === '' || !Number.isFinite(Number(text)) ? null : Number(text).toFixed(3);
            };
            const whole = (value) => (/^\d+$/.test(String(value ?? '').trim()) ? String(parseInt(String(value).trim(), 10)) : null);
            const canonical = { challan_no: plain, challan_date: (value) => String(value ?? '').trim() || null, vehicle_no: vehicle, challan_qty: quantity, supplier_name: plain };

            // Every field a reading can fill, and the cells whose warnings are shown.
            const fields = ['party_id', 'supplier_name', 'material_id', 'challan_no', 'challan_date', 'vehicle_no', 'challan_qty', 'unit_id'];
            const rows = ['challan_no', 'challan_date', 'vehicle_no', 'challan_qty', 'unit_id'];
            const warned = ['challan_no', 'challan_date', 'vehicle_no', 'challan_qty', 'unit_id', 'supplier_name', 'supplier_gstin', 'gross_weight', 'tare_weight', 'net_weight', 'weight_unit'];
            const own = (object, key) => Object.prototype.hasOwnProperty.call(object, key);

            return {
                slipState: 'idle',
                slipMessage: '',
                slipBlocked: '',
                slipData: null,
                // The form was sent back naming a reading that is not shown again yet: the inputs the server drew still name it.
                slipHeld: Boolean(config.old?.id),
                // Field => its value when confirmed, so changing the field afterwards takes the confirmation back.
                slipConfirmed: {},
                slipTicks: [],
                // Field => the value the reading put in, and what the field held before that.
                slipFilled: {},
                slipBefore: {},
                slipLine: null,
                slipPhoto: null,
                slipStarted: 0,
                slipUnitNote: '',
                // Typing is not watched by Alpine; this count is, so the tags follow what is typed.
                slipTyped: 0,

                slipInit() {
                    this.$watch('material', () => this.slipApplyUnit());
                    const form = this.$root.closest('form');
                    form.addEventListener('input', () => this.slipTyped++);
                    form.addEventListener('change', () => this.slipTyped++);
                    form.addEventListener('submit', (event) => this.slipBeforeSave(event));

                    if (config.old?.id) {
                        this.slipRestore(config.old);
                    }
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
                            this.slipMessage = data.read?.message || (Number(data.credits) > 0 ? config.words.failed_again : config.words.failed);
                        }
                    } catch (error) {
                        this.slipMessage = error.message;
                        this.slipPoll();
                    }
                },

                // The server sent the form back: the reading is shown again, and what the photo filled and the form still holds is tagged again.
                // When it cannot be fetched, the form keeps naming it (slipHeld), so the server checks the next Save as it did this one.
                async slipRestore(old) {
                    let data;

                    try {
                        data = await this.slipCall('GET', address('show', old.id));
                    } catch (error) {
                        this.slipMessage = error.message;

                        return;
                    }

                    this.slipData = data;
                    this.slipLine = old.line === 'weight' ? 'weight' : (/^\d+$/.test(old.line ?? '') ? Number(old.line) : this.slipDefaultLine());

                    for (const name of ['supplier_name', ...rows]) {
                        if (this.slipNeeds(name)) {
                            this.slipFilled[name] = this.slipCurrent(name);
                            this.slipBefore[name] = '';
                        }
                    }

                    this.slipHeld = false;

                    // The reading can no longer be used: what it filled goes, as with "Do not use this reading".
                    if (data.status !== 'ready') {
                        this.slipForget();
                        this.slipMessage = config.words.gone;

                        return;
                    }

                    this.slipState = 'ready';

                    for (const entry of old.confirmed ?? []) {
                        if (entry.startsWith('warning:')) {
                            this.slipTick(entry.slice(8), true);
                        } else if (fields.includes(entry)) {
                            this.slipConfirm(entry);
                        }
                    }
                },

                async slipRecheck() {
                    try {
                        const data = await this.slipCall('POST', address('recheck', this.slipData.id), new FormData());
                        // What the first reading filled goes, so a value the second one does not offer is not left behind.
                        this.slipUndo();
                        this.slipData = data;
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

                // A field's value as the form would send it and the server would compare it, or null when it sends none.
                slipCurrent(name) {
                    this.slipTyped;

                    switch (name) {
                        case 'party_id':
                            return this.newSupplier ? null : whole(this.party);
                        case 'supplier_name':
                            return this.newSupplier ? plain(this.slipInput('supplier_name')?.value) : null;
                        case 'material_id':
                            return whole(this.material);
                        case 'unit_id':
                            return whole(this.material) === null ? null : (whole(this.unit) ?? whole(config.bases[whole(this.material)]));
                        default:
                            return canonical[name](this.slipInput(name)?.value);
                    }
                },

                // The line the quantity and unit come from when none was picked: a single line, or the net weight of a slip without lines.
                slipDefaultLine() {
                    const lines = this.slipData?.lines ?? [];

                    if (lines.length === 1) {
                        return 0;
                    }

                    return lines.length === 0 && this.slipField('net_weight')?.value ? 'weight' : null;
                },

                slipLineOf(which) {
                    if (which === 'weight') {
                        return { qty: this.slipField('net_weight'), unit: this.slipField('weight_unit') };
                    }

                    return which === null ? null : (this.slipData?.lines?.[which] ?? null);
                },

                slipOfMaterial(unitId) {
                    const material = whole(this.material);

                    if (material === null) {
                        return false;
                    }

                    return [config.bases[material], ...(this.chosen?.units ?? []).map((unit) => unit.id)].map(whole).includes(whole(unitId));
                },

                // The values the server counts as filled from the photo for a field, as SlipLink works them out.
                slipOffered(name) {
                    if (!this.slipData) {
                        return [];
                    }

                    const one = (value, form) => (value === null || value === undefined || value === '' ? [] : [form(value)]);
                    const line = this.slipLineOf(this.slipLine ?? this.slipDefaultLine());

                    switch (name) {
                        case 'party_id':
                            return one(this.slipData.suggestions?.party?.id, whole);
                        case 'supplier_name':
                            return this.slipCurrent('party_id') === null ? one(this.slipField('supplier_name')?.value, plain) : [];
                        case 'material_id':
                            return (this.slipData.suggestions?.materials ?? []).map((option) => whole(option.id));
                        case 'challan_qty':
                            return one(line?.qty?.value, quantity);
                        case 'unit_id':
                            return line?.unit?.unit_id && this.slipOfMaterial(line.unit.unit_id) ? [whole(line.unit.unit_id)] : [];
                        default:
                            return one(this.slipField(name)?.value, canonical[name]);
                    }
                },

                // The field holds a value the photo filled: it is confirmed or changed before saving.
                slipNeeds(name) {
                    const current = this.slipCurrent(name);

                    return current !== null && this.slipOffered(name).includes(current);
                },

                slipConfirm(name) {
                    const value = this.slipCurrent(name);

                    if (value !== null) {
                        this.slipConfirmed = { ...this.slipConfirmed, [name]: value };
                        this.slipBlocked = '';
                    }
                },

                slipUnconfirm(name) {
                    const confirmed = { ...this.slipConfirmed };
                    delete confirmed[name];
                    this.slipConfirmed = confirmed;
                },

                slipToggle(name) {
                    this.slipIsConfirmed(name) ? this.slipUnconfirm(name) : this.slipConfirm(name);
                },

                slipIsConfirmed(name) {
                    const value = this.slipCurrent(name);

                    return value !== null && own(this.slipConfirmed, name) && this.slipConfirmed[name] === value;
                },

                slipTick(code, on) {
                    this.slipTicks = on ? [...new Set([...this.slipTicks, code])] : this.slipTicks.filter((entry) => entry !== code);

                    if (on) {
                        this.slipBlocked = '';
                    }
                },

                // What the form sends as read_confirmed[].
                slipSent() {
                    return [...fields.filter((name) => this.slipIsConfirmed(name)), ...this.slipTicks.map((code) => 'warning:' + code)];
                },

                slipChanged(name) {
                    return own(this.slipFilled, name) && this.slipCurrent(name) !== this.slipFilled[name];
                },

                slipRowShown(name) {
                    return this.slipNeeds(name) || own(this.slipFilled, name);
                },

                // Saving waits until every field holding a value from the photo is confirmed or changed, and every warning that asks for a tick has one.
                slipBeforeSave(event) {
                    if (this.slipState !== 'ready' || !this.slipData) {
                        return;
                    }

                    const open = fields.some((name) => this.slipNeeds(name) && !this.slipIsConfirmed(name));
                    const unticked = (this.slipData.warnings ?? []).some((warning) => warning.tick && !this.slipTicks.includes(warning.code));

                    if (open || unticked) {
                        event.preventDefault();
                        this.slipBlocked = config.words.confirm_all;
                        this.$root.scrollIntoView?.({ behavior: 'smooth', block: 'start' });
                    }
                },

                slipRemember(name, value) {
                    if (!own(this.slipBefore, name)) {
                        this.slipBefore[name] = value;
                    }
                },

                slipSet(name, value) {
                    if (name === 'unit_id') {
                        this.unit = value;
                    } else if (name === 'party_id') {
                        this.party = value;
                    } else if (name === 'material_id') {
                        this.material = value;
                    } else if (this.slipInput(name)) {
                        this.slipInput(name).value = value;
                    }

                    this.slipTyped++;
                },

                // Fills one input and remembers what it held, so the fill can be told apart from a change and taken back.
                slipPut(name, value) {
                    const input = this.slipInput(name);

                    if (!input || value === null || value === undefined || value === '') {
                        return;
                    }

                    this.slipRemember(name, input.value);
                    input.value = value;
                    this.slipTyped++;
                    this.slipFilled[name] = this.slipCurrent(name);
                    this.slipUnconfirm(name);
                },

                // Takes one filled value back out: the field gets what it held before, unless the person has changed it since.
                slipUndoField(name) {
                    if (!own(this.slipFilled, name)) {
                        return;
                    }

                    if (!this.slipChanged(name)) {
                        this.slipSet(name, this.slipBefore[name] ?? '');
                    }

                    delete this.slipFilled[name];
                    delete this.slipBefore[name];
                    this.slipUnconfirm(name);
                },

                slipFill() {
                    this.slipPut('challan_no', this.slipField('challan_no')?.value);
                    this.slipPut('challan_date', this.slipField('challan_date')?.value);
                    this.slipPut('vehicle_no', this.slipField('vehicle_no')?.value);

                    const line = this.slipDefaultLine();

                    if (line !== null) {
                        this.slipUseLine(line);
                    }
                },

                slipUseLine(which) {
                    const line = this.slipLineOf(which);

                    if (!line) {
                        return;
                    }

                    // Another line's quantity goes first, so it is never left standing for this one.
                    this.slipUndoField('challan_qty');
                    this.slipLine = which;
                    this.slipPut('challan_qty', line.qty?.value);
                    this.slipApplyUnit();
                },

                // A unit is filled only when it is one of the chosen material's units.
                slipApplyUnit() {
                    this.slipUnitNote = '';
                    this.slipUndoField('unit_id');
                    const unit = this.slipLineOf(this.slipLine)?.unit;

                    if (!unit?.unit_id || whole(this.material) === null) {
                        return;
                    }

                    if (!this.slipOfMaterial(unit.unit_id)) {
                        this.slipUnitNote = config.words.unit_not_of_material.replace(':slip', unit.raw);

                        return;
                    }

                    const base = whole(config.bases[whole(this.material)]) === whole(unit.unit_id);
                    this.slipRemember('unit_id', this.unit);
                    this.unit = base ? '' : String(unit.unit_id);
                    this.slipTyped++;
                    this.slipFilled.unit_id = this.slipCurrent('unit_id');
                    this.slipUnconfirm('unit_id');

                    if (!base) {
                        this.slipUnitNote = config.words.unit_differs.replace(':slip', unit.raw).replace(':base', this.chosen?.base_unit ?? '');
                    }
                },

                slipUseSupplier() {
                    const party = this.slipData?.suggestions?.party;

                    if (party) {
                        this.slipRemember('newSupplier', this.newSupplier);
                        this.slipRemember('party_id', this.party);
                        this.newSupplier = false;
                        this.party = String(party.id);
                        this.slipFilled.party_id = whole(party.id);
                        this.slipConfirm('party_id');
                    }
                },

                slipNewSupplier() {
                    const name = this.slipField('supplier_name')?.value;

                    if (name) {
                        this.slipRemember('newSupplier', this.newSupplier);
                        this.newSupplier = true;
                        this.$nextTick(() => {
                            const input = this.slipInput('supplier_name');
                            this.slipRemember('supplier_name', input.value);
                            input.value = name;
                            this.slipTyped++;
                            this.slipFilled.supplier_name = this.slipCurrent('supplier_name');
                            this.slipConfirm('supplier_name');
                        });
                    }
                },

                slipUseMaterial(id) {
                    this.slipRemember('material_id', this.material);
                    this.material = String(id);
                    this.slipFilled.material_id = whole(id);
                    this.slipConfirm('material_id');
                },

                // The cell a field's value and warnings come from: the chosen line's for the quantity and the unit.
                slipCellOf(name) {
                    if (name === 'challan_qty' || name === 'unit_id') {
                        const line = this.slipLineOf(this.slipLine ?? this.slipDefaultLine());

                        return (name === 'challan_qty' ? line?.qty : line?.unit) ?? null;
                    }

                    return this.slipField(name);
                },

                slipWarningsOf(name) {
                    return (this.slipCellOf(name)?.warnings ?? []).map((code) => config.fieldWords[code] ?? code);
                },

                // Warnings on cells not shown beside a filled field or the supplier, each with the text as read.
                slipOtherWarnings() {
                    const done = rows.filter((name) => this.slipRowShown(name)).map((name) => this.slipCellOf(name));

                    if (this.slipData?.suggestions?.party || this.slipField('supplier_name')?.value) {
                        done.push(this.slipField('supplier_name'));
                    }

                    const out = [];

                    for (const name of warned) {
                        const cell = this.slipCellOf(name);

                        if (!cell || done.includes(cell)) {
                            continue;
                        }

                        done.push(cell);

                        for (const code of cell.warnings ?? []) {
                            out.push({ key: name + ':' + code, text: (cell.raw ? cell.raw + ': ' : '') + (config.fieldWords[code] ?? code) });
                        }
                    }

                    return out;
                },

                slipWarningText(warning) {
                    const number = warning.params?.number;

                    if (warning.code === 'possible_duplicate' && !number) {
                        return config.warningWords.possible_duplicate_unseen;
                    }

                    return (config.warningWords[warning.code] ?? warning.code).replace(':number', number ?? '');
                },

                // Takes back everything the reading put in the form, then forgets what it confirmed.
                slipUndo() {
                    for (const name of Object.keys(this.slipFilled)) {
                        this.slipUndoField(name);
                    }

                    if (own(this.slipBefore, 'newSupplier')) {
                        this.newSupplier = this.slipBefore.newSupplier;
                    }

                    this.slipFilled = {};
                    this.slipBefore = {};
                    this.slipConfirmed = {};
                    this.slipTicks = [];
                    this.slipLine = null;
                    this.slipUnitNote = '';
                    this.slipBlocked = '';
                },

                slipForget() {
                    this.slipUndo();
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
        @error('read_id')<p class="error">{{ $message }}</p>@enderror
        @error('read_line')<p class="error">{{ $message }}</p>@enderror
        @error('read_confirmed')<p class="error">{{ $message }}</p>@enderror
        @if ($slipConfig['old'] !== null)
            {{-- The reading the form was sent back with, until it is shown again. --}}
            <input type="hidden" name="read_id" value="{{ $slipConfig['old']['id'] }}" :disabled="!slipHeld">
            <input type="hidden" name="read_line" value="{{ $slipConfig['old']['line'] ?? '' }}" :disabled="!slipHeld">
            @foreach ($slipConfig['old']['confirmed'] as $slipEntry)
                <input type="hidden" name="read_confirmed[]" value="{{ $slipEntry }}" :disabled="!slipHeld">
            @endforeach
        @endif
        <p class="mt-2" x-show="slipMessage" x-cloak x-text="slipMessage" role="status"></p>
        <p class="mt-2 font-medium text-laterite" x-show="slipBlocked" x-cloak x-text="slipBlocked" role="alert"></p>

        <template x-if="slipState === 'ready' && slipData">
            <div class="mt-3 grid gap-3 sm:grid-cols-[minmax(0,12rem)_1fr]">
                <img :src="slipPhoto" alt="{{ __('Photo of challan') }}" class="max-h-72 w-full rounded-[3px] border border-rule object-contain" x-show="slipPhoto">
                <div class="space-y-3">
                    <input type="hidden" name="read_id" :value="slipData.id">
                    <input type="hidden" name="read_line" :value="slipLine ?? ''">
                    <template x-for="entry in slipSent()" :key="entry"><input type="hidden" name="read_confirmed[]" :value="entry"></template>

                    <template x-for="warning in (slipData.warnings ?? [])" :key="warning.code">
                        <div class="rounded-[3px] border border-haldi bg-haldi/10 px-3 py-2">
                            <p x-text="slipWarningText(warning)"></p>
                            <label class="mt-1 flex items-center gap-2" x-show="warning.tick">
                                <input type="checkbox" :checked="slipTicks.includes(warning.code)" @change="slipTick(warning.code, $event.target.checked)">
                                <span>{{ __('I have checked this') }}</span>
                            </label>
                        </div>
                    </template>

                    <p class="font-medium">{{ __('Filled from the photo. Change each field or confirm it against the photo:') }}</p>
                    <template x-for="[name, label] in Object.entries(@js($slipRows))" :key="name">
                        <div class="border-b border-rule pb-2" x-show="slipRowShown(name)">
                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <span>
                                    <span class="stamp border-haldi text-[#8a6208]">{{ __('Read from photo') }}</span>
                                    <span x-text="label"></span>
                                    <span class="text-ink-3" x-show="slipCellOf(name)?.raw" x-text="@js(__('As printed:')) + ' ' + (slipCellOf(name)?.raw ?? '')"></span>
                                </span>
                                <span x-show="!slipNeeds(name)" class="text-ink-3">{{ __('Changed by you') }}</span>
                                <button type="button" class="btn btn-ghost min-h-10" x-show="slipNeeds(name)" @click="slipToggle(name)"
                                        x-text="slipIsConfirmed(name) ? @js(__('Confirmed against the photo')) : @js(__('Matches the photo'))"></button>
                            </div>
                            <template x-for="text in slipWarningsOf(name)" :key="name + text">
                                <p class="text-ink-2" x-text="text"></p>
                            </template>
                        </div>
                    </template>
                    <p x-show="slipUnitNote" x-text="slipUnitNote" class="text-laterite"></p>

                    <template x-for="warning in slipOtherWarnings()" :key="warning.key">
                        <p class="text-ink-2" x-text="warning.text"></p>
                    </template>

                    <div class="border-b border-rule pb-2" x-show="slipData.suggestions?.party || slipField('supplier_name')?.value">
                        <p class="font-medium">{{ __('Supplier') }}</p>
                        <template x-if="slipData.suggestions?.party">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="stamp border-haldi text-[#8a6208]" x-show="slipNeeds('party_id')">{{ __('Read from photo') }}</span>
                                <span x-text="@js(__('Found by its GSTIN:')) + ' ' + slipData.suggestions.party.name"></span>
                                <button type="button" class="btn btn-ghost min-h-10" @click="slipNeeds('party_id') ? slipToggle('party_id') : slipUseSupplier()"
                                        x-text="slipIsConfirmed('party_id') ? @js(__('Confirmed against the photo')) : (slipNeeds('party_id') ? @js(__('Matches the photo')) : @js(__('Use this supplier')))"></button>
                            </div>
                        </template>
                        <template x-if="slipField('supplier_name')?.value && (!slipData.suggestions?.party || newSupplier)">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="stamp border-haldi text-[#8a6208]" x-show="slipNeeds('supplier_name')">{{ __('Read from photo') }}</span>
                                <span x-text="@js(__('As printed:')) + ' ' + slipField('supplier_name').value"></span>
                                <button type="button" class="btn btn-ghost min-h-10" @click="newSupplier = false; $nextTick(() => document.getElementById('party_id')?.focus())">{{ __('Pick a supplier') }}</button>
                                <button type="button" class="btn btn-ghost min-h-10" @click="slipNeeds('supplier_name') ? slipToggle('supplier_name') : slipNewSupplier()"
                                        x-text="slipIsConfirmed('supplier_name') ? @js(__('Confirmed against the photo')) : (slipNeeds('supplier_name') ? @js(__('Matches the photo')) : @js(__('Use as a new supplier')))"></button>
                            </div>
                        </template>
                        <template x-for="text in slipWarningsOf('supplier_name')" :key="'s' + text">
                            <p class="text-ink-2" x-text="text"></p>
                        </template>
                    </div>

                    <div class="border-b border-rule pb-2" x-show="(slipData.lines ?? []).length > 1 || ((slipData.lines ?? []).length && slipField('net_weight')?.value)">
                        <p class="font-medium">{{ __('Lines on the challan. Pick the one this delivery is for:') }}</p>
                        <template x-for="(line, index) in (slipData.lines ?? [])" :key="'l' + index">
                            <label class="mt-1 flex items-center gap-2">
                                <input type="radio" name="slip_line" :checked="slipLine === index" @change="slipUseLine(index)">
                                <span x-text="[line.description?.raw, line.qty?.raw, line.unit?.raw].filter(Boolean).join(' · ')"></span>
                                <span class="text-ink-3" x-show="!line.qty?.value" x-text="@js($slipConfig['fieldWords']['not_a_number'])"></span>
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
                                        :class="String(material) === String(option.id) ? (slipIsConfirmed('material_id') ? 'border-moss text-moss' : 'border-haldi') : ''" x-text="option.name"></button>
                            </template>
                        </div>
                        <div class="mt-2 flex flex-wrap items-center gap-2" x-show="slipNeeds('material_id')">
                            <span class="stamp border-haldi text-[#8a6208]">{{ __('Read from photo') }}</span>
                            <span x-text="chosen?.label ?? ''"></span>
                            <button type="button" class="btn btn-ghost min-h-10" @click="slipToggle('material_id')"
                                    x-text="slipIsConfirmed('material_id') ? @js(__('Confirmed against the photo')) : @js(__('Matches the photo'))"></button>
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
