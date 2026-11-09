@php $shown = collect($fieldDefinitions)->filter(fn ($field) => filled($values[$field->key] ?? null)); @endphp
@if ($shown->isNotEmpty())
    <dl class="grid gap-x-4 gap-y-2 text-sm sm:grid-cols-2">
        @foreach ($shown as $field)
            <div>
                <dt class="eyebrow text-ink-3">{{ $field->label }}</dt>
                <dd>{{ $field->type === 'date' ? \Illuminate\Support\Carbon::parse($values[$field->key])->format('d M Y') : $values[$field->key] }}</dd>
            </div>
        @endforeach
    </dl>
@endif
