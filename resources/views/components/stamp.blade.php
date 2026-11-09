@props(['status'])
@php
    $key = strtoupper((string) $status);
    $tone = match ($key) {
        'APPROVED', 'ACTIVE', 'RESOLVED', 'COMPLETED', 'PRESENT' => 'border-moss text-moss',
        'PAID' => 'border-moss bg-moss text-white',
        'IN_REVIEW', 'IN_PROGRESS', 'PLANNING' => 'border-neel text-neel',
        'RETURNED', 'ON_HOLD', 'HIGH', 'MEDIUM' => 'border-haldi text-[#8a6208]',
        'REJECTED', 'CANCELLED', 'CRITICAL', 'OPEN', 'DEVIATION' => 'border-laterite text-laterite',
        default => 'border-ink-3 text-ink-3',
    };
@endphp
<span {{ $attributes->merge(['class' => "stamp {$tone}"]) }}>{{ __(\Illuminate\Support\Str::headline(strtolower($key))) }}</span>
