{{-- Category, details and the reason, in the edit window of a BOQ line. They sit inside the grid of the form and read the line from the Alpine "edit" value. --}}
<div class="sm:col-span-3">
    <label class="label" for="edit-category">{{ __('Category') }}</label>
    <select id="edit-category" name="category" x-model="edit.category" class="input">
        <option value="">—</option>
        @foreach (\App\Services\ProjectSetup\BoqCategories::ALL as $key => $label)
            <option value="{{ $key }}">{{ __($label) }}</option>
        @endforeach
    </select>
</div>
<div class="sm:col-span-3">
    <label class="label" for="edit-details">{{ __('Details') }}</label>
    <textarea id="edit-details" name="details" x-model="edit.details" rows="3" class="input" maxlength="10000"></textarea>
</div>
<div class="sm:col-span-3 rounded-[3px] border border-haldi/60 bg-haldi/10 p-3">
    <label class="label" for="edit-reason">{{ __('Why is it changing?') }}</label>
    <input id="edit-reason" name="reason" x-model="edit.reason" class="input" required maxlength="500" placeholder="{{ __('For example: client chose a different make') }}">
    <p class="hint">{{ __('The BOQ is never locked. Every change is kept in the history with your name and this reason. Measurements and bills already made do not change.') }}</p>
</div>
