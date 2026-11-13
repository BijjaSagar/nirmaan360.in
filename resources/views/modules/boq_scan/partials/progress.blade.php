{{--
    A read that is running or paused: where it stands, asked for every few seconds, what to do when
    it is paused or stuck, and its failed pages. The page can be closed; the person who started it is
    told when it is done. Once the read ends the screen is drawn again for what comes next.

    Needs $read, $status and $canSend.
--}}
@php
    $progressConfig = [
        'readId' => $read->id,
        'status' => $status,
        'canSend' => $canSend,
        'words' => [
            'read' => __(':read of :total pages read'),
            'failed' => __(':count pages failed'),
            'left' => __(':count pages left'),
            'eta' => __('About :minutes minutes left.'),
            'cancelAsk' => __('Cancel this scan? Nothing more is sent. Pages already read stay charged.'),
        ],
        'states' => collect(['draft', 'quoted', 'preparing', 'prepared', 'running', 'paused', 'failed'])->mapWithKeys(fn (string $state) => [$state => \App\Services\BoqScan\BoqScanScreen::statusLabel($state)])->all(),
    ];
@endphp
<section class="card" x-data="boqScanProgress(@js($progressConfig))" x-init="init()">
    <div class="card-head">
        <h2 class="card-title">{{ __('Reading the pages') }}</h2>
        <span class="text-sm font-medium text-ink-2" x-text="config.states[now.status] ?? now.status"></span>
    </div>
    <div class="space-y-4 p-4">
        <p class="rounded-[3px] border border-laterite bg-laterite/10 px-3 py-2 text-sm font-medium text-laterite-dark" role="alert" x-show="error" x-cloak x-text="error"></p>

        <div class="grid gap-3 sm:grid-cols-3">
            <div class="card px-4 py-3"><p class="num text-[22px] font-medium" x-text="now.pages_read + ' / ' + now.pages_total"></p><p class="text-xs text-ink-3" x-text="say(config.words.read, { read: now.pages_read, total: now.pages_total })"></p></div>
            <div class="card px-4 py-3"><p class="num text-[22px] font-medium" x-text="now.pages_left"></p><p class="text-xs text-ink-3" x-text="say(config.words.left, { count: now.pages_left })"></p></div>
            <div class="card px-4 py-3"><p class="num text-[22px] font-medium" x-text="now.pages_failed"></p><p class="text-xs text-ink-3" x-text="say(config.words.failed, { count: now.pages_failed })"></p></div>
        </div>

        <p class="text-sm text-ink-2" x-show="now.eta_minutes" x-text="say(config.words.eta, { minutes: now.eta_minutes })"></p>
        <p class="rounded-[3px] border border-haldi bg-haldi/15 px-3 py-2 text-sm" x-show="now.stalled" x-cloak>{{ __('The server has not started reading yet. Cancelling now costs nothing.') }}</p>
        <p class="rounded-[3px] border border-haldi bg-haldi/15 px-3 py-2 text-sm" x-show="now.status === 'paused' && now.message" x-cloak x-text="now.message"></p>
        <p class="text-sm text-ink-3">{{ __('You can close this page. You will get a notification when the reading is done.') }}</p>

        @include('modules.boq_scan.partials.failed-pages')

        <div class="flex flex-wrap gap-2">
            <button type="button" class="btn btn-primary" x-show="now.can_resume && canSend" x-cloak :disabled="busy" @click="resume()">{{ __('Continue reading') }}</button>
            <button type="button" class="btn btn-ghost" x-show="now.can_cancel" :disabled="busy" @click="cancel()">{{ __('Cancel this scan') }}</button>
        </div>
    </div>
</section>

<script>
    // Watches a running read and offers what can be done. When the read ends the screen is drawn again.
    function boqScanProgress(config) {
        const http = window.BoqScanHttp;
        const ENDED = ['ready', 'failed', 'confirmed', 'discarded', 'cancelled', 'expired'];

        return {
            config, now: config.status, canSend: config.canSend, busy: false, error: null, stop: null,
            say: http.say,

            get failedPages() {
                return this.now.failed_pages ?? [];
            },

            capture() {
                return window.ReaderCapture
                    ? Promise.resolve(window.ReaderCapture)
                    : new Promise((resolve) => document.addEventListener('reader-capture:ready', () => resolve(window.ReaderCapture), { once: true }));
            },

            async init() {
                if (ENDED.includes(config.status.status)) {
                    return;
                }

                const capture = await this.capture();

                this.stop = capture.watch(config.readId, (now) => {
                    this.now = now;

                    // Drawn again only when the read has moved on to an end, never for the end it was opened in.
                    if (ENDED.includes(now.status) && now.status !== config.status.status) {
                        window.location.reload();
                    }
                }, (problem) => { this.error = problem?.message ?? null; });
            },

            async act(step) {
                this.busy = true;
                this.error = null;

                try {
                    await step(await this.capture());
                    window.location.reload();
                } catch (problem) {
                    this.error = problem?.message || String(problem);
                    this.busy = false;
                }
            },

            resume() {
                return this.act((capture) => capture.resume(config.readId));
            },

            cancel() {
                if (!window.confirm(config.words.cancelAsk)) {
                    return;
                }

                return this.act((capture) => capture.cancel(config.readId));
            },

            retry(page) {
                return this.act((capture) => capture.retry(config.readId, page.id, {}));
            },

            halves(page) {
                return this.act((capture) => capture.halves(config.readId, page));
            },
        };
    }
</script>
