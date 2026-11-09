@php
    [$tone, $text] = match (true) {
        $row['status'] === 'none' => ['border-ink-3 text-ink-3', __('No contract value')],
        $row['over_limit'] => ['border-laterite bg-laterite text-white', __('Over limit')],
        $row['status'] === 'red' => ['border-laterite text-laterite', __('Paid ahead')],
        default => ['border-moss text-moss', __('Paid within work')],
    };
@endphp
<span class="stamp {{ $tone }}">{{ $text }}</span>
