{{--
    The challan's bill form, for someone who sees money: "Read details from the bill" while the bill
    reader is on for the company, and a note when the bill figures were filled from a photo.
    Included inside the bill form, right after its token, by
    resources/views/modules/material_control/challans/show.blade.php, with $challan.

    It reads the photo chosen in the form's own "Bill file" field and fills the bill number, date
    and amount (the bill's grand total). Bill figures are money: nothing here shows without money.view.
--}}
@php
    $challanBillUser = auth()->user();
    $challanBillSlips = app(\App\Services\SlipReading\SlipReadService::class);
    $challanBillMoney = $challanBillUser !== null && $challanBillUser->can('money.view');
    $challanBillOn = $challanBillMoney && $challanBillUser->can('agents.use') && $challanBillSlips->mayUseKind($challanBillUser, \App\Models\SlipRead::BILL)
        && $challanBillUser->organization !== null && $challanBillSlips->offered($challanBillUser->organization, \App\Models\SlipRead::BILL);
    $challanBillReading = $challanBillMoney ? app(\App\Services\SlipReading\SlipLink::class)->readingOf($challan, \App\Models\SlipRead::BILL) : null;
    $challanBillLabels = ['bill_no' => __('Bill no.'), 'bill_date' => __('Bill date'), 'bill_amount' => __('Amount')];
@endphp
@if ($challanBillReading !== null)
    @include('modules.slip_reading._filled_note', ['reading' => $challanBillReading, 'labels' => $challanBillLabels])
@endif
@if ($challanBillOn)
    @include('modules.slip_reading._bill_reader', [
        'project' => $challan->project,
        'fileInput' => 'bill',
        'fields' => [
            'bill_no' => ['from' => 'bill_no', 'label' => $challanBillLabels['bill_no']],
            'bill_date' => ['from' => 'bill_date', 'label' => $challanBillLabels['bill_date']],
            'bill_amount' => ['from' => 'grand_total', 'label' => $challanBillLabels['bill_amount']],
        ],
    ])
@endif
