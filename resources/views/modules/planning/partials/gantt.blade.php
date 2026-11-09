{{--
    The chart is drawn into inline SVG with createElementNS. Alpine's x-for cannot run inside SVG,
    and textContent keeps activity names from ever being read as markup.
--}}
@php
    $labels = [
        'zoom' => ['day' => __('Day'), 'week' => __('Week'), 'month' => __('Month')],
        'states' => [
            'overdue' => __('Overdue'),
            'late_start' => __('Late start'),
            'complete' => __('Complete'),
            'in_progress' => __('In progress'),
            'not_started' => __('Not started'),
            'unlinked' => __('Not linked'),
        ],
        'daysLate' => __(':days days late'),
        'noProgress' => __('No BOQ link'),
    ];
@endphp
<section class="card" x-data="planGantt(@js($gantt), @js($labels), @js(app()->getLocale()))">
    <div class="flex flex-wrap items-center gap-x-3 gap-y-2 border-b border-rule px-3 py-2">
        <div class="inline-flex overflow-hidden rounded-[3px] border border-rule" role="group" aria-label="{{ __('Zoom') }}">
            <template x-for="level in ['day', 'week', 'month']" :key="level">
                <button type="button" class="px-3 py-1.5 text-sm font-semibold" :class="zoom === level ? 'bg-ink text-paper' : 'bg-sheet text-ink-2'" :aria-pressed="zoom === level" @click="zoom = level" x-text="labels.zoom[level]"></button>
            </template>
        </div>
        <button type="button" class="btn btn-ghost" @click="goToday()">{{ __('Today') }}</button>
        <label class="inline-flex items-center gap-1.5 text-sm text-ink-2"><input type="checkbox" x-model="onlyDelayed" class="size-4">{{ __('Delayed only') }}</label>
        <label class="inline-flex items-center gap-1.5 text-sm text-ink-2"><input type="checkbox" x-model="showLinks" class="size-4">{{ __('Links') }}</label>
    </div>

    <div x-ref="scroller" class="relative max-h-[70vh] overflow-auto overscroll-x-contain">
        <div class="grid w-max [--label:118px] sm:[--label:280px]" :style="`grid-template-columns: var(--label) ${width}px`">
            <div x-ref="corner" class="eyebrow sticky top-0 left-0 z-30 flex h-10 items-end border-r border-b border-rule bg-paper-2 px-2 pb-1 text-[10px] text-ink-3">{{ __('Activity') }}</div>
            <svg x-ref="head" class="sticky top-0 z-20 block bg-paper-2" :width="width" height="40" aria-hidden="true"></svg>

            <div class="sticky left-0 z-10 border-r border-rule bg-sheet">
                <template x-for="row in visible" :key="row.key">
                    <div class="flex h-11 cursor-pointer items-center gap-1 border-b border-rule pr-1.5" :class="row.key === selectedKey ? 'bg-haldi/20' : ''" :style="{ paddingLeft: 4 + Math.min(row.depth, 4) * 8 + 'px' }" @click="pick(row)">
                        <button type="button" x-show="row.kind === 'wbs'" class="flex size-6 shrink-0 items-center justify-center text-ink-3" @click.stop="toggle(row)" :aria-expanded="!collapsed[row.id]" :aria-label="row.name">
                            <span class="text-[10px]" x-text="collapsed[row.id] ? '▶' : '▼'"></span>
                        </button>
                        <span class="line-clamp-2 min-w-0 text-[12px] leading-[1.2]" :class="row.kind === 'wbs' ? 'font-semibold text-ink' : 'text-ink-2'" x-text="row.name"></span>
                    </div>
                </template>
            </div>
            <svg x-ref="body" class="block" :width="width" :height="visible.length * rowHeight" role="img" aria-label="{{ __('Gantt chart of planned activities and their progress') }}" @click="clickChart($event)"></svg>
        </div>
    </div>
    <div x-effect="draw()" aria-hidden="true"></div>

    <div class="border-t border-rule px-3 py-2 text-xs text-ink-2">
        <span class="mr-3 inline-flex items-center gap-1.5"><span class="inline-block h-2.5 w-5 rounded-[1px] bg-neel/30"></span>{{ __('Planned') }}</span>
        <span class="mr-3 inline-flex items-center gap-1.5"><span class="inline-block h-2.5 w-5 rounded-[1px] bg-moss"></span>{{ __('Done') }}</span>
        <span class="mr-3 inline-flex items-center gap-1.5"><span class="inline-block h-1 w-5 bg-ink-3"></span>{{ __('Baseline') }}</span>
        <span class="inline-flex items-center gap-1.5"><span class="inline-block h-2.5 w-5 rounded-[1px] border border-laterite"></span>{{ __('Delayed') }}</span>
    </div>

    <div x-show="selected" x-cloak class="border-t border-rule bg-paper/50 px-3 py-3 text-sm">
        <template x-if="selected">
            <div class="flex items-start justify-between gap-3">
                <div class="min-w-0">
                    <p class="font-semibold" x-text="selected.name"></p>
                    <p class="num mt-0.5 text-xs text-ink-3"><span x-text="selected.code"></span><span x-show="selected.startLabel"> · <span x-text="selected.startLabel"></span> → <span x-text="selected.endLabel"></span></span></p>
                    <p class="mt-1 text-xs text-ink-2">
                        <span class="num font-medium" x-text="selected.pct === null ? labels.noProgress : selected.pct + '%'"></span>
                        <span x-show="selected.state"> · <span x-text="stateLabel(selected)"></span></span>
                        <span x-show="selected.delay"> · <span x-text="labels.daysLate.replace(':days', selected.delayDays)"></span></span>
                    </p>
                </div>
                <a x-show="selected.url" :href="selected.url" class="btn btn-ghost shrink-0">{{ __('Open') }}</a>
            </div>
        </template>
    </div>
</section>
@verbatim
<script>
    window.planGantt = function (chart, labels, locale) {
        const SVG = 'http://www.w3.org/2000/svg';
        const ROW = 44;
        const HEAD = 40;
        const BAR = 14;
        const DAY_MS = 86400000;
        const PIXELS = { day: 26, week: 9, month: 3 };
        const rows = chart.rows;

        const add = (parent, name, attrs, text) => {
            const node = document.createElementNS(SVG, name);
            Object.entries(attrs).forEach(([key, value]) => node.setAttribute(key, value));
            if (text !== undefined) node.textContent = text;
            parent.appendChild(node);
            return node;
        };

        const dateAt = (offset) => new Date((chart.origin + offset) * DAY_MS);
        const format = (date, options) => date.toLocaleDateString(locale, { timeZone: 'UTC', ...options });

        return {
            labels,
            zoom: chart.zoom,
            onlyDelayed: false,
            showLinks: window.innerWidth >= 640,
            collapsed: {},
            selectedKey: null,
            rowHeight: ROW,

            get pixels() { return PIXELS[this.zoom]; },
            get width() { return Math.max(chart.days * this.pixels, 240); },
            get selected() { return rows.find((row) => row.key === this.selectedKey) || null; },
            get visible() {
                if (this.onlyDelayed) return rows.filter((row) => row.delay);
                return rows.filter((row) => !row.ancestors.some((id) => this.collapsed[id]));
            },

            init() {
                this.$nextTick(() => this.goToday());
                this.$watch('zoom', () => this.$nextTick(() => this.goToday()));
            },
            pick(row) { this.selectedKey = this.selectedKey === row.key ? null : row.key; },
            toggle(row) { this.collapsed[row.id] = !this.collapsed[row.id]; },
            clickChart(event) {
                const hit = event.target.closest('[data-key]');
                const row = hit && rows.find((r) => r.key === hit.dataset.key);
                if (row) this.pick(row);
            },
            stateLabel(row) { return labels.states[row.delay || row.state] || ''; },
            goToday() {
                const box = this.$refs.scroller;
                if (chart.today === null) { box.scrollLeft = 0; return; }
                const free = box.clientWidth - this.$refs.corner.offsetWidth;
                box.scrollLeft = Math.max(0, (chart.today + 0.5) * this.pixels - free / 2);
            },

            draw() {
                this.drawHead();
                this.drawBody();
            },

            ticks() {
                const found = [];
                for (let i = 0; i < chart.days; i++) {
                    const date = dateAt(i);
                    const isMonthStart = date.getUTCDate() === 1;
                    const isMonday = date.getUTCDay() === 1;
                    if (this.zoom === 'day' || (this.zoom === 'week' && isMonday) || (this.zoom === 'month' && isMonthStart)) {
                        found.push({ offset: i, date });
                    }
                }
                return found;
            },

            drawHead() {
                const svg = this.$refs.head;
                svg.replaceChildren();
                const px = this.pixels;
                const bandOf = (date) => this.zoom === 'month' ? date.getUTCFullYear() : date.getUTCFullYear() + '-' + date.getUTCMonth();
                let band = null;

                for (let i = 0; i < chart.days; i++) {
                    const date = dateAt(i);
                    if (bandOf(date) === band) continue;
                    band = bandOf(date);
                    const text = this.zoom === 'month' ? String(date.getUTCFullYear()) : format(date, { month: 'short', year: 'numeric' });
                    add(svg, 'text', { x: i * px + 4, y: 15, class: 'fill-ink-2 text-[11px] font-semibold' }, text);
                    if (i > 0) add(svg, 'line', { x1: i * px, x2: i * px, y1: 0, y2: HEAD, class: 'stroke-ink-3/40' });
                }

                this.ticks().forEach(({ offset, date }) => {
                    const text = this.zoom === 'month' ? format(date, { month: 'short' }) : String(date.getUTCDate());
                    const x = this.zoom === 'day' ? (offset + 0.5) * px : offset * px + 3;
                    add(svg, 'text', { x, y: 33, 'text-anchor': this.zoom === 'day' ? 'middle' : 'start', class: 'num fill-ink-3 text-[10px]' }, text);
                });

                add(svg, 'line', { x1: 0, x2: this.width, y1: HEAD - 0.5, y2: HEAD - 0.5, class: 'stroke-rule' });
            },

            drawBody() {
                const svg = this.$refs.body;
                svg.replaceChildren();
                const list = this.visible;
                const px = this.pixels;
                const height = list.length * ROW;
                const position = new Map(list.map((row, i) => [row.key, i]));

                this.ticks().forEach(({ offset }) => {
                    add(svg, 'line', { x1: offset * px, x2: offset * px, y1: 0, y2: height, class: 'stroke-rule/50' });
                });

                list.forEach((row, i) => {
                    const y = i * ROW;
                    if (row.key === this.selectedKey) add(svg, 'rect', { x: 0, y, width: this.width, height: ROW, class: 'fill-haldi/20' });
                    add(svg, 'line', { x1: 0, x2: this.width, y1: y + ROW - 0.5, y2: y + ROW - 0.5, class: 'stroke-rule' });
                });

                if (chart.today !== null) {
                    const x = (chart.today + 0.5) * px;
                    add(svg, 'line', { x1: x, x2: x, y1: 0, y2: height, class: 'stroke-laterite', 'stroke-width': 1.5, 'stroke-dasharray': '4 3' });
                }

                if (this.showLinks) this.drawLinks(svg, list, position);
                list.forEach((row, i) => this.drawRow(svg, row, i * ROW));
            },

            drawRow(svg, row, y) {
                const px = this.pixels;

                if (row.start !== null) {
                    const x = row.start * px;
                    const width = Math.max((row.end - row.start + 1) * px, 3);
                    const top = y + (ROW - BAR) / 2 - 2;

                    if (row.kind === 'wbs') {
                        add(svg, 'rect', { x, y: y + ROW / 2 - 3, width, height: 6, rx: 1, class: 'fill-ink-2' });
                    } else if (row.milestone) {
                        const cx = x + px / 2;
                        const cy = top + BAR / 2;
                        add(svg, 'path', { d: `M${cx} ${cy - 8}L${cx + 8} ${cy}L${cx} ${cy + 8}L${cx - 8} ${cy}Z`, class: row.delay ? 'fill-laterite' : (row.state === 'complete' ? 'fill-moss' : 'fill-neel') });
                    } else {
                        add(svg, 'rect', { x, y: top, width, height: BAR, rx: 2, class: 'fill-neel/30' });
                        if (row.pct !== null && Number(row.pct) > 0) {
                            add(svg, 'rect', { x, y: top, width: Math.max(width * Number(row.pct) / 100, 2), height: BAR, rx: 2, class: 'fill-moss' });
                        }
                        if (row.delay) {
                            add(svg, 'rect', { x: x + 0.5, y: top + 0.5, width: width - 1, height: BAR - 1, rx: 2, fill: 'none', class: 'stroke-laterite', 'stroke-width': 1.5 });
                        }
                    }

                    if (row.kind === 'activity' && row.bstart !== null && row.bend !== null) {
                        add(svg, 'rect', { x: row.bstart * px, y: top + BAR + 3, width: Math.max((row.bend - row.bstart + 1) * px, 2), height: 3, class: 'fill-ink-3' });
                    }
                }

                add(svg, 'rect', { x: 0, y, width: this.width, height: ROW, fill: 'transparent', 'data-key': row.key, class: 'cursor-pointer' });
            },

            drawLinks(svg, list, position) {
                const px = this.pixels;
                const middle = (i) => i * ROW + ROW / 2 - 2;

                list.forEach((row, i) => {
                    if (row.kind !== 'activity') return;

                    row.deps.forEach((dep) => {
                        if (!position.has(dep.from)) return;
                        const from = list[position.get(dep.from)];
                        const fromY = middle(position.get(dep.from));
                        const toY = middle(i);
                        const out = dep.type[0] === 'S' ? -1 : 1;
                        const into = dep.type[1] === 'S' ? -1 : 1;
                        const startX = (out < 0 ? from.start : from.end + 1) * px;
                        const endX = (into < 0 ? row.start : row.end + 1) * px;
                        const bendOut = startX + out * 6;
                        const bendIn = endX + into * 8;
                        const midY = (fromY + toY) / 2;

                        add(svg, 'path', { d: `M${startX} ${fromY}H${bendOut}V${midY}H${bendIn}V${toY}H${endX}`, fill: 'none', class: 'stroke-ink-3', 'stroke-width': 1 });
                        add(svg, 'path', { d: `M${endX} ${toY}l${into * 5} -3v6Z`, class: 'fill-ink-3' });
                    });
                });
            },
        };
    };
</script>
@endverbatim
