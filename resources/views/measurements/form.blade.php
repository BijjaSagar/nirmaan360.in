@extends('layouts.app')
@section('title', $entry->exists ? __('Edit measurement') : __('New measurement'))
@section('content')
    <x-page-header :title="$entry->exists ? __('Edit measurement') : __('New measurement')" :subtitle="$project->name" />

    <form method="POST" action="{{ $entry->exists ? route('measurements.update', $entry) : route('measurements.store', $project) }}"
          class="max-w-3xl"
          x-data="{
              nos: @js((string) old('nos', $entry->nos ?? 1)),
              l: @js((string) old('length', $entry->length)),
              b: @js((string) old('breadth', $entry->breadth)),
              d: @js((string) old('depth', $entry->depth)),
              less: @js((bool) old('is_deduction', $entry->is_deduction)),
              get qty() {
                  let q = parseFloat(this.nos) || 0;
                  for (const v of [this.l, this.b, this.d]) { if (v !== '' && v !== null) { q *= parseFloat(v) || 0; } }
                  return (this.less ? -q : q).toFixed(3);
              },
          }">
        @csrf
        @if ($entry->exists) @method('PATCH') @endif
        @if ($clientUuid)<input type="hidden" name="client_uuid" value="{{ $clientUuid }}">@endif

        <section class="card">
            <div class="grid gap-4 p-4 sm:grid-cols-2">
                <x-field :label="__('BOQ item')" name="boq_item_id" class="sm:col-span-2">
                    <select id="boq_item_id" name="boq_item_id" class="input" required>
                        <option value="">{{ __('Choose an item') }}</option>
                        @foreach ($boqItems as $item)
                            <option value="{{ $item->id }}" @selected(old('boq_item_id', $entry->boq_item_id) == $item->id)>{{ $item->code }} · {{ \Illuminate\Support\Str::limit($item->description, 80) }} ({{ $item->unit }})</option>
                        @endforeach
                    </select>
                </x-field>
                <x-field :label="__('Measured on')" name="measured_on">
                    <input id="measured_on" name="measured_on" type="date" max="{{ now()->format('Y-m-d') }}" value="{{ old('measured_on', $entry->measured_on?->format('Y-m-d')) }}" class="input" required>
                </x-field>
                <x-field :label="__('Location')" name="location" :hint="__('Grid, floor or chainage.')">
                    <input id="location" name="location" value="{{ old('location', $entry->location) }}" class="input">
                </x-field>
                <x-field :label="__('Description')" name="description" class="sm:col-span-2">
                    <input id="description" name="description" value="{{ old('description', $entry->description) }}" class="input">
                </x-field>
                @if ($workOrders->isNotEmpty())
                    <x-field :label="__('Work order (for subcontractor billing)')" name="work_order_id" class="sm:col-span-2">
                        <select id="work_order_id" name="work_order_id" class="input">
                            <option value="">—</option>
                            @foreach ($workOrders as $workOrder)
                                <option value="{{ $workOrder->id }}" @selected(old('work_order_id', $entry->work_order_id) == $workOrder->id)>{{ $workOrder->number }} · {{ $workOrder->party->name }}</option>
                            @endforeach
                        </select>
                    </x-field>
                @endif
            </div>

            <div class="border-t border-rule bg-paper/50 p-4">
                <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
                    <x-field :label="__('Nos')" name="nos"><input id="nos" name="nos" x-model="nos" type="number" step="0.001" min="0" class="input num" required></x-field>
                    <x-field :label="__('Length')" name="length"><input id="length" name="length" x-model="l" type="number" step="0.001" min="0" class="input num"></x-field>
                    <x-field :label="__('Breadth')" name="breadth"><input id="breadth" name="breadth" x-model="b" type="number" step="0.001" min="0" class="input num"></x-field>
                    <x-field :label="__('Depth')" name="depth"><input id="depth" name="depth" x-model="d" type="number" step="0.001" min="0" class="input num"></x-field>
                </div>
                <div class="mt-3 flex flex-wrap items-center justify-between gap-3">
                    <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="is_deduction" value="1" x-model="less"> {{ __('Deduction (openings, voids)') }}</label>
                    <p class="text-right">
                        <span class="eyebrow text-ink-3">{{ __('Quantity') }}</span>
                        <span class="num ml-2 text-2xl font-medium" :class="qty < 0 && 'text-laterite'" x-text="qty"></span>
                    </p>
                </div>
            </div>

            <div class="border-t border-rule p-4">
                <x-field :label="__('Remarks')" name="remarks"><textarea id="remarks" name="remarks" rows="2" class="input">{{ old('remarks', $entry->remarks) }}</textarea></x-field>
            </div>
        </section>

        <button class="btn btn-primary mt-4">{{ $entry->exists ? __('Save changes') : __('Save draft') }}</button>
    </form>
@endsection
