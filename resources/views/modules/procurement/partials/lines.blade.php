{{-- Repeating material lines. Pass $materialOptions, and $boqItems to show BOQ and activity columns. --}}
@php
    $boqItems = $boqItems ?? null;
    $blank = ['material_id' => '', 'qty' => '', 'unit_id' => '', 'boq_item_id' => '', 'activity' => '', 'remarks' => ''];
    $initial = array_values(old('items', $lines ?? [$blank]));
@endphp
<div class="space-y-3" x-data="{
        materials: @js($materialOptions),
        lines: @js($initial),
        blank: @js($blank),
        find(id) { return this.materials.find(m => String(m.id) === String(id)); },
        add() { this.lines.push({ ...this.blank }); },
        remove(i) { if (this.lines.length > 1) this.lines.splice(i, 1); },
    }">
    <template x-for="(line, i) in lines" :key="i">
        <div class="grid gap-3 rounded-[3px] border border-rule p-3 sm:grid-cols-12">
            <div class="sm:col-span-5">
                <label class="label">{{ __('Material') }}</label>
                <select class="input" :name="`items[${i}][material_id]`" x-model="line.material_id" @change="line.unit_id = ''" required>
                    <option value="">{{ __('Choose') }}</option>
                    <template x-for="m in materials" :key="m.id">
                        <option :value="m.id" x-text="m.label" :selected="String(m.id) === String(line.material_id)"></option>
                    </template>
                </select>
            </div>
            <div class="sm:col-span-3">
                <label class="label">{{ __('Quantity') }}</label>
                <input class="input num" type="number" step="0.001" min="0.001" :name="`items[${i}][qty]`" x-model="line.qty" required>
            </div>
            <div class="sm:col-span-3">
                <label class="label">{{ __('Unit') }}</label>
                <select class="input" :name="`items[${i}][unit_id]`" x-model="line.unit_id">
                    <option value="" x-text="find(line.material_id)?.base_unit ?? '—'"></option>
                    <template x-for="u in (find(line.material_id)?.units ?? [])" :key="u.id">
                        <option :value="u.id" x-text="u.code" :selected="String(u.id) === String(line.unit_id)"></option>
                    </template>
                </select>
            </div>
            <div class="flex items-end sm:col-span-1">
                <button type="button" class="btn btn-ghost w-full" @click="remove(i)" :disabled="lines.length === 1" aria-label="{{ __('Remove line') }}">×</button>
            </div>
            @if ($boqItems !== null)
                <div class="sm:col-span-6">
                    <label class="label">{{ __('BOQ item') }}</label>
                    <select class="input" :name="`items[${i}][boq_item_id]`" x-model="line.boq_item_id">
                        <option value="">{{ __('None') }}</option>
                        @foreach ($boqItems as $boq)
                            <option value="{{ $boq->id }}">{{ $boq->code }} · {{ \Illuminate\Support\Str::limit($boq->description, 60) }} ({{ $boq->unit }})</option>
                        @endforeach
                    </select>
                </div>
                @if ($showActivity ?? false)
                    <div class="sm:col-span-6">
                        <label class="label">{{ __('Activity') }}</label>
                        <input class="input" :name="`items[${i}][activity]`" x-model="line.activity" maxlength="160" placeholder="{{ __('Needed if there is no BOQ item') }}">
                    </div>
                @endif
            @endif
        </div>
    </template>
    <button type="button" class="btn btn-ghost" @click="add()">{{ __('Add line') }}</button>
</div>
