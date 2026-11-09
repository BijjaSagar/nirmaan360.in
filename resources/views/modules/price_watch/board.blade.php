@extends('layouts.app')
@section('title', __('Material prices'))
@use('App\Support\Dec')
@use('App\Support\Figure')
@section('content')
    @php
        $canManage = auth()->user()->can('price_watch.manage');
        $signed = fn ($value) => (Dec::of($value)->isPositive() ? '+' : '').Figure::trim($value).'%';
        $priced = $rows->whereNotNull('current')->count();
        $stale = $rows->filter(fn ($row) => $row['current'] !== null && $row['current']['stale'])->count();
        $units = $rows->mapWithKeys(fn ($row) => [$row['id'] => $row['unit']]);
        $shared = collect($sources)->firstWhere('label', 'Shared list');
    @endphp

    <x-page-header :title="__('Material prices')" :subtitle="__('A dated list for each material. Every price shows the day it held and where it came from.')">
        @if ($canManage)
            <a href="{{ route('price-watch.template') }}" class="btn btn-ghost min-h-11">{{ __('Download Excel template') }}</a>
        @endif
    </x-page-header>

    <div class="mb-5 rounded-[3px] border border-haldi bg-haldi/10 px-4 py-3 text-sm text-ink-2" role="note">
        <strong>{{ __('No live price feed.') }}</strong>
        {{ __('Nirmaan360 does not fetch market prices. A price is here because someone typed it in, uploaded it from Excel, or took it from the shared list. A price older than :days days is marked out of date.', ['days' => $staleDays]) }}
    </div>

    <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
        <x-stat :label="__('Materials watched')" :value="$rows->count()" />
        <x-stat :label="__('With a price')" :value="$priced.' / '.$rows->count()" />
        <x-stat :label="__('Out of date')" :value="$stale" :note="__('Older than :days days', ['days' => $staleDays])" :accent="$stale > 0" />
        <x-stat :label="__('Shared list')" :value="$shared && $shared['connected'] ? __('Available') : __('Empty')" :note="$shared && $shared['updated_on'] ? __('Updated :date', ['date' => $shared['updated_on']->format('d M Y')]) : __('Kept by the platform admin')" />
    </div>

    @if ($errors->has('adopt'))
        <p class="error mt-3">{{ $errors->first('adopt') }}</p>
    @endif

    @if ($canManage)
        <div class="mt-5 grid gap-5 lg:grid-cols-3">
            {{-- Type a price in --}}
            <form method="POST" action="{{ route('price-watch.prices.store') }}" class="card" x-data="{ id: @js(old('price_watch_material_id', '')), units: @js($units) }">
                @csrf
                <input type="hidden" name="client_uuid" value="{{ $priceUuid }}">
                <div class="card-head"><h2 class="card-title">{{ __('Enter a price') }}</h2></div>
                <div class="grid gap-3 p-4 sm:grid-cols-2">
                    <x-field :label="__('Material')" name="price_watch_material_id" class="sm:col-span-2">
                        <select id="price_watch_material_id" name="price_watch_material_id" class="input min-h-11" x-model="id" required>
                            <option value="">—</option>
                            @foreach ($rows as $row)
                                <option value="{{ $row['id'] }}">{{ $row['name'] }} ({{ __('per') }} {{ $row['unit'] }})</option>
                            @endforeach
                        </select>
                    </x-field>
                    <x-field :label="__('Rate (₹)')" name="rate">
                        <div class="flex items-center gap-2">
                            <input id="rate" name="rate" type="number" inputmode="decimal" step="0.01" min="0" value="{{ old('rate') }}" class="input num min-h-11" required>
                            <span class="text-sm whitespace-nowrap text-ink-3" x-text="units[id] ? '{{ __('per') }} ' + units[id] : ''"></span>
                        </div>
                    </x-field>
                    <x-field :label="__('As on')" name="priced_on">
                        <input id="priced_on" name="priced_on" type="date" max="{{ today()->toDateString() }}" value="{{ old('priced_on', today()->toDateString()) }}" class="input min-h-11" required>
                    </x-field>
                    <x-field :label="__('Where it came from')" name="source" class="sm:col-span-2" :hint="__('A dealer quote, a bill, the local market. Kept with the price.')">
                        <input id="source" name="source" value="{{ old('source') }}" class="input min-h-11" maxlength="160">
                    </x-field>
                    <div class="sm:col-span-2"><button class="btn btn-primary min-h-12 w-full">{{ __('Save price') }}</button></div>
                </div>
            </form>

            {{-- Upload a sheet --}}
            <form method="POST" action="{{ route('price-watch.upload') }}" enctype="multipart/form-data" class="card">
                @csrf
                <div class="card-head"><h2 class="card-title">{{ __('Upload from Excel') }}</h2></div>
                <div class="space-y-3 p-4">
                    <p class="text-sm text-ink-3">{{ __('Columns: material, rate, unit, date, source. One row per price. The whole sheet is checked first; if any row is wrong, nothing is saved.') }}</p>
                    <x-field :label="__('Sheet (.xlsx)')" name="file">
                        <input id="file" name="file" type="file" accept=".xlsx" class="input min-h-11" required>
                    </x-field>
                    @if ($errors->has('file'))
                        <ul class="space-y-1 rounded-[3px] border border-laterite/60 bg-laterite/5 px-3 py-2 text-sm text-laterite">
                            @foreach ($errors->get('file') as $message)
                                <li>{{ $message }}</li>
                            @endforeach
                        </ul>
                    @endif
                    <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="add_missing" value="1" @checked(old('add_missing'))> {{ __('Add new materials from the sheet') }}</label>
                    <button class="btn btn-primary min-h-12 w-full">{{ __('Upload prices') }}</button>
                    <a href="{{ route('price-watch.template') }}" class="link block text-center text-sm">{{ __('Download Excel template') }}</a>
                </div>
            </form>

            {{-- The shared list --}}
            <form method="POST" action="{{ route('price-watch.adopt') }}" class="card">
                @csrf
                <div class="card-head"><h2 class="card-title">{{ __('Shared list') }}</h2></div>
                <div class="space-y-3 p-4">
                    <p class="text-sm text-ink-3">{{ __('The platform admin keeps a list of common material prices with their dates. Taking it adds each price your list does not have yet; your own entries are never changed.') }}</p>
                    @foreach ($sources as $source)
                        <p class="flex items-center justify-between gap-2 text-sm">
                            <span>{{ __($source['label']) }}</span>
                            <span class="{{ $source['connected'] ? 'text-moss' : 'text-ink-3' }}">{{ $source['connected'] ? __('Available') : __('Not connected') }}@if ($source['updated_on']) · {{ $source['updated_on']->format('d M Y') }}@endif</span>
                        </p>
                    @endforeach
                    <button class="btn btn-ghost min-h-12 w-full" @disabled(! collect($sources)->contains('connected', true))>{{ __('Take prices from the shared list') }}</button>
                </div>
            </form>
        </div>
    @endif

    {{-- The board --}}
    <section class="card mt-5 overflow-x-auto" id="board">
        <div class="card-head">
            <h2 class="card-title">{{ __('Price board') }}</h2>
            <span class="text-sm text-ink-3">{{ __('Change is against the previous price on the list') }}</span>
        </div>
        @if ($rows->isEmpty())
            <x-empty :title="__('No materials on the board')" :note="__('Add the materials whose prices you want to follow.')" />
        @else
            <table class="ledger min-w-[760px]">
                <thead>
                    <tr>
                        <th>{{ __('Material') }}</th>
                        <th>{{ __('Unit') }}</th>
                        <th>{{ __('Current price') }}</th>
                        <th class="r">{{ __('Previous') }}</th>
                        <th class="r">{{ __('Change') }}</th>
                        <th>{{ __('Trend') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($rows as $row)
                        <tr>
                            <td><a href="{{ route('price-watch.materials.show', $row['id']) }}" class="link font-medium">{{ $row['name'] }}</a>
                                <span class="block text-[11px] text-ink-3">{{ $row['count'] === 1 ? __('1 price on the list') : __(':count prices on the list', ['count' => $row['count']]) }}</span></td>
                            <td class="text-sm">{{ $row['unit'] }}</td>
                            <td>@include('modules.price_watch._price', ['price' => $row['current']])</td>
                            <td class="r text-sm">
                                @if ($row['previous'])
                                    <span class="num">{{ inr($row['previous']['rate']) }}</span>
                                    <span class="block text-[11px] text-ink-3">{{ \Illuminate\Support\Carbon::parse($row['previous']['priced_on'])->format('d M Y') }}</span>
                                @else
                                    —
                                @endif
                            </td>
                            <td class="r num text-sm font-medium {{ $row['change_pct'] !== null && Dec::of($row['change_pct'])->isPositive() ? 'text-laterite' : ($row['change_pct'] !== null && Dec::of($row['change_pct'])->isNegative() ? 'text-moss' : '') }}">{{ $row['change_pct'] === null ? '—' : $signed($row['change_pct']) }}</td>
                            <td>@include('modules.price_watch._sparkline', ['trend' => $row['trend']])</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </section>

    @if ($canManage)
        <div class="mt-5 grid gap-5 lg:grid-cols-2">
            <form method="POST" action="{{ route('price-watch.materials.store') }}" class="card">
                @csrf
                <input type="hidden" name="client_uuid" value="{{ $materialUuid }}">
                <div class="card-head"><h2 class="card-title">{{ __('Watch another material') }}</h2></div>
                <div class="grid gap-3 p-4 sm:grid-cols-3">
                    <x-field :label="__('Material')" name="name" class="sm:col-span-2">
                        <input id="name" name="name" value="{{ old('name') }}" class="input min-h-11" maxlength="160" required placeholder="{{ __('For example vitrified tiles') }}">
                    </x-field>
                    <x-field :label="__('Priced per')" name="unit">
                        <input id="unit" name="unit" value="{{ old('unit') }}" list="price-units" class="input min-h-11" maxlength="20" required placeholder="bag, kg, nos">
                        <datalist id="price-units">@foreach ([...config('buildos.units'), 'brass', 'litre', 'm'] as $unit)<option value="{{ $unit }}">@endforeach</datalist>
                    </x-field>
                    <x-field :label="__('Catalogue material (optional)')" name="material_id" class="sm:col-span-3" :hint="__('Link it to a material in the catalogue so the BOQ norms and deliveries give the quantity still to buy.')">
                        <select id="material_id" name="material_id" class="input min-h-11">
                            <option value="">—</option>
                            @foreach ($catalogue as $item)
                                <option value="{{ $item->id }}" @selected((string) old('material_id') === (string) $item->id)>{{ $item->code }} · {{ $item->name }}</option>
                            @endforeach
                        </select>
                    </x-field>
                    <div class="sm:col-span-3"><button class="btn btn-primary min-h-12">{{ __('Add to the board') }}</button></div>
                </div>
            </form>

            @if ($inactive->isNotEmpty())
                <section class="card">
                    <div class="card-head"><h2 class="card-title">{{ __('Switched off') }}</h2></div>
                    <ul class="divide-y divide-rule">
                        @foreach ($inactive as $row)
                            <li class="flex items-center justify-between gap-3 px-4 py-2.5 text-sm">
                                <span>{{ $row['name'] }} <span class="text-ink-3">· {{ $row['unit'] }}</span></span>
                                <form method="POST" action="{{ route('price-watch.materials.update', $row['id']) }}">
                                    @csrf @method('PATCH')
                                    <input type="hidden" name="name" value="{{ $row['material']->name }}">
                                    <input type="hidden" name="unit" value="{{ $row['unit'] }}">
                                    <input type="hidden" name="material_id" value="{{ $row['material_id'] }}">
                                    <input type="hidden" name="is_active" value="1">
                                    <button class="link">{{ __('Switch on') }}</button>
                                </form>
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endif
        </div>
    @endif
@endsection
