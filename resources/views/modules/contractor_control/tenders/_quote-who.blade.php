{{--
    Who is quoting: a contractor from the list, or a new one added in the popup (_contractor-popup) without leaving the page.
    A page can hold several quote forms, so ids carry $prefix, and old input and errors show only in the form that was sent ($active).
--}}
<div class="{{ $class ?? '' }}">
    <label class="label" for="{{ $prefix }}-party">{{ __('Contractor') }}</label>
    <div class="flex gap-2">
        <select id="{{ $prefix }}-party" name="party_id" class="input min-w-0" data-contractor-select required>
            <option value="">{{ __('Choose') }}</option>
            @foreach ($contractors as $contractor)
                <option value="{{ $contractor->id }}" @selected($active && (string) old('party_id') === (string) $contractor->id)>{{ $contractor->name }}</option>
            @endforeach
        </select>
        <button type="button" class="btn btn-ghost shrink-0" @click="show('{{ $prefix }}-party', $el)"><x-icon name="plus" class="size-4" />{{ __('Add contractor') }}</button>
    </div>
    <p class="hint" x-show="note['{{ $prefix }}-party']" x-text="note['{{ $prefix }}-party']" x-cloak></p>
    @if ($active)
        @error('party_id')<p class="error">{{ $message }}</p>@enderror
    @endif
</div>
