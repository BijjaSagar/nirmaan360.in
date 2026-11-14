{{--
    On the project's BOQ in Excel page (included there as modules.boq_scan._offer, with $project and,
    after an upload, $import).

    1. The offer to read a scanned copy or photos, for a person who may start a scan while the readers
       are on. After a PDF was refused as a scan ($import['scanned']) it says that this file can be
       read as a scan.
    2. For everyone who may import: when a PDF is uploaded through the import form, the browser keeps its
       own copy, so the preview can draw the PDF's pages beside the rows it read and the scan screen can
       offer the same file. The copy stays in the browser. When the person comes back here because the
       import of a PDF, or of a sheet through its column check, was confirmed or discarded
       (BoqPdfImport::ENDED), the copy is forgotten. After a refusal as a scan it is kept, for the scan
       screen to offer.
--}}
@php
    $scanScreen = app(\App\Services\BoqScan\BoqScanScreen::class);
    $scanOffered = auth()->check() && $scanScreen->offered(auth()->user());
    $scanRefused = ! empty($import['scanned'] ?? null);
@endphp
@if ($scanOffered)
    <section @class(['card mb-5', 'border-haldi' => $scanRefused]) data-boq-scan-offer>
        <div class="flex flex-wrap items-center justify-between gap-3 p-4">
            <p class="text-sm text-ink-2">
                {{ $scanRefused ? __('This PDF is a scanned copy. It can be read as a scan instead.') : __('Scanned copy or photos?') }}
            </p>
            <a href="{{ route('boq-scans.create', $project) }}" class="btn btn-primary">{{ __('Read them here (uses AI credits)') }}</a>
        </div>
    </section>
@endif

@can('boq.manage')
    {{-- The copy is kept whether or not the scan reader is installed, so nothing here asks for the reader kind. --}}
    @include('modules.boq_scan.partials.kept-pdf', ['minutes' => \App\Services\Reports\BoqPdfImport::TTL_MINUTES])
    <script>
        (() => {
            const FORM = 'boq-import-form';
            const key = @js(\App\Services\BoqScan\BoqScanScreen::keptKey(auth()->user(), $project));
            const ended = @js((bool) session(\App\Services\Reports\BoqPdfImport::ENDED));
            const kept = window.BoqScanKeptPdf;

            // Once on a page, so one upload is never kept or sent twice.
            if (!kept || window.BoqScanKeepsUploads) {
                return;
            }

            window.BoqScanKeepsUploads = true;

            // The import of the kept PDF was just confirmed or discarded: its copy has no more use.
            if (ended) {
                kept.forget(key);
            }

            // Listened for on the whole page: this script comes before the form on the BOQ in Excel page.
            document.addEventListener('submit', async (event) => {
                const form = event.target;

                if (event.defaultPrevented || !(form instanceof HTMLFormElement) || form.id !== FORM) {
                    return;
                }

                const file = form.querySelector('input[type="file"][name="file"]')?.files?.[0];

                if (!kept.isPdf(file)) {
                    return;
                }

                // The copy is written before the form leaves the page. It never holds the upload up for long.
                event.preventDefault();

                // Pressed again while the copy is written: the first press sends the form.
                if (form.dataset.keeping === '1') {
                    return;
                }

                form.dataset.keeping = '1';
                await Promise.race([kept.save(key, file), new Promise((resolve) => setTimeout(resolve, 3000))]);
                delete form.dataset.keeping;
                form.submit();
            });
        })();
    </script>
@endcan
