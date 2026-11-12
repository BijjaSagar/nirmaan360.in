{{-- The fields of one payment stage. $stage is null on the form that adds one; $key is its place in the order; $button is the form's own button. --}}
@php
    $adding = $stage === null;
    $value = fn (string $field, mixed $default = null) => $adding && old('_form') === 'add-stage' ? old($field, $default) : ($stage?->{$field} ?? $default);
    $percent = $value('percent');
@endphp
<div x-data="{ basis: @js($value('basis', \App\Models\ClientPaymentStage::PERCENT)) }" class="grid items-end gap-3 sm:grid-cols-6">
    <x-field :label="__('Stage')" class="sm:col-span-2">
        <input name="title" value="{{ $value('title') }}" maxlength="160" class="input" placeholder="{{ __('For example: On completion of plinth') }}" aria-label="{{ __('Stage') }}" required>
    </x-field>
    <x-field :label="__('Worked out as')">
        <select name="basis" class="input" x-model="basis" aria-label="{{ __('Worked out as') }}">
            <option value="{{ \App\Models\ClientPaymentStage::PERCENT }}">{{ __('A share of the total') }}</option>
            <option value="{{ \App\Models\ClientPaymentStage::AMOUNT }}">{{ __('A typed amount') }}</option>
        </select>
    </x-field>
    <x-field :label="__('Share of the total (%)')" x-show="basis === 'percent'">
        <input name="percent" value="{{ $percent === null ? '' : \App\Services\OwnerPack\QuotationMath::plain($percent) }}" inputmode="decimal" class="input num" aria-label="{{ __('Share of the total (%)') }}" :disabled="basis !== 'percent'" autocomplete="off">
    </x-field>
    <x-field :label="__('Amount (₹)')" x-show="basis === 'amount'" x-cloak>
        <input name="amount" value="{{ $adding || ! $stage->isPercent() ? $value('amount') : '' }}" inputmode="decimal" class="input num" aria-label="{{ __('Amount (₹)') }}" :disabled="basis !== 'amount'" autocomplete="off">
    </x-field>
    <x-field :label="__('Place in the order')" :hint="$adding ? __('Empty adds it at the end.') : null">
        <input name="position" value="{{ $adding ? $value('position') : $key }}" inputmode="numeric" class="input num" aria-label="{{ __('Place in the order') }}" autocomplete="off">
    </x-field>
    <div class="sm:col-span-1">{{ $button }}</div>
    <x-field :label="__('Condition')" class="sm:col-span-6" :hint="__('Optional. Printed as you type it, for example what must be finished before this stage is due.')">
        <input name="condition_text" value="{{ $value('condition_text') }}" maxlength="500" class="input" aria-label="{{ __('Condition') }}">
    </x-field>
</div>
