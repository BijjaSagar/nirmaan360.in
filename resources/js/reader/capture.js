// Turns a PDF or photos into page pictures in the browser and uploads them to a read, one page at
// a time. The PDF itself never leaves the browser for the reader: what is read are these pictures.
//
// The page that uses this includes the Blade partial modules.document_reading._capture, which
// carries the addresses, the words and where pdf.js finds its decoders. Everything is offered on
// window.ReaderCapture, because a feature's screen is plain Blade and Alpine with no build step of
// its own; the event "reader-capture:ready" on document says when it is there.
//
// The server has the last word on every picture: it redraws each upload, checks its size again and
// stores only its own copy.
import * as pdfjs from 'pdfjs-dist/legacy/build/pdf.mjs';
import PdfWorker from 'pdfjs-dist/legacy/build/pdf.worker.min.mjs?worker';
import { ReaderError, refusalOf, unreached, uploadFailure, worthRetrying } from './errors.js';
import { imageTokens, photoSize, targetSize, TOKEN_VECTORS, VECTORS } from './size.js';

export { ReaderError };

const JPEG_QUALITY = 0.9;
// A page whose longer side is above this many points at scale 1 is larger than A3 (1191 points) and is not drawn.
const LARGEST_PAGE_POINTS = 1280;
// The decoders refuse a single picture inside a PDF above this many pixels, so one huge scan cannot exhaust the tab.
const LARGEST_PDF_PICTURE = 40000000;
// A drawn page whose lightest and darkest sampled points differ by less than this is flat: nothing was drawn on it.
const FLAT_RANGE = 8;
// Every third pixel each way is looked at. A coarser grid misses a page with only a line or two on it.
const SAMPLE_STEP = 3;
// A page that has not finished drawing after this long is given up on, so one bad page cannot hold up the rest for ever.
const DRAW_TIMEOUT_MS = 90000;
const UPLOAD_TRIES = 3;
const WATCH_EVERY_MS = 3000;
const WATCH_PAUSED_EVERY_MS = 15000;
const FINAL = ['ready', 'failed', 'confirmed', 'discarded', 'cancelled', 'expired'];

let cachedSettings = null;

/** What the Blade partial put on the page: addresses, words and the folder of the pdf.js decoders. */
function settings() {
    if (cachedSettings) {
        return cachedSettings;
    }

    const element = document.querySelector('[data-reader-capture]');

    if (!element) {
        throw new ReaderError('not_set_up', 'The reader capture partial is missing from this page.');
    }

    const assets = element.dataset.assets.endsWith('/') ? element.dataset.assets : `${element.dataset.assets}/`;

    cachedSettings = {
        assets,
        urls: JSON.parse(element.dataset.urls || '{}'),
        words: JSON.parse(element.dataset.words || '{}'),
        halfShare: Number(element.dataset.halfShare || 550) / 1000,
    };

    return cachedSettings;
}

function words(key, values = {}) {
    const text = settings().words[key] ?? key;

    return Object.entries(values).reduce((line, [name, value]) => line.replaceAll(`:${name}`, String(value)), text);
}

function address(name, readId, pageId = null) {
    const template = settings().urls[name];

    if (!template) {
        throw new ReaderError('not_set_up', `No address for "${name}".`);
    }

    return template.replace('__READ__', encodeURIComponent(readId)).replace('__PAGE__', encodeURIComponent(pageId ?? ''));
}

/**
 * fetch() for the reader's addresses. A request that never reached the server (no connection, the
 * address could not be reached) becomes a ReaderError with the partial's sentence about the
 * connection, never the browser's own English.
 */
async function reach(url, init) {
    try {
        return await fetch(url, init);
    } catch (error) {
        throw unreached(error, words);
    }
}

/** One call to a reader route. A refusal becomes a ReaderError carrying the server's code, details and sentence. */
async function call(method, url, body = undefined) {
    const headers = {
        Accept: 'application/json',
        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
        'X-Requested-With': 'XMLHttpRequest',
    };
    let payload = body;

    if (body !== undefined && !(body instanceof FormData)) {
        headers['Content-Type'] = 'application/json';
        payload = JSON.stringify(body);
    }

    const response = await reach(url, { method, credentials: 'same-origin', headers, body: payload });

    if (response.status === 204) {
        return null;
    }

    const data = await response.json().catch(() => ({}));

    if (!response.ok) {
        // The server's own sentence when it sent one; otherwise a sentence of the partial, never a bare code or the framework's English.
        throw refusalOf(response.status, data, words);
    }

    return data;
}

const wait = (milliseconds) => new Promise((resolve) => setTimeout(resolve, milliseconds));

// ---- Drawing ----

function canvasOf(width, height) {
    const canvas = document.createElement('canvas');
    canvas.width = width;
    canvas.height = height;

    return canvas;
}

function release(canvas) {
    // A canvas keeps its memory until its size is set to nothing, even after it is out of reach.
    canvas.width = 0;
    canvas.height = 0;
}

/** The canvas as a JPEG file. `problem` is what to say when the browser cannot make one. */
function toJpeg(canvas, problem) {
    return new Promise((resolve, reject) => {
        canvas.toBlob((blob) => (blob ? resolve(blob) : reject(new ReaderError('draw_failed', problem))), 'image/jpeg', JPEG_QUALITY);
    });
}

/** How far apart the lightest and the darkest sampled point of a drawn page are, 0 to 255. */
export function luminanceRange(canvas) {
    const context = canvas.getContext('2d', { willReadFrequently: true });
    let lightest = 0;
    let darkest = 255;

    // Read in bands, so a large page never needs a second full copy of itself in memory.
    for (let top = 0; top < canvas.height; top += 256) {
        const height = Math.min(256, canvas.height - top);
        const { data } = context.getImageData(0, top, canvas.width, height);

        for (let y = 0; y < height; y += SAMPLE_STEP) {
            for (let x = 0; x < canvas.width; x += SAMPLE_STEP) {
                const at = (y * canvas.width + x) * 4;
                const light = 0.299 * data[at] + 0.587 * data[at + 1] + 0.114 * data[at + 2];

                lightest = Math.max(lightest, light);
                darkest = Math.min(darkest, light);
            }
        }

        if (lightest - darkest >= FLAT_RANGE) {
            return lightest - darkest;
        }
    }

    return lightest - darkest;
}

const PICTURE_OPERATORS = [
    'paintImageXObject', 'paintInlineImageXObject', 'paintImageXObjectRepeat', 'paintInlineImageXObjectGroup',
    'paintImageMaskXObject', 'paintImageMaskXObjectGroup', 'paintImageMaskXObjectRepeat', 'paintSolidColorImageMask',
];

async function paintsPicture(page) {
    const wanted = new Set(PICTURE_OPERATORS.map((name) => pdfjs.OPS[name]).filter((code) => code !== undefined));
    const { fnArray } = await page.getOperatorList({ intent: 'print' });

    return fnArray.some((code) => wanted.has(code));
}

/**
 * Opens a PDF with everything pdf.js needs for scanned pages: the decoders for fax, JBIG2 and
 * JPEG 2000 pictures, the character maps, the standard fonts and the colour profile. Each document
 * gets a worker of its own, which is ended with it, so a large scan does not stay in memory.
 */
async function openPdf(source) {
    const { assets } = settings();
    const port = new PdfWorker();
    const worker = new pdfjs.PDFWorker({ port });
    const from = typeof source === 'string' ? { url: source } : { data: new Uint8Array(source instanceof ArrayBuffer ? source : await source.arrayBuffer()) };

    const task = pdfjs.getDocument({
        ...from,
        worker,
        wasmUrl: `${assets}wasm/`,
        cMapUrl: `${assets}cmaps/`,
        cMapPacked: true,
        standardFontDataUrl: `${assets}standard_fonts/`,
        iccUrl: `${assets}iccs/`,
        maxImageSize: LARGEST_PDF_PICTURE,
        disableRange: true,
        disableStream: true,
    });

    const close = async () => {
        await task.destroy().catch(() => {});
        worker.destroy();
        port.terminate();
    };

    try {
        return { document: await task.promise, close };
    } catch (error) {
        await close();

        throw new ReaderError('pdf_failed', words('pdf_failed'), { reason: error?.name ?? null });
    }
}

/**
 * Draws one page of an open PDF. The answer says what became of it:
 *   ok           a picture to upload
 *   blank        nothing is on the page; it is left out unless the person includes it (the picture is there for that)
 *   not_decoded  the page is a scan this browser could not draw; never uploaded
 *   too_big      larger than A3; not drawn
 *   failed       drawing did not finish in time; never uploaded
 *
 * The page is drawn the way it would be printed. That is also what keeps a long upload going when
 * the person looks at another tab: drawing for the screen waits for the tab to be shown again.
 */
async function drawPage(document, pageNo) {
    const page = await document.getPage(pageNo);

    try {
        const base = page.getViewport({ scale: 1 });
        const result = { pageNo, pagesTotal: document.numPages, status: 'ok', blob: null, width: 0, height: 0, tokens: 0, message: null };

        if (Math.max(base.width, base.height) > LARGEST_PAGE_POINTS) {
            return { ...result, status: 'too_big', message: words('too_big', { n: pageNo }) };
        }

        const [width, height] = targetSize(base.width, base.height);
        const canvas = canvasOf(width, height);

        try {
            const task = page.render({ canvas, viewport: page.getViewport({ scale: width / base.width }), intent: 'print' });
            let timer = null;
            const late = new Promise((resolve) => {
                timer = setTimeout(() => resolve('late'), DRAW_TIMEOUT_MS);
            });
            const outcome = await Promise.race([task.promise.then(() => 'drawn', () => 'error'), late]);
            clearTimeout(timer);

            if (outcome !== 'drawn') {
                if (outcome === 'late') {
                    task.cancel();
                    await task.promise.catch(() => {});
                }

                return { ...result, status: 'failed', message: words('draw_failed', { n: pageNo }) };
            }

            if (luminanceRange(canvas) < FLAT_RANGE) {
                if (await paintsPicture(page)) {
                    return { ...result, status: 'not_decoded', message: words('not_decoded', { n: pageNo }) };
                }

                return { ...result, status: 'blank', blob: await toJpeg(canvas, words('draw_failed', { n: pageNo })), width, height, tokens: imageTokens(width, height), message: words('blank', { n: pageNo }) };
            }

            return { ...result, blob: await toJpeg(canvas, words('draw_failed', { n: pageNo })), width, height, tokens: imageTokens(width, height) };
        } finally {
            release(canvas);
        }
    } finally {
        page.cleanup();
    }
}

/**
 * Draws a PDF strictly one page at a time and hands each page to onPage before the next is drawn,
 * so only one page picture is ever held. onPage may return false to stop.
 *
 * @param {File|Blob|ArrayBuffer|string} source  A chosen file, or the address of a PDF this person may download.
 * @param {(page: object) => (Promise<boolean|void>|boolean|void)} onPage
 * @param {{ skip?: number[] }} options  Page numbers not to draw, such as pages already uploaded.
 * @returns {Promise<{ pages: number, stopped: boolean }>}
 */
export async function pdfToPages(source, onPage, options = {}) {
    const { document, close } = await openPdf(source);
    const skip = new Set(options.skip ?? []);

    try {
        for (let pageNo = 1; pageNo <= document.numPages; pageNo++) {
            if (skip.has(pageNo)) {
                continue;
            }

            if ((await onPage(await drawPage(document, pageNo))) === false) {
                return { pages: document.numPages, stopped: true };
            }
        }

        return { pages: document.numPages, stopped: false };
    } finally {
        await close();
    }
}

/** How many pages a PDF has, without drawing any. */
export async function pdfPageCount(source) {
    const { document, close } = await openPdf(source);

    try {
        return document.numPages;
    } finally {
        await close();
    }
}

async function bitmapOf(file) {
    try {
        return await createImageBitmap(file, { imageOrientation: 'from-image' });
    } catch {
        // An older browser without that option still turns a photo upright when it is loaded as a picture.
        const url = URL.createObjectURL(file);

        try {
            const image = new Image();
            image.src = url;
            await image.decode();

            return image;
        } catch {
            throw new ReaderError('image_failed', words('image_failed'));
        } finally {
            URL.revokeObjectURL(url);
        }
    }
}

/** Turns a photo into a page picture: upright, no larger than the reader takes, as a JPEG. A photo is never enlarged. */
export async function imageToPage(file) {
    const bitmap = await bitmapOf(file);
    const [width, height] = photoSize(bitmap.naturalWidth ?? bitmap.width, bitmap.naturalHeight ?? bitmap.height);
    const canvas = canvasOf(width, height);

    try {
        const context = canvas.getContext('2d');
        context.fillStyle = '#ffffff';
        context.fillRect(0, 0, width, height);
        context.drawImage(bitmap, 0, 0, width, height);

        return { status: 'ok', blob: await toJpeg(canvas, words('image_failed')), width, height, tokens: imageTokens(width, height) };
    } finally {
        bitmap.close?.();
        release(canvas);
    }
}

// ---- Uploading ----

/**
 * Uploads one page picture. Three tries for a failure of the connection or the server; a refusal
 * of the picture itself (too large, not a picture, the wrong state) is final at once.
 */
export async function uploadPage(readId, pageNo, blob, part = 'full') {
    let last = null;

    for (let attempt = 1; attempt <= UPLOAD_TRIES; attempt++) {
        const form = new FormData();
        form.append('page_no', String(pageNo));
        form.append('part', part);
        form.append('image', blob, `page-${pageNo}-${part}.jpg`);

        try {
            return await call('POST', address('pages', readId), form);
        } catch (error) {
            last = error;

            if (!worthRetrying(error)) {
                throw error;
            }

            if (attempt < UPLOAD_TRIES) {
                await wait(1000 * attempt * attempt);
            }
        }
    }

    // After the last try: the module's own refusal as it came, anything else as the page that could not be uploaded.
    throw uploadFailure(last, pageNo, words);
}

/**
 * Draws and uploads a PDF, page by page. Pages the read already has stored are not drawn again, so
 * an upload that was interrupted, even by closing the tab, goes on where it stopped when the same
 * file is chosen again.
 *
 * Before the second page is drawn, options.confirm is asked with how much is about to be uploaded
 * (the first page's size times the pages left); answering false stops with nothing more sent.
 *
 * @param {{ onProgress?: Function, confirm?: Function, includeBlank?: boolean }} options
 * @returns {Promise<{ pages: number, uploaded: number[], blank: number[], problems: object[], stopped: boolean }>}
 */
export async function uploadPdf(readId, source, options = {}) {
    const stored = new Set((await status(readId)).stored_pages ?? []);
    const summary = { pages: 0, uploaded: [], blank: [], problems: [], stopped: false };
    let asked = false;

    const outcome = await pdfToPages(source, async (page) => {
        const left = page.pagesTotal - stored.size - summary.uploaded.length - summary.blank.length - summary.problems.length;

        if (!asked && page.blob && options.confirm) {
            asked = true;

            const megabytes = Math.max(1, Math.ceil((page.blob.size * left) / (1024 * 1024)));

            if ((await options.confirm({ megabytes, pages: left, message: words('upload_size', { mb: megabytes }) })) === false) {
                return false;
            }
        }

        if (page.status === 'blank' && !options.includeBlank) {
            summary.blank.push(page.pageNo);
        } else if (page.status === 'ok' || page.status === 'blank') {
            await uploadPage(readId, page.pageNo, page.blob);
            summary.uploaded.push(page.pageNo);
        } else {
            summary.problems.push({ pageNo: page.pageNo, code: page.status, message: page.message });
        }

        options.onProgress?.({ pageNo: page.pageNo, pagesTotal: page.pagesTotal, status: page.status, message: page.message, done: stored.size + summary.uploaded.length });

        return true;
    }, { skip: [...stored] });

    return { ...summary, pages: outcome.pages, stopped: outcome.stopped };
}

/**
 * Uploads pages that are already drawn, in the order given. Pages the read already has stored are
 * skipped, so an interrupted upload can be run again as it is. options.confirm is asked first, with
 * how much is about to be uploaded.
 *
 * @param {{ pageNo: number, blob: Blob }[]} pages
 * @param {{ onProgress?: Function, confirm?: Function }} options
 */
export async function uploadPages(readId, pages, options = {}) {
    const stored = new Set((await status(readId)).stored_pages ?? []);
    const left = pages.filter((page) => !stored.has(page.pageNo));
    const uploaded = [];

    if (left.length > 0 && options.confirm) {
        const megabytes = Math.max(1, Math.ceil((left[0].blob.size * left.length) / (1024 * 1024)));

        if ((await options.confirm({ megabytes, pages: left.length, message: words('upload_size', { mb: megabytes }) })) === false) {
            return { uploaded, stopped: true };
        }
    }

    for (const page of left) {
        await uploadPage(readId, page.pageNo, page.blob);
        uploaded.push(page.pageNo);
        options.onProgress?.({ pageNo: page.pageNo, pagesTotal: pages.length, status: 'ok', done: stored.size + uploaded.length });
    }

    return { uploaded, stopped: false };
}

/**
 * Uploads photos as pages, in the order given, starting at page startAt. Photos whose page number
 * the read already has stored are skipped, so an interrupted upload can be run again as it is.
 *
 * @param {File[]} files
 * @param {{ startAt?: number, onProgress?: Function, confirm?: Function }} options
 */
export async function uploadPhotos(readId, files, options = {}) {
    const stored = new Set((await status(readId)).stored_pages ?? []);
    const first = options.startAt ?? 1;
    const uploaded = [];
    let asked = false;

    for (let index = 0; index < files.length; index++) {
        const pageNo = first + index;

        if (stored.has(pageNo)) {
            continue;
        }

        const page = await imageToPage(files[index]);

        if (!asked && options.confirm) {
            asked = true;

            const left = files.length - index;
            const megabytes = Math.max(1, Math.ceil((page.blob.size * left) / (1024 * 1024)));

            if ((await options.confirm({ megabytes, pages: left, message: words('upload_size', { mb: megabytes }) })) === false) {
                return { uploaded, stopped: true };
            }
        }

        await uploadPage(readId, pageNo, page.blob);
        uploaded.push(pageNo);
        options.onProgress?.({ pageNo, pagesTotal: first + files.length - 1, status: 'ok', done: stored.size + uploaded.length });
    }

    return { uploaded, stopped: false };
}

/**
 * For a page whose answer was cut off: takes the stored page picture, cuts its top part and its
 * bottom part (they overlap, so no row is lost at the cut), uploads both and asks for them to be
 * read. page is an entry of failed_pages from the status, with can_split true.
 */
export async function halves(readId, page, options = {}) {
    const response = await reach(address('image', readId, page.id), { credentials: 'same-origin', headers: { Accept: 'image/jpeg' } });

    if (!response.ok) {
        throw new ReaderError('image_failed', words('image_failed'), {}, response.status);
    }

    const bitmap = await bitmapOf(await response.blob());
    const width = bitmap.naturalWidth ?? bitmap.width;
    const whole = bitmap.naturalHeight ?? bitmap.height;
    const height = Math.round(whole * settings().halfShare);

    try {
        for (const [part, top] of [['top', 0], ['bottom', whole - height]]) {
            const canvas = canvasOf(width, height);

            try {
                canvas.getContext('2d').drawImage(bitmap, 0, top, width, height, 0, 0, width, height);
                await uploadPage(readId, page.page_no, await toJpeg(canvas, words('draw_failed', { n: page.page_no })), part);
            } finally {
                release(canvas);
            }
        }
    } finally {
        bitmap.close?.();
    }

    return call('POST', address('split', readId, page.id), options.cap ? { cap: options.cap } : {});
}

// ---- The steps of a read ----

export const status = (readId) => call('GET', address('status', readId));
export const quote = (readId, range = {}) => call('POST', address('quote', readId), range);
export const start = (readId, body = {}) => call('POST', address('start', readId), body);
export const cancel = (readId) => call('POST', address('cancel', readId), {});
export const resume = (readId, body = {}) => call('POST', address('resume', readId), body);
export const retry = (readId, pageId, body = {}) => call('POST', address('retry', readId, pageId), body);

/** Keeps the original PDF with the read, for the record. Only kinds that keep one accept it. */
export function uploadSource(readId, file) {
    const form = new FormData();
    form.append('_method', 'PUT');
    form.append('file', file, file.name ?? 'source.pdf');

    return call('POST', address('source', readId), form);
}

/**
 * Asks for the status every three seconds and hands each answer to onStatus, until the read has
 * ended. A paused read is asked about less often: nothing moves until someone continues it.
 * Returns a function that stops the watching.
 */
export function watch(readId, onStatus, onError = () => {}) {
    let stopped = false;
    let timer = null;

    const ask = async () => {
        if (stopped) {
            return;
        }

        let pause = WATCH_EVERY_MS;

        try {
            const now = await status(readId);
            onStatus(now);

            if (FINAL.includes(now.status)) {
                stopped = true;

                return;
            }

            pause = now.status === 'paused' ? WATCH_PAUSED_EVERY_MS : WATCH_EVERY_MS;
        } catch (error) {
            onError(error);

            if (error instanceof ReaderError && [401, 403, 404].includes(error.status)) {
                stopped = true;

                return;
            }
        }

        timer = setTimeout(ask, pause);
    };

    ask();

    return () => {
        stopped = true;
        clearTimeout(timer);
    };
}

// ---- Self-check ----

/**
 * Checks, in this browser, the two things page drawing depends on: that the size arithmetic gives
 * the published sizes, and that scanned PDFs of each kind are drawn as a page with something on it.
 *
 * @param {Record<string, string>} fixtures  Name => address of a one-page test PDF.
 * @returns {Promise<{ ok: boolean, sizes: object[], fixtures: object[] }>}
 */
export async function selfCheck(fixtures = {}) {
    const same = (a, b) => a[0] === b[0] && a[1] === b[1];

    const sizes = [
        ...VECTORS.map((vector) => {
            const got = targetSize(...vector.from);

            return { name: `${vector.from.join(' x ')}`, expected: vector.to, got, ok: same(got, vector.to) && imageTokens(...got) === vector.tokens };
        }),
        ...TOKEN_VECTORS.map((vector) => {
            const got = imageTokens(...vector.size);

            return { name: `tokens ${vector.size.join(' x ')}`, expected: vector.tokens, got, ok: got === vector.tokens };
        }),
    ];

    const drawn = [];

    for (const [name, url] of Object.entries(fixtures)) {
        try {
            let page = null;

            await pdfToPages(url, (result) => {
                page = result;

                return false;
            });

            drawn.push({ name, ok: page?.status === 'ok', status: page?.status ?? 'no_page', width: page?.width ?? 0, height: page?.height ?? 0, bytes: page?.blob?.size ?? 0 });
        } catch (error) {
            drawn.push({ name, ok: false, status: error?.code ?? 'error', message: String(error?.message ?? error) });
        }
    }

    return { ok: sizes.every((row) => row.ok) && drawn.length > 0 && drawn.every((row) => row.ok), sizes, fixtures: drawn };
}

const api = {
    ReaderError, targetSize, photoSize, imageTokens, luminanceRange, pdfToPages, pdfPageCount, imageToPage,
    uploadPage, uploadPages, uploadPdf, uploadPhotos, uploadSource, halves, status, quote, start, cancel, resume, retry, watch, selfCheck,
};

if (typeof window !== 'undefined') {
    window.ReaderCapture = api;
    document.dispatchEvent(new CustomEvent('reader-capture:ready'));
}

export default api;
