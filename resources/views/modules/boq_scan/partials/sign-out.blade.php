{{--
    Beside every sign-out button (included there as modules.boq_scan._sign_out; the button's form carries
    data-sign-out): before the sign-out leaves the page, the browser deletes the store that holds its
    copies of uploaded PDFs (partials.kept-pdf), every person's and every project's. It never holds the
    sign-out up for long: while another tab still has the store open, the browser deletes it once that
    tab lets go, and the sign-out goes ahead without waiting.

    The script defines window.BoqScanSignOut once, however many buttons a page has.
--}}
<script>
    window.BoqScanSignOut ??= (() => {
        const DATABASE = @js(\App\Services\BoqScan\BoqScanScreen::KEPT_DATABASE);

        const forgetAll = () => new Promise((resolve) => {
            try {
                const request = window.indexedDB.deleteDatabase(DATABASE);
                request.onsuccess = () => resolve(true);
                request.onerror = () => resolve(false);
            } catch {
                resolve(false);
            }
        });

        document.addEventListener('submit', async (event) => {
            const form = event.target;

            // Without IndexedDB nothing was ever kept, so the sign-out goes as it is.
            if (event.defaultPrevented || !(form instanceof HTMLFormElement) || !form.hasAttribute('data-sign-out') || !window.indexedDB) {
                return;
            }

            event.preventDefault();

            // Pressed again while the store is deleted: the first press signs out.
            if (form.dataset.signingOut === '1') {
                return;
            }

            form.dataset.signingOut = '1';
            await Promise.race([forgetAll(), new Promise((resolve) => setTimeout(resolve, 2000))]);
            delete form.dataset.signingOut;
            form.submit();
        });

        return true;
    })();
</script>
