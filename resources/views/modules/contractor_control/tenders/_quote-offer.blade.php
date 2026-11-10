{{--
    The rest of a quote form: when it came and what the contractor offers besides the price, with the quotation itself.
    Each field is one cell of the form's grid. Ids carry $prefix, and old input and errors show only in the form that was sent ($active).
--}}
@php
    $was = fn (string $field, $default = null) => $active ? old($field, $default) : $default;
    $wrong = fn (string $field) => $active ? $errors->first($field) : null;
    $material = (string) $was('includes_material', '');
@endphp
<div>
    <label class="label" for="{{ $prefix }}-received">{{ __('Date received') }}</label>
    <input id="{{ $prefix }}-received" name="received_on" type="date" max="{{ today()->format('Y-m-d') }}" value="{{ $was('received_on', today()->format('Y-m-d')) }}" class="input">
    @if ($wrong('received_on'))<p class="error">{{ $wrong('received_on') }}</p>@endif
</div>
<div>
    <label class="label" for="{{ $prefix }}-valid">{{ __('Valid till') }}</label>
    <input id="{{ $prefix }}-valid" name="valid_until" type="date" value="{{ $was('valid_until') }}" class="input">
    @if ($wrong('valid_until'))<p class="error">{{ $wrong('valid_until') }}</p>@endif
</div>
<div>
    <label class="label" for="{{ $prefix }}-material">{{ __('Material') }}</label>
    <select id="{{ $prefix }}-material" name="includes_material" class="input">
        <option value="">{{ __('Not stated') }}</option>
        <option value="1" @selected($material === '1')>{{ __('With material') }}</option>
        <option value="0" @selected($material === '0')>{{ __('Labour only') }}</option>
    </select>
    @if ($wrong('includes_material'))<p class="error">{{ $wrong('includes_material') }}</p>@endif
</div>
<div>
    <label class="label" for="{{ $prefix }}-days">{{ __('Completion time (days)') }}</label>
    <input id="{{ $prefix }}-days" name="completion_days" type="number" inputmode="numeric" step="1" min="1" max="3650" value="{{ $was('completion_days') }}" class="input num">
    @if ($wrong('completion_days'))<p class="error">{{ $wrong('completion_days') }}</p>@endif
</div>
<div class="sm:col-span-2">
    <label class="label" for="{{ $prefix }}-payment">{{ __('Payment terms') }}</label>
    <input id="{{ $prefix }}-payment" name="payment_terms" value="{{ $was('payment_terms') }}" maxlength="500" class="input" placeholder="{{ __('For example: 30 days after the bill') }}">
    @if ($wrong('payment_terms'))<p class="error">{{ $wrong('payment_terms') }}</p>@endif
</div>
<div class="sm:col-span-2">
    <label class="label" for="{{ $prefix }}-file">{{ __('Quotation file') }}</label>
    <input id="{{ $prefix }}-file" name="quotation" type="file" accept="image/*,application/pdf" class="input">
    <p class="hint">{{ __('Photo or PDF, up to 8 MB.') }}</p>
    @if ($wrong('quotation'))<p class="error">{{ $wrong('quotation') }}</p>@endif
</div>
<div class="sm:col-span-2">
    <label class="label" for="{{ $prefix }}-remarks">{{ __('Remarks') }}</label>
    <input id="{{ $prefix }}-remarks" name="remarks" value="{{ $was('remarks') }}" maxlength="500" class="input">
    @if ($wrong('remarks'))<p class="error">{{ $wrong('remarks') }}</p>@endif
</div>
