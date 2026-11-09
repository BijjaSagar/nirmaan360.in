@extends('layouts.app')
@section('title', __('Extra work'))
@section('content')
    <x-page-header :title="__('Extra work')" :subtitle="$project->name" />

    @include('modules.material_control.partials.tabs')

    @can('material_control.extra')
        <form method="POST" action="{{ route('material_control.extra.store', $project) }}" class="card mb-5 max-w-xl space-y-4 p-4"
              x-data="{
                  materials: @js($materialOptions),
                  material: @js((string) old('material_id')),
                  unit: @js((string) old('unit_id')),
                  get chosen() { return this.materials.find(m => String(m.id) === String(this.material)); },
              }">
            @csrf
            <input type="hidden" name="client_uuid" value="{{ old('client_uuid', (string) \Illuminate\Support\Str::uuid()) }}">
            <p class="text-sm text-ink-2">{{ __('Say what the extra work was, so its material is not counted as wastage.') }}</p>

            <x-field :label="__('What was the extra work?')" name="description">
                <textarea id="description" name="description" rows="2" maxlength="500" class="input text-base" required>{{ old('description') }}</textarea>
            </x-field>

            <x-field :label="__('Material')" name="material_id">
                <select id="material_id" name="material_id" class="input min-h-12 text-base" x-model="material" @change="unit = ''" required>
                    <option value="">{{ __('Choose') }}</option>
                    @foreach ($materialOptions as $option)
                        <option value="{{ $option['id'] }}" @selected((string) old('material_id') === (string) $option['id'])>{{ $option['label'] }}</option>
                    @endforeach
                </select>
            </x-field>

            <div class="grid grid-cols-2 gap-3">
                <x-field :label="__('Quantity')" name="qty">
                    <input id="qty" name="qty" type="number" inputmode="decimal" step="0.001" min="0.001" value="{{ old('qty') }}" class="input num min-h-12 text-base" required>
                </x-field>
                <x-field :label="__('Unit')" name="unit_id">
                    <select id="unit_id" name="unit_id" class="input min-h-12 text-base" x-model="unit">
                        <option value="" x-text="chosen ? chosen.base_unit : '—'"></option>
                        <template x-for="u in (chosen ? chosen.units : [])" :key="u.id"><option :value="u.id" x-text="u.code"></option></template>
                    </select>
                </x-field>
            </div>

            <div class="grid gap-3 sm:grid-cols-2">
                <x-field :label="__('Date')" name="work_date">
                    <input id="work_date" name="work_date" type="date" max="{{ today()->format('Y-m-d') }}" value="{{ old('work_date', today()->format('Y-m-d')) }}" class="input min-h-12 text-base" required>
                </x-field>
                <x-field :label="__('BOQ item, if any')" name="boq_item_id">
                    <select id="boq_item_id" name="boq_item_id" class="input min-h-12 text-base">
                        <option value="">{{ __('None') }}</option>
                        @foreach ($boqItems as $boq)<option value="{{ $boq->id }}" @selected((string) old('boq_item_id') === (string) $boq->id)>{{ $boq->code }} · {{ \Illuminate\Support\Str::limit($boq->description, 50) }}</option>@endforeach
                    </select>
                </x-field>
            </div>

            <button class="btn btn-primary min-h-12 w-full text-base sm:w-auto">{{ __('Save') }}</button>
        </form>
    @endcan

    <section class="card">
        <div class="card-head"><h2 class="card-title">{{ __('Recorded extra work') }}</h2></div>
        @forelse ($works as $work)
            <div class="flex items-start justify-between gap-3 border-b border-rule px-4 py-3 last:border-b-0">
                <div class="min-w-0">
                    <p class="font-semibold">{{ $work->description }}</p>
                    <p class="truncate text-sm text-ink-2">{{ $work->material->name }}@if ($work->boqItem) · {{ $work->boqItem->code }}@endif</p>
                    <p class="num mt-0.5 text-xs text-ink-3">{{ $work->work_date->format('d M Y') }} · {{ $work->creator?->name ?? '—' }}</p>
                </div>
                <div class="shrink-0 text-right">
                    <p class="num font-medium">{{ fmt_qty($work->qty) }} {{ $work->unit->code }}</p>
                    @if ($work->status === 'REVERSED')
                        <x-stamp :status="$work->status" />
                    @elseif (auth()->user()->can('material_control.adjust'))
                        <form method="POST" action="{{ route('material_control.extra.reverse', $work) }}" class="mt-1" onsubmit="return confirm('{{ __('Reverse this extra work?') }}')">
                            @csrf
                            <input type="hidden" name="reason" value="{{ __('Entered in error') }}">
                            <button class="link text-xs">{{ __('Reverse') }}</button>
                        </form>
                    @endif
                </div>
            </div>
        @empty
            <x-empty :title="__('No extra work recorded')" />
        @endforelse
    </section>
    <div class="mt-4">{{ $works->links() }}</div>
@endsection
