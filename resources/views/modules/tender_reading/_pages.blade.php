{{--
    Draws one page of a tender PDF in the browser, for the screens that show a page beside the text or
    the fact read from it. The PDF comes from the tender's own download route and is fetched once per
    screen. Include it once; it brings the capture partial, which loads pdf.js with its decoders.

        TenderPages.draw(pdfUrl, pageNo) resolves to an address for the drawn page picture.
        TenderPages.upload(readId, fileUrl, source, pages, onPage) draws those pages (or makes a picture
        document into one page) and uploads them to the read as page pictures.
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

                /*
                 * Draws the given pages of the document and uploads each as a page picture of the read,
                 * one page at a time, skipping pages the read already holds. A picture document is
                 * made into one page picture. onPage hears of every page: { pageNo, uploaded, message }.
                 * A blank page, or one this browser cannot draw, is left out and reported.
                 */
                async upload(readId, url, source, pages, onPage = () => {}) {
                    const capture = await ready();
                    const stored = new Set((await capture.status(readId)).stored_pages ?? []);
                    const wanted = pages.filter((pageNo) => !stored.has(pageNo));

                    pages.filter((pageNo) => stored.has(pageNo)).forEach((pageNo) => onPage({ pageNo, uploaded: true, message: null }));

                    if (wanted.length === 0) {
                        return;
                    }

                    const bytes = await bytesOf(url);

                    if (source === 'image') {
                        const picture = await capture.imageToPage(new Blob([bytes]));
                        await capture.uploadPage(readId, 1, picture.blob);
                        onPage({ pageNo: 1, uploaded: true, message: null });

                        return;
                    }

                    const want = new Set(wanted);
                    const last = Math.max(...wanted);
                    const skip = Array.from({ length: last }, (_, index) => index + 1).filter((pageNo) => !want.has(pageNo));

                    await capture.pdfToPages(bytes.slice(0), async (page) => {
                        if (page.pageNo > last) {
                            return false;
                        }

                        if (page.status === 'ok') {
                            await capture.uploadPage(readId, page.pageNo, page.blob);
                            onPage({ pageNo: page.pageNo, uploaded: true, message: null });
                        } else {
                            onPage({ pageNo: page.pageNo, uploaded: false, message: page.message });
                        }

                        return page.pageNo < last;
                    }, { skip });
                },
            };
        })();
    </script>
@endonce
