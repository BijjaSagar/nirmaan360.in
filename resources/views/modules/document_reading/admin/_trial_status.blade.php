{{-- The state of a dry run or of one of its pages, as a stamp. --}}
@php
    [$words, $tone] = match ($status) {
        'draft' => [__('Not started'), 'border-ink-3 text-ink-3'],
        'waiting' => [__('Waiting'), 'border-ink-3 text-ink-3'],
        'queued' => [__('In the queue'), 'border-neel text-neel'],
        'running' => [__('Being read'), 'border-neel text-neel'],
        'paused' => [__('Stopped'), 'border-haldi text-[#8a6208]'],
        'read', 'done' => [__('Finished'), 'border-moss text-moss'],
        'failed' => [__('Call failed'), 'border-laterite text-laterite'],
        'expired' => [__('Files deleted before it finished'), 'border-ink-3 text-ink-3'],
        default => [__('Not sent'), 'border-ink-3 text-ink-3'],
    };
@endphp
<span class="stamp {{ $tone }}">{{ $words }}</span>
