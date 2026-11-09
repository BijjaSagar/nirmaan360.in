{{-- Pick a company rate to fill in the description, unit and rate of a new BOQ item. --}}
@if (auth()->user()->organization->allowsFeature('reports') && auth()->user()->can('rate.view'))
    <div class="relative sm:col-span-4"
         @click.outside="open = false"
         x-data="{
            q: '', results: [], open: false, loading: false, timer: null,
            find() {
                clearTimeout(this.timer);
                this.timer = setTimeout(async () => {
                    this.loading = true;
                    try {
                        const response = await fetch(@js(route('ratelib.search')) + '?q=' + encodeURIComponent(this.q), { headers: { Accept: 'application/json' } });
                        this.results = response.ok ? await response.json() : [];
                    } catch (error) {
                        this.results = [];
                    }
                    this.loading = false;
                    this.open = true;
                }, 250);
            },
            money(value) { return Number(value).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); },
            pick(rate) {
                for (const [id, value] of [['description', rate.item], ['unit', rate.unit], ['rate', rate.rate]]) {
                    const field = document.getElementById(id);
                    if (field) { field.value = value; field.dispatchEvent(new Event('input', { bubbles: true })); }
                }
                this.open = false;
                this.q = '';
            }
         }">
        <label class="label" for="rate-pick">{{ __('Fill from the rate library') }}</label>
        <input id="rate-pick" type="search" x-model="q" @input="find()" @focus="find()" autocomplete="off" class="input" placeholder="{{ __('Search your rates by item or category') }}">
        <div x-show="open" x-cloak class="card absolute z-20 mt-1 max-h-72 w-full overflow-y-auto shadow-lg">
            <template x-for="rate in results" :key="rate.id">
                <button type="button" class="block w-full border-b border-rule px-3 py-2 text-left text-sm last:border-b-0 hover:bg-paper" @click="pick(rate)">
                    <span class="block font-medium" x-text="rate.item"></span>
                    <span class="block text-xs text-ink-3"><span x-text="rate.category ? rate.category + ' · ' : ''"></span>₹ <span x-text="money(rate.rate)"></span> / <span x-text="rate.unit"></span></span>
                </button>
            </template>
            <p x-show="! results.length && ! loading" class="px-3 py-3 text-sm text-ink-3">{{ __('No matching rates.') }}</p>
        </div>
    </div>
@endif
