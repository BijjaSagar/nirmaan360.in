{{--
    The browser's own copy of the last PDF a person uploaded for a project, kept in IndexedDB. It is
    used to draw the pages of a text PDF beside its preview, and to offer the same file to the scan
    screen after it was refused as a scan. The copy never leaves the browser: nothing here sends it
    anywhere. It is kept under one name per person and project, and replaced by the next upload.

    It is deleted:
    - when the import of the PDF, or of a sheet through its column check, is confirmed or discarded, by
      the BOQ in Excel page the person comes back to (partials.offer);
    - at sign-out, with everything this store holds (partials.sign-out, beside every sign-out button);
    - once it is older than $minutes minutes, as long as the preview of the upload lasts: it is never
      used after that, and every page that carries this script deletes every copy that old as the page
      opens, together with any copy left by another person who used this browser (only one person is
      signed in to a browser at a time). A copy nobody comes back for stays in this browser, unused,
      until the next such page opens or the next sign-out.

    Included by the module's screens and partials; the script defines window.BoqScanKeptPdf once.
--}}
@php
    $keptOwn = auth()->check() ? \App\Services\BoqScan\BoqScanScreen::keptPrefix(auth()->user()) : null;
@endphp
<script>
    window.BoqScanKeptPdf ??= (() => {
        const DATABASE = @js(\App\Services\BoqScan\BoqScanScreen::KEPT_DATABASE);
        const STORE = 'kept-pdf';
        const LIFETIME_MS = {{ (int) $minutes }} * 60 * 1000;
        // How the names of the signed-in person's copies begin. A copy under any other name was left by someone else.
        const OWN = @js($keptOwn);

        // IndexedDB can be missing or refused (a private window, a browser setting). Then nothing is kept and the screens say so.
        const open = () => new Promise((resolve, reject) => {
            if (!window.indexedDB) {
                reject(new Error('no-indexeddb'));

                return;
            }

            const request = indexedDB.open(DATABASE, 1);
            request.onupgradeneeded = () => request.result.createObjectStore(STORE);
            request.onsuccess = () => resolve(request.result);
            request.onerror = () => reject(request.error);
        });

        // Whether this browser holds the store at all, so a page that only clears out never makes an empty one.
        const exists = async () => {
            if (typeof window.indexedDB?.databases !== 'function') {
                return !!window.indexedDB;
            }

            try {
                return (await indexedDB.databases()).some((database) => database.name === DATABASE);
            } catch {
                return true;
            }
        };

        const run = async (mode, work) => {
            const database = await open();

            try {
                return await new Promise((resolve, reject) => {
                    const transaction = database.transaction(STORE, mode);
                    const request = work(transaction.objectStore(STORE));
                    transaction.oncomplete = () => resolve(request?.result);
                    transaction.onerror = () => reject(transaction.error);
                    transaction.onabort = () => reject(transaction.error);
                });
            } finally {
                database.close();
            }
        };

        const isPdf = (file) => !!file && (file.type === 'application/pdf' || /\.pdf$/i.test(file.name ?? ''));

        // Too old to use, or not a copy at all. One dated further ahead than its lifetime (a clock that was put back) is too old too.
        const stale = (kept) => !kept || !(kept.file instanceof Blob) || !Number.isFinite(kept.saved) || Math.abs(Date.now() - kept.saved) > LIFETIME_MS;

        const kept = {
            isPdf,

            /** Keeps this file as the person's latest PDF for the project. Never throws: keeping a copy is a help, not a step. */
            async save(key, file) {
                if (!isPdf(file)) {
                    return false;
                }

                try {
                    await run('readwrite', (store) => store.put({ name: file.name, size: file.size, saved: Date.now(), file }, key));

                    return true;
                } catch {
                    return false;
                }
            },

            /** The kept copy, or null when there is none, it is too old, or the browser keeps nothing. A copy too old is deleted. */
            async load(key) {
                try {
                    const found = await run('readonly', (store) => store.get(key));

                    if (stale(found)) {
                        if (found) {
                            await kept.forget(key);
                        }

                        return null;
                    }

                    return found;
                } catch {
                    return null;
                }
            },

            /** Deletes the copy under this name. Never throws, and never makes an empty store where there is none. */
            async forget(key) {
                try {
                    if (!(await exists())) {
                        return;
                    }

                    await run('readwrite', (store) => store.delete(key));
                } catch {
                    // Nothing was kept.
                }
            },

            /** Deletes every copy too old to use and every copy that is not the signed-in person's. Never throws. */
            async sweep() {
                try {
                    if (!(await exists())) {
                        return;
                    }

                    await run('readwrite', (store) => {
                        const request = store.openCursor();

                        request.onsuccess = () => {
                            const cursor = request.result;

                            if (!cursor) {
                                return;
                            }

                            if (stale(cursor.value) || (OWN !== null && !String(cursor.key).startsWith(OWN))) {
                                cursor.delete();
                            }

                            cursor.continue();
                        };

                        return request;
                    });
                } catch {
                    // Nothing was kept, or the browser keeps nothing.
                }
            },
        };

        // Every page that carries this script clears out as it opens, not only the preview a copy was kept for.
        kept.sweep();

        return kept;
    })();
</script>
