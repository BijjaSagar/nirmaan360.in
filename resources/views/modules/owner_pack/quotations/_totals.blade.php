{{-- The money summary of a quotation, from its own columns. --}}
<dl class="text-sm">
    <div class="flex justify-between border-b border-rule px-4 py-2"><dt>{{ __('Value of the work') }}</dt><dd class="num">{{ inr($quotation->subtotal) }}</dd></div>
    <div class="flex justify-between border-b border-rule px-4 py-2">
        @if ($quotation->gst_pct !== null)
            <dt>{{ __('GST at :pct', ['pct' => \App\Services\OwnerPack\QuotationMath::plain($quotation->gst_pct).'%']) }}</dt><dd class="num">+{{ inr($quotation->gst_amount) }}</dd>
        @else
            <dt>{{ __('GST') }}</dt><dd>{{ __('Not included') }}</dd>
        @endif
    </div>
    <div class="flex items-baseline justify-between bg-paper-2/60 px-4 py-3"><dt class="font-display text-lg font-semibold">{{ __('Quotation total') }}</dt><dd class="num text-lg font-medium">{{ inr($quotation->total) }}</dd></div>
</dl>
