{{--
    Beside the rows of a text PDF's preview (included there as modules.boq_scan._pdf_pages, with
    $project and $preview): the pages of the same PDF, drawn in the browser from the copy it kept when
    the file was uploaded on the BOQ in Excel page. Nothing is sent anywhere and no AI is used.

    Without a kept copy of this file (another browser, a private window, or after :minutes minutes)
    the person is told to open the PDF beside the page, as the preview says without this module.
--}}
@php
    $pagesConfig = [
        'key' => \App\Services\BoqScan\BoqScanScreen::keptKey(auth()->user(), $project),
        'file' => (string) $preview['file'],
        'pages' => (int) $preview['pages'],
        'words' => [
            'page' => __('Page :n'),
            'drawing' => __('Drawing page :n of :total…'),
            'notDrawn' => __('Page :n could not be drawn in this browser.'),
        ],
    ];
@endphp
<aside class="card min-w-0 xl:sticky xl:top-4" x-data="boqPdfPages(@js($pagesConfig))" x-init="draw()">
    <div class="card-head">
        <h2 class="card-title">{{ __('Pages of the PDF') }}</h2>
        <span class="num text-sm text-ink-3" x-show="images.length" x-cloak x-text="images.length + ' / ' + config.pages"></span>
    </div>
    <div class="space-y-3 p-4">
        <p class="text-sm text-ink-3" x-show="!images.length && !drawing">{{ __('Open the PDF beside this page and compare each row with it.') }}</p>
        <p class="text-sm text-ink-3" x-show="drawing" x-cloak x-text="progress"></p>
        <ul class="space-y-1 text-sm text-laterite-dark" x-show="problems.length" x-cloak>
            <template x-for="problem in problems" :key="problem"><li x-text="problem"></li></template>
        </ul>
        <div class="max-h-[80vh] space-y-4 overflow-y-auto" x-show="images.length" x-cloak>
            <template x-for="image in images" :key="image.pageNo">
                <figure>
                    <figcaption class="eyebrow mb-1 text-ink-3" x-text="image.label"></figcaption>
                    <img :src="image.url" :alt="image.label" class="w-full border border-rule bg-white">
                </figure>
            </template>
        </div>
        <p class="text-xs text-ink-3" x-show="images.length" x-cloak>{{ __('These pages are drawn in your browser from the copy it kept when you uploaded the file. Nothing is sent anywhere.') }}</p>
    </div>
</aside>

@include('modules.boq_scan.partials.kept-pdf', ['minutes' => \App\Services\Reports\BoqPdfImport::TTL_MINUTES])
@include('modules.document_reading._capture')
<script>
    // Draws the kept copy of the PDF one page at a time, only when it is the file this preview read.
    function boqPdfPages(config) {
        const say = (text, values) => Object.entries(values).reduce((line, [name, value]) => line.replaceAll(':' + name, String(value)), text);

        return {
            config, images: [], problems: [], drawing: false, progress: '',

            draw() {
                const go = () => this.run().catch(() => { this.drawing = false; });

                window.ReaderCapture ? go() : document.addEventListener('reader-capture:ready', go, { once: true });
            },

            async run() {
                const kept = await window.BoqScanKeptPdf.load(config.key);

                // Only the file this preview was made from. Any other copy would put the wrong pages beside the rows.
                if (!kept || kept.name !== config.file) {
                    return;
                }

                this.drawing = true;

                await window.ReaderCapture.pdfToPages(kept.file, (page) => {
                    this.progress = say(config.words.drawing, { n: page.pageNo, total: page.pagesTotal });

                    if (page.blob) {
                        this.images.push({ pageNo: page.pageNo, label: say(config.words.page, { n: page.pageNo }), url: URL.createObjectURL(page.blob) });
                    } else {
                        this.problems.push(say(config.words.notDrawn, { n: page.pageNo }));
                    }

                    return page.pageNo < Math.max(config.pages, 1);
                });

                this.drawing = false;
            },
        };
    }
</script>
