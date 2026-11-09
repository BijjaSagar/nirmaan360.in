@php
    [$label, $tone] = match (true) {
        $progress->delayKind === 'overdue' => [__('Overdue'), 'border-laterite text-laterite'],
        $progress->delayKind === 'late_start' => [__('Late start'), 'border-haldi text-[#8a6208]'],
        $progress->state === 'complete' => [__('Complete'), 'border-moss text-moss'],
        $progress->state === 'in_progress' => [__('In progress'), 'border-neel text-neel'],
        $progress->state === 'not_started' => [__('Not started'), 'border-ink-3 text-ink-3'],
        default => [__('Not linked'), 'border-rule text-ink-3'],
    };
@endphp
<span class="stamp {{ $tone }}">{{ $label }}</span>
