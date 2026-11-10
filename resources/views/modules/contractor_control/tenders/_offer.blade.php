{{--
    What a contractor offers besides the price: when the quote came, how long it holds, whether material is in it
    and how long the work takes. Payment terms and remarks are free text that may quote a price, so the caller
    passes them only for someone with money.view.
--}}
@php
    $day = fn ($value) => \Illuminate\Support\Carbon::parse($value)->format('d M Y');
    $facts = array_filter([
        $receivedOn ? __('Received :date', ['date' => $day($receivedOn)]) : null,
        $validUntil ? __('Valid till :date', ['date' => $day($validUntil)]) : null,
        $includesMaterial === null ? null : ($includesMaterial ? __('With material') : __('Labour only')),
        $completionDays ? trans_choice('Finish in :count day|Finish in :count days', $completionDays, ['count' => $completionDays]) : null,
        $enteredBy ?: null,
    ]);
@endphp
<p class="mt-1.5 text-xs text-ink-3">{{ implode(' · ', $facts) }}</p>
@if (filled($paymentTerms ?? null))
    <p class="mt-0.5 text-xs"><span class="text-ink-3">{{ __('Payment terms') }}:</span> {{ $paymentTerms }}</p>
@endif
@if (filled($remarks ?? null))
    <p class="mt-0.5 text-xs text-ink-2">{{ $remarks }}</p>
@endif
