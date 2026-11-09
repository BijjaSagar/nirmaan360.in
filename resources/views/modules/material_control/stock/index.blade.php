@extends('layouts.app')
@section('title', __('Stock on site'))
@section('content')
    <x-page-header :title="__('Stock on site')" :subtitle="$project->name" />

    @include('modules.material_control.partials.tabs')

    @can('material_control.count')
        <form method="POST" action="{{ route('material_control.stock.store', $project) }}" class="card mb-5 max-w-xl space-y-4 p-4"
              x-data="{
                  materials: @js($materialOptions),
                  material: @js((string) old('material_id')),
                  unit: @js((string) old('unit_id')),
                  get chosen() { return this.materials.find(m => String(m.id) === String(this.material)); },
              }">
            @csrf
            <input type="hidden" name="client_uuid" value="{{ old('client_uuid', (string) \Illuminate\Support\Str::uuid()) }}">
            <h2 class="card-title">{{ __('Physical stock check') }}</h2>

            <x-field :label="__('Material')" name="material_id">
                <select id="material_id" name="material_id" class="input min-h-12 text-base" x-model="material" @change="unit = ''" required>
                    <option value="">{{ __('Choose') }}</option>
                    @foreach ($materialOptions as $option)
                        <option value="{{ $option['id'] }}" @selected((string) old('material_id') === (string) $option['id'])>{{ $option['label'] }}</option>
                    @endforeach
                </select>
            </x-field>

            <div class="grid grid-cols-2 gap-3">
                <x-field :label="__('Counted quantity')" name="physical_qty">
                    <input id="physical_qty" name="physical_qty" type="number" inputmode="decimal" step="0.001" min="0" value="{{ old('physical_qty') }}" class="input num min-h-12 text-base" required>
                </x-field>
                <x-field :label="__('Unit')" name="unit_id">
                    <select id="unit_id" name="unit_id" class="input min-h-12 text-base" x-model="unit">
                        <option value="" x-text="chosen ? chosen.base_unit : '—'"></option>
                        <template x-for="u in (chosen ? chosen.units : [])" :key="u.id"><option :value="u.id" x-text="u.code"></option></template>
                    </select>
                </x-field>
            </div>

            <div class="grid grid-cols-2 gap-3">
                <x-field :label="__('Date')" name="check_date">
                    <input id="check_date" name="check_date" type="date" max="{{ today()->format('Y-m-d') }}" value="{{ old('check_date', today()->format('Y-m-d')) }}" class="input min-h-12 text-base" required>
                </x-field>
                @if ($stores->count() > 1)
                    <x-field :label="__('Store')" name="store_id">
                        <select id="store_id" name="store_id" class="input min-h-12 text-base">
                            @foreach ($stores as $store)<option value="{{ $store->id }}" @selected((string) old('store_id') === (string) $store->id)>{{ $store->name }}</option>@endforeach
                        </select>
                    </x-field>
                @endif
            </div>

            <x-field :label="__('Note')" name="remarks"><input id="remarks" name="remarks" maxlength="500" value="{{ old('remarks') }}" class="input min-h-12 text-base"></x-field>

            <p class="hint">{{ __('Stock is corrected to what you counted. Any missing quantity shows as unaccounted.') }}</p>
            <button class="btn btn-primary min-h-12 w-full text-base sm:w-auto">{{ __('Save count') }}</button>
        </form>
    @endcan

    <section class="card mb-5">
        <div class="card-head"><h2 class="card-title">{{ __('In stock now') }}</h2></div>
        @forelse ($balances as $row)
            <div class="flex items-center justify-between gap-3 border-b border-rule px-4 py-3 last:border-b-0">
                <div class="min-w-0">
                    <p class="truncate font-semibold">{{ $row['material']['label'] }}</p>
                    <p class="truncate text-xs text-ink-3">{{ $row['store']->name }}</p>
                </div>
                <p class="num shrink-0 font-medium">{{ fmt_qty($row['balance']) }} {{ $row['material']['base_unit'] }}</p>
            </div>
        @empty
            <x-empty :title="__('No stock has moved yet')" />
        @endforelse
    </section>

    <section class="card">
        <div class="card-head"><h2 class="card-title">{{ __('Past counts') }}</h2></div>
        @forelse ($checks as $check)
            <div class="flex items-start justify-between gap-3 border-b border-rule px-4 py-3 last:border-b-0">
                <div class="min-w-0">
                    <p class="truncate font-semibold">{{ $check->material->name }}</p>
                    <p class="num mt-0.5 text-xs text-ink-3">{{ $check->check_date->format('d M Y') }} · {{ $check->store->name }} · {{ $check->creator?->name ?? '—' }}</p>
                    @if ($check->remarks)<p class="mt-0.5 text-sm text-ink-2">{{ $check->remarks }}</p>@endif
                </div>
                <div class="shrink-0 text-right">
                    <p class="num font-medium">{{ fmt_qty($check->physical_base_qty) }} {{ $check->material->baseUnit->code }}</p>
                    <p class="num text-xs {{ \App\Support\Dec::of($check->variance_qty)->isZero() ? 'text-ink-3' : 'text-laterite' }}">{{ __('Book :qty', ['qty' => fmt_qty($check->book_qty)]) }}</p>
                    @if ($check->status === 'REVERSED')
                        <x-stamp :status="$check->status" />
                    @elseif (auth()->user()->can('material_control.adjust'))
                        <form method="POST" action="{{ route('material_control.stock.reverse', $check) }}" class="mt-1" onsubmit="return confirm('{{ __('Reverse this count?') }}')">
                            @csrf
                            <input type="hidden" name="reason" value="{{ __('Counted in error') }}">
                            <button class="link text-xs">{{ __('Reverse') }}</button>
                        </form>
                    @endif
                </div>
            </div>
        @empty
            <x-empty :title="__('No counts yet')" />
        @endforelse
    </section>
    <div class="mt-4">{{ $checks->links() }}</div>
@endsection
