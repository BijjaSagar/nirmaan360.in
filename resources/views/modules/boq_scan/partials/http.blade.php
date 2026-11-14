{{--
    The calls the module's screens make to its own JSON routes, and how they show a refusal: the
    server's own sentence (a check of the form, or a refusal with a code), otherwise a sentence of
    this partial for the kind of failure, never the framework's English. Defines window.BoqScanHttp once.
--}}
@php
    $httpWords = [
        'network' => __('Your request did not reach the server. Check your connection and try again.'),
        'signed_out' => __('You have been signed out. Sign in again, then try once more.'),
        'not_allowed' => __('You are not allowed to do this.'),
        'not_found' => __('This document is no longer here. Reload the page.'),
        'too_many' => __('Too many tries in a short time. Wait a minute and try again.'),
        'server_failed' => __('Something went wrong on our side. Try again in a few minutes.'),
    ];
@endphp
<script>
    window.BoqScanHttp ??= ((words) => {
        class Refusal extends Error {
            constructor(message, status, body) {
                super(message);
                this.status = status;
                this.body = body ?? {};
                this.code = this.body.code ?? null;
            }
        }

        const wordFor = (status) => ({ 401: 'signed_out', 419: 'signed_out', 403: 'not_allowed', 404: 'not_found', 429: 'too_many' })[status] ?? 'server_failed';

        async function send(method, url, body = undefined) {
            const headers = {
                Accept: 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
                'X-Requested-With': 'XMLHttpRequest',
            };

            if (body !== undefined) {
                headers['Content-Type'] = 'application/json';
            }

            let response;

            try {
                response = await fetch(url, { method, credentials: 'same-origin', headers, body: body === undefined ? undefined : JSON.stringify(body) });
            } catch {
                throw new Refusal(words.network, 0, {});
            }

            const data = await response.json().catch(() => ({}));

            if (response.ok) {
                return data;
            }

            const firstError = data.errors ? Object.values(data.errors)[0]?.[0] : null;
            // The module's sentences are written for the person and already in their language: a form check, or a refusal with a code.
            const own = firstError ?? (data.code && typeof data.message === 'string' && data.message !== '' ? data.message : null);

            throw new Refusal(own ?? words[wordFor(response.status)], response.status, data);
        }

        /** Where a route of the module is, with the ids put in for __READ__, __ROW__ and __PAGE__. */
        const at = (template, values = {}) => Object.entries(values).reduce((url, [name, value]) => url.replace(`__${name.toUpperCase()}__`, encodeURIComponent(value)), template);

        /** A new id for a write, so sending it twice makes it once. */
        const uuid = () => window.crypto?.randomUUID?.()
            ?? '10000000-1000-4000-8000-100000000000'.replace(/[018]/g, (c) => (c ^ (crypto.getRandomValues(new Uint8Array(1))[0] & (15 >> (c / 4)))).toString(16));

        /** A figure as a decimal string with Indian grouping, without turning it into a float on the way. */
        function indian(value) {
            if (value === null || value === undefined || value === '') {
                return '';
            }

            const text = String(value);
            const negative = text.startsWith('-');
            const [whole, fraction] = text.replace(/^-/, '').split('.');
            const last = whole.slice(-3);
            const rest = whole.slice(0, -3).replace(/\B(?=(\d{2})+(?!\d))/g, ',');

            return (negative ? '-' : '') + (rest ? rest + ',' : '') + last + (fraction !== undefined ? '.' + fraction : '');
        }

        /** A rupee figure, with the sign before the rupee mark. */
        const rupees = (value) => (value === null || value === undefined || value === '') ? '—' : (String(value).startsWith('-') ? '-₹' + indian(String(value).slice(1)) : '₹' + indian(value));

        const say = (text, values = {}) => Object.entries(values).reduce((line, [name, value]) => line.replaceAll(':' + name, String(value)), text ?? '');

        return { send, at, uuid, indian, rupees, say, Refusal };
    })(@js($httpWords));
</script>
<script>
    // A button that throws a read away (or cancels one that has not started), after asking.
    window.boqScanDiscard ??= (url, question) => ({
        busy: false, error: null,

        async go() {
            if (!window.confirm(question)) {
                return;
            }

            this.busy = true;

            try {
                await window.BoqScanHttp.send('POST', url, {});
                window.location.reload();
            } catch (problem) {
                this.error = problem.message;
                this.busy = false;
            }
        },
    });
</script>
