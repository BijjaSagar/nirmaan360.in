{{--
    Choosing the pages of a scan, uploading them and starting the read. Used by the scan screen
    ($read null: the read is made when the pages are prepared) and by the screen of a read that has
    not started yet ($read given: an upload that was interrupted goes on where it stopped, because
    pages already stored are not drawn or sent again).

    The browser draws each page of a PDF, or turns each photo upright, and uploads it as a picture,
    one at a time. The PDF itself is kept with the read for the record, and is never read.

    Needs $project, $limits, $keptKey and $read (or null). The page includes the capture partial,
    the kept-PDF partial and the http partial.
--}}
@php
    $uploadRead = $read ?? null;
    $uploadConfig = [
        'mode' => $uploadRead === null ? 'new' : 'existing',
        'readId' => $uploadRead?->id,
        'projectId' => $project->id,
        'storeUrl' => route('boq-scans.store', $project, false),
        'showUrl' => route('boq-scans.show', ['read' => '__READ__'], false),
        'creditsUrl' => auth()->user()->can('agents.credits') && \Illuminate\Support\Facades\Route::has('agent-credits.index') ? route('agent-credits.index', [], false) : null,
        'maxPages' => (int) $limits['pages'],
        'maxPdfBytes' => (int) $limits['pdf_bytes'],
        'pagesTotal' => (int) ($uploadRead?->pages_total ?? 0),
        'stored' => $stored ?? [],
        'language' => $uploadRead?->options['language'] ?? (in_array(app()->getLocale(), ['en', 'mr', 'hi'], true) ? app()->getLocale() : 'en'),
        'sourceName' => $uploadRead?->source_name,
        'keptKey' => $keptKey,
        'words' => [
            'noFile' => __('Choose a PDF or photos first.'),
            'notPdf' => __('Choose one PDF file.'),
            'notPhoto' => __('Choose photos in JPG, PNG or WebP.'),
            'tooManyPages' => __('This PDF has :count pages. A scan can have at most :max pages. Upload only the BOQ pages.'),
            'tooManyPhotos' => __('Choose at most :max photos.'),
            'tooBig' => __('This PDF is larger than :mb MB. Upload only the BOQ pages.'),
            'otherCount' => __('This choice has :count pages, but this scan was started with :total. Choose the same file or photos.'),
            'opening' => __('Opening the file…'),
            'uploading' => __('Uploading page :n of :total…'),
            'stopped' => __('The upload was stopped. The pages already uploaded are kept.'),
            'sourceFailed' => __('The PDF itself could not be kept with the scan. Its pages are uploaded all the same.'),
            'leftOut' => __('These pages will not be read: :pages.'),
            'pagesLine' => __(':pages pages will be read, in about :minutes minutes.'),
            'estimate' => __('Estimated credits: :credits.'),
            'rupees' => __('About ₹:rupees.'),
            'free' => __('Free credits: :credits.'),
            'notEnough' => __('Your credits cover :count of these pages now.'),
            'start' => __('Read :count pages'),
            'nothing' => __('No page is uploaded yet.'),
        ],
    ];
@endphp
<section class="card" x-data="boqScanUpload(@js($uploadConfig))" x-init="init()">
    <div class="card-head">
        <h2 class="card-title">{{ $uploadRead === null ? __('Pages to read') : __('Upload the pages') }}</h2>
    </div>
    <div class="space-y-4 p-4">
        <p class="rounded-[3px] border border-laterite bg-laterite/10 px-3 py-2 text-sm font-medium text-laterite-dark" role="alert" x-show="error" x-cloak x-text="error"></p>
        <ul class="space-y-1 text-sm text-ink-2" x-show="notes.length" x-cloak>
            <template x-for="note in notes" :key="note"><li x-text="note"></li></template>
        </ul>

        {{-- Choosing what to upload. --}}
        <div class="space-y-4" x-show="step === 'choose'">
            @if ($uploadRead !== null)
                <p class="text-sm text-ink-2" x-text="say(@js(__(':count of :total pages are uploaded. Choose the same file or photos again to upload the rest.')), { count: stored.length, total: config.pagesTotal })"></p>
            @endif

            <fieldset class="flex flex-wrap gap-4 text-sm">
                <legend class="label">{{ __('What do you have?') }}</legend>
                <label class="flex items-center gap-2"><input type="radio" value="pdf" x-model="source" class="size-4"> {{ __('A PDF of the scanned BOQ') }}</label>
                <label class="flex items-center gap-2"><input type="radio" value="photos" x-model="source" class="size-4"> {{ __('Photos of the pages') }}</label>
            </fieldset>

            <div>
                <label class="label" for="boq-scan-files" x-text="source === 'pdf' ? @js(__('PDF file')) : @js(__('Photos, in page order'))"></label>
                <input id="boq-scan-files" type="file" class="input" x-ref="files" :accept="source === 'pdf' ? '.pdf,application/pdf' : 'image/jpeg,image/png,image/webp'" :multiple="source === 'photos'" @change="pick($event.target.files)">
                <p class="hint">{{ __('One PDF of at most :mb MB and :max pages, or up to :max photos. Leave out pages that have no BOQ lines.', ['mb' => intdiv($limits['pdf_bytes'], 1024 * 1024), 'max' => $limits['pages']]) }}</p>
            </div>

            <div class="rounded-[3px] border border-rule bg-sheet px-3 py-2 text-sm" x-show="kept && source === 'pdf'" x-cloak>
                <p class="text-ink-2" x-text="say(@js(__('The PDF you uploaded on the BOQ in Excel page is still in this browser: :file')), { file: kept?.name ?? '' })"></p>
                <button type="button" class="link mt-1" @click="useKept()">{{ __('Read this PDF as a scan') }}</button>
            </div>

            @if ($uploadRead === null)
                <x-field :label="__('Main language of the document')" name="boq-scan-language" :hint="__('A hint for the reader only.')" class="max-w-sm">
                    <select id="boq-scan-language" class="input" x-model="language">
                        <option value="en">{{ __('English') }}</option>
                        <option value="mr">{{ __('Marathi') }}</option>
                        <option value="hi">{{ __('Hindi') }}</option>
                    </select>
                </x-field>
            @endif

            <div class="flex flex-wrap items-center gap-3">
                <button type="button" class="btn btn-primary" :disabled="busy" @click="prepare()">{{ __('Prepare the pages') }}</button>
                @if ($uploadRead !== null)
                    <button type="button" class="btn btn-ghost" x-show="stored.length > 0" :disabled="busy" @click="quoteStored()">{{ __('Read only the uploaded pages') }}</button>
                @endif
            </div>
        </div>

        {{-- Uploading, one page at a time. --}}
        <div class="space-y-3" x-show="step === 'uploading'" x-cloak>
            <p class="text-sm text-ink-2" x-text="progress"></p>
            <div class="rounded-[3px] border border-haldi bg-haldi/15 px-3 py-2 text-sm" x-show="ask" x-cloak>
                <p x-text="ask?.message"></p>
                <div class="mt-2 flex gap-2">
                    <button type="button" class="btn btn-primary" @click="answer(true)">{{ __('Upload these pages') }}</button>
                    <button type="button" class="btn btn-ghost" @click="answer(false)">{{ __('Stop') }}</button>
                </div>
            </div>
        </div>

        {{-- The price, then starting. --}}
        <div class="space-y-3" x-show="step === 'quote'" x-cloak>
            <template x-if="quote">
                <div class="space-y-1 text-sm text-ink-2">
                    <p class="font-medium text-ink" x-text="say(config.words.pagesLine, { pages: quote.pages, minutes: quote.minutes })"></p>
                    <p x-text="say(config.words.estimate, { credits: quote.estimate }) + (quote.rupees ? ' ' + say(config.words.rupees, { rupees: indian(quote.rupees) }) : '')"></p>
                    <p x-show="quote.free !== null" x-text="say(config.words.free, { credits: quote.free })"></p>
                    <p x-show="leftOut.length" x-text="say(config.words.leftOut, { pages: leftOut.join(', ') })"></p>
                    <p class="font-medium text-laterite-dark" x-show="!quote.enough" x-text="say(config.words.notEnough, { count: quote.pages_that_fit })"></p>
                </div>
            </template>
            <p class="text-sm text-ink-3">{{ __('Credits are held for this scan while it runs. Only pages that are read are charged.') }}</p>
            <p class="text-sm text-ink-3">{{ __('You can close this page once the reading has started. You will get a notification when it is done.') }}</p>
            <div class="flex flex-wrap items-center gap-3">
                <button type="button" class="btn btn-primary" :disabled="busy || !quote || quote.pages < 1" @click="start()" x-text="say(config.words.start, { count: quote?.pages ?? 0 })"></button>
                <button type="button" class="btn btn-ghost" :disabled="busy" @click="step = 'choose'">{{ __('Choose other pages') }}</button>
                <a x-show="config.creditsUrl && quote && !quote.enough" :href="config.creditsUrl" class="link text-sm">{{ __('Open the credits page') }}</a>
            </div>
        </div>
    </div>
</section>

<script>
    // The upload and start of a scan. Every step goes through the capture script, which draws the pages and calls the shared read routes.
    function boqScanUpload(config) {
        const http = window.BoqScanHttp;

        return {
            config, step: 'choose', source: 'pdf', files: [], kept: null, language: config.language,
            readId: config.readId, pagesTotal: config.pagesTotal, stored: config.stored, leftOut: [],
            busy: false, error: null, notes: [], progress: '', ask: null, quote: null,
            say: http.say, indian: http.indian,

            capture() {
                return window.ReaderCapture
                    ? Promise.resolve(window.ReaderCapture)
                    : new Promise((resolve) => document.addEventListener('reader-capture:ready', () => resolve(window.ReaderCapture), { once: true }));
            },

            async init() {
                if (config.mode === 'new') {
                    this.kept = await window.BoqScanKeptPdf.load(config.keptKey);
                } else if (this.stored.length > 0 && this.stored.length >= this.pagesTotal) {
                    // Every page is already there: straight to the price.
                    await this.quoteStored();
                }
            },

            pick(list) {
                this.files = Array.from(list ?? []);
                this.error = null;
            },

            useKept() {
                this.source = 'pdf';
                this.files = [new File([this.kept.file], this.kept.name, { type: 'application/pdf' })];
                this.prepare();
            },

            /** How many pages the choice has, or an error. */
            async count(capture) {
                const words = config.words;

                if (this.files.length === 0) {
                    throw new Error(words.noFile);
                }

                if (this.source === 'pdf') {
                    const file = this.files[0];

                    if (this.files.length !== 1 || !window.BoqScanKeptPdf.isPdf(file)) {
                        throw new Error(words.notPdf);
                    }

                    if (file.size > config.maxPdfBytes) {
                        throw new Error(http.say(words.tooBig, { mb: Math.floor(config.maxPdfBytes / 1048576) }));
                    }

                    this.progress = words.opening;
                    const pages = await capture.pdfPageCount(file);

                    if (pages > config.maxPages) {
                        throw new Error(http.say(words.tooManyPages, { count: pages, max: config.maxPages }));
                    }

                    return pages;
                }

                if (this.files.some((file) => !/^image\/(jpeg|png|webp)$/.test(file.type))) {
                    throw new Error(words.notPhoto);
                }

                if (this.files.length > config.maxPages) {
                    throw new Error(http.say(words.tooManyPhotos, { max: config.maxPages }));
                }

                return this.files.length;
            },

            /** The same choice sent twice opens the same read: the id is kept for this tab. */
            clientUuid() {
                const key = 'boq-scan:' + config.projectId + ':' + this.files.map((file) => file.name + '/' + file.size).join('|');
                let id = null;

                try {
                    id = sessionStorage.getItem(key);
                } catch {
                    // A browser that keeps nothing gets a new id each time.
                }

                if (!id) {
                    id = http.uuid();

                    try {
                        sessionStorage.setItem(key, id);
                    } catch {
                        // As above.
                    }
                }

                return id;
            },

            async prepare() {
                this.busy = true;
                this.error = null;
                this.notes = [];

                try {
                    const capture = await this.capture();
                    const pages = await this.count(capture);
                    const pdf = this.source === 'pdf';

                    if (config.mode === 'existing' && pages !== this.pagesTotal) {
                        throw new Error(http.say(config.words.otherCount, { count: pages, total: this.pagesTotal }));
                    }

                    if (!this.readId) {
                        const read = await http.send('POST', config.storeUrl, {
                            client_uuid: this.clientUuid(), pages_total: pages, language: this.language, source_name: (pdf ? this.files[0].name : '').slice(0, 160) || null,
                        });

                        this.readId = read.id;
                        this.pagesTotal = pages;
                        // A reload from here on opens this read, which carries on where the upload stopped.
                        history.replaceState(null, '', http.at(config.showUrl, { read: read.id }));
                    }

                    this.step = 'uploading';

                    if (pdf && config.mode === 'new') {
                        // Kept for the record only; the pages are what is read.
                        await capture.uploadSource(this.readId, this.files[0]).catch(() => this.notes.push(config.words.sourceFailed));
                    }

                    const options = {
                        confirm: (question) => new Promise((resolve) => { this.ask = { ...question, resolve }; }),
                        onProgress: (step) => {
                            this.progress = http.say(config.words.uploading, { n: step.done, total: this.pagesTotal });

                            if (step.message) {
                                this.notes.push(step.message);
                            }
                        },
                    };

                    const outcome = pdf ? await capture.uploadPdf(this.readId, this.files[0], options) : await capture.uploadPhotos(this.readId, this.files, options);

                    if (outcome.stopped) {
                        this.notes.push(config.words.stopped);
                        this.step = 'choose';
                        await this.refresh(capture);

                        return;
                    }

                    await this.quoteStored();
                } catch (problem) {
                    this.error = problem?.message || String(problem);
                    this.step = 'choose';
                } finally {
                    this.ask = null;
                    this.busy = false;
                }
            },

            answer(yes) {
                this.ask?.resolve(yes);
                this.ask = null;
            },

            async refresh(capture) {
                const status = await capture.status(this.readId);
                this.stored = status.stored_pages ?? [];
                this.leftOut = Array.from({ length: this.pagesTotal }, (_, index) => index + 1).filter((number) => !this.stored.includes(number));
            },

            /** The price of the pages that are uploaded. Pages not uploaded, such as blank ones, are left out. */
            async quoteStored() {
                this.busy = true;
                this.error = null;

                try {
                    const capture = await this.capture();
                    await this.refresh(capture);

                    if (this.stored.length === 0) {
                        throw new Error(config.words.nothing);
                    }

                    this.quote = await capture.quote(this.readId);
                    this.step = 'quote';
                } catch (problem) {
                    this.error = problem?.message || String(problem);
                    this.step = 'choose';
                } finally {
                    this.busy = false;
                }
            },

            async start() {
                this.busy = true;
                this.error = null;

                try {
                    const capture = await this.capture();
                    await capture.start(this.readId, { cap: this.quote.cap, skip_pages: this.leftOut });
                    window.location.href = http.at(config.showUrl, { read: this.readId });
                } catch (problem) {
                    this.error = problem?.message || String(problem);
                    this.busy = false;
                }
            },
        };
    }
</script>
