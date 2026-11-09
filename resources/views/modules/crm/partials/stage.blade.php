@php
    $tone = match ($stage) {
        'won' => 'border-moss text-moss',
        'lost' => 'border-laterite text-laterite',
        'submitted' => 'border-haldi text-[#8a6208]',
        'bidding' => 'border-neel text-neel',
        default => 'border-ink-3 text-ink-3',
    };
@endphp
<span class="stamp {{ $tone }}">{{ \App\Services\Crm\Vocab::stages()[$stage] ?? $stage }}</span>
