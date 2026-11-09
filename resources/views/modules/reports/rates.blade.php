@extends('layouts.app')
@section('title', __('Rate library'))
@section('content')
    @php
        $parts = ['material_rate' => 'Material', 'labour_rate' => 'Labour', 'plant_rate' => 'Plant', 'overhead_rate' => 'Overhead', 'profit_rate' => 'Profit'];
    @endphp

    <div x-data="{ add: {{ $errors->any() && old('_form') === 'add' ? 'true' : 'false' }}, edit: null }">
        <x-page-header :title="__('Rate library')" :subtitle="__('Your company rates. Pick one when adding a BOQ item.')">
            @can('rate.manage')
                <button type="button" class="btn btn-primary" @click="add = !add">{{ __('Add rate') }}</button>
            @endcan
        </x-page-header>

        @can('rate.manage')
            <form x-show="add" x-cloak method="POST" action="{{ route('ratelib.store') }}" class="card mb-5"
                  x-data="{ m: @js(old('material_rate', '')), l: @js(old('labour_rate', '')), p: @js(old('plant_rate', '')), o: @js(old('overhead_rate', '')), f: @js(old('profit_rate', '')),
                            get split() { return [this.m, this.l, this.p, this.o, this.f].some(v => v !== '' && v !== null); },
                            get total() { return ([this.m, this.l, this.p, this.o, this.f].reduce((sum, v) => sum + Math.round((parseFloat(v) || 0) * 100), 0) / 100).toFixed(2); } }">
                @csrf
                <input type="hidden" name="_form" value="add">
                <input type="hidden" name="client_uuid" value="{{ $clientUuid }}">
                <div class="card-head"><h2 class="card-title">{{ __('Add rate') }}</h2></div>
                <div class="grid gap-3 p-4 sm:grid-cols-4">
                    <x-field :label="__('Item')" name="item" class="sm:col-span-2"><input id="item" name="item" value="{{ old('item') }}" class="input" maxlength="255" required></x-field>
                    <x-field :label="__('Category')" name="category">
                        <input id="category" name="category" value="{{ old('category') }}" list="rate-categories" class="input" maxlength="80">
                        <datalist id="rate-categories">@foreach ($categories as $category)<option value="{{ $category }}">@endforeach</datalist>
                    </x-field>
                    <x-field :label="__('Unit')" name="unit">
                        <input id="unit" name="unit" value="{{ old('unit') }}" list="rate-units" class="input" maxlength="16" required>
                        <datalist id="rate-units">@foreach (config('buildos.units') as $unit)<option value="{{ $unit }}">@endforeach</datalist>
                    </x-field>
                    <x-field :label="__('Rate (₹)')" name="rate" :hint="__('Per unit. Worked out from the split below when you fill it in.')">
                        <input id="rate" name="rate" type="number" step="0.01" min="0" value="{{ old('rate') }}" class="input num" x-show="! split" :required="! split" :disabled="split">
                        <input type="text" class="input num bg-paper-2/60" x-show="split" x-cloak :value="total" readonly aria-label="{{ __('Rate (₹)') }}">
                    </x-field>
                    <div class="sm:col-span-4"><p class="eyebrow text-ink-3">{{ __('Rate analysis (optional)') }}</p></div>
                    @foreach (['material_rate' => 'm', 'labour_rate' => 'l', 'plant_rate' => 'p', 'overhead_rate' => 'o', 'profit_rate' => 'f'] as $name => $model)
                        <x-field :label="__($parts[$name]).' (₹)'" :name="$name">
                            <input id="{{ $name }}" name="{{ $name }}" type="number" step="0.01" min="0" x-model="{{ $model }}" class="input num">
                        </x-field>
                    @endforeach
                    <div class="flex items-end"><button class="btn btn-primary w-full">{{ __('Add rate') }}</button></div>
                </div>
            </form>
        @endcan

        <form method="GET" action="{{ route('ratelib.index') }}" class="mb-3 flex flex-wrap items-end gap-2">
            <div class="w-full sm:w-72"><label class="label" for="q">{{ __('Search') }}</label><input id="q" name="q" value="{{ $filters['q'] ?? '' }}" class="input" placeholder="{{ __('Item or category') }}" maxlength="100"></div>
            <div class="w-full sm:w-52">
                <label class="label" for="filter-category">{{ __('Category') }}</label>
                <select id="filter-category" name="category" class="input">
                    <option value="">{{ __('All categories') }}</option>
                    @foreach ($categories as $category)<option value="{{ $category }}" @selected(($filters['category'] ?? '') === $category)>{{ $category }}</option>@endforeach
                </select>
            </div>
            <button class="btn btn-ghost">{{ __('Filter') }}</button>
        </form>

        <div class="card overflow-x-auto">
            @if ($items->isEmpty())
                <x-empty :title="__('No rates yet')" :note="__('Save the rates you use often, then pick them when adding a BOQ item.')" />
            @else
                <table class="ledger min-w-[760px]">
                    <thead><tr><th class="w-[34%]">{{ __('Item') }}</th><th>{{ __('Category') }}</th><th>{{ __('Unit') }}</th><th class="r">{{ __('Rate') }}</th><th>{{ __('Split') }}</th>@can('rate.manage')<th></th>@endcan</tr></thead>
                    <tbody>
                    @foreach ($items as $item)
                        <tr>
                            <td>{{ $item->item }}</td>
                            <td>{{ $item->category ?? '—' }}</td>
                            <td>{{ $item->unit }}</td>
                            <td class="r">{{ \App\Support\Dec::indian($item->rate) }}</td>
                            <td class="text-xs text-ink-3">
                                @if ($item->hasSplit())
                                    {{ collect($parts)->filter(fn ($label, $name) => $item->{$name} !== null)->map(fn ($label, $name) => __($label).' '.\App\Support\Dec::indian($item->{$name}))->implode(' · ') }}
                                @else
                                    —
                                @endif
                            </td>
                            @can('rate.manage')
                                <td class="whitespace-nowrap text-right">
                                    <button type="button" class="link text-xs" @click="edit = @js(['url' => route('ratelib.update', $item), 'item' => $item->item, 'category' => $item->category, 'unit' => $item->unit, 'rate' => $item->rate, 'material_rate' => $item->material_rate, 'labour_rate' => $item->labour_rate, 'plant_rate' => $item->plant_rate, 'overhead_rate' => $item->overhead_rate, 'profit_rate' => $item->profit_rate])">{{ __('Edit') }}</button>
                                    <form method="POST" action="{{ route('ratelib.destroy', $item) }}" class="ml-2 inline" onsubmit="return confirm(@js(__('Remove this rate?')))">@csrf @method('DELETE')<button class="link text-xs text-laterite">{{ __('Remove') }}</button></form>
                                </td>
                            @endcan
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            @endif
        </div>
        <div class="mt-4">{{ $items->links() }}</div>

        @can('rate.manage')
            <div x-show="edit" x-cloak class="fixed inset-0 z-50 grid place-items-center overflow-y-auto bg-ink/50 p-4" @keydown.escape.window="edit = null">
                <template x-if="edit">
                    <form method="POST" :action="edit.url" class="card w-full max-w-xl" @click.outside="edit = null"
                          x-data="{ get split() { return ['material_rate', 'labour_rate', 'plant_rate', 'overhead_rate', 'profit_rate'].some(k => edit[k] !== null && edit[k] !== ''); },
                                    get total() { return (['material_rate', 'labour_rate', 'plant_rate', 'overhead_rate', 'profit_rate'].reduce((sum, k) => sum + Math.round((parseFloat(edit[k]) || 0) * 100), 0) / 100).toFixed(2); } }">
                        @csrf @method('PATCH')
                        <div class="card-head"><h2 class="card-title">{{ __('Edit rate') }}</h2></div>
                        <div class="grid gap-3 p-4 sm:grid-cols-3">
                            <div class="sm:col-span-3"><label class="label">{{ __('Item') }}</label><input name="item" x-model="edit.item" class="input" maxlength="255" required></div>
                            <div><label class="label">{{ __('Category') }}</label><input name="category" x-model="edit.category" list="rate-categories" class="input" maxlength="80"></div>
                            <div><label class="label">{{ __('Unit') }}</label><input name="unit" x-model="edit.unit" class="input" maxlength="16" required></div>
                            <div>
                                <label class="label">{{ __('Rate (₹)') }}</label>
                                <input name="rate" type="number" step="0.01" min="0" x-model="edit.rate" class="input num" x-show="! split" :required="! split" :disabled="split">
                                <input type="text" class="input num bg-paper-2/60" x-show="split" x-cloak :value="total" readonly>
                            </div>
                            <div class="sm:col-span-3"><p class="eyebrow text-ink-3">{{ __('Rate analysis (optional)') }}</p></div>
                            @foreach ($parts as $name => $label)
                                <div><label class="label">{{ __($label) }} (₹)</label><input name="{{ $name }}" type="number" step="0.01" min="0" x-model="edit.{{ $name }}" class="input num"></div>
                            @endforeach
                        </div>
                        <div class="flex justify-end gap-2 border-t border-rule px-4 py-3">
                            <button type="button" class="btn btn-ghost" @click="edit = null">{{ __('Cancel') }}</button>
                            <button class="btn btn-primary">{{ __('Save changes') }}</button>
                        </div>
                    </form>
                </template>
            </div>
        @endcan
    </div>
@endsection
