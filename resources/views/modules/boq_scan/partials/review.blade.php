{{--
    The review of a read, one document page at a time: the page picture beside its rows, each row
    with what was printed, the part of the page it was read from, the values that will be imported,
    every check the code made on it, and what a person can do about each. The totals of the whole
    read and the button that writes the BOQ are at the foot.

    Every check is worked out again by the server on each change; the screen only shows what it
    answers. Text the reader returned is only ever shown as text (x-text, :value), never as markup.

    Needs $read, $page (the first page's JSON), $status and $canSend.
--}}
@php
    $reviewUrl = fn (string $name, array $extra = []) => route('boq-scans.'.$name, ['read' => $read->id, ...$extra], false);
    $reviewConfig = [
        'readId' => $read->id,
        'page' => $page,
        'failedPages' => $status['failed_pages'] ?? [],
        'canSend' => $canSend,
        'reasonMin' => (int) config('modules.boq_scan.reason_min'),
        'reasonMax' => (int) config('modules.boq_scan.reason_max'),
        'urls' => [
            'rows' => $reviewUrl('rows'),
            'add' => $reviewUrl('rows.store'),
            'update' => $reviewUrl('rows.update', ['row' => '__ROW__']),
            'exclude' => $reviewUrl('rows.exclude', ['row' => '__ROW__']),
            'join' => $reviewUrl('rows.join', ['row' => '__ROW__']),
            'confirmRow' => $reviewUrl('rows.confirm', ['row' => '__ROW__']),
            'override' => $reviewUrl('rows.override', ['row' => '__ROW__']),
            'confirmClean' => $reviewUrl('pages.confirm-clean', ['page' => 0]),
            'noLines' => $reviewUrl('pages.no-lines', ['page' => 0]),
            'acks' => $reviewUrl('acks.store'),
            'confirm' => $reviewUrl('confirm'),
            'discard' => $reviewUrl('discard'),
        ],
        'kinds' => [
            ['value' => 'item', 'label' => __('Line item')],
            ['value' => 'heading', 'label' => __('Heading')],
            ['value' => 'subtotal', 'label' => __('Subtotal')],
            ['value' => 'total', 'label' => __('Total')],
            ['value' => 'addon', 'label' => __('Add-on')],
            ['value' => 'note', 'label' => __('Note')],
        ],
        'rowAcks' => \App\Services\BoqScan\ScanFlag::ROW_ACKS,
        'pageAcks' => \App\Services\BoqScan\ScanFlag::PAGE_ACKS,
        'words' => [
            'pageOption' => __('Page :n'),
            'pageOpen' => __('Page :n, :count open'),
            'pageOf' => __('Page :n of :total'),
            'clean' => __('Confirm the clean rows of this page (:count)'),
            'noLinesAsk' => __('Mark this page as having no BOQ lines? Its rows are left out of the import.'),
            'discardAsk' => __('Discard this scan? Nothing is written to the BOQ, and the scan cannot be opened again.'),
            'product' => __('Quantity × rate: :product'),
            'write' => __('Write :count lines to the BOQ'),
            'reasonShort' => __('Write the reason in :min to :max characters.'),
            'old' => __('Now in the BOQ: :description, :qty :unit at :rate'),
            'devanagari' => __('Devanagari digits'),
            'ascii' => __('Western digits'),
            'mixed' => __('two kinds of digits'),
            'other' => __('other digits'),
            'comma' => __('printed with commas'),
            'unclear' => __('not clear'),
            'confirmedRow' => __('Confirmed one by one'),
            'confirmedPage' => __('Confirmed with the page'),
            'notConfirmed' => __('Not confirmed yet.'),
            'leftOut' => __('Left out'),
            'byHand' => __('Added by hand'),
        ],
    ];
    $printedFields = ['item_no' => __('Item no.'), 'description' => __('Description'), 'unit' => __('Unit'), 'qty' => __('Quantity'), 'rate' => __('Rate'), 'amount' => __('Amount')];
    $editFields = ['section' => __('Section'), 'code' => __('Item code'), 'unit' => __('Unit'), 'qty' => __('Quantity'), 'rate' => __('Rate'), 'amount' => __('Amount'), 'percent' => __('Percentage')];
@endphp
<div class="space-y-5" x-data="boqScanReview(@js($reviewConfig))">
    <p class="text-sm text-ink-2">{{ __('Each line is shown beside the part of the page the AI says it read. That position is approximate. Every line is confirmed by a person before it is saved.') }}</p>
    <p class="rounded-[3px] border border-laterite bg-laterite/10 px-3 py-2 text-sm font-medium text-laterite-dark" role="alert" x-show="error" x-cloak x-text="error"></p>

    {{-- The page bar: which page, its checks and what can be done for the whole page. --}}
    <section class="card">
        <div class="card-head flex-wrap gap-2">
            <h2 class="card-title" x-text="say(config.words.pageOf, { n: pageNo, total: data.pages.length })"></h2>
            <div class="flex flex-wrap items-center gap-2">
                <button type="button" class="btn btn-ghost" :disabled="busy || pageNo <= 1" @click="load(pageNo - 1)">{{ __('Previous page') }}</button>
                <label class="sr-only" for="boq-scan-page">{{ __('Go to page') }}</label>
                <select id="boq-scan-page" class="input w-auto" :value="pageNo" :disabled="busy" @change="load(Number($event.target.value))">
                    <template x-for="entry in data.pages" :key="entry.page_no">
                        <option :value="entry.page_no" :selected="entry.page_no === pageNo" x-text="entry.open > 0 ? say(config.words.pageOpen, { n: entry.page_no, count: entry.open }) : say(config.words.pageOption, { n: entry.page_no })"></option>
                    </template>
                </select>
                <button type="button" class="btn btn-ghost" :disabled="busy || pageNo >= data.pages.length" @click="load(pageNo + 1)">{{ __('Next page') }}</button>
            </div>
        </div>
        <div class="space-y-3 p-4">
            <template x-for="(flag, index) in data.page.flags" :key="index + flag.code">
                <div class="rounded-[3px] border px-3 py-2 text-sm" :class="flag.level === 'block' ? 'border-laterite bg-laterite/5' : 'border-haldi bg-haldi/10'">
                    <p x-text="flag.message"></p>
                    <div class="mt-2 flex flex-wrap gap-2">
                        <button type="button" class="btn btn-ghost" x-show="flag.code === 'columns_suspect'" :disabled="busy" @click="ack({ flag: flag.code, page_no: pageNo })">{{ __('I checked which column is quantity and which is rate on this page') }}</button>
                        <button type="button" class="btn btn-ghost" x-show="flag.code === 'second_reading_extra_row'" :disabled="busy" @click="addExtra(flag)">{{ __('Add this row by hand') }}</button>
                        <button type="button" class="btn btn-ghost" x-show="flag.code === 'second_reading_extra_row'" :disabled="busy" @click="ack({ flag: flag.code, page_no: pageNo, extra: flag.params.extra })">{{ __('It is not a line') }}</button>
                        <button type="button" class="btn btn-ghost" x-show="flag.level === 'tick' && !flag.ticked && config.pageAcks.includes(flag.code)" :disabled="busy" @click="ack({ flag: flag.code, page_no: pageNo })">{{ __('I have checked this') }}</button>
                        <span class="text-xs font-medium text-moss" x-show="flag.level === 'tick' && flag.ticked">{{ __('Ticked') }}</span>
                    </div>
                </div>
            </template>

            @include('modules.boq_scan.partials.failed-pages')

            <div class="flex flex-wrap gap-2">
                <button type="button" class="btn btn-primary" x-show="data.page.clean_rows > 0" :disabled="busy || !data.page.confirm_clean || !viewed" @click="confirmClean()" x-text="say(config.words.clean, { count: data.page.clean_rows })"></button>
                <button type="button" class="btn btn-ghost" x-show="!data.page.no_lines" :disabled="busy" @click="noLines()">{{ __('This page has no BOQ lines') }}</button>
                <button type="button" class="btn btn-ghost" :disabled="busy" @click="add(null)">{{ __('Add a row by hand') }}</button>
            </div>
            <p class="text-xs text-ink-3" x-show="data.page.clean_rows > 0 && !viewed">{{ __('The clean rows of a page can be confirmed together only while the picture of the page is shown.') }}</p>
            <p class="text-xs text-ink-3" x-show="data.page.no_lines" x-cloak>{{ __('This page is marked as having no BOQ lines. Choose the kind of a row to bring it back.') }}</p>
        </div>
    </section>

    <div class="grid items-start gap-5 xl:grid-cols-[minmax(0,2fr)_minmax(0,3fr)]">
        {{-- The page itself. --}}
        <section class="card min-w-0 xl:sticky xl:top-4">
            <div class="card-head"><h2 class="card-title">{{ __('The page') }}</h2></div>
            <div class="p-4">
                <template x-if="image">
                    <img :src="image.url" :alt="say(config.words.pageOption, { n: pageNo })" class="w-full border border-rule bg-white" @load="viewed = true">
                </template>
                <p class="text-sm text-ink-3" x-show="!image">{{ __('The picture of this page is not kept any more.') }}</p>
            </div>
        </section>

        {{-- Its rows. --}}
        <section class="min-w-0 space-y-3">
            <p class="card p-4 text-sm text-ink-3" x-show="data.rows.length === 0">{{ __('No rows were read on this page.') }}</p>

            <template x-for="row in data.rows" :key="row.id">
                <article class="card" :class="row.kind === 'excluded' ? 'opacity-70' : ''">
                    <div class="flex flex-wrap items-center justify-between gap-2 border-b border-rule px-4 py-2">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="num font-medium" x-text="row.raw.item_no || '—'"></span>
                            <label class="sr-only" :for="'kind-' + row.id">{{ __('Kind of row') }}</label>
                            <select :id="'kind-' + row.id" class="input w-auto py-1" :disabled="busy" @change="patch(row, { kind: $event.target.value })">
                                <option value="excluded" disabled :selected="row.kind === 'excluded'" x-text="config.words.leftOut"></option>
                                <template x-for="kind in config.kinds" :key="kind.value">
                                    <option :value="kind.value" :selected="row.kind === kind.value" x-text="kind.label"></option>
                                </template>
                            </select>
                            <span class="text-xs text-ink-3" x-show="row.added_by_hand" x-text="config.words.byHand"></span>
                        </div>
                        <span class="text-xs font-medium" :class="row.confirmed ? 'text-moss' : 'text-ink-3'" x-show="['item', 'addon'].includes(row.kind)"
                              x-text="row.confirmed ? (row.confirmed_mode === 'page' ? config.words.confirmedPage : config.words.confirmedRow) : config.words.notConfirmed"></span>
                    </div>

                    <div class="space-y-3 p-4">
                        {{-- What the page prints, as read. Read-only. --}}
                        <div x-show="!row.added_by_hand">
                            <p class="eyebrow mb-1 text-ink-3">{{ __('As printed') }}</p>
                            <dl class="grid gap-x-4 gap-y-1 text-sm sm:grid-cols-2">
                                @foreach ($printedFields as $field => $label)
                                    <div class="flex gap-2" x-show="row.raw.{{ $field }} !== ''">
                                        <dt class="shrink-0 text-ink-3">{{ $label }}</dt>
                                        <dd class="min-w-0 break-words">
                                            <span class="num" x-text="row.raw.{{ $field }}"></span>
                                            <span class="text-xs text-ink-3" x-text="marks(row, '{{ $field }}')"></span>
                                        </dd>
                                    </div>
                                @endforeach
                            </dl>
                        </div>

                        {{-- Where on the page it was read: about, not exactly. --}}
                        <template x-if="image && row.y_top !== null && row.y_bottom !== null">
                            <figure>
                                <figcaption class="mb-1 text-xs text-ink-3">{{ __('About here on the page') }}</figcaption>
                                <div class="relative overflow-hidden border border-rule bg-white" :style="'aspect-ratio: ' + strip(row).ratio">
                                    <img :src="image.url" alt="" class="block w-full" loading="lazy" :style="'transform: translateY(-' + strip(row).shift + '%)'">
                                </div>
                            </figure>
                        </template>

                        {{-- What will be imported. Every value typed here counts as typed by you. --}}
                        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                            <div class="sm:col-span-2 lg:col-span-4">
                                <label class="label" :for="'description-' + row.id">{{ __('Description') }} <span class="text-xs text-ink-3" x-show="(row.typed ?? []).includes('description')">{{ __('typed') }}</span></label>
                                <textarea :id="'description-' + row.id" rows="2" class="input" :value="row.description ?? ''" :disabled="busy" @change="patch(row, { description: $event.target.value })"></textarea>
                            </div>
                            @foreach ($editFields as $field => $label)
                                <div @if ($field === 'percent') x-show="row.kind === 'addon'" @elseif ($field === 'section' || $field === 'unit' || $field === 'qty' || $field === 'rate') x-show="['item', 'addon'].includes(row.kind)" @endif>
                                    <label class="label" :for="'{{ $field }}-' + row.id">{{ $label }} <span class="text-xs text-ink-3" x-show="(row.typed ?? []).includes('{{ $field }}')">{{ __('typed') }}</span></label>
                                    <input :id="'{{ $field }}-' + row.id" type="text" class="input @if (in_array($field, ['qty', 'rate', 'amount', 'percent'], true)) num @endif" @if (in_array($field, ['qty', 'rate', 'amount', 'percent'], true)) inputmode="decimal" @endif
                                           :value="row.{{ $field }} ?? ''" :disabled="busy" @change="patch(row, { {{ $field }}: $event.target.value })">
                                </div>
                            @endforeach
                        </div>
                        <p class="num text-sm text-ink-2" x-show="row.product !== null" x-text="say(config.words.product, { product: rupees(row.product) })"></p>
                        <p class="text-sm text-ink-3" x-show="row.flags.some((flag) => flag.code === 'updates_existing')" x-text="oldLine(row)"></p>

                        {{-- The checks on this row. --}}
                        <template x-for="(flag, index) in row.flags.filter((flag) => flag.code !== 'unconfirmed_row')" :key="index + flag.code">
                            <div class="rounded-[3px] border px-3 py-2 text-sm" :class="flag.level === 'block' ? 'border-laterite bg-laterite/5' : 'border-haldi bg-haldi/10'">
                                <p x-text="flag.message"></p>
                                <div class="mt-2 flex flex-wrap items-center gap-2">
                                    <button type="button" class="btn btn-ghost" x-show="flag.level === 'tick' && !flag.ticked && config.rowAcks.includes(flag.code)" :disabled="busy" @click="ack({ flag: flag.code, row_id: row.id })"
                                            x-text="flag.code === 'amount_unverified' ? @js(__('I checked quantity and rate against the page; no amount was available to check them')) : @js(__('I have checked this'))"></button>
                                    <span class="text-xs font-medium text-moss" x-show="flag.level === 'tick' && flag.ticked">{{ __('Ticked') }}</span>
                                    <button type="button" class="btn btn-ghost" x-show="flag.code === 'possible_duplicate_row'" :disabled="busy" @click="ack({ flag: flag.code, row_id: row.id })">{{ __('Both are real lines') }}</button>
                                </div>
                                <div class="mt-2 space-y-2" x-show="flag.code === 'amount_mismatch'">
                                    <label class="label" :for="'override-' + row.id">{{ __('Only if the page itself does not multiply out: why') }}</label>
                                    <textarea :id="'override-' + row.id" rows="2" class="input" x-model="reasons[row.id]" :maxlength="config.reasonMax"></textarea>
                                    <button type="button" class="btn btn-ghost" :disabled="busy" @click="override(row)">{{ __('The page itself does not multiply out; use my quantity and rate') }}</button>
                                </div>
                            </div>
                        </template>

                        <p class="error" x-show="rowErrors[row.id]" x-text="rowErrors[row.id]"></p>

                        <div class="flex flex-wrap gap-2">
                            <button type="button" class="btn btn-primary" x-show="['item', 'addon'].includes(row.kind) && !row.confirmed" :disabled="busy || !row.can_confirm" @click="rowStep(row, 'confirmRow')">{{ __('Confirm row') }}</button>
                            <button type="button" class="btn btn-ghost" x-show="row.kind !== 'excluded'" :disabled="busy" @click="rowStep(row, 'join')">{{ __('Join with the row above') }}</button>
                            <button type="button" class="btn btn-ghost" x-show="row.kind !== 'excluded'" :disabled="busy" @click="rowStep(row, 'exclude', 'DELETE')">{{ __('Leave out') }}</button>
                            <button type="button" class="btn btn-ghost" :disabled="busy" @click="add(row)">{{ __('Add a row below') }}</button>
                        </div>
                    </div>
                </article>
            </template>
        </section>
    </div>

    {{-- The whole document: its totals, its own checks, and writing the BOQ. --}}
    <section class="card">
        <div class="card-head"><h2 class="card-title">{{ __('Totals of the whole document') }}</h2></div>
        <div class="space-y-4 p-4">
            <dl class="grid gap-3 text-sm sm:grid-cols-2 lg:grid-cols-3">
                <div><dt class="text-ink-3">{{ __('Lines to import') }}</dt><dd class="num text-lg font-medium" x-text="data.summary.lines"></dd></div>
                <div><dt class="text-ink-3">{{ __('Sum of the printed amounts') }}</dt><dd class="num text-lg font-medium" x-text="rupees(data.summary.printed_amounts)"></dd></div>
                <div><dt class="text-ink-3">{{ __('BOQ value after import (quantity × rate)') }}</dt><dd class="num text-lg font-medium" x-text="rupees(data.summary.value_after_import)"></dd></div>
                <div><dt class="text-ink-3">{{ __('Printed total') }}</dt><dd class="num text-lg font-medium" x-text="rupees(data.summary.printed_total)"></dd></div>
                <div><dt class="text-ink-3">{{ __('Printed total less the printed amounts') }}</dt><dd class="num text-lg font-medium" x-text="rupees(data.summary.difference_printed)"></dd></div>
                <div><dt class="text-ink-3">{{ __('Printed total less the BOQ value') }}</dt><dd class="num text-lg font-medium" x-text="rupees(data.summary.difference_value)"></dd></div>
            </dl>
            <dl class="grid gap-3 text-sm sm:grid-cols-4">
                <div><dt class="text-ink-3">{{ __('Marks that stop the import') }}</dt><dd class="num font-medium" :class="data.summary.blocking ? 'text-laterite-dark' : ''" x-text="data.summary.blocking"></dd></div>
                <div><dt class="text-ink-3">{{ __('Ticks still to give') }}</dt><dd class="num font-medium" :class="data.summary.unticked ? 'text-laterite-dark' : ''" x-text="data.summary.unticked"></dd></div>
                <div><dt class="text-ink-3">{{ __('Lines not confirmed') }}</dt><dd class="num font-medium" :class="data.summary.unconfirmed ? 'text-laterite-dark' : ''" x-text="data.summary.unconfirmed"></dd></div>
                <div><dt class="text-ink-3">{{ __('Pages not resolved') }}</dt><dd class="num font-medium" :class="data.summary.pages_unresolved ? 'text-laterite-dark' : ''" x-text="data.summary.pages_unresolved"></dd></div>
            </dl>

            <template x-for="(flag, index) in data.read_flags" :key="index + flag.code">
                <div class="rounded-[3px] border px-3 py-2 text-sm" :class="flag.level === 'block' ? 'border-laterite bg-laterite/5' : 'border-haldi bg-haldi/10'">
                    <p x-text="flag.message"></p>
                    <div class="mt-2 space-y-2" x-show="flag.code === 'total_mismatch'">
                        <label class="label" :for="'total-' + (flag.params.row_id ?? '')">{{ __('Only if the document itself does not add up: why') }}</label>
                        <textarea :id="'total-' + (flag.params.row_id ?? '')" rows="2" class="input" x-model="totalReasons[flag.params.row_id]" :maxlength="config.reasonMax"></textarea>
                        <button type="button" class="btn btn-ghost" :disabled="busy" @click="ack({ flag: flag.code, row_id: flag.params.row_id, reason: totalReasons[flag.params.row_id] ?? '' })">{{ __('The document\'s total is not the sum of its lines; I have checked the lines') }}</button>
                    </div>
                    <div class="mt-2 flex items-center gap-2" x-show="flag.level === 'tick'">
                        <button type="button" class="btn btn-ghost" x-show="!flag.ticked" :disabled="busy" @click="ack({ flag: flag.code })">{{ __('I have checked this') }}</button>
                        <span class="text-xs font-medium text-moss" x-show="flag.ticked">{{ __('Ticked') }}</span>
                    </div>
                </div>
            </template>

            <div x-show="data.summary.needs_value_reason" x-cloak>
                <label class="label" for="boq-scan-value-reason">{{ __('Why the BOQ value after import differs from the printed total') }}</label>
                <textarea id="boq-scan-value-reason" rows="2" class="input" x-model="valueReason" :maxlength="config.reasonMax"></textarea>
                <p class="hint">{{ __('The BOQ keeps quantity and rate only. The reason is kept in the BOQ history.') }}</p>
            </div>

            <div class="flex flex-wrap items-center justify-between gap-3">
                <button type="button" class="btn btn-ghost" :disabled="busy" @click="discard()">{{ __('Discard this scan') }}</button>
                <button type="button" class="btn btn-primary" :disabled="busy || !canWrite()" @click="confirm()" x-text="say(config.words.write, { count: data.summary.lines })"></button>
            </div>
            <p class="text-xs text-ink-3">{{ __('A line without a mark has two readings that multiply out. That makes an error less likely, not impossible. Descriptions and units are not protected by any check: read them.') }}</p>
        </div>
    </section>
</div>

<script>
    // The review of a scanned BOQ. Every change goes to the server, which works every check out again; the page is then asked for again.
    function boqScanReview(config) {
        const http = window.BoqScanHttp;
        const FIELDS = ['qty', 'rate', 'amount'];

        return {
            config, data: config.page, pageNo: config.page.page.page_no, viewed: false, busy: false, error: null,
            rowErrors: {}, reasons: {}, totalReasons: {}, valueReason: '',
            say: http.say, indian: http.indian, rupees: http.rupees,

            /** The failed readings of the page on screen, for "Read this page again" and "Read as two halves". */
            get failedPages() {
                return config.failedPages.filter((page) => page.page_no === this.pageNo);
            },

            get canSend() {
                return config.canSend;
            },

            /** The picture the rows were read from: the whole page when there is one. */
            get image() {
                const readings = this.data.page.readings.filter((reading) => reading.image_url && reading.width && reading.height);
                const reading = readings.find((one) => one.pass === 'rows' && one.part === 'full') ?? readings.find((one) => one.part === 'full');

                return reading ? { url: reading.image_url, width: reading.width, height: reading.height } : null;
            },

            /** The band of the page a row was read from, with some room above and below. */
            strip(row) {
                const image = this.image;
                const top = Math.max(0, row.y_top - 40);
                const bottom = Math.min(1000, Math.max(row.y_bottom, row.y_top) + 40);
                const band = Math.max(bottom - top, 20);

                return { ratio: image.width + ' / ' + Math.max(1, Math.round(image.height * band / 1000)), shift: (top / 10).toFixed(2) };
            },

            /** What is worth knowing about a printed figure: its digits, its commas, and whether the reader was sure of it. */
            marks(row, field) {
                const words = config.words;
                const marks = [];

                if (FIELDS.includes(field) && row.script[field]) {
                    marks.push(words[row.script[field]] ?? row.script[field]);
                }

                if (row.had_comma.includes(field)) {
                    marks.push(words.comma);
                }

                if (row.state[field] === 'unclear') {
                    marks.push(words.unclear);
                }

                return marks.length ? '(' + marks.join(', ') + ')' : '';
            },

            oldLine(row) {
                const old = row.flags.find((flag) => flag.code === 'updates_existing')?.params?.old ?? {};

                return http.say(config.words.old, { description: old.description ?? '', qty: old.qty ?? '', unit: old.unit ?? '', rate: old.rate ? http.rupees(old.rate) : '' });
            },

            canWrite() {
                const summary = this.data.summary;
                const reason = this.valueReason.trim().length;

                return summary.can_confirm && (!summary.needs_value_reason || (reason >= config.reasonMin && reason <= config.reasonMax));
            },

            async load(number) {
                if (!number || number < 1) {
                    return;
                }

                this.busy = true;

                try {
                    const url = config.urls.rows + '?page_no=' + encodeURIComponent(number);
                    const data = await http.send('GET', url);

                    if (data.page.page_no !== this.pageNo) {
                        this.viewed = false;
                    }

                    this.data = data;
                    this.pageNo = data.page.page_no;
                    this.error = null;
                } catch (problem) {
                    this.error = problem.message;
                } finally {
                    this.busy = false;
                }
            },

            /** Sends one change, then asks for the page again, because a change to one row can move the checks of others. */
            async step(work, row = null) {
                this.busy = true;
                this.error = null;

                if (row) {
                    this.rowErrors[row.id] = null;
                }

                try {
                    await work();
                    this.busy = false;
                    await this.load(this.pageNo);

                    return true;
                } catch (problem) {
                    if (row) {
                        this.rowErrors[row.id] = problem.message;
                    } else {
                        this.error = problem.message;
                    }

                    this.busy = false;

                    return false;
                }
            },

            patch(row, values) {
                return this.step(() => http.send('PATCH', http.at(config.urls.update, { row: row.id }), values), row);
            },

            rowStep(row, name, method = 'POST') {
                return this.step(() => http.send(method, http.at(config.urls[name], { row: row.id }), method === 'DELETE' ? undefined : {}), row);
            },

            override(row) {
                return this.step(() => http.send('POST', http.at(config.urls.override, { row: row.id }), { flag: 'amount_mismatch', reason: this.reasons[row.id] ?? '' }), row);
            },

            add(after) {
                return this.step(() => http.send('POST', config.urls.add, { client_uuid: http.uuid(), page_no: this.pageNo, after_row_id: after?.id ?? null, kind: 'item' }), after);
            },

            /** An amount the second reading found on no row: a row is added by hand with that amount, for the person to fill in. */
            addExtra(flag) {
                return this.step(() => http.send('POST', config.urls.add, { client_uuid: http.uuid(), page_no: this.pageNo, kind: 'item', extra: flag.params.extra, amount: flag.params.amount || null }));
            },

            ack(body) {
                return this.step(() => http.send('POST', config.urls.acks, body));
            },

            confirmClean() {
                return this.step(() => http.send('POST', config.urls.confirmClean.replace('/pages/0/', '/pages/' + this.pageNo + '/'), { viewed: this.viewed }));
            },

            noLines() {
                if (!window.confirm(config.words.noLinesAsk)) {
                    return;
                }

                return this.step(() => http.send('POST', config.urls.noLines.replace('/pages/0/', '/pages/' + this.pageNo + '/'), {}));
            },

            capture() {
                return window.ReaderCapture
                    ? Promise.resolve(window.ReaderCapture)
                    : new Promise((resolve) => document.addEventListener('reader-capture:ready', () => resolve(window.ReaderCapture), { once: true }));
            },

            /** Reading a page again, or as two halves, starts the read again: the screen then shows its progress. */
            async resend(work) {
                this.busy = true;
                this.error = null;

                try {
                    await work(await this.capture());
                    window.location.reload();
                } catch (problem) {
                    this.error = problem?.message || String(problem);
                    this.busy = false;
                }
            },

            retry(page) {
                return this.resend((capture) => capture.retry(config.readId, page.id, {}));
            },

            halves(page) {
                return this.resend((capture) => capture.halves(config.readId, page));
            },

            async confirm() {
                const done = await this.step(() => http.send('POST', config.urls.confirm, this.data.summary.needs_value_reason ? { value_reason: this.valueReason } : {}));

                if (done) {
                    window.location.reload();
                }
            },

            async discard() {
                if (!window.confirm(config.words.discardAsk)) {
                    return;
                }

                const done = await this.step(() => http.send('POST', config.urls.discard, {}));

                if (done) {
                    window.location.reload();
                }
            },
        };
    }
</script>
