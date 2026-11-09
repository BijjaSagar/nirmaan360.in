// Sheet viewer, takeoff canvas and issue pins. Every saved quantity comes back from the server;
// the numbers worked out here are only a preview while drawing.
import Konva from 'konva';
import * as pdfjs from 'pdfjs-dist/legacy/build/pdf.mjs';
import PdfWorker from 'pdfjs-dist/legacy/build/pdf.worker.min.mjs?worker';

Konva.hitOnDragEnabled = true;

const MAX_BITMAP_SIDE = 6000;
const DUPLICATE_PX = 3;
const MINIMUM_POINTS = { length: 2, area: 3, volume: 3, count: 1, calibrate: 2 };
const PIN_COLOURS = { open: '#b4451f', in_progress: '#1b4a66', resolved: '#3b6a4c', closed: '#6b7773' };

const round3 = (value) => Math.round(value * 1000) / 1000;
const clamp = (value, low, high) => Math.min(Math.max(value, low), high);

function newUuid() {
    if (window.crypto?.randomUUID) {
        return window.crypto.randomUUID();
    }

    const bytes = window.crypto.getRandomValues(new Uint8Array(16));
    bytes[6] = (bytes[6] & 0x0f) | 0x40;
    bytes[8] = (bytes[8] & 0x3f) | 0x80;
    const hex = Array.from(bytes, (byte) => byte.toString(16).padStart(2, '0')).join('');

    return `${hex.slice(0, 8)}-${hex.slice(8, 12)}-${hex.slice(12, 16)}-${hex.slice(16, 20)}-${hex.slice(20)}`;
}

async function api(method, url, body, fallback) {
    const response = await fetch(url, {
        method,
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
        },
        body: body === undefined ? undefined : JSON.stringify(body),
    });
    const data = await response.json().catch(() => ({}));

    if (!response.ok) {
        const firstError = data.errors ? Object.values(data.errors)[0]?.[0] : null;
        throw new Error(firstError ?? data.message ?? fallback);
    }

    return data;
}

let workerStarted = false;

function openPdf(url) {
    if (!workerStarted) {
        pdfjs.GlobalWorkerOptions.workerPort = new PdfWorker();
        workerStarted = true;
    }

    return pdfjs.getDocument({ url, disableRange: true, disableStream: true }).promise;
}

function loadImage(url) {
    return new Promise((resolve, reject) => {
        const image = new Image();
        image.onload = () => resolve(image);
        image.onerror = () => reject(new Error('image'));
        image.src = url;
    });
}

/** First visit after an upload: read the page sizes from the file and record them as sheets. */
async function registerSheets(element) {
    const { file, kind, url, failed, tooMany } = element.dataset;

    try {
        let pages;

        if (kind === 'pdf') {
            const document = await openPdf(file);

            if (document.numPages > 500) {
                element.textContent = tooMany;

                return;
            }

            pages = [];

            for (let number = 1; number <= document.numPages; number++) {
                const viewport = (await document.getPage(number)).getViewport({ scale: 1 });
                pages.push({ page: number, width: round3(viewport.width), height: round3(viewport.height) });
            }
        } else {
            const image = await loadImage(file);
            pages = [{ page: 1, width: image.naturalWidth, height: image.naturalHeight }];
        }

        await api('POST', url, { pages }, failed);
        window.location.reload();
    } catch (error) {
        element.textContent = failed;
        console.error(error);
    }
}

function mountViewer() {
    const data = JSON.parse(document.getElementById('drawing-viewer-data').textContent);
    const t = data.strings;
    const byId = (id) => document.getElementById(id);
    const on = (id, event, handler) => byId(id)?.addEventListener(event, handler);
    const urlFor = (template, id) => template.replace('__ID__', id);

    const page = { width: Number(data.sheet.width), height: Number(data.sheet.height) };
    const state = {
        tool: 'pan',
        draft: null,
        selected: null,
        selectedPin: null,
        pinPoint: null,
        layers: [...data.layers],
        measurements: [...data.measurements],
        pins: [...data.pins],
        hidden: new Set(),
        metresPerUnit: data.sheet.metresPerUnit === null ? null : Number(data.sheet.metresPerUnit),
        calibLength: data.sheet.calibLength,
        calibPoints: data.sheet.calibPoints,
        fitted: true,
    };

    const holder = byId('dv-stage');
    const stage = new Konva.Stage({ container: holder, width: holder.clientWidth, height: holder.clientHeight });
    const pageLayer = new Konva.Layer({ listening: false });
    const shapeLayer = new Konva.Layer();
    const draftLayer = new Konva.Layer({ listening: false });
    const pageImage = new Konva.Image({ x: 0, y: 0, width: page.width, height: page.height });
    pageLayer.add(new Konva.Rect({ x: 0, y: 0, width: page.width, height: page.height, fill: '#ffffff' }), pageImage);
    stage.add(pageLayer, shapeLayer, draftLayer);

    // Toolbar and messages

    function flash(text, tone = 'ok') {
        const box = byId('dv-message');
        box.textContent = text;
        box.classList.remove('hidden', 'border-moss', 'bg-moss/10', 'text-moss', 'border-laterite', 'bg-laterite/10', 'text-laterite-dark');
        box.classList.add(...(tone === 'ok' ? ['border-moss', 'bg-moss/10', 'text-moss'] : ['border-laterite', 'bg-laterite/10', 'text-laterite-dark']));
    }

    function hint(text) {
        byId('dv-hint').textContent = text;
    }

    function show(id, visible) {
        byId(id)?.classList.toggle('hidden', !visible);
    }

    function setActions() {
        const drawing = state.draft && !state.draft.done;
        document.querySelector('[data-action="undo"]').classList.toggle('hidden', !drawing);
        document.querySelector('[data-action="finish"]').classList.toggle('hidden', !drawing || state.draft.type === 'calibrate');
        document.querySelector('[data-action="cancel"]').classList.toggle('hidden', !state.draft && !state.pinPoint);
    }

    function setTool(tool) {
        if (['length', 'area', 'volume'].includes(tool) && state.metresPerUnit === null) {
            flash(t.setScaleFirst, 'error');

            return;
        }

        clearDraft();
        clearPinPoint();
        closeSelection();
        state.tool = tool;
        stage.draggable(tool === 'pan');
        holder.style.cursor = tool === 'pan' ? 'grab' : 'crosshair';

        document.querySelectorAll('[data-tool]').forEach((button) => {
            const active = button.dataset.tool === tool;
            button.classList.toggle('btn-ink', active);
            button.classList.toggle('btn-ghost', !active);
            button.setAttribute('aria-pressed', String(active));
        });

        if (tool === 'pan') {
            hint('');
        } else if (tool === 'pin') {
            hint(t.pinHint);
        } else if (tool === 'calibrate') {
            hint(t.pickTwo);
        } else {
            hint(t.clickHint);
        }

        if (!['pan', 'pin'].includes(tool)) {
            state.draft = { type: tool, points: [], done: false, uuid: newUuid() };
        }

        drawDraft();
        setActions();
    }

    // Zoom and pan

    let fitScale = 1;
    let rerenderTimer = null;

    function afterScaleChange() {
        const inverse = 1 / stage.scaleX();
        stage.find('.marker').forEach((node) => node.scale({ x: inverse, y: inverse }));
        stage.batchDraw();
        clearTimeout(rerenderTimer);
        rerenderTimer = setTimeout(renderPdf, 180);
    }

    function fit() {
        const width = stage.width();
        const height = stage.height();
        fitScale = Math.min((width - 24) / page.width, (height - 24) / page.height);
        stage.scale({ x: fitScale, y: fitScale });
        stage.position({ x: (width - page.width * fitScale) / 2, y: (height - page.height * fitScale) / 2 });
        state.fitted = true;
        afterScaleChange();
    }

    function zoomAt(anchor, factor) {
        const before = stage.scaleX();
        const after = clamp(before * factor, fitScale * 0.5, fitScale * 40);
        const world = { x: (anchor.x - stage.x()) / before, y: (anchor.y - stage.y()) / before };
        stage.scale({ x: after, y: after });
        stage.position({ x: anchor.x - world.x * after, y: anchor.y - world.y * after });
        state.fitted = false;
        afterScaleChange();
    }

    const centre = () => ({ x: stage.width() / 2, y: stage.height() / 2 });

    stage.on('wheel', (event) => {
        event.evt.preventDefault();
        zoomAt(stage.getPointerPosition(), event.evt.deltaY > 0 ? 1 / 1.12 : 1.12);
    });

    stage.on('dragend', () => {
        state.fitted = false;
    });

    let lastDistance = 0;
    let lastCentre = null;

    stage.on('touchmove', (event) => {
        const [first, second] = event.evt.touches;

        if (!second) {
            return;
        }

        event.evt.preventDefault();
        stage.stopDrag();
        const box = holder.getBoundingClientRect();
        const a = { x: first.clientX - box.left, y: first.clientY - box.top };
        const b = { x: second.clientX - box.left, y: second.clientY - box.top };
        const middle = { x: (a.x + b.x) / 2, y: (a.y + b.y) / 2 };
        const distance = Math.hypot(a.x - b.x, a.y - b.y);

        if (lastCentre) {
            zoomAt(middle, distance / lastDistance);
            stage.position({ x: stage.x() + middle.x - lastCentre.x, y: stage.y() + middle.y - lastCentre.y });
            stage.batchDraw();
        }

        lastDistance = distance;
        lastCentre = middle;
    });

    stage.on('touchend', () => {
        lastDistance = 0;
        lastCentre = null;
    });

    new ResizeObserver(() => {
        stage.size({ width: holder.clientWidth, height: holder.clientHeight });

        if (state.fitted) {
            fit();
        }
    }).observe(holder);

    // The page image

    let pdfPage = null;
    let renderTask = null;
    let renderedAt = 0;

    async function renderPdf() {
        if (!pdfPage) {
            return;
        }

        const wanted = stage.scaleX() * (window.devicePixelRatio || 1);
        const pixelsPerUnit = Math.min(wanted, MAX_BITMAP_SIDE / Math.max(page.width, page.height));

        if (renderedAt && Math.abs(pixelsPerUnit - renderedAt) / renderedAt < 0.15) {
            return;
        }

        renderTask?.cancel();
        const viewport = pdfPage.getViewport({ scale: pixelsPerUnit });
        const canvas = document.createElement('canvas');
        canvas.width = Math.ceil(viewport.width);
        canvas.height = Math.ceil(viewport.height);
        renderTask = pdfPage.render({ canvas, viewport });

        try {
            await renderTask.promise;
        } catch (error) {
            if (error?.name !== 'RenderingCancelledException') {
                console.error(error);
            }

            return;
        }

        renderedAt = pixelsPerUnit;
        pageImage.image(canvas);
        pageLayer.batchDraw();
    }

    async function loadFile() {
        try {
            if (data.file.kind === 'pdf') {
                const document = await openPdf(data.file.url);
                pdfPage = await document.getPage(data.sheet.page);
                await renderPdf();
            } else {
                pageImage.image(await loadImage(data.file.url));
                pageLayer.batchDraw();
            }
        } catch (error) {
            console.error(error);
            hint(t.loadFailed);
            flash(t.loadFailed, 'error');
        }
    }

    // Saved shapes

    const layerOf = (id) => state.layers.find((layer) => layer.id === id);
    const flat = (points) => points.flatMap(([x, y]) => [Number(x), Number(y)]);

    function drawMeasurement(item) {
        const colour = layerOf(item.layer_id)?.colour ?? '#16201f';
        const selected = state.selected === item.id;
        const select = () => {
            if (state.tool === 'pan') {
                selectMeasurement(item.id);
            }
        };

        if (item.type === 'count') {
            item.points.forEach(([x, y]) => {
                const dot = new Konva.Circle({ x: Number(x), y: Number(y), radius: selected ? 8 : 6, fill: colour, stroke: '#ffffff', strokeWidth: 1.5, name: 'marker' });
                dot.on('click tap', select);
                shapeLayer.add(dot);
            });

            return;
        }

        const closed = item.type !== 'length';
        const line = new Konva.Line({
            points: flat(item.points),
            closed,
            stroke: colour,
            strokeWidth: selected ? 4 : 2,
            strokeScaleEnabled: false,
            hitStrokeWidth: 14,
            fill: closed ? `${colour}33` : undefined,
        });
        line.on('click tap', select);
        shapeLayer.add(line);
    }

    function drawPin(pin, index) {
        const colour = PIN_COLOURS[pin.status] ?? '#6b7773';
        const group = new Konva.Group({ x: Number(pin.x), y: Number(pin.y), name: 'marker' });
        group.add(new Konva.Circle({ radius: state.selectedPin === pin.id ? 13 : 11, fill: colour, stroke: '#ffffff', strokeWidth: 2 }));
        group.add(new Konva.Text({ text: String(index + 1), fontSize: 11, fontStyle: 'bold', fill: '#ffffff', width: 22, height: 22, offsetX: 11, offsetY: 11, align: 'center', verticalAlign: 'middle', listening: false }));
        group.on('click tap', () => {
            if (state.tool === 'pan') {
                selectPin(pin.id);
            }
        });
        shapeLayer.add(group);
    }

    function drawShapes() {
        shapeLayer.destroyChildren();

        if (state.calibPoints) {
            shapeLayer.add(new Konva.Line({ points: flat(state.calibPoints), stroke: '#6b7773', strokeWidth: 1, strokeScaleEnabled: false, dash: [8, 4], listening: false }));
        }

        state.measurements.filter((item) => !state.hidden.has(item.layer_id)).forEach(drawMeasurement);
        state.pins.forEach(drawPin);
        afterScaleChange();
    }

    // Measuring

    function previewQuantity(type, points) {
        if (type === 'count') {
            return `${points.length} nos`;
        }

        const scale = state.metresPerUnit ?? 0;

        if (type === 'length') {
            let total = 0;

            for (let index = 1; index < points.length; index++) {
                total += Math.hypot(points[index].x - points[index - 1].x, points[index].y - points[index - 1].y);
            }

            return `${(total * scale).toFixed(3)} rmt`;
        }

        let sum = 0;

        points.forEach((point, index) => {
            const next = points[(index + 1) % points.length];
            sum += point.x * next.y - next.x * point.y;
        });

        const area = (Math.abs(sum) / 2) * scale * scale;

        if (type === 'area') {
            return `${area.toFixed(3)} sqm`;
        }

        return `${(area * (Number(byId('dv-depth')?.value) || 0)).toFixed(3)} cum`;
    }

    function draftColour() {
        return layerOf(Number(byId('dv-layer')?.value))?.colour ?? '#e2a31a';
    }

    function drawDraft(pointer = null) {
        draftLayer.destroyChildren();
        const draft = state.draft;

        if (draft && draft.points.length) {
            const colour = draft.type === 'calibrate' ? '#e2a31a' : draftColour();
            const flatPoints = draft.points.flatMap((point) => [point.x, point.y]);

            if (draft.type !== 'count') {
                const closed = draft.done && ['area', 'volume'].includes(draft.type);
                draftLayer.add(new Konva.Line({ points: flatPoints, closed, stroke: colour, strokeWidth: 2, strokeScaleEnabled: false, fill: closed ? `${colour}33` : undefined }));

                if (pointer && !draft.done) {
                    const last = draft.points[draft.points.length - 1];
                    draftLayer.add(new Konva.Line({ points: [last.x, last.y, pointer.x, pointer.y], stroke: colour, strokeWidth: 1.5, strokeScaleEnabled: false, dash: [4, 4] }));
                }
            }

            draft.points.forEach((point) => {
                draftLayer.add(new Konva.Circle({ x: point.x, y: point.y, radius: 4, fill: colour, stroke: '#ffffff', strokeWidth: 1, name: 'marker' }));
            });
        }

        if (state.pinPoint) {
            draftLayer.add(new Konva.Circle({ x: state.pinPoint.x, y: state.pinPoint.y, radius: 11, fill: PIN_COLOURS.open, stroke: '#ffffff', strokeWidth: 2, name: 'marker' }));
        }

        const inverse = 1 / stage.scaleX();
        draftLayer.find('.marker').forEach((node) => node.scale({ x: inverse, y: inverse }));
        draftLayer.batchDraw();
    }

    function pagePoint() {
        const position = stage.getRelativePointerPosition();

        return position ? { x: round3(clamp(position.x, 0, page.width)), y: round3(clamp(position.y, 0, page.height)) } : null;
    }

    function addPoint(point) {
        const draft = state.draft;
        const last = draft.points[draft.points.length - 1];

        if (last && Math.hypot(point.x - last.x, point.y - last.y) * stage.scaleX() < DUPLICATE_PX) {
            return;
        }

        draft.points.push(point);
        drawDraft();
        setActions();

        if (draft.type === 'calibrate' && draft.points.length === 2) {
            finishDraft();
        }
    }

    function finishDraft() {
        const draft = state.draft;

        if (!draft || draft.done || draft.points.length < MINIMUM_POINTS[draft.type]) {
            return;
        }

        draft.done = true;
        setActions();
        drawDraft();

        if (draft.type === 'calibrate') {
            show('dv-calib-panel', true);
            byId('dv-calib-length').focus();
        } else {
            openDraftPanel();
        }
    }

    function clearDraft() {
        state.draft = null;
        show('dv-calib-panel', false);
        drawDraft();
        setActions();
    }

    /** After saving or cancelling, stay on the same tool so the next one can be drawn straight away. */
    function restartTool() {
        const tool = state.tool;
        show('dv-draft-panel', false);
        show('dv-calib-panel', false);
        state.draft = ['pan', 'pin', 'calibrate'].includes(tool) ? null : { type: tool, points: [], done: false, uuid: newUuid() };

        if (tool === 'calibrate') {
            setTool('pan');

            return;
        }

        drawDraft();
        setActions();
    }

    let lastClick = null;
    let clickRepeated = false;

    stage.on('click tap', (event) => {
        if (event.evt.button > 0 || state.tool === 'pan') {
            return;
        }

        const point = pagePoint();

        if (!point) {
            return;
        }

        clickRepeated = lastClick !== null && Math.hypot(point.x - lastClick.x, point.y - lastClick.y) * stage.scaleX() < DUPLICATE_PX;
        lastClick = point;

        if (state.tool === 'pin') {
            openPinPanel(point);
        } else if (state.draft && !state.draft.done) {
            addPoint(point);
        }
    });

    // Konva reports any two quick clicks as a double-click, even at two different corners,
    // and fires it after the click. So the click handler records whether it repeated the last one.
    stage.on('dblclick dbltap', () => {
        if (clickRepeated) {
            finishDraft();
        }
    });

    stage.on('mousemove', () => {
        if (state.draft && !state.draft.done && state.draft.points.length) {
            drawDraft(pagePoint());
        }
    });

    // Side panels

    function option(value, label, selected = false) {
        const element = document.createElement('option');
        element.value = value;
        element.textContent = label;
        element.selected = selected;

        return element;
    }

    function fillLayerSelect(selected) {
        const select = byId('dv-layer');

        if (!select) {
            return;
        }

        select.replaceChildren(...state.layers.map((layer) => option(layer.id, layer.name, layer.id === selected)));
    }

    function fillBoqSelect(type, selected) {
        const select = byId('dv-boq');

        if (!select) {
            return;
        }

        const matching = data.boqItems.filter((item) => item.dimension === type);
        select.replaceChildren(
            option('', t.noLink, selected === null),
            ...matching.map((item) => option(item.id, `${item.code} · ${item.description} (${item.unit})`, item.id === selected)),
        );
    }

    function updatePreview() {
        if (state.draft?.done) {
            byId('dv-draft-qty').textContent = `${t.preview}: ${previewQuantity(state.draft.type, state.draft.points)}`;
        }
    }

    function openDraftPanel() {
        const draft = state.draft;
        byId('dv-draft-title').textContent = t[draft.type];
        byId('dv-draft-note').textContent = t.previewNote;
        fillLayerSelect(state.lastLayer ?? state.layers[0]?.id);
        fillBoqSelect(draft.type, null);
        byId('dv-label').value = '';
        show('dv-depth-field', draft.type === 'volume');
        byId('dv-depth').required = draft.type === 'volume';
        byId('dv-depth').value = '';
        show('dv-draft-delete', false);
        show('dv-draft-panel', true);
        updatePreview();
        drawDraft();
    }

    function selectMeasurement(id) {
        state.selected = id;
        state.selectedPin = null;
        show('dv-pin-detail', false);
        drawShapes();
        renderMeasurementList();

        if (!data.can.takeoff) {
            return;
        }

        const item = state.measurements.find((entry) => entry.id === id);
        byId('dv-draft-title').textContent = t[item.type];
        byId('dv-draft-qty').textContent = `${item.qty} ${item.unit}`;
        byId('dv-draft-note').textContent = '';
        fillLayerSelect(item.layer_id);
        fillBoqSelect(item.type, item.boq_item_id);
        byId('dv-label').value = item.label ?? '';
        show('dv-depth-field', item.type === 'volume');
        byId('dv-depth').required = item.type === 'volume';
        byId('dv-depth').value = item.depth ?? '';
        show('dv-draft-delete', true);
        show('dv-draft-panel', true);
    }

    function closeSelection() {
        const hadSelection = state.selected !== null || state.selectedPin !== null;
        state.selected = null;
        state.selectedPin = null;
        show('dv-draft-panel', false);
        show('dv-pin-detail', false);

        if (hadSelection) {
            drawShapes();
            renderMeasurementList();
        }
    }

    function upsert(list, item) {
        const index = list.findIndex((entry) => entry.id === item.id);

        if (index === -1) {
            list.push(item);
        } else {
            list[index] = item;
        }
    }

    on('dv-draft-panel', 'submit', async (event) => {
        event.preventDefault();
        const button = byId('dv-draft-save');
        const layerId = Number(byId('dv-layer').value);

        if (!layerId) {
            flash(t.needsLayer, 'error');

            return;
        }

        const boq = byId('dv-boq').value;
        const label = byId('dv-label').value.trim() || null;
        const depth = byId('dv-depth').value;
        button.disabled = true;

        try {
            let saved;

            if (state.draft?.done) {
                const draft = state.draft;
                saved = await api('POST', data.urls.measurements, {
                    type: draft.type,
                    takeoff_layer_id: layerId,
                    boq_item_id: boq ? Number(boq) : null,
                    label,
                    depth: draft.type === 'volume' ? depth : null,
                    points: draft.points.map((point) => [point.x, point.y]),
                    client_uuid: draft.uuid,
                }, t.failed);
                state.lastLayer = layerId;
                upsert(state.measurements, saved.measurement);
                restartTool();
            } else {
                const item = state.measurements.find((entry) => entry.id === state.selected);
                saved = await api('PATCH', urlFor(data.urls.measurement, item.id), {
                    takeoff_layer_id: layerId,
                    boq_item_id: boq ? Number(boq) : null,
                    label,
                    ...(item.type === 'volume' ? { depth } : {}),
                }, t.failed);
                upsert(state.measurements, saved.measurement);
                byId('dv-draft-qty').textContent = `${saved.measurement.qty} ${saved.measurement.unit}`;
            }

            drawShapes();
            renderMeasurementList();
            flash(`${t.saved} ${saved.measurement.qty} ${saved.measurement.unit}`);
        } catch (error) {
            flash(error.message, 'error');
        } finally {
            button.disabled = false;
        }
    });

    on('dv-depth', 'input', updatePreview);
    on('dv-layer', 'change', () => drawDraft());

    on('dv-draft-close', 'click', () => {
        if (state.draft?.done) {
            restartTool();
        } else {
            closeSelection();
        }
    });

    on('dv-draft-delete', 'click', async () => {
        if (!state.selected || !window.confirm(t.confirmRemove)) {
            return;
        }

        try {
            await api('DELETE', urlFor(data.urls.measurement, state.selected), undefined, t.failed);
            state.measurements = state.measurements.filter((item) => item.id !== state.selected);
            closeSelection();
            drawShapes();
            renderMeasurementList();
            flash(t.removed);
        } catch (error) {
            flash(error.message, 'error');
        }
    });

    on('dv-calib-panel', 'submit', async (event) => {
        event.preventDefault();

        try {
            const result = await api('POST', data.urls.calibrate, {
                points: state.draft.points.map((point) => [point.x, point.y]),
                length_m: byId('dv-calib-length').value,
            }, t.failed);
            state.metresPerUnit = Number(result.metresPerUnit);
            state.calibLength = result.calibLength;
            state.calibPoints = result.calibPoints;
            state.measurements = result.measurements;
            byId('dv-calib-length').value = '';
            renderScale();
            setTool('pan');
            drawShapes();
            renderMeasurementList();
            flash(t.scaleSaved);
        } catch (error) {
            flash(error.message, 'error');
        }
    });

    // Pins

    function openPinPanel(point) {
        state.pinPoint = point;
        drawDraft();
        setActions();
        show('dv-pin-panel', true);
        byId('dv-pin-title').focus();
    }

    function clearPinPoint() {
        state.pinPoint = null;
        show('dv-pin-panel', false);
        drawDraft();
        setActions();
    }

    function selectPin(id) {
        const pin = state.pins.find((entry) => entry.id === id);
        state.selectedPin = id;
        state.selected = null;
        show('dv-draft-panel', false);
        byId('dv-pin-detail-title').textContent = pin.title;
        const priority = data.priorities.find((entry) => entry.value === pin.priority)?.label ?? pin.priority;
        byId('dv-pin-detail-meta').textContent = [t[pin.status] ?? pin.status, priority, pin.assignee, pin.due_date].filter(Boolean).join(' · ');
        byId('dv-pin-detail-link').href = data.urls.issues;
        show('dv-pin-detail', true);
        drawShapes();
    }

    if (byId('dv-pin-priority')) {
        byId('dv-pin-priority').replaceChildren(...data.priorities.map((priority) => option(priority.value, priority.label, priority.value === 'medium')));
        byId('dv-pin-assignee').replaceChildren(option('', '—', true), ...data.users.map((user) => option(user.id, user.name)));
        byId('dv-pin-issue').replaceChildren(...data.openIssues.map((issue) => option(issue.id, issue.title)));

        document.querySelectorAll('input[name="dv-pin-mode"]').forEach((radio) => radio.addEventListener('change', () => {
            const existing = radio.value === 'existing' && radio.checked;
            show('dv-pin-new', !existing);
            show('dv-pin-existing', existing);
        }));
    }

    on('dv-pin-close', 'click', clearPinPoint);

    on('dv-pin-panel', 'submit', async (event) => {
        event.preventDefault();
        const existing = document.querySelector('input[name="dv-pin-mode"]:checked').value === 'existing';
        const button = byId('dv-pin-save');
        button.disabled = true;

        try {
            const body = { x: state.pinPoint.x, y: state.pinPoint.y, client_uuid: newUuid() };

            if (existing) {
                body.issue_id = Number(byId('dv-pin-issue').value);
            } else {
                body.title = byId('dv-pin-title').value.trim();
                body.priority = byId('dv-pin-priority').value;
                body.assigned_to = byId('dv-pin-assignee').value ? Number(byId('dv-pin-assignee').value) : null;
                body.due_date = byId('dv-pin-due').value || null;
            }

            const saved = await api('POST', data.urls.pins, body, t.failed);
            upsert(state.pins, saved.pin);
            byId('dv-pin-title').value = '';
            byId('dv-pin-due').value = '';
            clearPinPoint();
            drawShapes();
            renderPinList();
            flash(t.saved);
        } catch (error) {
            flash(error.message, 'error');
        } finally {
            button.disabled = false;
        }
    });

    on('dv-pin-detail-remove', 'click', async () => {
        if (!state.selectedPin || !window.confirm(t.confirmUnpin)) {
            return;
        }

        try {
            await api('DELETE', urlFor(data.urls.pin, state.selectedPin), undefined, t.failed);
            state.pins = state.pins.filter((pin) => pin.id !== state.selectedPin);
            closeSelection();
            drawShapes();
            renderPinList();
            flash(t.removed);
        } catch (error) {
            flash(error.message, 'error');
        }
    });

    // Lists

    function row(children, onClick) {
        const element = document.createElement(onClick ? 'button' : 'div');

        if (onClick) {
            element.type = 'button';
            element.addEventListener('click', onClick);
        }

        element.className = 'flex w-full items-center gap-2 rounded-[3px] px-1 py-0.5 text-left hover:bg-paper';
        element.append(...children);

        return element;
    }

    function dot(colour) {
        const element = document.createElement('span');
        element.className = 'inline-block size-2.5 shrink-0 rounded-full';
        element.style.background = colour;

        return element;
    }

    function text(content, className = '') {
        const element = document.createElement('span');
        element.textContent = content;
        element.className = className;

        return element;
    }

    function empty(content) {
        const element = document.createElement('p');
        element.className = 'text-xs text-ink-3';
        element.textContent = content;

        return element;
    }

    function renderScale() {
        byId('dv-scale-status').textContent = state.metresPerUnit === null ? t.notCalibrated : t.calibrated.replace(':length', state.calibLength);
    }

    function renderLayerList() {
        byId('dv-layers').replaceChildren(...state.layers.map((layer) => {
            const box = document.createElement('input');
            box.type = 'checkbox';
            box.checked = !state.hidden.has(layer.id);
            box.setAttribute('aria-label', layer.name);
            box.addEventListener('change', () => {
                if (box.checked) {
                    state.hidden.delete(layer.id);
                } else {
                    state.hidden.add(layer.id);
                }

                drawShapes();
            });

            return row([box, dot(layer.colour), text(layer.name, 'truncate')]);
        }));
    }

    function renderMeasurementList() {
        if (!state.measurements.length) {
            byId('dv-measurements').replaceChildren(empty(t.noMeasurements));

            return;
        }

        byId('dv-measurements').replaceChildren(...state.measurements.map((item) => {
            const layer = layerOf(item.layer_id);
            const element = row([
                dot(layer?.colour ?? '#16201f'),
                text(item.label || t[item.type], 'min-w-0 flex-1 truncate'),
                text(`${item.qty} ${item.unit}`, 'num text-xs whitespace-nowrap'),
            ], () => {
                setToolIfNeeded();
                selectMeasurement(item.id);
            });

            if (state.selected === item.id) {
                element.classList.add('bg-paper-2');
            }

            return element;
        }));
    }

    function renderPinList() {
        if (!state.pins.length) {
            byId('dv-pins').replaceChildren(empty(t.noPins));

            return;
        }

        byId('dv-pins').replaceChildren(...state.pins.map((pin, index) => row([
            dot(PIN_COLOURS[pin.status] ?? '#6b7773'),
            text(`${index + 1}. ${pin.title}`, 'min-w-0 flex-1 truncate'),
            text(t[pin.status] ?? pin.status, 'text-xs text-ink-3 whitespace-nowrap'),
        ], () => {
            setToolIfNeeded();
            selectPin(pin.id);
        })));
    }

    function setToolIfNeeded() {
        if (state.tool !== 'pan') {
            setTool('pan');
        }
    }

    on('dv-layer-form', 'submit', async (event) => {
        event.preventDefault();

        try {
            const result = await api('POST', data.urls.layers, { name: byId('dv-layer-name').value.trim(), colour: byId('dv-layer-colour').value }, t.failed);
            upsert(state.layers, result.layer);
            state.layers.sort((a, b) => a.name.localeCompare(b.name));
            state.lastLayer = result.layer.id;
            byId('dv-layer-name').value = '';
            byId('dv-layer-colour').value = data.layerColours[state.layers.length % data.layerColours.length];
            renderLayerList();
            fillLayerSelect(result.layer.id);
            drawDraft();
            flash(t.saved);
        } catch (error) {
            flash(error.message, 'error');
        }
    });

    // Wiring

    document.querySelectorAll('[data-tool]').forEach((button) => button.addEventListener('click', () => setTool(button.dataset.tool)));

    function cancelCurrent() {
        if (state.pinPoint) {
            clearPinPoint();
        } else if (state.tool === 'calibrate') {
            setTool('pan');
        } else if (state.draft) {
            restartTool();
        }
    }

    function undoPoint() {
        if (state.draft && !state.draft.done) {
            state.draft.points.pop();
            drawDraft();
            setActions();
        }
    }

    document.querySelector('[data-action="zoom-in"]').addEventListener('click', () => zoomAt(centre(), 1.25));
    document.querySelector('[data-action="zoom-out"]').addEventListener('click', () => zoomAt(centre(), 0.8));
    document.querySelector('[data-action="fit"]').addEventListener('click', fit);
    document.querySelector('[data-action="finish"]').addEventListener('click', finishDraft);
    document.querySelector('[data-action="undo"]').addEventListener('click', undoPoint);
    document.querySelector('[data-action="cancel"]').addEventListener('click', cancelCurrent);

    document.addEventListener('keydown', (event) => {
        if (['INPUT', 'SELECT', 'TEXTAREA'].includes(event.target.tagName)) {
            return;
        }

        if (event.key === 'Escape') {
            cancelCurrent();
        } else if (event.key === 'Enter') {
            finishDraft();
        } else if (event.key === 'Backspace') {
            event.preventDefault();
            undoPoint();
        }
    });

    if (byId('dv-layer-colour')) {
        byId('dv-layer-colour').value = data.layerColours[state.layers.length % data.layerColours.length];
    }

    renderScale();
    renderLayerList();
    renderMeasurementList();
    renderPinList();
    fit();
    setTool('pan');
    drawShapes();
    loadFile();
}

const register = document.getElementById('drawing-sheet-register');

if (register) {
    registerSheets(register);
}

if (document.getElementById('drawing-viewer')) {
    mountViewer();
}
