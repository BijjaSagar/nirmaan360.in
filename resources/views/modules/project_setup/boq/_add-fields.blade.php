{{-- Category and details of a new BOQ line. Inside the grid of the add form. --}}
<x-field :label="__('Category')" name="category" class="sm:col-span-2">
    <select id="category" name="category" class="input">
        <option value="">—</option>
        @foreach (\App\Services\ProjectSetup\BoqCategories::ALL as $key => $label)
            <option value="{{ $key }}" @selected(old('category') === $key)>{{ __($label) }}</option>
        @endforeach
    </select>
</x-field>
<x-field :label="__('Details')" name="details" class="sm:col-span-4" :hint="__('Describe the item in full: make, grade, thickness, how it is measured.')">
    <textarea id="details" name="details" rows="3" class="input" maxlength="10000">{{ old('details') }}</textarea>
</x-field>
