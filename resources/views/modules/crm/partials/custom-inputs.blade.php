{{-- Inputs for the company's own fields. $fieldDefinitions are the definitions and $values what is saved. --}}
@foreach ($fieldDefinitions as $field)
    @php $name = 'custom.'.$field->key; $current = old($name, $values[$field->key] ?? ''); @endphp
    <x-field :label="$field->label.($field->required ? ' *' : '')" :name="$name">
        @if ($field->type === 'select')
            <select id="{{ $name }}" name="custom[{{ $field->key }}]" class="input" @required($field->required)>
                <option value="">—</option>
                @foreach ($field->options ?? [] as $option)
                    <option value="{{ $option }}" @selected((string) $current === (string) $option)>{{ $option }}</option>
                @endforeach
            </select>
        @else
            <input id="{{ $name }}" name="custom[{{ $field->key }}]" value="{{ $current }}"
                   type="{{ $field->type === 'number' ? 'number' : ($field->type === 'date' ? 'date' : 'text') }}"
                   @if ($field->type === 'number') step="any" inputmode="decimal" @endif
                   class="input {{ $field->type === 'number' ? 'num' : '' }}" @required($field->required) maxlength="255">
        @endif
    </x-field>
@endforeach
