{{-- Tags typed with commas, with the company's existing tags offered as buttons. --}}
<div x-data="{
        value: @js(old('tags', implode(', ', $tags ?? []))),
        list() { return this.value.split(',').map(t => t.trim().toLowerCase()).filter(Boolean) },
        add(tag) { if (! this.list().includes(tag)) { this.value = this.list().concat(tag).join(', ') } },
     }">
    <label class="label" for="tags">{{ __('Tags') }}</label>
    <input id="tags" name="tags" x-model="value" class="input" maxlength="500" placeholder="{{ __('e.g. highway, priority') }}">
    @if (! empty($tagOptions))
        <div class="mt-1.5 flex flex-wrap gap-1">
            @foreach ($tagOptions as $option)
                <button type="button" @click="add(@js($option))" class="inline-flex min-h-7 items-center rounded-full border border-rule bg-sheet px-2.5 text-[11px] text-ink-2 hover:border-ink-3">+ {{ $option }}</button>
            @endforeach
        </div>
    @endif
    <p class="hint">{{ __('Separate tags with commas.') }}</p>
    @error('tags')<p class="error">{{ $message }}</p>@enderror
</div>
