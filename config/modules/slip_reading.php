<?php

/*
 * Reading a photo to fill a form before it is saved: a delivery challan or a weighbridge slip on the
 * challan form (App\Services\SlipReading\SlipReadKind, the "slip" figures in
 * config/modules/document_reading.php), and a supplier's bill on a challan's bill form or the vendor
 * bill form (App\Services\SlipReading\BillReadKind, the "bill" figures). Saving goes through each
 * form's own service, which checks every field the photo filled (SlipLink).
 *
 * Both readers are built but not registered yet, so no company is offered them and nothing changes
 * on any screen. Registering them is these two lines:
 *
 *     'read_kinds' => [\App\Services\SlipReading\SlipReadKind::class, \App\Services\SlipReading\BillReadKind::class],
 *     'reader_offers' => ['slip_challan' => \App\Services\SlipReading\SlipChallanOffer::class],
 *
 * They wait because the shared reader tests stand in for a "slip" kind of their own, use "bill" as
 * the kind that is not deployed, and assume that no reader ships; those tests have to make room for
 * real ones in the same change. A bill is read on the web only, so it has no offer for the field app.
 */
return [
    'read_kinds' => [],

    'reader_offers' => [],

    // The challan date a photo shows is marked when it is older than this many days.
    'old_after_days' => 30,

    // A material whose name shares words with a line is offered; at most this many.
    'material_suggestions' => 5,

    // The shortest side, in pixels, a photo of a slip may have. A phone photo of a slip is kept at 1600 pixels.
    'min_short_side' => 480,
];
