{{--
    On a vendor bill's page, for everyone who may open it and so for its approvers: the bill was
    filled from a photo, who confirmed it field by field and when, and which fields were typed over.
    Included by resources/views/modules/finance/vendor-bills/show.blade.php, with $bill.
--}}
@php
    $vendorBillReading = app(\App\Services\SlipReading\SlipLink::class)->readingOf($bill, \App\Models\SlipRead::BILL);
@endphp
@if ($vendorBillReading !== null)
    <section class="card p-4">
        <h2 class="card-title mb-2">{{ __('Read from photo') }}</h2>
        @include('modules.slip_reading._filled_note', ['reading' => $vendorBillReading, 'labels' => [
            'party_id' => __('Vendor'),
            'bill_no' => __('Vendor bill no.'),
            'bill_date' => __('Bill date'),
            'taxable_amount' => __('Taxable value'),
            'gst_pct' => __('GST rate'),
            'supply_type' => __('Supply'),
        ]])
    </section>
@endif
