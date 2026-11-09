@php $points = \App\Services\PriceWatch\PriceBoard::sparkline($trend); @endphp
@if ($points)
    <svg viewBox="0 0 72 20" width="72" height="20" class="inline-block align-middle text-neel" role="img" aria-label="{{ __('Price trend, oldest on the left') }}">
        <polyline fill="none" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round" stroke-linecap="round" points="{{ $points }}" />
    </svg>
@else
    <span class="text-xs text-ink-3" title="{{ __('A trend shows after the second price.') }}">—</span>
@endif
