{{-- The fields of one permission, for the add form and for the edit form of each. $permit is null when adding. --}}
@php
    $statuses = ['not_started' => __('Not started'), 'applied' => __('Applied'), 'approved' => __('Approved'), 'rejected' => __('Rejected')];
    // After a failed save only the form that was sent shows what was typed; the others keep their saved values.
    $typed = ! $permit || (string) old('_permit') === (string) $permit->id;
    $value = fn (string $field, $default = null) => $typed ? old($field, $permit?->{$field} ?? $default) : ($permit?->{$field} ?? $default);
    $date = fn (string $field) => $typed ? old($field, $permit?->{$field}?->format('Y-m-d')) : $permit?->{$field}?->format('Y-m-d');
    $id = fn (string $field) => $permit ? 'permit-'.$permit->id.'-'.$field : $field;
    $name = fn (string $field) => $permit ? null : $field;
@endphp
<x-field :label="__('Permission')" :name="$name('name')" class="sm:col-span-2">
    <input id="{{ $id('name') }}" name="name" value="{{ $value('name') }}" class="input min-h-11" required maxlength="160" placeholder="{{ __('For example Building plan sanction') }}">
</x-field>
<x-field :label="__('Authority')" :name="$name('authority')">
    <input id="{{ $id('authority') }}" name="authority" value="{{ $value('authority') }}" class="input min-h-11" maxlength="160" placeholder="{{ __('For example Municipal corporation') }}">
</x-field>
<x-field :label="__('Status')" :name="$name('status')">
    <select id="{{ $id('status') }}" name="status" class="input min-h-11">
        @foreach ($statuses as $status => $label)
            <option value="{{ $status }}" @selected($value('status', 'not_started') === $status)>{{ $label }}</option>
        @endforeach
    </select>
</x-field>
<x-field :label="__('Applied on')" :name="$name('applied_on')">
    <input id="{{ $id('applied_on') }}" name="applied_on" type="date" value="{{ $date('applied_on') }}" class="input min-h-11">
</x-field>
<x-field :label="__('Approved on')" :name="$name('approved_on')">
    <input id="{{ $id('approved_on') }}" name="approved_on" type="date" value="{{ $date('approved_on') }}" class="input min-h-11">
</x-field>
@can('money.overview')
    <x-field :label="__('Fee (₹)')" :name="$name('fee')">
        <input id="{{ $id('fee') }}" name="fee" type="number" inputmode="decimal" step="0.01" min="0" value="{{ $value('fee') }}" class="input num min-h-11">
    </x-field>
@endcan
<x-field :label="__('Documents required')" :name="$name('documents_required')" class="sm:col-span-2">
    <textarea id="{{ $id('documents_required') }}" name="documents_required" rows="2" class="input" maxlength="2000" placeholder="{{ __('For example site plan, ownership papers, structural drawings') }}">{{ $value('documents_required') }}</textarea>
</x-field>
<x-field :label="__('Remarks')" :name="$name('remarks')" class="sm:col-span-2">
    <textarea id="{{ $id('remarks') }}" name="remarks" rows="2" class="input" maxlength="2000">{{ $value('remarks') }}</textarea>
</x-field>
