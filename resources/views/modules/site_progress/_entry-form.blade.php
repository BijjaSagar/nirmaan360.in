@php
    $lockFloor = $lockFloor ?? false;
    $choices = $items->map(fn (array $item) => [
        'id' => (string) $item['id'],
        'label' => $item['code'].' · '.\Illuminate\Support\Str::limit($item['description'], 70),
        'unit' => $item['unit'],
        'qty' => fmt_qty($item['qty']),
        'done' => fmt_qty($item['done_qty']),
        'percent' => \App\Services\SiteProgress\ProgressReport::show($item['percent']),
    ])->values();
    $selectedFloor = (string) old('project_floor_id', $floorId ?? '');
    $knownFloors = collect($floorOptions)->pluck('id')->map(fn ($id) => (string) $id);
    $selectedFloor = $knownFloors->contains($selectedFloor) ? $selectedFloor : '';
@endphp
<form method="POST" action="{{ route('site-progress.entries.store', $project) }}" enctype="multipart/form-data" class="card space-y-4 p-4"
      x-data="{
          items: @js($choices),
          query: '',
          itemId: @js((string) old('boq_item_id', '')),
          pending: @js((bool) old('is_pending')),
          busy: false,
          get shown() { const q = this.query.trim().toLowerCase(); return this.items.filter(i => i.id === this.itemId || q === '' || i.label.toLowerCase().includes(q)); },
          get chosen() { return this.items.find(i => i.id === this.itemId) || null; },
      }"
      @submit="busy = true">
    @csrf
    <input type="hidden" name="client_uuid" value="{{ $clientUuid }}">

    <h2 class="card-title">{{ __('Add work done') }}</h2>

    <x-field :label="__('BOQ item')" name="boq_item_id">
        @if ($choices->count() > 12)
            <input type="search" x-model="query" class="input mb-2 min-h-12 text-base" placeholder="{{ __('Search by code or name') }}" aria-label="{{ __('Search by code or name') }}" autocomplete="off">
        @endif
        <select id="boq_item_id" name="boq_item_id" x-model="itemId" class="input min-h-12 text-base" required>
            <option value="">{{ __('Choose the work') }}</option>
            <template x-for="item in shown" :key="item.id"><option :value="item.id" x-text="item.label"></option></template>
        </select>
        <p class="hint" x-show="chosen" x-cloak>
            <span x-text="chosen ? @js(__('Done so far')) + ': ' + chosen.done + ' / ' + chosen.qty + ' ' + chosen.unit + ' (' + chosen.percent + ')' : ''"></span>
        </p>
    </x-field>

    @if ($lockFloor)
        <input type="hidden" name="project_floor_id" value="{{ $selectedFloor }}">
    @else
        <x-field :label="__('Floor')" name="project_floor_id">
            <select id="project_floor_id" name="project_floor_id" class="input min-h-12 text-base">
                <option value="">{{ __('Whole project (no floor)') }}</option>
                @foreach ($floorOptions as $option)
                    <option value="{{ $option['id'] }}" @selected($selectedFloor === (string) $option['id'])>{{ $option['label'] }}</option>
                @endforeach
            </select>
        </x-field>
    @endif

    <x-field :label="__('Quantity done')" name="qty_done">
        <div class="flex items-center gap-2">
            <input id="qty_done" name="qty_done" type="number" inputmode="decimal" step="0.001" min="0" value="{{ old('qty_done') }}" class="input num min-h-14 flex-1 text-2xl" placeholder="0" :required="!pending">
            <span class="num w-14 shrink-0 text-base text-ink-2" x-text="chosen ? chosen.unit : ''"></span>
        </div>
    </x-field>

    <x-field :label="__('Remark')" name="remark">
        <textarea id="remark" name="remark" rows="2" maxlength="1000" class="input text-base" placeholder="{{ __('For example: outside passage wiring not done') }}" :required="pending">{{ old('remark') }}</textarea>
    </x-field>

    <label class="flex min-h-12 cursor-pointer items-center gap-3 rounded-[3px] border border-rule bg-paper px-3 py-2" :class="pending ? 'border-laterite bg-laterite/10' : ''">
        <input type="checkbox" name="is_pending" value="1" x-model="pending" class="size-6 shrink-0 accent-[#b4451f]">
        <span class="text-[15px] font-semibold">{{ __('Something is left undone') }}
            <span class="block text-xs font-normal text-ink-3">{{ __('It will show in the pending list until someone marks it finished.') }}</span>
        </span>
    </label>

    <x-field :label="__('Photos')" name="photos">
        <input id="photos" name="photos[]" type="file" accept="image/*" multiple class="input min-h-12 text-base">
        @error('photos.*')<p class="error">{{ $message }}</p>@enderror
    </x-field>

    <details class="text-sm" @if (old('work_date')) open @endif>
        <summary class="cursor-pointer py-1 font-semibold text-ink-2">{{ __('Entering for an earlier day?') }}</summary>
        <x-field :label="__('Date of work')" name="work_date" class="mt-2">
            <input id="work_date" name="work_date" type="date" max="{{ now()->format('Y-m-d') }}" value="{{ old('work_date') }}" class="input min-h-12 text-base">
        </x-field>
    </details>

    <button class="btn btn-primary min-h-12 w-full text-base" :disabled="busy">{{ __('Save') }}</button>
</form>
