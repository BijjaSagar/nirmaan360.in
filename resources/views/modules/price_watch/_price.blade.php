{{-- One price with its date and source. $price is a PriceBoard::price() array or null. --}}
@php $kinds = ['manual' => __('typed in'), 'excel' => __('from Excel'), 'shared' => __('from the shared list')]; @endphp
@if ($price === null)
    <span class="text-sm text-ink-3">{{ __('No price yet') }}</span>
@else
    <span class="num text-sm font-medium">{{ inr($price['rate']) }}</span>
    <span class="block text-[11px] leading-tight text-ink-3">
        {{ __('as on') }} {{ \Illuminate\Support\Carbon::parse($price['priced_on'])->format('d M Y') }}
        · {{ $price['source'] ?: ($kinds[$price['source_kind']] ?? $price['source_kind']) }}
        @if ($price['stale'])
            <span class="stamp ml-1 border-laterite text-laterite">{{ __('Out of date') }}</span>
        @endif
    </span>
@endif
