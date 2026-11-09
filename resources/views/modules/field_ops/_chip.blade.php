@php
    [$chipLabel, $chipTone] = \App\Services\FieldOps\Tone::chip($set, (string) $value);
    $chipClass = [
        'good' => 'border-moss text-moss',
        'warn' => 'border-haldi text-[#8a6208]',
        'bad' => 'border-laterite text-laterite',
        'info' => 'border-neel text-neel',
        'muted' => 'border-ink-3 text-ink-3',
    ][$chipTone];
@endphp
<span class="stamp {{ $chipClass }}">{{ __($chipLabel) }}</span>
