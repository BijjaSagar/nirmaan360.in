{{--
    The browser's own copy of the last PDF a person uploaded for a project, kept in IndexedDB. It is
    used to draw the pages of a text PDF beside its preview, and to offer the same file to the scan
    screen after it was refused as a scan. The copy never leaves the browser: nothing here sends it
    anywhere. It is kept under one name per person and project, replaced by the next upload, and
    thrown away after $minutes minutes, as long as the preview of the upload lasts.

    Included by the module's screens and partials; the script defines window.BoqScanKeptPdf once.
--}}
<script>
    window.BoqScanKeptPdf ??= (() => {
        const DATABASE = 'nirmaan-boq-scan';
        const STORE = 'kept-pdf';
        const LIFETIME_MS = {{ (int) $minutes }} * 60 * 1000;

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

        return {
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

            /** The kept copy, or null when there is none, it is too old, or the browser keeps nothing. */
            async load(key) {
                try {
                    const kept = await run('readonly', (store) => store.get(key));

                    if (!kept || !(kept.file instanceof Blob) || Date.now() - kept.saved > LIFETIME_MS) {
                        if (kept) {
                            await this.forget(key);
                        }

                        return null;
                    }

                    return kept;
                } catch {
                    return null;
                }
            },

            async forget(key) {
                try {
                    await run('readwrite', (store) => store.delete(key));
                } catch {
                    // Nothing was kept.
                }
            },
        };
    })();
</script>
