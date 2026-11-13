{{--
    "Read details from the bill" on the vendor bill form, while the bill reader is on for the company
    and for someone who may read a bill (money.view, and the right to enter bills). Included inside
    the form by resources/views/modules/finance/vendor-bills/form.blade.php, with $project.

    It fills the vendor (only one found by its GSTIN), the bill number and date, the taxable value,
    the GST rate and the supply, each to be confirmed against the photo. The TDS, the due date, the
    cost head and any purchase order or goods receipt stay the person's to enter. The picture is
    read, not kept with the bill.
--}}
@php
    $vendorBillUser = auth()->user();
    $vendorBillSlips = app(\App\Services\SlipReading\SlipReadService::class);
    $vendorBillOn = $vendorBillUser !== null && $vendorBillUser->can('agents.use') && $vendorBillSlips->mayUseKind($vendorBillUser, \App\Models\SlipRead::BILL)
        && $vendorBillUser->organization !== null && $vendorBillSlips->offered($vendorBillUser->organization, \App\Models\SlipRead::BILL);
@endphp
@if ($vendorBillOn)
    @include('modules.slip_reading._bill_reader', [
        'project' => $project,
        'fileInput' => null,
        'fields' => [
            'party_id' => ['from' => 'party', 'label' => __('Vendor')],
            'bill_no' => ['from' => 'bill_no', 'label' => __('Vendor bill no.')],
            'bill_date' => ['from' => 'bill_date', 'label' => __('Bill date')],
            'taxable_amount' => ['from' => 'taxable_total', 'label' => __('Taxable value')],
            'gst_pct' => ['from' => 'gst_pct', 'label' => __('GST rate')],
            'supply_type' => ['from' => 'supply_type', 'label' => __('Supply')],
        ],
    ])
@endif
