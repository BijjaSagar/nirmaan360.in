{{-- Paid against work, in one stamp. $row carries status from ContractorPortalService. --}}
@php
    [$tone, $text] = match ($row['status']) {
        'none' => ['border-ink-3 text-ink-3', __('No contract value')],
        'red' => ['border-laterite text-laterite', __('Paid ahead of work')],
        default => ['border-moss text-moss', __('Paid within work')],
    };
@endphp
<span class="stamp {{ $tone }}">{{ $text }}</span>
