{{-- BOQ lines to tick. $ticked: ids that start ticked. --}}
<div x-data="{ q: '' }">
    <div class="card-head flex-wrap"><h2 class="card-title">{{ __('BOQ lines') }}</h2><input x-model="q" type="search" class="input sm:w-64" placeholder="{{ __('Search lines') }}" aria-label="{{ __('Search lines') }}"></div>
    <p class="px-4 pt-3 text-xs text-ink-3">{{ __('Each line is copied as it stands in the BOQ now: description, quantity and rate. A later change in the BOQ does not change this quotation.') }}</p>
    @error('boq_item_ids')<p class="error px-4 pt-2">{{ $message }}</p>@enderror
    @foreach ($boqItems as $item)
        <label class="flex cursor-pointer items-start gap-3 border-b border-rule px-4 py-2.5 last:border-b-0 hover:bg-paper/70" x-show="!q || @js(mb_strtolower($item->code.' '.$item->description)).includes(q.toLowerCase())">
            <input type="checkbox" name="boq_item_ids[]" value="{{ $item->id }}" class="mt-1" @checked(in_array($item->id, array_map('intval', (array) old('boq_item_ids', $ticked)), true))>
            <span class="min-w-0 flex-1 text-sm"><span class="num text-xs text-ink-3">{{ $item->code }}</span> {{ $item->description }}
                <span class="num mt-0.5 block text-xs text-ink-3">{{ fmt_qty($item->qty) }} {{ $item->unit }} × {{ inr($item->rate) }} = {{ inr($item->amount()) }}</span>
            </span>
        </label>
    @endforeach
</div>
