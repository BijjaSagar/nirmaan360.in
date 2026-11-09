{{-- Measurement sheet PDF for a project and period. --}}
@if (auth()->user()->organization->allowsFeature('reports'))
    <div class="relative" x-data="{ sheet: false }" @click.outside="sheet = false">
        <button type="button" class="btn btn-ghost" @click="sheet = !sheet">{{ __('Measurement sheet PDF') }}</button>
        <form x-show="sheet" x-cloak method="GET" action="{{ route('measurements.sheet', $project) }}" class="card absolute left-0 z-20 mt-1 w-72 max-w-[calc(100vw-2rem)] space-y-3 p-4 shadow-lg sm:right-0 sm:left-auto">
            <p class="text-xs text-ink-3">{{ __('Approved measurements for the dates you choose, grouped by BOQ item.') }}</p>
            <div><label class="label" for="sheet-from">{{ __('From') }}</label><input id="sheet-from" name="from" type="date" value="{{ now()->startOfMonth()->format('Y-m-d') }}" class="input" required></div>
            <div><label class="label" for="sheet-to">{{ __('To') }}</label><input id="sheet-to" name="to" type="date" value="{{ now()->format('Y-m-d') }}" class="input" required></div>
            <button class="btn btn-primary w-full">{{ __('Download PDF') }}</button>
        </form>
    </div>
@endif
