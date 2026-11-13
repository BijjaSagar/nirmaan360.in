{{--
    Draws one page of a tender PDF in the browser, for the screens that show a page beside the text or
    the fact read from it. The PDF comes from the tender's own download route and is fetched once per
    screen. Include it once; it brings the capture partial, which loads pdf.js with its decoders.

        TenderPages.draw(pdfUrl, pageNo) resolves to an address for the drawn page picture.
--}}
@once
    @include('modules.document_reading._capture')
    <script>
        window.TenderPages = window.TenderPages || (() => {
            const files = new Map();

            const ready = () => window.ReaderCapture
                ? Promise.resolve(window.ReaderCapture)
                : new Promise((resolve) => document.addEventListener('reader-capture:ready', () => resolve(window.ReaderCapture), { once: true }));

            const bytesOf = (url) => {
                if (!files.has(url)) {
                    files.set(url, fetch(url, { credentials: 'same-origin' })
                        .then((response) => {
                            if (!response.ok) {
                                throw new Error('not_fetched');
                            }

                            return response.arrayBuffer();
                        })
                        .catch((error) => {
                            files.delete(url);
                            throw error;
                        }));
                }

                return files.get(url);
            };

            return {
                async draw(url, pageNo) {
                    const capture = await ready();
                    // pdf.js takes the bytes it is given, so each drawing gets its own copy.
                    const data = (await bytesOf(url)).slice(0);
                    const skip = Array.from({ length: Math.max(0, pageNo - 1) }, (_, index) => index + 1);
                    let drawn = null;

                    await capture.pdfToPages(data, (page) => {
                        drawn = page;

                        return false;
                    }, { skip });

                    if (!drawn || !drawn.blob || !['ok', 'blank'].includes(drawn.status)) {
                        throw new Error(drawn?.message || 'not_drawn');
                    }

                    return URL.createObjectURL(drawn.blob);
                },
            };
        })();
    </script>
@endonce
