// Runs the site's analytics script in a pretend browser and reports what it did.
// Used by AnalyticsScriptTest.php: `node analytics-harness.mjs <script.js> <scenarios.json>`.
// Each scenario is a fresh page load. Nothing here touches the network.
import { readFileSync } from 'node:fs';
import vm from 'node:vm';

const [scriptPath, scenariosPath] = process.argv.slice(2);
const code = readFileSync(scriptPath, 'utf8');
const scenarios = JSON.parse(readFileSync(scenariosPath, 'utf8'));
const STORE = 'n360.analytics';
const DAY = 24 * 60 * 60 * 1000;

function run(scenario) {
    const listeners = {};
    const storage = new Map();
    const headScripts = [];
    const bodyChildren = [];
    const cookieWrites = [];
    let cookies = scenario.cookies || '';
    let storageWrites = 0;

    if (scenario.stored) {
        storage.set(STORE, JSON.stringify({ choice: scenario.stored, at: Date.now() - (scenario.storedDaysAgo || 0) * DAY }));
    }

    if (scenario.storedRaw !== undefined) {
        storage.set(STORE, scenario.storedRaw);
    }

    const body = {
        appendChild(node) {
            node.parentNode = body;
            bodyChildren.push(node);
        },
        removeChild(node) {
            bodyChildren.splice(bodyChildren.indexOf(node), 1);
            node.parentNode = null;
        },
    };

    const document = {
        readyState: scenario.readyState || 'complete',
        referrer: scenario.referrer || '',
        get cookie() {
            return cookies;
        },
        set cookie(value) {
            cookieWrites.push(value);
            const name = value.split('=')[0];

            if (/Max-Age=0/.test(value)) {
                cookies = cookies.split('; ').filter((part) => part.split('=')[0] !== name).join('; ');
            }
        },
        getElementById(id) {
            if (id === 'analytics-config') {
                return scenario.config ? { textContent: JSON.stringify(scenario.config) } : null;
            }

            if (id === 'analytics-consent' && scenario.config && scenario.config.mode === 'pageview') {
                return { content: { firstElementChild: { cloneNode: () => ({ banner: true, parentNode: null }) } } };
            }

            return null;
        },
        createElement(tag) {
            return { tag };
        },
        head: { appendChild: (node) => headScripts.push(node) },
        body,
        addEventListener(type, handler) {
            (listeners[type] = listeners[type] || []).push(handler);
        },
    };

    const window = {
        localStorage: {
            getItem(key) {
                if (scenario.storageBlocked) {
                    throw new Error('storage is blocked');
                }

                return storage.has(key) ? storage.get(key) : null;
            },
            setItem(key, value) {
                if (scenario.storageBlocked) {
                    throw new Error('storage is blocked');
                }

                storageWrites++;
                storage.set(key, value);
            },
        },
        // The address the visitor really typed. None of it may be sent.
        location: {
            hostname: 'www.nirmaan360.in',
            origin: 'https://www.nirmaan360.in',
            href: 'https://www.nirmaan360.in/contact?email=someone@example.com&name=Ramesh+Patil&phone=1234567890',
        },
    };

    vm.runInContext(code, vm.createContext({ window, document, URL, Date, JSON, console }));

    const fire = (type, target) => (listeners[type] || []).forEach((handler) => handler({ target }));
    const element = (attributes, dataset = {}) => ({
        dataset,
        closest: () => element(attributes, dataset),
        hasAttribute: (name) => name in attributes,
        getAttribute: (name) => (name in attributes ? attributes[name] : null),
    });

    for (const step of scenario.steps || []) {
        if (step === 'ready') {
            fire('DOMContentLoaded', null);
        } else if (step === 'accept' || step === 'decline') {
            fire('click', element({ 'data-consent': step }));
        } else if (step === 'open') {
            fire('click', element({ 'data-consent-open': '' }));
        } else if (step === 'click-elsewhere') {
            fire('click', { closest: () => null });
        } else if (step.input) {
            const dataset = { track: step.input };

            for (const [key, value] of Object.entries(step.details || {})) {
                dataset['track' + key.charAt(0).toUpperCase() + key.slice(1)] = value;
            }

            fire('input', element({ 'data-track': step.input }, dataset));
        } else if (step === 'type-elsewhere') {
            fire('input', { closest: () => null });
        }
    }

    const saved = storage.has(STORE) ? storage.get(STORE) : null;
    let choice = null;

    try {
        choice = saved ? JSON.parse(saved).choice : null;
    } catch (error) {
        choice = 'unreadable';
    }

    const sent = (window.dataLayer || []).map((call) => Array.from(call).map((part) => (part instanceof Date ? 'date' : part)));

    return {
        name: scenario.name,
        // Every command queued for Google, in order.
        sent,
        // What the page fetched from other sites.
        loaded: headScripts.map((node) => ({ tag: node.tag, src: node.src, async: node.async })),
        bannerShown: bodyChildren.length > 0,
        choice,
        storageWrites,
        cookiesLeft: cookies,
        cookieWrites,
        offSwitch: scenario.config ? window['ga-disable-' + scenario.config.id] : undefined,
        listeners: Object.keys(listeners).sort(),
    };
}

process.stdout.write(JSON.stringify(scenarios.map(run)));
