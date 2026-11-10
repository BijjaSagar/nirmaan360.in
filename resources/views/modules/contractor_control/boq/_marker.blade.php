{{-- Under a BOQ line's description, for people who may see tenders: the tenders it is in and the quotes that came in. --}}
@if ($marker)
    <a href="{{ route('boq.tenders.show', $item) }}" class="mt-1 inline-flex flex-wrap items-center gap-x-1.5 gap-y-0.5 text-xs">
        <span class="stamp border-neel text-neel">{{ trans_choice(':count tender|:count tenders', $marker['tenders'], ['count' => $marker['tenders']]) }}</span>
        <span class="link">{{ $marker['quotes'] > 0 ? trans_choice(':count quote|:count quotes', $marker['quotes'], ['count' => $marker['quotes']]) : __('No quotes yet') }}</span>
        {{-- The presenter has already dropped the rate for anyone without money.view. --}}
        @can('money.view')
            @if (($marker['lowest_rate'] ?? null) !== null)
                <span class="num text-ink-3">{{ __('Lowest :rate', ['rate' => inr($marker['lowest_rate'])]) }}</span>
            @endif
        @endcan
    </a>
@endif
