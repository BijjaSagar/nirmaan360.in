{{-- Why a run stopped without an answer. --}}
@php
    $limitMessages = [
        'steps' => __('This run stopped because it reached its limit of steps.'),
        'time' => __('This run stopped because it ran out of time.'),
        'credits' => __('This run stopped because it used its credit budget.'),
        'wallet' => __('This run stopped because your company ran out of credits.'),
    ];
@endphp
@if ($run->status === 'LIMIT_REACHED')
    <p class="text-sm text-[#8a6208]">{{ $limitMessages[$run->limit_reason] ?? __('This run stopped at a limit.') }}</p>
@elseif ($run->status === 'FAILED')
    <p class="text-sm text-laterite">{{ __($run->error ?: 'This run failed.') }}</p>
@elseif ($run->status === 'CANCELLED')
    <p class="text-sm text-ink-3">{{ __('This run was cancelled.') }}</p>
@elseif ($run->status === 'WAITING_APPROVAL')
    <p class="text-sm text-neel">{{ __('Waiting for a person to approve. See the approvals page.') }}</p>
@elseif (! $run->isFinished())
    <p class="text-sm text-ink-3">{{ __('Working on it…') }}</p>
@endif
