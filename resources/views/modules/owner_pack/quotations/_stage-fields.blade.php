{{-- The fields of one payment stage. $stage is null on the form that adds one; $key is its place in the order; $button is the form's own button. --}}
@php
    $adding = $stage === null;
    // Each stage form names itself, so after a refusal only that form shows what was typed and why it was refused.
    $form = $adding ? 'add-stage' : 'stage-'.$stage->id;
    $mine = old('_form') === $form;
    $typed = fn (string $field) => is_scalar(old($field)) ? (string) old($field) : null;
    $value = fn (string $field, mixed $default = null) => $mine ? ($typed($field) ?? $default) : ($stage?->{$field} ?? $default);
    $percent = $value('percent');
    $amount = $mine ? $typed('amount') : ($adding || ! $stage->isPercent() ? $stage?->amount : null);
    $position = $mine ? $typed('position') : ($adding ? null : $key);
    $error = fn (string $field) => $mine ? $errors->first($field) : '';
@endphp
<input type="hidden" name="_form" value="{{ $form }}">
<div x-data="{ basis: @js($value('basis', \App\Models\ClientPaymentStage::PERCENT)) }" class="grid items-end gap-3 sm:grid-cols-6">
    <x-field :label="__('Stage')" class="sm:col-span-2">
        <input name="title" value="{{ $value('title') }}" maxlength="160" class="input" placeholder="{{ __('For example: On completion of plinth') }}" aria-label="{{ __('Stage') }}" required>
        @if ($error('title') !== '')<p class="error">{{ $error('title') }}</p>@endif
    </x-field>
    <x-field :label="__('Worked out as')">
        <select name="basis" class="input" x-model="basis" aria-label="{{ __('Worked out as') }}">
            <option value="{{ \App\Models\ClientPaymentStage::PERCENT }}">{{ __('A share of the total') }}</option>
            <option value="{{ \App\Models\ClientPaymentStage::AMOUNT }}">{{ __('A typed amount') }}</option>
        </select>
        @if ($error('basis') !== '')<p class="error">{{ $error('basis') }}</p>@endif
    </x-field>
    <x-field :label="__('Share of the total (%)')" x-show="basis === 'percent'">
        <input name="percent" value="{{ $percent === null || $percent === '' ? '' : ($mine ? $percent : \App\Services\OwnerPack\QuotationMath::plain($percent)) }}" inputmode="decimal" class="input num" aria-label="{{ __('Share of the total (%)') }}" :disabled="basis !== 'percent'" autocomplete="off">
        @if ($error('percent') !== '')<p class="error">{{ $error('percent') }}</p>@endif
    </x-field>
    <x-field :label="__('Amount (₹)')" x-show="basis === 'amount'" x-cloak>
        <input name="amount" value="{{ $amount }}" inputmode="decimal" class="input num" aria-label="{{ __('Amount (₹)') }}" :disabled="basis !== 'amount'" autocomplete="off">
        @if ($error('amount') !== '')<p class="error">{{ $error('amount') }}</p>@endif
    </x-field>
    <x-field :label="__('Place in the order')" :hint="$adding ? __('Empty adds it at the end.') : null">
        <input name="position" value="{{ $position }}" inputmode="numeric" class="input num" aria-label="{{ __('Place in the order') }}" autocomplete="off">
        @if ($error('position') !== '')<p class="error">{{ $error('position') }}</p>@endif
    </x-field>
    <div class="sm:col-span-1">{{ $button }}</div>
    <x-field :label="__('Condition')" class="sm:col-span-6" :hint="__('Optional. Printed as you type it, for example what must be finished before this stage is due.')">
        <input name="condition_text" value="{{ $value('condition_text') }}" maxlength="500" class="input" aria-label="{{ __('Condition') }}">
        @if ($error('condition_text') !== '')<p class="error">{{ $error('condition_text') }}</p>@endif
    </x-field>
</div>
