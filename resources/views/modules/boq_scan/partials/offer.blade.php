{{--
    On the project's BOQ in Excel page (included there as modules.boq_scan._offer, with $project and,
    after an upload, $import).

    1. The offer to read a scanned copy or photos, for a person who may start a scan while the readers
       are on. After a PDF was refused as a scan ($import['scanned']) it says that this file can be
       read as a scan.
    2. For everyone who may import: when a PDF is chosen in the import form, the browser keeps its own
       copy, so the preview can draw the PDF's pages beside the rows it read and the scan screen can
       offer the same file. The copy stays in the browser.
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
    @include('modules.boq_scan.partials.kept-pdf', ['minutes' => $scanScreen->limits()['minutes']])
    <script>
        (() => {
            const form = document.getElementById('boq-import-form');
            const input = form?.querySelector('input[type="file"][name="file"]');
            const key = @js(\App\Services\BoqScan\BoqScanScreen::keptKey(auth()->user(), $project));

            if (!form || !input) {
                return;
            }

            form.addEventListener('submit', async (event) => {
                const file = input.files?.[0];

                if (form.dataset.kept === '1' || !window.BoqScanKeptPdf?.isPdf(file)) {
                    return;
                }

                // The copy is written before the form leaves the page. It never holds the upload up for long.
                event.preventDefault();
                await Promise.race([window.BoqScanKeptPdf.save(key, file), new Promise((resolve) => setTimeout(resolve, 3000))]);
                form.dataset.kept = '1';
                form.submit();
            });
        })();
    </script>
@endcan
