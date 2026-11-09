@extends('layouts.public')
@section('title', __('Free construction calculators'))
@section('description', __('Free calculators for concrete, brickwork, plaster, steel, tiles, paint and cut and fill, in metric units for Indian sites.'))
@section('content')
<script>
    function calculators() {
        // One 50 kg bag of cement at a bulk density of 1440 kg/m³.
        const BAG = 50 / 1440;
        const n = (v) => parseFloat(v) || 0;
        const india = (v, d) => new Intl.NumberFormat('en-IN', { minimumFractionDigits: d, maximumFractionDigits: d }).format(Number.isFinite(v) ? v : 0);

        return {
            tab: 'concrete',
            tabs: { concrete: @js(__('Concrete')), brick: @js(__('Brickwork')), plaster: @js(__('Plaster')), steel: @js(__('Steel')), tiles: @js(__('Tiles')), paint: @js(__('Paint')), earth: @js(__('Cut and fill')) },
            f: india,
            concrete: { l: 10, b: 5, d: 0.15, mix: '1:1.5:3' },
            brick: { l: 10, h: 3, t: 0.23, mix: 6, waste: 5 },
            plaster: { a: 100, t: 12, mix: 6, waste: 15 },
            steel: { d: 12, l: 12, n: 10 },
            tiles: { a: 50, l: 600, w: 600, waste: 7, box: 4 },
            paint: { a: 200, coats: 2, cover: 10 },
            earth: { swell: 0, rows: [{ ch: 0, cut: 12, fill: 0 }, { ch: 20, cut: 8, fill: 2 }, { ch: 40, cut: 3, fill: 6 }] },

            get concreteOut() {
                const c = this.concrete;
                const wet = n(c.l) * n(c.b) * n(c.d);
                const dry = wet * 1.54;
                const [ce, sa, ag] = c.mix.split(':').map(Number);
                const parts = ce + sa + ag;
                return { wet, bags: (dry * ce / parts) / BAG, sand: dry * sa / parts, agg: dry * ag / parts };
            },
            mortar(wet, parts) {
                const dry = wet * 1.33;
                return { bags: (dry / (1 + parts)) / BAG, sand: dry * parts / (1 + parts) };
            },
            get brickOut() {
                const c = this.brick;
                const volume = n(c.l) * n(c.h) * n(c.t);
                const bricks = volume / (0.2 * 0.1 * 0.1);
                const wet = Math.max(volume - bricks * (0.19 * 0.09 * 0.09), 0);
                return { volume, bricks: Math.ceil(bricks * (1 + n(c.waste) / 100)), ...this.mortar(wet, n(c.mix)) };
            },
            get plasterOut() {
                const c = this.plaster;
                const wet = n(c.a) * (n(c.t) / 1000) * (1 + n(c.waste) / 100);
                return { wet, ...this.mortar(wet, n(c.mix)) };
            },
            get steelOut() {
                const c = this.steel;
                const perMetre = (n(c.d) * n(c.d)) / 162;
                const kg = perMetre * n(c.l) * n(c.n);
                return { perMetre, kg, tonnes: kg / 1000 };
            },
            get tilesOut() {
                const c = this.tiles;
                const area = (n(c.l) / 1000) * (n(c.w) / 1000);
                const pieces = area > 0 ? Math.ceil((n(c.a) / area) * (1 + n(c.waste) / 100)) : 0;
                return { pieces, boxes: n(c.box) > 0 ? Math.ceil(pieces / n(c.box)) : 0 };
            },
            get paintOut() {
                const c = this.paint;
                return { litres: n(c.cover) > 0 ? (n(c.a) * n(c.coats)) / n(c.cover) : 0 };
            },
            get earthOut() {
                let cut = 0, fill = 0;
                const rows = this.earth.rows;
                for (let i = 1; i < rows.length; i++) {
                    const gap = n(rows[i].ch) - n(rows[i - 1].ch);
                    cut += gap * (n(rows[i].cut) + n(rows[i - 1].cut)) / 2;
                    fill += gap * (n(rows[i].fill) + n(rows[i - 1].fill)) / 2;
                }
                return { cut, fill, loose: cut * (1 + n(this.earth.swell) / 100), net: cut - fill };
            },
        };
    }
</script>

<section class="graph-paper bg-neel-deep text-paper">
    <div class="mx-auto max-w-7xl px-5 pt-10 pb-14">
        <p class="eyebrow text-haldi">{{ __('Free tools') }}</p>
        <h1 class="mt-3 text-[clamp(3rem,7vw,5.5rem)] leading-[0.92]">{{ __('Site calculators') }}</h1>
        <p class="mt-3 max-w-2xl text-lg text-paper/75">{{ __('Quick material estimates in metric units. No sign-up needed.') }}</p>
    </div>
</section>

<section class="mx-auto max-w-7xl px-5 py-10" x-data="calculators()">
    <div class="flex flex-wrap gap-1.5" role="tablist">
        <template x-for="(label, key) in tabs" :key="key">
            <button type="button" role="tab" class="btn" :aria-selected="tab === key" :class="tab === key ? 'btn-ink' : 'btn-ghost'" @click="tab = key" x-text="label"></button>
        </template>
    </div>

    <div class="mt-5 grid gap-5 lg:grid-cols-2">
        <div class="card p-5">
            <div x-show="tab === 'concrete'" class="grid gap-3 sm:grid-cols-3">
                <div><label class="label">{{ __('Length (m)') }}</label><input type="number" step="any" min="0" x-model="concrete.l" class="input num"></div>
                <div><label class="label">{{ __('Breadth (m)') }}</label><input type="number" step="any" min="0" x-model="concrete.b" class="input num"></div>
                <div><label class="label">{{ __('Depth (m)') }}</label><input type="number" step="any" min="0" x-model="concrete.d" class="input num"></div>
                <div class="sm:col-span-3"><label class="label">{{ __('Mix') }}</label>
                    <select x-model="concrete.mix" class="input">
                        <option value="1:3:6">M10 (1:3:6)</option>
                        <option value="1:2:4">M15 (1:2:4)</option>
                        <option value="1:1.5:3">M20 (1:1.5:3)</option>
                    </select>
                    <p class="hint">{{ __('Nominal mixes only. IS 456 calls for a design mix above M20.') }}</p>
                </div>
            </div>

            <div x-show="tab === 'brick'" x-cloak class="grid gap-3 sm:grid-cols-3">
                <div><label class="label">{{ __('Length (m)') }}</label><input type="number" step="any" min="0" x-model="brick.l" class="input num"></div>
                <div><label class="label">{{ __('Height (m)') }}</label><input type="number" step="any" min="0" x-model="brick.h" class="input num"></div>
                <div><label class="label">{{ __('Wall thickness') }}</label><select x-model="brick.t" class="input"><option value="0.115">115 mm</option><option value="0.23">230 mm</option></select></div>
                <div><label class="label">{{ __('Mortar') }}</label><select x-model="brick.mix" class="input"><option value="4">1:4</option><option value="6">1:6</option></select></div>
                <div><label class="label">{{ __('Wastage %') }}</label><input type="number" step="any" min="0" x-model="brick.waste" class="input num"></div>
                <p class="hint sm:col-span-3">{{ __('Modular brick 190 × 90 × 90 mm with 10 mm joints.') }}</p>
            </div>

            <div x-show="tab === 'plaster'" x-cloak class="grid gap-3 sm:grid-cols-2">
                <div><label class="label">{{ __('Area (m²)') }}</label><input type="number" step="any" min="0" x-model="plaster.a" class="input num"></div>
                <div><label class="label">{{ __('Thickness') }}</label><select x-model="plaster.t" class="input"><option value="12">12 mm</option><option value="15">15 mm</option><option value="20">20 mm</option></select></div>
                <div><label class="label">{{ __('Mortar') }}</label><select x-model="plaster.mix" class="input"><option value="3">1:3</option><option value="4">1:4</option><option value="6">1:6</option></select></div>
                <div><label class="label">{{ __('Wastage %') }}</label><input type="number" step="any" min="0" x-model="plaster.waste" class="input num"></div>
            </div>

            <div x-show="tab === 'steel'" x-cloak class="grid gap-3 sm:grid-cols-3">
                <div><label class="label">{{ __('Bar diameter') }}</label><select x-model="steel.d" class="input">@foreach ([8, 10, 12, 16, 20, 25, 32] as $bar)<option value="{{ $bar }}">{{ $bar }} mm</option>@endforeach</select></div>
                <div><label class="label">{{ __('Length per bar (m)') }}</label><input type="number" step="any" min="0" x-model="steel.l" class="input num"></div>
                <div><label class="label">{{ __('Number of bars') }}</label><input type="number" step="1" min="0" x-model="steel.n" class="input num"></div>
            </div>

            <div x-show="tab === 'tiles'" x-cloak class="grid gap-3 sm:grid-cols-3">
                <div class="sm:col-span-3"><label class="label">{{ __('Area (m²)') }}</label><input type="number" step="any" min="0" x-model="tiles.a" class="input num"></div>
                <div><label class="label">{{ __('Tile length (mm)') }}</label><input type="number" step="any" min="0" x-model="tiles.l" class="input num"></div>
                <div><label class="label">{{ __('Tile width (mm)') }}</label><input type="number" step="any" min="0" x-model="tiles.w" class="input num"></div>
                <div><label class="label">{{ __('Wastage %') }}</label><input type="number" step="any" min="0" x-model="tiles.waste" class="input num"></div>
                <div><label class="label">{{ __('Tiles per box') }}</label><input type="number" step="1" min="1" x-model="tiles.box" class="input num"></div>
            </div>

            <div x-show="tab === 'paint'" x-cloak class="grid gap-3 sm:grid-cols-3">
                <div><label class="label">{{ __('Area (m²)') }}</label><input type="number" step="any" min="0" x-model="paint.a" class="input num"></div>
                <div><label class="label">{{ __('Coats') }}</label><input type="number" step="1" min="1" x-model="paint.coats" class="input num"></div>
                <div><label class="label">{{ __('Coverage (m²/L)') }}</label><input type="number" step="any" min="0" x-model="paint.cover" class="input num"></div>
                <p class="hint sm:col-span-3">{{ __('Check the coverage printed on the product you are buying.') }}</p>
            </div>

            <div x-show="tab === 'earth'" x-cloak>
                <p class="mb-3 text-sm text-ink-3">{{ __('Enter cross-section areas at each chainage. Volumes use the average end area method.') }}</p>
                <div class="grid grid-cols-[1fr_1fr_1fr_auto] gap-2">
                    <span class="label">{{ __('Chainage (m)') }}</span><span class="label">{{ __('Cut (m²)') }}</span><span class="label">{{ __('Fill (m²)') }}</span><span></span>
                    <template x-for="(row, i) in earth.rows" :key="i">
                        <div class="contents">
                            <input type="number" step="any" x-model="row.ch" class="input num" aria-label="{{ __('Chainage (m)') }}">
                            <input type="number" step="any" min="0" x-model="row.cut" class="input num" aria-label="{{ __('Cut (m²)') }}">
                            <input type="number" step="any" min="0" x-model="row.fill" class="input num" aria-label="{{ __('Fill (m²)') }}">
                            <button type="button" class="btn btn-ghost" @click="earth.rows.length > 2 && earth.rows.splice(i, 1)" aria-label="{{ __('Remove row') }}">×</button>
                        </div>
                    </template>
                </div>
                <div class="mt-3 flex flex-wrap items-end justify-between gap-3">
                    <button type="button" class="btn btn-ghost" @click="earth.rows.push({ ch: (parseFloat(earth.rows.at(-1).ch) || 0) + 20, cut: 0, fill: 0 })">{{ __('Add section') }}</button>
                    <div class="w-32"><label class="label">{{ __('Swell %') }}</label><input type="number" step="any" min="0" x-model="earth.swell" class="input num"></div>
                </div>
            </div>
        </div>

        <div class="card self-start">
            <div class="border-b-2 border-ink px-5 py-3"><p class="eyebrow text-ink-3">{{ __('Result') }}</p></div>
            <dl class="result-list num text-[15px]">
                <template x-if="tab === 'concrete'"><div class="contents">
                    <div><dt>{{ __('Wet volume') }}</dt><dd x-text="f(concreteOut.wet, 3) + ' m³'"></dd></div>
                    <div><dt>{{ __('Cement') }}</dt><dd x-text="f(concreteOut.bags, 1) + ' {{ __('bags') }}'"></dd></div>
                    <div><dt>{{ __('Sand') }}</dt><dd x-text="f(concreteOut.sand, 3) + ' m³'"></dd></div>
                    <div><dt>{{ __('Aggregate') }}</dt><dd x-text="f(concreteOut.agg, 3) + ' m³'"></dd></div>
                </div></template>
                <template x-if="tab === 'brick'"><div class="contents">
                    <div><dt>{{ __('Masonry volume') }}</dt><dd x-text="f(brickOut.volume, 3) + ' m³'"></dd></div>
                    <div><dt>{{ __('Bricks') }}</dt><dd x-text="f(brickOut.bricks, 0)"></dd></div>
                    <div><dt>{{ __('Cement') }}</dt><dd x-text="f(brickOut.bags, 1) + ' {{ __('bags') }}'"></dd></div>
                    <div><dt>{{ __('Sand') }}</dt><dd x-text="f(brickOut.sand, 3) + ' m³'"></dd></div>
                </div></template>
                <template x-if="tab === 'plaster'"><div class="contents">
                    <div><dt>{{ __('Mortar volume') }}</dt><dd x-text="f(plasterOut.wet, 3) + ' m³'"></dd></div>
                    <div><dt>{{ __('Cement') }}</dt><dd x-text="f(plasterOut.bags, 1) + ' {{ __('bags') }}'"></dd></div>
                    <div><dt>{{ __('Sand') }}</dt><dd x-text="f(plasterOut.sand, 3) + ' m³'"></dd></div>
                </div></template>
                <template x-if="tab === 'steel'"><div class="contents">
                    <div><dt>{{ __('Weight per metre') }}</dt><dd x-text="f(steelOut.perMetre, 3) + ' kg/m'"></dd></div>
                    <div><dt>{{ __('Total weight') }}</dt><dd x-text="f(steelOut.kg, 2) + ' kg'"></dd></div>
                    <div><dt>{{ __('In tonnes') }}</dt><dd x-text="f(steelOut.tonnes, 3) + ' MT'"></dd></div>
                </div></template>
                <template x-if="tab === 'tiles'"><div class="contents">
                    <div><dt>{{ __('Tiles') }}</dt><dd x-text="f(tilesOut.pieces, 0)"></dd></div>
                    <div><dt>{{ __('Boxes') }}</dt><dd x-text="f(tilesOut.boxes, 0)"></dd></div>
                </div></template>
                <template x-if="tab === 'paint'"><div class="contents">
                    <div><dt>{{ __('Paint needed') }}</dt><dd x-text="f(paintOut.litres, 2) + ' L'"></dd></div>
                </div></template>
                <template x-if="tab === 'earth'"><div class="contents">
                    <div><dt>{{ __('Cut volume') }}</dt><dd x-text="f(earthOut.cut, 3) + ' m³'"></dd></div>
                    <div><dt>{{ __('Loose cut volume') }}</dt><dd x-text="f(earthOut.loose, 3) + ' m³'"></dd></div>
                    <div><dt>{{ __('Fill volume') }}</dt><dd x-text="f(earthOut.fill, 3) + ' m³'"></dd></div>
                    <div><dt>{{ __('Net (cut − fill)') }}</dt><dd x-text="f(earthOut.net, 3) + ' m³'"></dd></div>
                </div></template>
            </dl>
            <p class="px-5 py-3 text-xs text-ink-3">{{ __('These are planning estimates. Check them against your drawings, specifications and site conditions before ordering.') }}</p>
        </div>
    </div>

    <div class="mt-8 flex flex-wrap items-center justify-between gap-3 rounded-[3px] border border-ink/70 bg-sheet px-5 py-4">
        <p class="font-display text-2xl font-semibold">{{ __('Track these quantities against your BOQ.') }}</p>
        <a href="{{ route('register') }}" class="btn btn-primary">{{ __('Start a :days-day trial', ['days' => config('buildos.trial_days')]) }}</a>
    </div>
</section>
@endsection
