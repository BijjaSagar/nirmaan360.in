{{-- Remove a BOQ line, under the edit form. A separate form, because forms cannot be nested. --}}
<form method="POST" :action="edit.removeUrl" class="border-t border-rule bg-paper/50 px-4 py-3" x-data="{ open: false }">
    @csrf @method('DELETE')
    <button type="button" class="link text-sm text-laterite" x-show="! open" @click="open = true">{{ __('Remove this item') }}</button>
    <div x-show="open" x-cloak class="space-y-2">
        <label class="label" for="remove-reason">{{ __('Why is it being removed?') }}</label>
        <input id="remove-reason" name="reason" class="input" maxlength="500" :required="open" :disabled="! open">
        <p class="hint">{{ __('An item with measurements or bills cannot be removed. The removal is kept in the history.') }}</p>
        <div class="flex justify-end gap-2">
            <button type="button" class="btn btn-ghost" @click="open = false">{{ __('Keep it') }}</button>
            <button class="btn btn-danger">{{ __('Remove item') }}</button>
        </div>
    </div>
</form>
