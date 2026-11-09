@extends('layouts.app')
@section('title', __('Shared material prices'))
@section('content')
    <x-page-header :title="__('Shared material prices')" :subtitle="__('A list every company can take onto its own price board. It is only as current as your last edit.')" />
    @include('admin._tabs')

    <div class="grid gap-5 lg:grid-cols-3" x-data="{ form: @js(['key' => old('key', ''), 'name' => old('name', ''), 'unit' => old('unit', ''), 'rate' => old('rate', ''), 'priced_on' => old('priced_on', today()->toDateString()), 'source' => old('source', '')]) }">
        <section class="card lg:col-span-2 overflow-x-auto">
            <div class="card-head"><h2 class="card-title">{{ __('Shared list') }}</h2><span class="text-sm text-ink-3">{{ __('One current price per material') }}</span></div>
            @if ($rows->isEmpty())
                <x-empty :title="__('The shared list is empty')" :note="__('Companies see it as not connected until a price is here.')" />
            @else
                <table class="ledger min-w-[720px]">
                    <thead><tr><th>{{ __('Material') }}</th><th>{{ __('Key') }}</th><th>{{ __('Unit') }}</th><th class="r">{{ __('Rate') }}</th><th>{{ __('As on') }}</th><th>{{ __('Source') }}</th><th></th></tr></thead>
                    <tbody>
                        @foreach ($rows as $row)
                            <tr>
                                <td>{{ $row->name }}</td>
                                <td class="num text-xs text-ink-3">{{ $row->key }}</td>
                                <td class="text-sm">{{ $row->unit }}</td>
                                <td class="r num text-sm">{{ inr($row->rate) }}</td>
                                <td class="text-sm">{{ $row->priced_on->format('d M Y') }}</td>
                                <td class="text-sm text-ink-3">{{ $row->source ?: '—' }}</td>
                                <td class="r whitespace-nowrap">
                                    <button type="button" class="link text-xs" @click="form = @js(['key' => $row->key, 'name' => $row->name, 'unit' => $row->unit, 'rate' => $row->rate, 'priced_on' => $row->priced_on->toDateString(), 'source' => $row->source])">{{ __('Edit') }}</button>
                                    <form method="POST" action="{{ route('price-watch.admin.prices.destroy', $row) }}" class="ml-2 inline" onsubmit="return confirm(@js(__('Remove this material from the shared list?')))">@csrf @method('DELETE')<button class="link text-xs text-laterite">{{ __('Remove') }}</button></form>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </section>

        <div class="space-y-5">
            <form method="POST" action="{{ route('price-watch.admin.prices.save') }}" class="card">
                @csrf
                <div class="card-head"><h2 class="card-title">{{ __('Add or update a price') }}</h2></div>
                <div class="space-y-3 p-4">
                    <x-field :label="__('Material')" name="name"><input id="name" name="name" x-model="form.name" class="input min-h-11" maxlength="160" required></x-field>
                    <x-field :label="__('Key')" name="key" :hint="__('Matches the material on each company board. Leave blank to make one from the name.')">
                        <input id="key" name="key" x-model="form.key" list="default-keys" class="input num min-h-11" maxlength="40" pattern="[a-z0-9_]+">
                        <datalist id="default-keys">@foreach ($defaults as $default)<option value="{{ $default['key'] }}">{{ $default['name'] }} ({{ $default['unit'] }})</option>@endforeach</datalist>
                    </x-field>
                    <div class="grid grid-cols-2 gap-3">
                        <x-field :label="__('Unit')" name="unit"><input id="unit" name="unit" x-model="form.unit" class="input min-h-11" maxlength="20" required></x-field>
                        <x-field :label="__('Rate (₹)')" name="rate"><input id="rate" name="rate" type="number" step="0.01" min="0" x-model="form.rate" class="input num min-h-11" required></x-field>
                    </div>
                    <x-field :label="__('As on')" name="priced_on"><input id="priced_on" name="priced_on" type="date" max="{{ today()->toDateString() }}" x-model="form.priced_on" class="input min-h-11" required></x-field>
                    <x-field :label="__('Source')" name="source" :hint="__('Travels with the price into every company that takes it.')"><input id="source" name="source" x-model="form.source" class="input min-h-11" maxlength="160"></x-field>
                    <button class="btn btn-primary min-h-12 w-full">{{ __('Save to the shared list') }}</button>
                </div>
            </form>

            <form method="POST" action="{{ route('price-watch.admin.settings') }}" class="card">
                @csrf @method('PATCH')
                <div class="card-head"><h2 class="card-title">{{ __('Limits') }}</h2></div>
                <div class="space-y-3 p-4">
                    <x-field :label="__('A price is out of date after (days)')" name="stale_days"><input id="stale_days" name="stale_days" type="number" min="1" max="3650" value="{{ old('stale_days', $staleDays) }}" class="input num min-h-11" required></x-field>
                    <x-field :label="__('Price alert at a rise of (%)')" name="rise_pct" :hint="__('Above the BOQ rate, or above the previous price. Twice this is critical.')"><input id="rise_pct" name="rise_pct" type="number" step="0.01" min="0.01" max="1000" value="{{ old('rise_pct', $risePct) }}" class="input num min-h-11" required></x-field>
                    <x-field :label="__('Budget alert at an impact of (% of budget)')" name="impact_pct"><input id="impact_pct" name="impact_pct" type="number" step="0.01" min="0.01" max="100" value="{{ old('impact_pct', $impactPct) }}" class="input num min-h-11" required></x-field>
                    <button class="btn btn-ghost min-h-11 w-full">{{ __('Save limits') }}</button>
                </div>
            </form>
        </div>
    </div>
@endsection
