@extends('layouts.app')
@section('title', __('Stores & stock'))
@section('content')
    <x-page-header :title="__('Stores & stock')" :subtitle="$project->name" />

    @if ($alerts->isNotEmpty())
        <section class="card mb-5 border-laterite">
            <div class="card-head"><h2 class="card-title text-laterite">{{ __('Below reorder level') }}</h2><span class="num text-sm">{{ $alerts->count() }}</span></div>
            <div class="overflow-x-auto">
                <table class="ledger min-w-[520px]">
                    <thead><tr><th>{{ __('Store') }}</th><th>{{ __('Material') }}</th><th class="r">{{ __('In stock') }}</th><th class="r">{{ __('Reorder at') }}</th><th class="r">{{ __('Short by') }}</th></tr></thead>
                    <tbody>
                    @foreach ($alerts as $alert)
                        <tr>
                            <td>{{ $alert['store']->name }}</td>
                            <td>{{ $alert['material']->name }}</td>
                            <td class="r">{{ fmt_qty($alert['balance']) }} {{ $alert['material']->baseUnit->code }}</td>
                            <td class="r">{{ fmt_qty($alert['reorder_level']) }}</td>
                            <td class="r font-medium text-laterite">{{ fmt_qty($alert['shortfall']) }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    @endif

    <div class="space-y-5">
        @forelse ($stores as $store)
            <section class="card">
                <div class="card-head">
                    <div>
                        <h2 class="card-title">{{ $store->name }}</h2>
                        @if ($store->site)<p class="text-xs text-ink-3">{{ $store->site->name }}</p>@endif
                    </div>
                </div>
                @if ($rows[$store->id]->isEmpty())
                    <p class="px-4 py-5 text-sm text-ink-3">{{ __('No stock has moved through this store yet.') }}</p>
                @else
                    <div class="overflow-x-auto">
                        <table class="ledger min-w-[560px]">
                            <thead><tr><th>{{ __('Material') }}</th><th class="r">{{ __('In stock') }}</th><th>{{ __('Reorder level') }}</th><th></th></tr></thead>
                            <tbody>
                            @foreach ($rows[$store->id] as $row)
                                <tr>
                                    <td>{{ $row['material']->name }}</td>
                                    <td class="r">{{ fmt_qty($row['balance']) }} {{ $row['material']->baseUnit->code }}</td>
                                    <td>
                                        @can('procurement.masters')
                                            <form method="POST" action="{{ route('procurement.stock.reorder', [$store, $row['material']]) }}" class="flex items-center gap-1.5">
                                                @csrf @method('PATCH')
                                                <input name="reorder_level" type="number" step="0.001" min="0" value="{{ $row['level']?->reorder_level }}" class="input num w-28" aria-label="{{ __('Reorder level') }}">
                                                <button class="btn btn-ghost">{{ __('Set') }}</button>
                                            </form>
                                        @else
                                            <span class="num">{{ $row['level']?->reorder_level ? fmt_qty($row['level']->reorder_level) : '—' }}</span>
                                        @endcan
                                    </td>
                                    <td class="text-right"><a href="{{ route('procurement.stock.card', [$store, $row['material']]) }}" class="link text-xs">{{ __('Stock card') }}</a></td>
                                </tr>
                            @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </section>
        @empty
            <div class="card"><x-empty :title="__('No stores yet')" :note="__('Add a store for each place material is kept on this project.')" /></div>
        @endforelse

        @can('procurement.masters')
            <form method="POST" action="{{ route('procurement.stock.stores.store', $project) }}" class="card flex flex-wrap items-end gap-3 p-4">
                @csrf
                <input type="hidden" name="client_uuid" value="{{ \Illuminate\Support\Str::uuid() }}">
                <x-field :label="__('New store name')" name="name" class="min-w-48 flex-1"><input id="name" name="name" class="input" maxlength="120" required></x-field>
                <x-field :label="__('Site')" name="site_id" class="min-w-48 flex-1">
                    <select id="site_id" name="site_id" class="input">
                        <option value="">{{ __('None') }}</option>
                        @foreach ($sites as $site)<option value="{{ $site->id }}">{{ $site->name }}</option>@endforeach
                    </select>
                </x-field>
                <button class="btn btn-ghost">{{ __('Add store') }}</button>
            </form>
        @endcan

        @can('procurement.adjust')
            @if ($stores->isNotEmpty())
                <form method="POST" action="{{ route('procurement.stock.adjustments.store', $project) }}" class="card p-4"
                      x-data="{ materials: @js($materialOptions), material: '', find(id) { return this.materials.find(m => String(m.id) === String(id)); } }">
                    @csrf
                    <input type="hidden" name="client_uuid" value="{{ \Illuminate\Support\Str::uuid() }}">
                    <h2 class="card-title mb-3">{{ __('Opening stock or adjustment') }}</h2>
                    <div class="grid gap-3 sm:grid-cols-6">
                        <div class="sm:col-span-2">
                            <label class="label">{{ __('Type') }}</label>
                            <select name="adjustment_type" class="input" required>
                                <option value="opening">{{ __('Opening stock') }}</option>
                                <option value="increase">{{ __('Increase') }}</option>
                                <option value="decrease">{{ __('Decrease') }}</option>
                            </select>
                        </div>
                        <div class="sm:col-span-2">
                            <label class="label">{{ __('Store') }}</label>
                            <select name="store_id" class="input" required>
                                @foreach ($stores as $store)<option value="{{ $store->id }}">{{ $store->name }}</option>@endforeach
                            </select>
                        </div>
                        <div class="sm:col-span-2">
                            <label class="label">{{ __('Date') }}</label>
                            <input name="adjustment_date" type="date" max="{{ today()->format('Y-m-d') }}" value="{{ today()->format('Y-m-d') }}" class="input" required>
                        </div>
                        <div class="sm:col-span-3">
                            <label class="label">{{ __('Material') }}</label>
                            <select name="material_id" class="input" x-model="material" required>
                                <option value="">{{ __('Choose') }}</option>
                                <template x-for="m in materials" :key="m.id"><option :value="m.id" x-text="m.label"></option></template>
                            </select>
                        </div>
                        <div class="sm:col-span-1">
                            <label class="label">{{ __('Quantity') }}</label>
                            <input name="qty" type="number" step="0.001" min="0.001" class="input num" required>
                        </div>
                        <div class="sm:col-span-2">
                            <label class="label">{{ __('Unit') }}</label>
                            <select name="unit_id" class="input">
                                <option value="" x-text="find(material)?.base_unit ?? '—'"></option>
                                <template x-for="u in (find(material)?.units ?? [])" :key="u.id"><option :value="u.id" x-text="u.code"></option></template>
                            </select>
                        </div>
                        <div class="sm:col-span-6">
                            <label class="label">{{ __('Reason') }}</label>
                            <input name="reason" class="input" maxlength="255" required>
                        </div>
                    </div>
                    <button class="btn btn-primary mt-3">{{ __('Post to stock') }}</button>
                </form>
            @endif
        @endcan

        @if ($adjustments->isNotEmpty())
            <section class="card">
                <div class="card-head"><h2 class="card-title">{{ __('Recent adjustments') }}</h2></div>
                <div class="overflow-x-auto">
                    <table class="ledger min-w-[760px]">
                        <thead><tr><th>{{ __('No.') }}</th><th>{{ __('Date') }}</th><th>{{ __('Store') }}</th><th>{{ __('Material') }}</th><th>{{ __('Type') }}</th><th class="r">{{ __('Quantity') }}</th><th>{{ __('Reason') }}</th><th>{{ __('Status') }}</th><th></th></tr></thead>
                        <tbody>
                        @foreach ($adjustments as $adjustment)
                            <tr>
                                <td class="num text-xs">{{ $adjustment->number }}</td>
                                <td class="num text-xs">{{ $adjustment->adjustment_date->format('d M Y') }}</td>
                                <td>{{ $adjustment->store->name }}</td>
                                <td>{{ $adjustment->material->name }}</td>
                                <td>{{ __(ucfirst($adjustment->adjustment_type)) }}</td>
                                <td class="r">{{ fmt_qty($adjustment->base_qty) }}</td>
                                <td>{{ $adjustment->reason }}</td>
                                <td><x-stamp :status="$adjustment->status" /></td>
                                <td>
                                    @can('procurement.adjust')
                                        @unless ($adjustment->isReversed())
                                            <form method="POST" action="{{ route('procurement.stock.adjustments.reverse', $adjustment) }}" class="flex items-center gap-1.5">
                                                @csrf
                                                <input name="reason" class="input w-32" placeholder="{{ __('Reason') }}" aria-label="{{ __('Reason') }}" required>
                                                <button class="btn btn-ghost">{{ __('Reverse') }}</button>
                                            </form>
                                        @endunless
                                    @endcan
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            </section>
        @endif
    </div>
@endsection
