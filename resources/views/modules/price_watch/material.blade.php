@extends('layouts.app')
@section('title', $row['name'])
@use('App\Support\Dec')
@use('App\Support\Figure')
@section('content')
    @php
        $canManage = auth()->user()->can('price_watch.manage');
        $kinds = ['manual' => __('typed in'), 'excel' => __('from Excel'), 'shared' => __('from the shared list')];
        $signed = fn ($value) => (Dec::of($value)->isPositive() ? '+' : '').Figure::trim($value).'%';
        $material = $row['material'];
    @endphp

    <x-page-header :title="$row['name']" :subtitle="__('Priced per :unit', ['unit' => $row['unit']])">
        <a href="{{ route('price-watch.index') }}" class="btn btn-ghost min-h-11">{{ __('Back to the price board') }}</a>
    </x-page-header>

    <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
        <x-stat :label="__('Current price')" :value="$row['current'] ? inr($row['current']['rate']) : '—'" :note="$row['current'] ? __('as on').' '.\Illuminate\Support\Carbon::parse($row['current']['priced_on'])->format('d M Y') : __('No price yet')" :accent="(bool) ($row['current']['stale'] ?? false)" />
        <x-stat :label="__('Previous')" :value="$row['previous'] ? inr($row['previous']['rate']) : '—'" :note="$row['previous'] ? \Illuminate\Support\Carbon::parse($row['previous']['priced_on'])->format('d M Y') : null" />
        <x-stat :label="__('Change')" :value="$row['change_pct'] === null ? '—' : $signed($row['change_pct'])" :accent="$row['change_pct'] !== null && Dec::of($row['change_pct'])->isPositive()" />
        <div class="card flex items-center justify-center px-4 py-3">@include('modules.price_watch._sparkline', ['trend' => $row['trend']])</div>
    </div>

    @if (($row['current']['stale'] ?? false))
        <p class="mt-3 text-sm"><span class="stamp border-laterite text-laterite">{{ __('Out of date') }}</span> <span class="text-ink-3">{{ __('The latest price is older than :days days. Enter a fresh one before relying on it.', ['days' => $staleDays]) }}</span></p>
    @endif

    <div class="mt-5 grid gap-5 lg:grid-cols-3">
        <section class="card lg:col-span-2">
            <div class="card-head"><h2 class="card-title">{{ __('Dated prices') }}</h2><span class="text-sm text-ink-3">{{ __('Newest first') }}</span></div>
            @if ($row['prices'] === [])
                <x-empty :title="__('No price yet')" :note="__('Enter the first price on the board, or upload a sheet.')" />
            @else
                <table class="ledger">
                    <thead><tr><th>{{ __('As on') }}</th><th class="r">{{ __('Rate') }}</th><th>{{ __('Source') }}</th><th>{{ __('Entered by') }}</th>@if ($canManage)<th></th>@endif</tr></thead>
                    <tbody>
                        @foreach ($row['prices'] as $price)
                            <tr>
                                <td class="text-sm">{{ \Illuminate\Support\Carbon::parse($price['priced_on'])->format('d M Y') }}@if ($loop->first && $price['stale']) <span class="stamp ml-1 border-laterite text-laterite">{{ __('Out of date') }}</span>@endif</td>
                                <td class="r num text-sm">{{ inr($price['rate']) }} <span class="text-xs text-ink-3">/ {{ $price['unit'] }}</span></td>
                                <td class="text-sm">{{ $price['source'] ?: '—' }} <span class="block text-[11px] text-ink-3">{{ $kinds[$price['source_kind']] ?? $price['source_kind'] }}</span></td>
                                <td class="text-sm text-ink-3">{{ $price['noted_by'] ?? '—' }}</td>
                                @if ($canManage)
                                    <td class="r">
                                        <form method="POST" action="{{ route('price-watch.prices.destroy', $price['id']) }}" onsubmit="return confirm(@js(__('Remove this price from the list?')))">
                                            @csrf @method('DELETE')
                                            <button class="link text-xs text-laterite">{{ __('Remove') }}</button>
                                        </form>
                                    </td>
                                @endif
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </section>

        @if ($canManage)
            <div class="space-y-5">
                <form method="POST" action="{{ route('price-watch.prices.store') }}" class="card">
                    @csrf
                    <input type="hidden" name="client_uuid" value="{{ $priceUuid }}">
                    <input type="hidden" name="price_watch_material_id" value="{{ $material->id }}">
                    <div class="card-head"><h2 class="card-title">{{ __('Enter a price') }}</h2></div>
                    <div class="space-y-3 p-4">
                        <x-field :label="__('Rate (₹) per :unit', ['unit' => $row['unit']])" name="rate"><input id="rate" name="rate" type="number" inputmode="decimal" step="0.01" min="0" value="{{ old('rate') }}" class="input num min-h-11" required></x-field>
                        <x-field :label="__('As on')" name="priced_on"><input id="priced_on" name="priced_on" type="date" max="{{ today()->toDateString() }}" value="{{ old('priced_on', today()->toDateString()) }}" class="input min-h-11" required></x-field>
                        <x-field :label="__('Where it came from')" name="source"><input id="source" name="source" value="{{ old('source') }}" class="input min-h-11" maxlength="160"></x-field>
                        <button class="btn btn-primary min-h-12 w-full">{{ __('Save price') }}</button>
                    </div>
                </form>

                <form method="POST" action="{{ route('price-watch.materials.update', $material) }}" class="card">
                    @csrf @method('PATCH')
                    <div class="card-head"><h2 class="card-title">{{ __('Material details') }}</h2></div>
                    <div class="space-y-3 p-4">
                        <x-field :label="__('Material')" name="name"><input id="name" name="name" value="{{ old('name', $material->name) }}" class="input min-h-11" maxlength="160" required></x-field>
                        <x-field :label="__('Priced per')" name="unit" :hint="$row['count'] > 0 ? __('Fixed once prices are recorded.') : null">
                            <input id="unit" name="unit" value="{{ old('unit', $material->unit) }}" class="input min-h-11" maxlength="20" required @readonly($row['count'] > 0)>
                        </x-field>
                        <x-field :label="__('Catalogue material (optional)')" name="material_id" :hint="__('Link it to a material in the catalogue so the BOQ norms and deliveries give the quantity still to buy.')">
                            <select id="material_id" name="material_id" class="input min-h-11">
                                <option value="">—</option>
                                @foreach ($catalogue as $item)
                                    <option value="{{ $item->id }}" @selected((string) old('material_id', $material->material_id) === (string) $item->id)>{{ $item->code }} · {{ $item->name }}</option>
                                @endforeach
                            </select>
                        </x-field>
                        <label class="flex items-center gap-2 text-sm"><input type="hidden" name="is_active" value="0"><input type="checkbox" name="is_active" value="1" @checked(old('is_active', $material->is_active))> {{ __('On the board') }}</label>
                        <button class="btn btn-ghost min-h-11 w-full">{{ __('Save material') }}</button>
                    </div>
                </form>
            </div>
        @endif
    </div>
@endsection
