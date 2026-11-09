@extends('layouts.app')
@section('title', __('Use of material'))
@section('content')
    <x-page-header :title="__('Use of material')" :subtitle="$project->name" />

    @include('modules.material_control.partials.tabs')

    @can('material_control.consume')
        <form method="POST" action="{{ route('material_control.consumption.store', $project) }}" class="card mb-5 max-w-xl space-y-4 p-4"
              x-data="{
                  materials: @js($materialOptions),
                  material: @js((string) old('material_id')),
                  unit: @js((string) old('unit_id')),
                  extra: @js((bool) old('extra_work')),
                  get chosen() { return this.materials.find(m => String(m.id) === String(this.material)); },
              }">
            @csrf
            <input type="hidden" name="client_uuid" value="{{ old('client_uuid', (string) \Illuminate\Support\Str::uuid()) }}">

            <x-field :label="__('Material')" name="material_id">
                <select id="material_id" name="material_id" class="input min-h-12 text-base" x-model="material" @change="unit = ''" required>
                    <option value="">{{ __('Choose') }}</option>
                    @foreach ($materialOptions as $option)
                        <option value="{{ $option['id'] }}" @selected((string) old('material_id') === (string) $option['id'])>{{ $option['label'] }}</option>
                    @endforeach
                </select>
            </x-field>

            <div class="grid grid-cols-2 gap-3">
                <x-field :label="__('Quantity used')" name="qty">
                    <input id="qty" name="qty" type="number" inputmode="decimal" step="0.001" min="0.001" value="{{ old('qty') }}" class="input num min-h-12 text-base" required>
                </x-field>
                <x-field :label="__('Unit')" name="unit_id">
                    <select id="unit_id" name="unit_id" class="input min-h-12 text-base" x-model="unit">
                        <option value="" x-text="chosen ? chosen.base_unit : '—'"></option>
                        <template x-for="u in (chosen ? chosen.units : [])" :key="u.id"><option :value="u.id" x-text="u.code"></option></template>
                    </select>
                </x-field>
            </div>

            <x-field :label="__('BOQ item')" name="boq_item_id">
                <select id="boq_item_id" name="boq_item_id" class="input min-h-12 text-base" :required="!extra">
                    <option value="">{{ __('Choose') }}</option>
                    @foreach ($boqItems as $boq)
                        <option value="{{ $boq->id }}" @selected((string) old('boq_item_id') === (string) $boq->id)>{{ $boq->code }} · {{ \Illuminate\Support\Str::limit($boq->description, 50) }} ({{ $boq->unit }})</option>
                    @endforeach
                </select>
            </x-field>

            <div class="grid grid-cols-2 gap-3">
                <x-field :label="__('Stage')" name="stage">
                    <input id="stage" name="stage" list="stage-options" value="{{ old('stage') }}" maxlength="160" class="input min-h-12 text-base" placeholder="{{ __('Slab, 2nd floor') }}">
                    <datalist id="stage-options">@foreach ($stages as $stage)<option value="{{ $stage }}"></option>@endforeach</datalist>
                </x-field>
                <x-field :label="__('Date')" name="used_on">
                    <input id="used_on" name="used_on" type="date" max="{{ today()->format('Y-m-d') }}" value="{{ old('used_on', today()->format('Y-m-d')) }}" class="input min-h-12 text-base" required>
                </x-field>
            </div>

            @if ($stores->count() > 1)
                <x-field :label="__('From store')" name="store_id">
                    <select id="store_id" name="store_id" class="input min-h-12 text-base">
                        @foreach ($stores as $store)<option value="{{ $store->id }}" @selected((string) old('store_id') === (string) $store->id)>{{ $store->name }}</option>@endforeach
                    </select>
                </x-field>
            @endif

            <div>
                <label class="flex min-h-11 items-center gap-2 text-base font-semibold"><input type="checkbox" class="size-5" x-model="extra"> {{ __('This was extra work, not in the BOQ') }}</label>
                <div x-show="extra" x-cloak class="mt-2">
                    <x-field :label="__('What was the extra work?')" name="extra_work">
                        <textarea id="extra_work" name="extra_work" rows="2" maxlength="500" class="input text-base" :disabled="!extra" :required="extra">{{ old('extra_work') }}</textarea>
                    </x-field>
                </div>
            </div>

            <button class="btn btn-primary min-h-12 w-full text-base sm:w-auto">{{ __('Save') }}</button>
        </form>
    @endcan

    <section class="card">
        <div class="card-head"><h2 class="card-title">{{ __('Recent use') }}</h2></div>
        @forelse ($recent as $line)
            <div class="flex items-start justify-between gap-3 border-b border-rule px-4 py-3 last:border-b-0">
                <div class="min-w-0">
                    <p class="truncate font-semibold">{{ $line->material->name }}</p>
                    <p class="truncate text-sm text-ink-2">{{ $line->boqItem?->code ?? $line->activity ?? '—' }}@if ($line->boqItem && $line->activity) · {{ $line->activity }}@endif</p>
                    <p class="num mt-0.5 text-xs text-ink-3">{{ $line->issue->issue_date->format('d M Y') }} · {{ $line->issue->creator?->name ?? '—' }}</p>
                </div>
                <div class="shrink-0 text-right">
                    <p class="num font-medium">{{ fmt_qty($line->qty) }} {{ $line->unit->code }}</p>
                    @if ($line->issue->status === 'REVERSED')<p class="mt-1"><x-stamp :status="$line->issue->status" /></p>@endif
                    @can('procurement.view')
                        <a href="{{ route('procurement.issues.show', $line->issue) }}" class="link text-xs">{{ __('Open') }}</a>
                    @endcan
                </div>
            </div>
        @empty
            <x-empty :title="__('No material used yet')" />
        @endforelse
    </section>
    <div class="mt-4">{{ $recent->links() }}</div>
@endsection
