{{-- What changed in one revision: each field with its old and new value. Rates are money, so they appear only with money.view. --}}
@php
    $names = [
        'section' => __('Section'), 'code' => __('Item code'), 'description' => __('Description'), 'unit' => __('Unit'),
        'qty' => __('Quantity'), 'rate' => __('Rate (₹)'), 'category' => __('Category'), 'details' => __('Details'),
        'concrete_mix' => __('Concrete'),
    ];
    $show = function (string $field, ?string $value): string {
        if ($value === null || $value === '') {
            return '—';
        }

        return match ($field) {
            'qty' => fmt_qty($value),
            'rate' => \App\Support\Dec::indian($value),
            'category' => \App\Services\ProjectSetup\BoqCategories::label($value),
            default => $value,
        };
    };
    $moneyFields = \App\Services\ProjectSetup\BoqRevisions::MONEY_FIELDS;
    $changes = collect($revision->changes ?? [])->filter(fn ($pair, $field) => $showMoney || ! in_array($field, $moneyFields, true));
    $hidden = count($revision->changes ?? []) - $changes->count();
@endphp
<dl class="space-y-2">
    @foreach ($changes as $field => $pair)
        <div>
            <dt class="eyebrow text-ink-3">{{ $names[$field] ?? \Illuminate\Support\Str::headline($field) }}</dt>
            <dd class="break-words">
                @if ($revision->action === 'added' || $revision->action === 'restored')
                    <span class="font-medium whitespace-pre-line">{{ $show($field, $pair[1] ?? null) }}</span>
                @elseif ($revision->action === 'deleted')
                    <span class="text-ink-3 whitespace-pre-line">{{ $show($field, $pair[0] ?? null) }}</span>
                @else
                    <span class="text-ink-3 line-through whitespace-pre-line">{{ $show($field, $pair[0] ?? null) }}</span>
                    <span aria-hidden="true">→</span>
                    <span class="font-medium whitespace-pre-line">{{ $show($field, $pair[1] ?? null) }}</span>
                @endif
            </dd>
        </div>
    @endforeach
</dl>
@if ($hidden > 0)
    <p class="text-xs text-ink-3">{{ __('Money figures are not shown to you.') }}</p>
@endif
