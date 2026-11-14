// Runs the scan module's page scripts that keep a copy of an uploaded PDF in the browser (kept-pdf,
// offer, sign-out) in a pretend browser with its own IndexedDB and clock, and reports what they did.
// Used by KeptPdfScriptTest.php: `node kept-pdf-harness.mjs <scenarios.json>`. Nothing touches the network.
//
// A scenario: { name, pages: { name: parts }, steps } and is one browser: its IndexedDB and its clock
// last across the pages it opens. A page's parts are what the server drew, in the order it drew them:
// { script } (an inline script, run where it stands) and { form: { id, action, attrs, file } } (a form,
// there from the point it stands; "file" says it has a file box named "file"). A script runs before
// the forms drawn after it exist, as in a browser that runs each script as it reaches it.
//
// Steps: ['open', page], ['choose', formId, { name, type, size }], ['submit', formId | '[data-sign-out]'],
// ['save', key, name, minutesAgo] (a copy kept earlier, written straight into the store), ['load', key]
// (what the page's own BoqScanKeptPdf.load answers), ['stored'], ['advance', seconds] (the clock moves on and
// the timers due run), ['hangDeletes'] (the browser never answers a deletion of a database, as while another tab
// holds it open).
import { readFileSync } from 'node:fs';
import vm from 'node:vm';

const scenarios = JSON.parse(readFileSync(process.argv[2], 'utf8'));

const settle = async () => {
    for (let round = 0; round < 50; round++) {
        await new Promise((resolve) => setImmediate(resolve));
    }
};

/** A small IndexedDB: databases of object stores, transactions that end when their last request has. */
function fakeIndexedDB(browser) {
    const later = (work) => setImmediate(work);
    const request = () => ({ result: undefined, error: null, onsuccess: null, onerror: null, onupgradeneeded: null, onblocked: null });

    const connect = (name, database) => ({
        objectStoreNames: { contains: (store) => database.stores.has(store) },
        createObjectStore(store) {
            database.stores.set(store, new Map());
        },
        close() {
            database.open--;
        },
        transaction(storeName, mode) {
            const data = database.stores.get(storeName);

            if (!data) {
                throw new Error('NotFoundError');
            }

            const transaction = { oncomplete: null, onerror: null, onabort: null, error: null };
            let pending = 0;
            let done = false;
            const finish = () => later(() => {
                if (pending === 0 && !done) {
                    done = true;
                    transaction.oncomplete?.();
                }
            });
            const writable = () => {
                if (mode !== 'readwrite') {
                    throw new Error('ReadOnlyError');
                }
            };
            const op = (work) => {
                const asked = request();
                pending++;
                later(() => {
                    asked.result = work();
                    asked.onsuccess?.({ target: asked });
                    pending--;
                    finish();
                });

                return asked;
            };

            transaction.objectStore = () => ({
                put(value, key) {
                    writable();

                    return op(() => {
                        data.set(key, value);

                        return key;
                    });
                },
                get: (key) => op(() => data.get(key)),
                delete(key) {
                    writable();

                    return op(() => {
                        data.delete(key);
                    });
                },
                openCursor() {
                    const asked = request();
                    const keys = [...data.keys()].sort();
                    let at = 0;
                    pending++;

                    const step = () => later(() => {
                        if (at >= keys.length) {
                            asked.result = null;
                            asked.onsuccess?.({ target: asked });
                            pending--;
                            finish();

                            return;
                        }

                        const key = keys[at];
                        let moved = false;
                        asked.result = {
                            key,
                            value: data.get(key),
                            delete() {
                                writable();
                                data.delete(key);
                            },
                            continue() {
                                moved = true;
                                at++;
                                step();
                            },
                        };
                        asked.onsuccess?.({ target: asked });

                        if (!moved) {
                            pending--;
                            finish();
                        }
                    });

                    step();

                    return asked;
                },
            });

            // A transaction nobody asked anything of still ends.
            finish();

            return transaction;
        },
    });

    return {
        async databases() {
            return [...browser.databases.keys()].map((name) => ({ name, version: browser.databases.get(name).version }));
        },
        open(name, version = 1) {
            const asked = request();
            later(() => {
                let database = browser.databases.get(name);

                if (!database) {
                    database = { version: 0, stores: new Map(), open: 0 };
                    browser.databases.set(name, database);
                }

                asked.result = connect(name, database);

                if (database.version < version) {
                    database.version = version;
                    asked.onupgradeneeded?.({ target: asked });
                }

                database.open++;
                asked.onsuccess?.({ target: asked });
            });

            return asked;
        },
        deleteDatabase(name) {
            const asked = request();
            browser.deletesAsked++;

            if (!browser.hangDeletes) {
                later(() => {
                    browser.databases.delete(name);
                    asked.onsuccess?.({ target: asked });
                });
            }

            return asked;
        },
    };
}

async function run(scenario) {
    const browser = { databases: new Map(), now: Date.UTC(2026, 10, 14, 6, 30), timers: [], hangDeletes: false, deletesAsked: 0 };
    const indexedDB = fakeIndexedDB(browser);
    const events = [];
    let page = null;

    class FakeDate extends Date {
        static now() {
            return browser.now;
        }
    }

    // Timers run only when the clock is moved on, so a wait is seen exactly as long as it lasts.
    const setTimeout = (callback, ms = 0) => {
        browser.timers.push({ at: browser.now + ms, callback });

        return browser.timers.length;
    };

    const fireTimers = () => {
        const due = browser.timers.filter((timer) => timer.at <= browser.now);
        browser.timers = browser.timers.filter((timer) => timer.at > browser.now);
        due.forEach((timer) => timer.callback());
    };

    const store = () => {
        const database = browser.databases.get('nirmaan-boq-scan');

        if (!database) {
            return null;
        }

        const data = database.stores.get('kept-pdf') ?? new Map();

        return Object.fromEntries([...data.entries()].sort(([a], [b]) => String(a).localeCompare(String(b))).map(([key, value]) => [key, { name: value.name, minutes: Math.round((browser.now - value.saved) / 60000) }]));
    };

    const openPage = async (name) => {
        const parts = scenario.pages[name];

        if (!parts) {
            throw new Error(`No page "${name}" in the scenario.`);
        }

        const listeners = [];
        const forms = [];
        const errors = [];

        class HTMLFormElement {
            constructor(spec) {
                this.id = spec.id ?? '';
                this.action = spec.action ?? '';
                this.attrs = spec.attrs ?? {};
                this.dataset = {};
                this.sent = 0;
                this.fileBox = spec.file ? { type: 'file', name: 'file', files: [] } : null;
            }

            hasAttribute(attribute) {
                return Object.hasOwn(this.attrs, attribute);
            }

            querySelector(selector) {
                return selector === 'input[type="file"][name="file"]' ? this.fileBox : null;
            }

            addEventListener(type, callback) {
                listeners.push({ type, callback, on: this });
            }

            submit() {
                this.sent++;
            }
        }

        const document = {
            addEventListener(type, callback) {
                listeners.push({ type, callback, on: document });
            },
            getElementById: (id) => forms.find((form) => form.id === id) ?? null,
            querySelector: () => null,
        };

        const context = { document, indexedDB, setTimeout, Date: FakeDate, Blob, File, HTMLFormElement, console };
        context.window = context;
        vm.createContext(context);

        for (const part of parts) {
            if (part.form) {
                forms.push(new HTMLFormElement(part.form));
            } else {
                try {
                    vm.runInContext(part.script, context);
                } catch (error) {
                    errors.push(String(error?.message ?? error));
                }
            }
        }

        page = { name, context, forms, listeners, errors };
        await settle();

        return { scripts: parts.filter((part) => part.script).length, errors };
    };

    const find = (which) => {
        const form = which === '[data-sign-out]'
            ? page.forms.find((candidate) => candidate.hasAttribute('data-sign-out'))
            : page.forms.find((candidate) => candidate.id === which);

        if (!form) {
            throw new Error(`No form ${which} on the page ${page.name}.`);
        }

        return form;
    };

    for (const step of scenario.steps) {
        const [action, ...args] = step;

        if (action === 'open') {
            events.push({ step: action, page: args[0], ...(await openPage(args[0])), stored: store() });
        } else if (action === 'choose') {
            const form = find(args[0]);
            form.fileBox.files = [new File(['%PDF-1.4 invented test pages'], args[1].name, { type: args[1].type ?? '' })];
            events.push({ step: action });
        } else if (action === 'submit') {
            const form = find(args[0]);
            const event = {
                type: 'submit',
                target: form,
                defaultPrevented: false,
                preventDefault() {
                    this.defaultPrevented = true;
                },
            };

            // The form's own listeners first, then the page's: the event bubbles up to the document.
            for (const listener of page.listeners.filter((entry) => entry.type === 'submit' && entry.on === form)) {
                listener.callback.call(form, event);
            }

            for (const listener of page.listeners.filter((entry) => entry.type === 'submit' && entry.on !== form)) {
                listener.callback.call(page.context.document, event);
            }

            await settle();
            // Not prevented, the browser sends the form itself.
            events.push({ step: action, prevented: event.defaultPrevented, sent: form.sent + (event.defaultPrevented ? 0 : 1), byScript: form.sent, stored: store() });
        } else if (action === 'save') {
            const [key, name, minutesAgo] = args;
            let database = browser.databases.get('nirmaan-boq-scan');

            if (!database) {
                database = { version: 1, stores: new Map([['kept-pdf', new Map()]]), open: 0 };
                browser.databases.set('nirmaan-boq-scan', database);
            }

            database.stores.get('kept-pdf').set(key, { name, size: 28, saved: browser.now - minutesAgo * 60000, file: new File(['%PDF-1.4'], name, { type: 'application/pdf' }) });
            events.push({ step: action, stored: store() });
        } else if (action === 'load') {
            const found = page.context.window.BoqScanKeptPdf.load(args[0]);
            await settle();
            const value = await found;
            events.push({ step: action, found: value ? value.name : null, stored: store() });
        } else if (action === 'stored') {
            events.push({ step: action, stored: store() });
        } else if (action === 'advance') {
            browser.now += args[0] * 1000;
            fireTimers();
            await settle();
            events.push({ step: action, sent: page ? page.forms.map((form) => [form.id || 'sign-out', form.sent]) : [], stored: store() });
        } else if (action === 'hangDeletes') {
            browser.hangDeletes = true;
            events.push({ step: action });
        } else {
            throw new Error(`Unknown step ${action}.`);
        }
    }

    return { name: scenario.name, events, deletesAsked: browser.deletesAsked, databases: [...browser.databases.keys()] };
}

const results = [];

for (const scenario of scenarios) {
    try {
        results.push(await run(scenario));
    } catch (error) {
        results.push({ name: scenario.name, error: String(error?.stack ?? error) });
    }
}

process.stdout.write(JSON.stringify(results));
process.exit(0);
