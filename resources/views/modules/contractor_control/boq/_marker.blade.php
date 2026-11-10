{{--
    Under a BOQ line's description, for people who may see tenders: the tenders it is in and the quotes that came in,
    and for those who may start one, an "Add tender" button that opens the form on the BOQ page itself.
    On a wide screen the Tenders action also sits in the table's last column, which is often scrolled out of view.
--}}
@php
    use App\Support\Dec;
    use Illuminate\Support\Str;

    $canStart = ($canTender ?? false) && ! Dec::of($item->qty)->isZero();
    $start = $canStart ? [
        'url' => route('boq.tenders.store', $item),
        'item' => $item->id,
        'uuid' => (string) Str::uuid(),
        'code' => $item->code,
        'description' => Str::limit($item->description, 140),
        'title' => Str::limit($item->description, 80, ''),
        'due_on' => '',
        'scope' => '',
    ] : null;
@endphp
<div class="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs">
    @if ($marker)
        <a href="{{ route('boq.tenders.show', $item) }}" class="inline-flex flex-wrap items-center gap-x-1.5 gap-y-0.5">
            <span class="stamp border-neel text-neel">{{ trans_choice(':count tender|:count tenders', $marker['tenders'], ['count' => $marker['tenders']]) }}</span>
            <span class="link">{{ $marker['quotes'] > 0 ? trans_choice(':count quote|:count quotes', $marker['quotes'], ['count' => $marker['quotes']]) : __('No quotes yet') }}</span>
            {{-- The presenter has already dropped the rate for anyone without money.view. --}}
            @can('money.view')
                @if (($marker['lowest_rate'] ?? null) !== null)
                    <span class="num text-ink-3">{{ __('Lowest :rate', ['rate' => inr($marker['lowest_rate'])]) }}</span>
                @endif
            @endcan
        </a>
    @elseif (! $canStart)
        {{-- A phone already has Tenders in the row of actions below. --}}
        <a href="{{ route('boq.tenders.show', $item) }}" class="hidden items-center gap-x-1.5 sm:inline-flex">
            <span class="stamp">{{ __('Tenders') }}</span>
            <span class="link">{{ __('No tenders yet') }}</span>
        </a>
    @endif
    @if ($canStart)
        <button type="button" class="btn btn-ghost px-2 py-1 text-xs" @click="tender = @js($start)">+ {{ __('Add tender') }}</button>
    @endif
</div>
