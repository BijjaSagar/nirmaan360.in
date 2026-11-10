{{-- The "Tenders" action on a BOQ line: opens the tenders that line is in, and the quotes for it. --}}
<a href="{{ route('boq.tenders.show', $item) }}" class="link {{ $class ?? '' }}">{{ __('Tenders') }}</a>
