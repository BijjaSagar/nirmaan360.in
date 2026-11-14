{{--
    "Read details from the bill" inside a bill form: the challan's bill form and the vendor bill
    form. The two partials that include this one decide who sees it; this one draws the reader.

    Expects:
        $project    the project the bill belongs to
        $fileInput  the id of the form's own file input to read, or null to draw a picture input of
                    its own that is not sent with the form
        $fields     form field name => ['from' => the reading's field, or 'party', 'label' => words]

    It only fills fields. Each one it fills carries a "read from photo" tag and must be changed or
    confirmed with "Matches the photo" before the form is saved; the server checks the same
    (SlipLink). A form sent back by the server shows the same reading again, with what was typed.
    Until it is shown again, the form still names the reading in inputs the server draws, so a Save
    meanwhile, or after the reading could not be fetched, is checked by the server as before. A
    reading that can no longer be used takes back what it filled. Reading again, a new reading and
    "Do not use this reading" put back what the fields held before the reading filled them, unless
    the person has changed them since.
--}}
@php
    $billService = app(\App\Services\SlipReading\SlipReadService::class);
    $billOld = filled(old('read_id')) ? ['id' => (int) old('read_id'), 'confirmed' => array_values(array_filter((array) old('read_confirmed', []), 'is_string'))] : null;
    $billConfig = [
        'urls' => [
            'store' => route('slip-reading.store', $project),
            'show' => route('slip-reading.show', ['slip' => '__SLIP__']),
            'recheck' => route('slip-reading.recheck', ['slip' => '__SLIP__']),
            'discard' => route('slip-reading.discard', ['slip' => '__SLIP__']),
        ],
        'file' => $fileInput ?? 'slip_bill_photo',
        'fields' => $fields,
        'old' => $billOld,
        'words' => [
            'choose' => __('Choose the photo of the bill first.'),
            'reading' => __('Reading the photo…'),
            'slow' => __('Still working. You can keep filling the form.'),
            'stopped' => __('Still working. Reload the page to see the reading.'),
            'failed' => __('The photo could not be read. You were not charged for it. Type the details yourself.'),
            'failed_again' => __('The second reading could not be used. Type the details yourself.'),
            'network' => __('Your request did not reach the server. Check your connection and try again.'),
            'refused' => __('This could not be done. Reload the page and try again.'),
            'gone' => __('This photo reading can no longer be used. Read the photo again or type the details yourself.'),
            'no_page' => __('The first page of this PDF could not be turned into a picture. Take a photo of the bill instead.'),
            'confirm_all' => __('Confirm each field filled from the photo, or change it, and tick each warning, before saving.'),
        ],
    ];
    $billFieldWords = [
        'unclear' => __('The reader was not sure of this value.'),
        'date_old' => __('This date is more than :days days old.', ['days' => (int) config('modules.slip_reading.old_after_days', 30)]),
        'date_other_year' => __('This date is in another financial year.'),
        'date_in_future' => __('The date on the photo is after today, so it is not filled.'),
        'date_unreadable' => __('This could not be read as a date, so it is not filled.'),
        'not_a_number' => __('This does not read as one number, so it is not filled.'),
        'gstin_check_failed' => __('The GSTIN did not pass the check, so no supplier is picked by it.'),
        'reads_disagree' => __('The two readings did not agree on this, so it is not filled.'),
        'qty_times_rate_differs' => __('Quantity times rate is not this amount.'),
        'lines_differ' => __('The lines do not add up to this figure, so it is not filled.'),
        'total_differs' => __('The figures above it do not add up to this total, so it is not filled.'),
        'cannot_be_checked' => __('No other figure on the bill confirms this one, so it is not filled.'),
        'round_off_too_large' => __('This round off is more than one rupee.'),
        'no_tax_printed' => __('No tax is printed on this bill, so no GST rate is filled. Enter it yourself.'),
        'gst_unusual' => __('Mixed or unusual GST on this bill, so no rate is filled. Enter it yourself.'),
        'tax_heads_mixed' => __('The tax printed does not show whether the supply is within the state or between states, so it is not filled.'),
        'states_differ' => __('The tax heads on this bill do not match the states of the two GSTINs. Check the supply.'),
    ];
    $billWarningWords = [
        'lines_do_not_add_up' => __('On lines :lines, quantity times rate is not the amount printed.'),
        'taxable_does_not_add_up' => __('The lines do not add up to the taxable value printed, so it is not filled.'),
        'total_does_not_add_up' => __('Taxable value, tax and round off do not add up to the grand total printed, so it is not filled.'),
        'states_differ' => __('The tax heads on this bill do not match the states of the two GSTINs. Check the supply.'),
        'bill_already_entered' => __('A bill of this supplier with the number :number is already entered.'),
        'same_photo' => __('This photo was already used for a saved record.'),
        'not_a_bill' => __('This paper does not look like a bill.'),
        'nothing_read' => __('Nothing could be read from this photo.'),
        'reads_disagree' => __('The two readings did not agree on some values. Those are not filled.'),
    ];
    $billFigures = [
        'supplier_name' => __('Supplier'),
        'supplier_gstin' => __('Supplier GSTIN'),
        'buyer_gstin' => __('Buyer GSTIN'),
        'eway_bill_no' => __('E-way bill no.'),
        'taxable_total' => __('Taxable value'),
        'cgst' => 'CGST',
        'sgst' => 'SGST',
        'igst' => 'IGST',
        'round_off' => __('Round off'),
        'grand_total' => __('Grand total'),
    ];
@endphp
@include('modules.document_reading._capture')
@once
    <script>
        // Reads the chosen photo of a bill once, then fills the bill form from what the server made of it.
        // Nothing is saved here: the form's own button does that, and the server checks every filled field again.
        function slipBillReader(config) {
            const address = (name, id) => config.urls[name].replace('__SLIP__', encodeURIComponent(id));

            // Values as the server compares them (SlipLink): text in upper case with single spaces, a date as typed,
            // money to two places rounded half up on the digits as typed, ids as whole numbers. Nothing is null.
            // So "70800" is the reading's "70800.00" and " inv/0912 " its "INV/0912": still to be confirmed, not changed.
            const plain = (value) => String(value ?? '').replace(/[\s\p{Z}]+/gu, ' ').trim().toUpperCase() || null;
            const money = (value) => {
                const match = /^([+-]?)(\d*)(?:\.(\d*))?$/.exec(String(value ?? '').trim());

                if (!match || (match[2] === '' && (match[3] ?? '') === '')) {
                    return null;
                }

                const thousandths = BigInt((match[2] || '0') + ((match[3] ?? '') + '000').slice(0, 3));
                const hundredths = ((thousandths + 5n) / 10n).toString().padStart(3, '0');
                const sign = match[1] === '-' && /[1-9]/.test(hundredths) ? '-' : '';

                return `${sign}${hundredths.slice(0, -2)}.${hundredths.slice(-2)}`;
            };
            const whole = (value) => (/^\d+$/.test(String(value ?? '').trim()) ? String(parseInt(String(value).trim(), 10)) : null);
            const canonical = { party_id: whole, bill_no: plain, bill_date: (value) => String(value ?? '').trim() || null, bill_amount: money, taxable_amount: money, gst_pct: money, supply_type: plain };
            const same = (name, a, b) => (canonical[name] ?? ((value) => String(value ?? '')))(a) === (canonical[name] ?? ((value) => String(value ?? '')))(b);

            return {
                state: 'idle',
                message: '',
                blocked: '',
                data: null,
                // The form was sent back naming a reading that is not shown again yet: the inputs the server drew still name it.
                held: Boolean(config.old?.id),
                confirmed: [],
                // Field => the value the reading put in, and what the field held before that.
                filled: {},
                before: {},
                photo: null,
                started: 0,

                init() {
                    const form = this.$root.closest('form');
                    form.addEventListener('input', (event) => this.edited(event.target));
                    form.addEventListener('change', (event) => this.edited(event.target));
                    form.addEventListener('submit', (event) => this.beforeSave(event));

                    if (config.old?.id) {
                        this.restore(config.old);
                    }
                },

                async call(method, url, body = undefined) {
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

                // A PDF bill is read from its first page, drawn in the browser; a photo as it is.
                async pageOf(file) {
                    if (file.type === 'application/pdf' || /\.pdf$/i.test(file.name ?? '')) {
                        let first = null;
                        await window.ReaderCapture.pdfToPages(file, (page) => {
                            first = page;

                            return false;
                        });

                        if (!first || first.status !== 'ok' || !first.blob) {
                            throw new Error(first?.message || config.words.no_page);
                        }

                        return first;
                    }

                    return window.ReaderCapture.imageToPage(file);
                },

                async read() {
                    const file = document.getElementById(config.file)?.files?.[0];

                    if (!file) {
                        this.message = config.words.choose;

                        return;
                    }

                    this.forget();
                    this.state = 'sending';
                    this.message = config.words.reading;

                    try {
                        const page = await this.pageOf(file);
                        this.photo = URL.createObjectURL(page.blob);
                        const body = new FormData();
                        body.append('client_uuid', crypto.randomUUID());
                        body.append('kind', 'bill');
                        body.append('image', page.blob, 'bill.jpg');
                        const created = await this.call('POST', config.urls.store, body);
                        this.data = { id: created.id, status: created.status };
                        this.watch();
                    } catch (error) {
                        this.state = 'idle';
                        this.message = error.message;
                    }
                },

                watch() {
                    this.state = 'reading';
                    this.started = Date.now();
                    this.poll();
                },

                // Every 3 seconds for half a minute, then every 10 seconds, and not after 10 minutes.
                poll() {
                    const elapsed = Date.now() - this.started;

                    if (elapsed > 600000) {
                        this.message = config.words.stopped;

                        return;
                    }

                    setTimeout(() => this.refresh(), elapsed < 30000 ? 3000 : 10000);
                },

                async refresh() {
                    try {
                        const data = await this.call('GET', address('show', this.data.id));
                        this.data = data;

                        if (data.status === 'ready') {
                            this.state = 'ready';
                            this.message = '';
                            this.fill();
                        } else if (data.status === 'reading') {
                            this.message = data.read?.message || (Date.now() - this.started > 30000 ? config.words.slow : config.words.reading);
                            this.poll();
                        } else {
                            this.state = 'idle';
                            this.message = data.read?.message || (Number(data.credits) > 0 ? config.words.failed_again : config.words.failed);
                        }
                    } catch (error) {
                        this.message = error.message;
                        this.poll();
                    }
                },

                // The server sent the form back: the reading is shown again and what was typed stays as it is.
                // When it cannot be fetched, the form keeps naming it (held), so the server checks the next Save as it did this one.
                async restore(old) {
                    let data;

                    try {
                        data = await this.call('GET', address('show', old.id));
                    } catch (error) {
                        this.message = error.message;

                        return;
                    }

                    this.data = data;

                    for (const [name, spec] of Object.entries(config.fields)) {
                        const value = this.offered(spec);

                        if (value !== null && value !== undefined && value !== '' && this.input(name)) {
                            this.filled[name] = String(value);
                        }
                    }

                    this.held = false;

                    // The reading can no longer be used: what it filled goes, as with "Do not use this reading".
                    if (data.status !== 'ready') {
                        this.forget();
                        this.message = config.words.gone;

                        return;
                    }

                    this.state = 'ready';
                    this.confirmed = [...old.confirmed];
                },

                async recheck() {
                    try {
                        const data = await this.call('POST', address('recheck', this.data.id), new FormData());
                        // What the first reading filled goes, so a value the second one does not offer is not left behind.
                        this.forgetFilled();
                        this.data = data;
                        this.watch();
                    } catch (error) {
                        this.message = error.message;
                    }
                },

                async discard() {
                    const id = this.data?.id;
                    this.forget();

                    if (id) {
                        await this.call('POST', address('discard', id), new FormData()).catch(() => null);
                    }
                },

                field(name) {
                    return this.data?.fields?.[name] ?? null;
                },

                offered(spec) {
                    return spec.from === 'party' ? (this.data?.suggestions?.party?.id ?? null) : (this.field(spec.from)?.value ?? null);
                },

                input(name) {
                    return this.$root.closest('form').querySelector(`[name="${name}"]`);
                },

                // Fills one input and remembers what was filled, so a change by the person can be told apart.
                // A list is filled only with one of its own choices.
                put(name, value) {
                    const input = this.input(name);

                    if (!input || value === null || value === undefined || value === '') {
                        return;
                    }

                    if (input.tagName === 'SELECT' && ![...input.options].some((option) => option.value === String(value))) {
                        return;
                    }

                    if (!Object.prototype.hasOwnProperty.call(this.before, name)) {
                        this.before[name] = input.value;
                    }

                    input.value = String(value);
                    this.filled[name] = String(value);
                    this.unconfirm(name);
                },

                fill() {
                    for (const [name, spec] of Object.entries(config.fields)) {
                        this.put(name, this.offered(spec));
                    }
                },

                confirm(name) {
                    if (!this.confirmed.includes(name)) {
                        this.confirmed.push(name);
                    }

                    this.blocked = '';
                },

                unconfirm(name) {
                    this.confirmed = this.confirmed.filter((entry) => entry !== name);
                },

                toggle(name) {
                    this.isConfirmed(name) ? this.unconfirm(name) : this.confirm(name);
                },

                isConfirmed(name) {
                    return this.confirmed.includes(name);
                },

                // A filled field the person changed is theirs now: its confirmation no longer applies.
                edited(input) {
                    const name = input?.name;

                    if (name && this.filled[name] !== undefined && !same(name, input.value, this.filled[name])) {
                        this.unconfirm(name);
                    }
                },

                changed(name) {
                    const input = this.input(name);

                    return Boolean(input) && this.filled[name] !== undefined && !same(name, input.value, this.filled[name]);
                },

                // Saving waits until every filled field is confirmed or changed and every warning that asks for a tick has one.
                beforeSave(event) {
                    if (this.state !== 'ready' || !this.data) {
                        return;
                    }

                    const open = Object.keys(this.filled).some((name) => !this.changed(name) && !this.isConfirmed(name));
                    const unticked = (this.data.warnings ?? []).some((warning) => warning.tick && !this.isConfirmed('warning:' + warning.code));

                    if (open || unticked) {
                        event.preventDefault();
                        this.blocked = config.words.confirm_all;
                        this.$root.scrollIntoView({ behavior: 'smooth', block: 'start' });
                    }
                },

                // Takes back what the reading put in: each field gets what it held before, unless the person has changed it since.
                forgetFilled() {
                    for (const name of Object.keys(this.filled)) {
                        const input = this.input(name);

                        if (input && !this.changed(name)) {
                            input.value = this.before[name] ?? '';
                        }
                    }

                    this.confirmed = [];
                    this.filled = {};
                    this.before = {};
                    this.blocked = '';
                },

                forget() {
                    this.forgetFilled();
                    this.state = 'idle';
                    this.data = null;
                    this.message = '';
                },
            };
        }
    </script>
@endonce

<div x-data="slipBillReader(@js($billConfig))" class="rounded-[3px] border border-rule p-3 text-sm sm:col-span-2">
    @if (($fileInput ?? null) === null)
        <label class="label" for="slip_bill_photo">{{ __('Photo of the bill') }}</label>
        {{-- No name: the picture is read, not saved with the bill. --}}
        <input id="slip_bill_photo" type="file" accept="image/*,application/pdf" class="input mb-2 min-h-12 text-base">
    @endif
    <div class="flex flex-wrap items-center gap-2">
        <button type="button" class="btn btn-ink min-h-11" @click="read()" :disabled="state === 'sending' || state === 'reading'">
            {{ __('Read details from the bill (up to :n credits)', ['n' => $billService->upToWhole(\App\Models\SlipRead::BILL)]) }}
        </button>
        <button type="button" class="link text-xs" x-show="data" x-cloak @click="discard()">{{ __('Do not use this reading') }}</button>
    </div>
    <p class="hint mt-1">{{ __('Each field shows the text as read, beside the photo. You confirm each field against the photo.') }}</p>
    @error('read_id')<p class="error">{{ $message }}</p>@enderror
    @error('read_confirmed')<p class="error">{{ $message }}</p>@enderror
    @if ($billOld !== null)
        {{-- The reading the form was sent back with, until it is shown again. --}}
        <input type="hidden" name="read_id" value="{{ $billOld['id'] }}" :disabled="!held">
        @foreach ($billOld['confirmed'] as $billEntry)
            <input type="hidden" name="read_confirmed[]" value="{{ $billEntry }}" :disabled="!held">
        @endforeach
    @endif
    <p class="mt-2" x-show="message" x-cloak x-text="message" role="status"></p>
    <p class="mt-2 font-medium text-laterite" x-show="blocked" x-cloak x-text="blocked" role="alert"></p>

    <template x-if="state === 'ready' && data">
        <div class="mt-3 grid gap-3 sm:grid-cols-[minmax(0,12rem)_1fr]">
            <img :src="photo" alt="{{ __('Photo of the bill') }}" class="max-h-72 w-full rounded-[3px] border border-rule object-contain" x-show="photo">
            <div class="min-w-0 space-y-3">
                <input type="hidden" name="read_id" :value="data.id">
                <template x-for="entry in confirmed" :key="entry"><input type="hidden" name="read_confirmed[]" :value="entry"></template>

                <template x-for="warning in (data.warnings ?? [])" :key="warning.code">
                    <div class="rounded-[3px] border border-haldi bg-haldi/10 px-3 py-2">
                        <p x-text="(@js($billWarningWords)[warning.code] ?? warning.code).replace(':number', warning.params?.number ?? '').replace(':lines', warning.params?.lines ?? '')"></p>
                        <label class="mt-1 flex items-center gap-2" x-show="warning.tick">
                            <input type="checkbox" :checked="isConfirmed('warning:' + warning.code)" @change="$event.target.checked ? confirm('warning:' + warning.code) : unconfirm('warning:' + warning.code)">
                            <span>{{ __('I have checked this') }}</span>
                        </label>
                    </div>
                </template>

                <p class="font-medium" x-show="Object.keys(filled).length">{{ __('Filled from the photo. Change each field or confirm it against the photo:') }}</p>
                <template x-for="[name, spec] in Object.entries(@js($fields))" :key="name">
                    <div class="border-b border-rule pb-2" x-show="filled[name] !== undefined">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <span>
                                <span class="stamp border-haldi text-[#8a6208]">{{ __('Read from photo') }}</span>
                                <span x-text="spec.label"></span>
                            </span>
                            <span x-show="changed(name)" class="text-ink-3">{{ __('Changed by you') }}</span>
                            <button type="button" class="btn btn-ghost min-h-10" x-show="!changed(name)" @click="toggle(name)"
                                    x-text="isConfirmed(name) ? @js(__('Confirmed against the photo')) : @js(__('Matches the photo'))"></button>
                        </div>
                        <p class="text-ink-3" x-show="spec.from === 'party'" x-text="@js(__('Found by its GSTIN:')) + ' ' + (data.suggestions?.party?.name ?? '')"></p>
                        <p class="text-ink-3" x-show="spec.from !== 'party' && field(spec.from)?.worked_out" x-text="spec.from === 'gst_pct' ? @js(__('Worked out by the app from the taxable value and the tax printed.')) : @js(__('Worked out by the app from the tax heads printed.'))"></p>
                        <p class="text-ink-3" x-show="spec.from !== 'party' && field(spec.from)?.raw" x-text="@js(__('As printed:')) + ' ' + (field(spec.from)?.raw ?? '')"></p>
                    </div>
                </template>

                <template x-for="[name, spec] in Object.entries(@js($fields))" :key="'w' + name">
                    <div x-show="spec.from !== 'party'">
                        <template x-for="code in (field(spec.from)?.warnings ?? [])" :key="name + code">
                            <p class="text-ink-2" x-text="spec.label + ': ' + (@js($billFieldWords)[code] ?? code)"></p>
                        </template>
                    </div>
                </template>

                <details class="border-b border-rule pb-2">
                    <summary class="cursor-pointer font-medium">{{ __('Figures as printed on the bill') }}</summary>
                    <dl class="mt-2 grid gap-1">
                        <template x-for="[name, label] in Object.entries(@js($billFigures))" :key="'f' + name">
                            <div class="flex flex-wrap justify-between gap-2" x-show="field(name)?.raw">
                                <dt class="text-ink-3" x-text="label"></dt>
                                <dd class="num text-right">
                                    <span x-text="field(name)?.raw"></span>
                                    <template x-for="code in (field(name)?.warnings ?? [])" :key="name + code">
                                        <span class="block text-xs text-ink-2" x-text="@js($billFieldWords)[code] ?? code"></span>
                                    </template>
                                </dd>
                            </div>
                        </template>
                    </dl>
                    <div class="mt-2 overflow-x-auto" x-show="(data.lines ?? []).length">
                        <table class="ledger text-xs">
                            <thead><tr><th>{{ __('Description') }}</th><th>HSN</th><th class="r">{{ __('Quantity') }}</th><th>{{ __('Unit') }}</th><th class="r">{{ __('Rate') }}</th><th class="r">{{ __('Amount') }}</th></tr></thead>
                            <tbody>
                                <template x-for="(line, index) in (data.lines ?? [])" :key="'l' + index">
                                    <tr>
                                        <td x-text="line.description?.raw"></td>
                                        <td class="num" x-text="line.hsn?.raw"></td>
                                        <td class="r num" x-text="line.qty?.raw"></td>
                                        <td x-text="line.unit?.raw"></td>
                                        <td class="r num" x-text="line.rate?.raw"></td>
                                        <td class="r num">
                                            <span x-text="line.amount?.raw"></span>
                                            <span class="block text-laterite" x-show="(line.amount?.warnings ?? []).includes('qty_times_rate_differs')">{{ __('Quantity times rate is not this amount.') }}</span>
                                        </td>
                                    </tr>
                                </template>
                            </tbody>
                        </table>
                    </div>
                </details>

                <button type="button" class="btn btn-ghost min-h-10" x-show="data.can_recheck" @click="recheck()"
                        x-text="@js(__('Read again with the stronger model (up to :n credits)')).replace(':n', data.recheck_credits ?? '')"></button>
            </div>
        </div>
    </template>
</div>
