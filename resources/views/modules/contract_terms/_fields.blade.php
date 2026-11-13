{{--
    The fields of one term, for the add form and for the edit form of each. $term is null when adding.
    The add form starts empty: no percentage, amount, number of days or date is filled in for the user.
    Only the fields that belong to the chosen term are in the form; the others are not sent.
--}}
@php
    // After a failed save only the form that was sent shows what was typed; the others keep their saved values.
    $typed = $term ? (string) old('_term') === (string) $term->id : old('_term') === null;
    $value = fn (string $field) => $typed ? old($field, $term?->{$field}) : $term?->{$field};
    $date = fn (string $field) => $typed ? old($field, $term?->{$field}?->format('Y-m-d')) : $term?->{$field}?->format('Y-m-d');
    $plain = fn ($number) => $number === null || ! str_contains((string) $number, '.') ? (string) $number : rtrim(rtrim((string) $number, '0'), '.');
    $id = fn (string $field) => $term ? 'term-'.$term->id.'-'.$field : $field;
    // Only the add form shows an error under its field; an edit form's first error is shown at the top of the page.
    $name = fn (string $field) => $term ? null : $field;

    $kindOf = [];
    foreach ($options['codes'] as $kind => $codes) {
        foreach (array_keys($codes) as $code) {
            $kindOf[$code] = $kind;
        }
    }

    $state = [
        'code' => (string) $value('code'),
        'method' => (string) $value('method'),
        'kindOf' => $kindOf,
        'billed' => array_keys(\App\Models\ContractTerm::BILL_PERCENTAGES),
    ];
    $workOrderId = (string) $value('work_order_id');
@endphp
<div class="grid gap-3 p-4 sm:grid-cols-2"
     x-data="{ ...@js($state), get kind() { return this.kindOf[this.code] || '' }, get percent() { return this.kind === 'deduction' && this.method === 'percent' } }">
    <input type="hidden" name="kind" :value="kind" value="{{ $kindOf[$state['code']] ?? '' }}">

    <x-field :label="__('Contract')" :name="$name('work_order_id')">
        <select id="{{ $id('work_order_id') }}" name="work_order_id" class="input min-h-11">
            <option value="" @selected($workOrderId === '')>{{ __('Client contract') }}</option>
            @foreach ($workOrders as $workOrder)
                <option value="{{ $workOrder->id }}" @selected($workOrderId === (string) $workOrder->id)>{{ $workOrder->number }} · {{ $workOrder->title }}</option>
            @endforeach
            @if ($term?->work_order_id && ! $workOrders->contains('id', $term->work_order_id))
                <option value="{{ $term->work_order_id }}" @selected($workOrderId === (string) $term->work_order_id)>{{ $term->workOrder?->number ?? __('Removed work order') }}</option>
            @endif
        </select>
    </x-field>

    <x-field :label="__('Term')" :name="$name('code')">
        {{--
            A deduction the bill already works out is a percentage there, so its term is entered as one and that
            choice is made with it. For any other term the choice starts empty again, so none is carried over.
        --}}
        <select id="{{ $id('code') }}" name="code" x-model="code" @change="method = billed.includes($event.target.value) ? 'percent' : ''" class="input min-h-11" required>
            <option value="">{{ __('Choose') }}</option>
            @foreach ($options['codes'] as $kind => $codes)
                <optgroup label="{{ __($options['kinds'][$kind]) }}">
                    @foreach ($codes as $code => $label)
                        <option value="{{ $code }}" @selected($state['code'] === $code)>{{ __($label) }}</option>
                    @endforeach
                </optgroup>
            @endforeach
        </select>
    </x-field>

    <x-field :label="__('Name used in the agreement')" :name="$name('label')" :hint="__('Leave blank to use the name chosen above. Type a name when you choose Other deduction.')" class="sm:col-span-2">
        <input id="{{ $id('label') }}" name="label" value="{{ $value('label') }}" class="input min-h-11" maxlength="160" :required="code === 'other'">
    </x-field>

    <template x-if="kind === 'deduction'">
        <x-field :label="__('How it is worked out')" :name="$name('method')">
            <select id="{{ $id('method') }}" name="method" x-model="method" class="input min-h-11" required>
                <option value="">{{ __('Choose') }}</option>
                @foreach ($options['methods'] as $method => $label)
                    <option value="{{ $method }}" @selected($state['method'] === $method) @if ($method !== 'percent') :disabled="billed.includes(code)" @endif>{{ __($label) }}</option>
                @endforeach
            </select>
        </x-field>
    </template>

    <template x-if="kind === 'deduction'">
        <x-field :label="__('What kind of deduction it is')" :name="$name('nature')">
            <select id="{{ $id('nature') }}" name="nature" class="input min-h-11">
                <option value="">{{ __('Not stated') }}</option>
                @foreach ($options['natures'] as $nature => $label)
                    <option value="{{ $nature }}" @selected((string) $value('nature') === $nature)>{{ __($label) }}</option>
                @endforeach
            </select>
        </x-field>
    </template>

    <template x-if="percent || kind === 'excess_quantity'">
        <x-field :label="__('Percentage (%)')" :name="$name('pct')">
            <input id="{{ $id('pct') }}" name="pct" type="number" inputmode="decimal" step="0.0001" min="0" value="{{ $plain($value('pct')) }}" class="input num min-h-11" required>
        </x-field>
    </template>

    <template x-if="percent">
        <x-field :label="__('Worked out on')" :name="$name('base')">
            <select id="{{ $id('base') }}" name="base" class="input min-h-11" required>
                <option value="">{{ __('Choose') }}</option>
                @foreach ($options['bases'] as $base => $label)
                    <option value="{{ $base }}" @selected((string) $value('base') === $base)>{{ __($label) }}</option>
                @endforeach
            </select>
        </x-field>
    </template>

    <template x-if="percent">
        <x-field :label="__('Not more than, in all (₹)')" :name="$name('cap_amount')" :hint="__('Only if the agreement gives a highest amount for this deduction over the whole contract.')" class="sm:col-span-2">
            <input id="{{ $id('cap_amount') }}" name="cap_amount" type="number" inputmode="decimal" step="0.01" min="0" value="{{ $value('cap_amount') }}" class="input num min-h-11">
        </x-field>
    </template>

    <template x-if="kind === 'deduction' && method === 'fixed_per_bill'">
        <x-field :label="__('Amount on every bill (₹)')" :name="$name('amount')">
            <input id="{{ $id('amount') }}" name="amount" type="number" inputmode="decimal" step="0.01" min="0" value="{{ $value('amount') }}" class="input num min-h-11" required>
        </x-field>
    </template>

    <template x-if="kind === 'notice' || kind === 'payment_period'">
        <x-field :label="__('Number of days')" :name="$name('days')">
            <input id="{{ $id('days') }}" name="days" type="number" inputmode="numeric" step="1" min="1" value="{{ $value('days') }}" class="input num min-h-11" required>
        </x-field>
    </template>

    @foreach ($options['counted_from'] as $kind => $starts)
        <template x-if="kind === '{{ $kind }}'">
            <x-field :label="__('Counted from')" :name="$name('counted_from')">
                <select id="{{ $id('counted_from') }}" name="counted_from" class="input min-h-11" required>
                    <option value="">{{ __('Choose') }}</option>
                    @foreach ($starts as $start => $label)
                        <option value="{{ $start }}" @selected((string) $value('counted_from') === $start)>{{ __($label) }}</option>
                    @endforeach
                </select>
            </x-field>
        </template>
    @endforeach

    <template x-if="kind === 'notice'">
        <x-field :label="__('Goes together with another period')" :name="$name('combine')" :hint="__('Only when the agreement gives two periods for the same notice and says which of the two dates applies.')">
            <select id="{{ $id('combine') }}" name="combine" class="input min-h-11">
                <option value="">{{ __('No, it stands alone') }}</option>
                @foreach ($options['combine'] as $combine => $label)
                    <option value="{{ $combine }}" @selected((string) $value('combine') === $combine)>{{ __($label) }}</option>
                @endforeach
            </select>
        </x-field>
    </template>

    <template x-if="kind === 'notice'">
        <x-field :label="__('Group name')" :name="$name('combine_group')" :hint="__('Give both periods the same short name, so the app knows they go together.')">
            <input id="{{ $id('combine_group') }}" name="combine_group" value="{{ $value('combine_group') }}" class="input min-h-11" maxlength="20">
        </x-field>
    </template>

    <template x-if="code === 'agreement_no'">
        <x-field :label="__('As written in the agreement')" :name="$name('value_text')" class="sm:col-span-2">
            <input id="{{ $id('value_text') }}" name="value_text" value="{{ $value('value_text') }}" class="input min-h-11" maxlength="255" required>
        </x-field>
    </template>

    <template x-if="kind === 'reference' && code !== 'agreement_no'">
        <x-field :label="__('The date in the agreement')" :name="$name('value_date')">
            <input id="{{ $id('value_date') }}" name="value_date" type="date" value="{{ $date('value_date') }}" class="input min-h-11" required>
        </x-field>
    </template>

    <x-field :label="__('Applies from')" :name="$name('effective_from')" class="sm:col-start-1">
        <input id="{{ $id('effective_from') }}" name="effective_from" type="date" value="{{ $date('effective_from') }}" class="input min-h-11" required>
    </x-field>
    <x-field :label="__('Applies up to')" :name="$name('effective_to')" :hint="__('Leave blank while it still applies.')">
        <input id="{{ $id('effective_to') }}" name="effective_to" type="date" value="{{ $date('effective_to') }}" class="input min-h-11">
    </x-field>
    <x-field :label="__('Clause or page in the agreement')" :name="$name('source_ref')" :hint="__('Type where this is written, so anyone can look it up. The app keeps no copy of the agreement.')" class="sm:col-span-2">
        <input id="{{ $id('source_ref') }}" name="source_ref" value="{{ $value('source_ref') }}" class="input min-h-11" maxlength="255" required>
    </x-field>
</div>
