// Runs the challan reader's and the bill reader's page scripts in a pretend browser and reports what
// the form would send. Used by SlipReaderScriptTest.php: `node reader-harness.mjs <scenarios.json>`.
// Each scenario is a fresh page. The server's answers come from the scenario; nothing touches the network.
//
// A scenario: { name, script, factory ('slipChallanReader' | 'slipBillReader'), xdata (the x-data
// attribute as drawn), parent (the challan form's own Alpine data), inputs (field name => value, or
// { value, options } for a list), files (ids of file inputs holding a picture), responses (route
// name => answers in turn, the last one repeating), steps }.
//
// Steps: ['call', method, ...args], ['type', field, value], ['pick', key, value] (a list of the
// challan form, as the person picks it), ['flush'] (timers and promises run out), ['confirmNeeded']
// (presses "Matches the photo" on every field that asks for it), ['submit'], ['snap'].
import { readFileSync } from 'node:fs';

const scenarios = JSON.parse(readFileSync(process.argv[2], 'utf8'));
const settle = async () => {
    for (let round = 0; round < 25; round++) {
        await new Promise((resolve) => setImmediate(resolve));
    }
};
const escape = (text) => text.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
const CHALLAN_FIELDS = ['party_id', 'supplier_name', 'material_id', 'challan_no', 'challan_date', 'vehicle_no', 'challan_qty', 'unit_id'];

async function run(scenario) {
    const challan = scenario.factory === 'slipChallanReader';
    const timers = [];
    const watchers = {};
    const listeners = {};
    const calls = [];
    const events = [];
    const queues = Object.fromEntries(Object.entries(scenario.responses ?? {}).map(([route, answers]) => [route, [...answers]]));
    const parent = {
        party: '',
        material: '',
        unit: '',
        newSupplier: false,
        materials: [],
        ...(scenario.parent ?? {}),
        get chosen() {
            return this.materials.find((material) => String(material.id) === String(this.material));
        },
    };
    const inputs = Object.fromEntries(Object.entries(scenario.inputs ?? {}).map(([name, spec]) => [name, spec !== null && typeof spec === 'object'
        ? { name, tagName: 'SELECT', value: spec.value, options: spec.options.map((value) => ({ value })) }
        : { name, tagName: 'INPUT', value: spec }]));
    const form = {
        querySelector(selector) {
            const match = /^\[name="(.+)"\]$/.exec(selector);

            return match ? (inputs[match[1]] ?? null) : null;
        },
        addEventListener(type, callback) {
            (listeners[type] ??= []).push(callback);
        },
    };
    const root = { closest: () => form, scrollIntoView() {} };
    const document = {
        getElementById(id) {
            if ((scenario.files ?? []).includes(id)) {
                return { files: [{ name: 'photo.jpg', type: 'image/jpeg' }] };
            }

            return id === 'party_id' ? { focus() {} } : null;
        },
        querySelector: () => null,
    };
    const window = { ReaderCapture: { imageToPage: async () => ({ status: 'ok', blob: new Blob(['picture']) }) } };
    let config = null;

    const route = (url) => {
        if (url === config.urls.store) {
            return 'store';
        }

        for (const name of ['recheck', 'discard', 'show']) {
            if (new RegExp('^' + escape(config.urls[name]).replace('__SLIP__', '[^/]+') + '$').test(url)) {
                return name;
            }
        }

        return url;
    };
    const fetch = async (url, options) => {
        const name = route(url);
        calls.push(`${options.method} ${name}`);
        const queue = queues[name] ?? [];
        const answer = queue.length > 1 ? queue.shift() : queue[0];

        if (!answer) {
            throw new Error(`No answer for ${options.method} ${name}`);
        }

        return { ok: (answer.status ?? 200) < 400, json: async () => answer.body };
    };

    const factory = new Function('document', 'window', 'fetch', 'setTimeout', 'URL', `${scenario.script}\nreturn ${scenario.factory};`)(
        document, window, fetch, (callback) => timers.push(callback), { createObjectURL: () => 'blob:photo' },
    );
    const component = new Function(scenario.factory, `return ${scenario.xdata};`)((given) => {
        config = given;

        return factory(given);
    });

    // As Alpine does: the component's own data first, then the form's; a change the form watches calls back after a tick.
    const self = new Proxy(component, {
        get(target, key) {
            if (key === '$root') return root;
            if (key === '$watch') return (name, callback) => (watchers[name] ??= []).push(callback);
            if (key === '$nextTick') return (callback) => queueMicrotask(callback);
            if (key in target) return target[key];

            return parent[key];
        },
        set(target, key, value) {
            if (!(key in target) && key in parent) {
                const before = parent[key];
                parent[key] = value;

                if (before !== value) {
                    (watchers[key] ?? []).forEach((callback) => queueMicrotask(() => callback(value)));
                }

                return true;
            }

            target[key] = value;

            return true;
        },
    });

    // A script of an older shape is still run, so a test can show what it got wrong.
    const has = (method) => typeof component[method] === 'function';
    const reading = () => (challan
        ? (self.slipState === 'ready' && self.slipData ? { read_id: self.slipData.id, read_line: self.slipLine ?? '', read_confirmed: has('slipSent') ? self.slipSent() : [...self.slipConfirmed] } : {})
        : (self.state === 'ready' && self.data ? { read_id: self.data.id, read_confirmed: [...self.confirmed] } : {}));
    const payload = () => {
        const out = {};

        for (const [name, input] of Object.entries(inputs)) {
            if (!challan || name !== 'supplier_name' || parent.newSupplier) {
                out[name] = input.value;
            }
        }

        if (challan) {
            if (parent.newSupplier) {
                delete out.party_id;
            } else {
                out.party_id = parent.party;
            }

            out.material_id = parent.material;
            out.unit_id = parent.unit;
        }

        return { ...out, ...reading() };
    };
    const snapshot = () => ({
        calls: [...calls],
        payload: payload(),
        state: challan ? self.slipState : self.state,
        message: challan ? self.slipMessage : self.message,
        blocked: challan ? self.slipBlocked : self.blocked,
        parent: { party: parent.party, material: parent.material, unit: parent.unit, newSupplier: parent.newSupplier },
        needs: challan && self.slipData && has('slipNeeds') ? CHALLAN_FIELDS.filter((name) => self.slipNeeds(name)) : [],
        filled: Object.keys(challan ? self.slipFilled : self.filled),
        warnings: challan && self.slipData && has('slipWarningsOf') ? Object.fromEntries(CHALLAN_FIELDS.map((name) => [name, self.slipWarningsOf(name)])) : {},
        other: challan && self.slipData && has('slipOtherWarnings') ? self.slipOtherWarnings().map((warning) => warning.text) : [],
        texts: challan && self.slipData && has('slipWarningText') ? (self.slipData.warnings ?? []).map((warning) => self.slipWarningText(warning)) : [],
        unitNote: challan ? self.slipUnitNote : '',
    });

    challan ? self.slipInit() : self.init();
    await settle();

    for (const [step, ...args] of scenario.steps ?? []) {
        if (step === 'call') {
            await self[args[0]](...args.slice(1));
        } else if (step === 'type') {
            inputs[args[0]].value = args[1];
            (listeners.input ?? []).forEach((callback) => callback({ target: inputs[args[0]] }));
        } else if (step === 'pick') {
            self[args[0]] = args[1];

            // The material list empties the unit when it changes, as the form's own @change does.
            if (args[0] === 'material') {
                self.unit = '';
            }

            (listeners.change ?? []).forEach((callback) => callback({ target: {} }));
        } else if (step === 'flush') {
            for (let round = 0; round < 10 && timers.length; round++) {
                timers.splice(0).forEach((callback) => callback());
                await settle();
            }
        } else if (step === 'confirmNeeded') {
            if (challan && !has('slipNeeds')) {
                Object.keys(self.slipFilled).forEach((name) => self.slipConfirm(name));
            } else if (challan) {
                CHALLAN_FIELDS.filter((name) => self.slipNeeds(name) && !self.slipIsConfirmed(name)).forEach((name) => self.slipConfirm(name));
                (self.slipData?.warnings ?? []).filter((warning) => warning.tick).forEach((warning) => self.slipTick(warning.code, true));
            } else {
                Object.keys(self.filled).filter((name) => !self.changed(name)).forEach((name) => self.confirm(name));
                (self.data?.warnings ?? []).filter((warning) => warning.tick).forEach((warning) => self.confirm('warning:' + warning.code));
            }
        } else if (step === 'submit') {
            let prevented = false;
            (listeners.submit ?? []).forEach((callback) => callback({ preventDefault: () => (prevented = true) }));
            events.push({ step: 'submit', prevented, ...snapshot() });
        } else if (step === 'snap') {
            events.push({ step: 'snap', ...snapshot() });
        }

        await settle();
    }

    return { name: scenario.name, events };
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
