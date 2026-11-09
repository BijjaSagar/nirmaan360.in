{{-- Enter a concrete line by the cubic metre (RMC) or by ingredients. $row holds the line and how it is entered now. --}}
@php
    $typed = (string) old('_item') === (string) $item->id;
    $first = fn (string $role) => $row['mix']['rows'][$role] ?? null;
    $state = [
        'mode' => $typed ? old('mode', $row['mix']['mode']) : $row['mix']['mode'],
        'ing' => collect($roles)->map(fn ($label, $role) => [
            'material' => (string) ($typed ? old($role.'_material_id', $first($role)['material_id'] ?? '') : ($first($role)['material_id'] ?? '')),
            'qty' => (string) ($typed ? old($role.'_qty', $first($role)['qty'] ?? '') : ($first($role)['qty'] ?? '')),
            'unit' => (string) ($typed ? old($role.'_unit_id', $first($role)['unit_id'] ?? '') : ($first($role)['unit_id'] ?? '')),
        ])->all(),
    ];
@endphp
<form method="POST" action="{{ route('project-setup.boq-setup.concrete', $item) }}" class="space-y-4"
      x-data="{ mode: @js($state['mode']), ing: @js($state['ing']), units: @js($unitsByMaterial),
                firstUnit(role) { return (this.units[this.ing[role].material] || [])[0]?.id ?? ''; } }">
    @csrf
    <input type="hidden" name="_item" value="{{ $item->id }}">

    <div class="grid gap-2 sm:grid-cols-2">
        <label class="flex min-h-12 cursor-pointer items-center gap-3 rounded-[3px] border p-3" :class="mode === 'rmc' ? 'border-neel bg-neel/5' : 'border-rule'">
            <input type="radio" name="mode" value="rmc" x-model="mode" class="size-5">
            <span><span class="block font-semibold">{{ __('Ready-mix (RMC)') }}</span><span class="block text-sm text-ink-3">{{ __('By the cubic metre, as on the BOQ line') }}</span></span>
        </label>
        <label class="flex min-h-12 cursor-pointer items-center gap-3 rounded-[3px] border p-3" :class="mode === 'ingredients' ? 'border-neel bg-neel/5' : 'border-rule'">
            <input type="radio" name="mode" value="ingredients" x-model="mode" class="size-5">
            <span><span class="block font-semibold">{{ __('Mixed on site') }}</span><span class="block text-sm text-ink-3">{{ __('By cement, aggregate and sand') }}</span></span>
        </label>
    </div>

    <div x-show="mode === 'ingredients'" x-cloak class="space-y-3">
        <p class="text-sm text-ink-3">{{ __('Enter the total for the whole item, for example 100 bags of cement and 4 brass of sand. For a unit like brass, the material needs that unit added under its conversions.') }}</p>
        @foreach ($roles as $role => $label)
            <div class="rounded-[3px] border border-rule bg-paper/40 p-3">
                <p class="eyebrow mb-2 text-ink-3">{{ __($label) }}</p>
                <div class="grid gap-2 sm:grid-cols-3">
                    <div class="sm:col-span-3">
                        <label class="label" for="{{ $role }}-material-{{ $item->id }}">{{ __('Material') }}</label>
                        <select id="{{ $role }}-material-{{ $item->id }}" name="{{ $role }}_material_id" class="input min-h-11" x-model="ing.{{ $role }}.material" @change="ing.{{ $role }}.unit = firstUnit('{{ $role }}')" :disabled="mode !== 'ingredients'">
                            <option value="">—</option>
                            @foreach ($materials as $material)
                                <option value="{{ $material->id }}">{{ $material->name }}</option>
                            @endforeach
                        </select>
                        @if ($typed)@error($role.'_material_id')<p class="error">{{ $message }}</p>@enderror @endif
                    </div>
                    <div class="sm:col-span-2">
                        <label class="label" for="{{ $role }}-qty-{{ $item->id }}">{{ __('Total quantity') }}</label>
                        <input id="{{ $role }}-qty-{{ $item->id }}" name="{{ $role }}_qty" type="number" inputmode="decimal" step="0.001" min="0" class="input num min-h-11" x-model="ing.{{ $role }}.qty" :disabled="mode !== 'ingredients'">
                        @if ($typed)@error($role.'_qty')<p class="error">{{ $message }}</p>@enderror @endif
                    </div>
                    <div>
                        <label class="label" for="{{ $role }}-unit-{{ $item->id }}">{{ __('Unit') }}</label>
                        <select id="{{ $role }}-unit-{{ $item->id }}" name="{{ $role }}_unit_id" class="input min-h-11" x-model="ing.{{ $role }}.unit" :disabled="mode !== 'ingredients'">
                            <template x-for="unit in (units[ing.{{ $role }}.material] || [])" :key="unit.id">
                                <option :value="String(unit.id)" x-text="unit.code" :selected="String(unit.id) === ing.{{ $role }}.unit"></option>
                            </template>
                        </select>
                        @if ($typed)@error($role.'_unit_id')<p class="error">{{ $message }}</p>@enderror @endif
                    </div>
                </div>
            </div>
        @endforeach
        <p class="hint">{{ __('The ingredients are kept per cubic metre of the item, so they follow it if its quantity is revised.') }}</p>
    </div>

    <div>
        <label class="label" for="mix-reason-{{ $item->id }}">{{ __('Why is it changing?') }}</label>
        <input id="mix-reason-{{ $item->id }}" name="reason" value="{{ $typed ? old('reason') : '' }}" class="input min-h-11" required maxlength="500" placeholder="{{ __('For example: we will mix on site for this slab') }}">
        @if ($typed)@error('reason')<p class="error">{{ $message }}</p>@enderror @endif
        @if ($typed)@error('mode')<p class="error">{{ $message }}</p>@enderror @endif
    </div>

    <button class="btn btn-primary min-h-12 px-5 text-base">{{ __('Save concrete') }}</button>
</form>
