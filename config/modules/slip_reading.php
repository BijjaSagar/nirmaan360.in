<?php

use App\Services\SlipReading\BillReadKind;
use App\Services\SlipReading\SlipChallanOffer;
use App\Services\SlipReading\SlipReadKind;

/*
 * Reading a photo to fill a form before it is saved: a delivery challan or a weighbridge slip on the
 * challan form (App\Services\SlipReading\SlipReadKind, the "slip" figures in
 * config/modules/document_reading.php), and a supplier's bill on a challan's bill form or the vendor
 * bill form (App\Services\SlipReading\BillReadKind, the "bill" figures). Saving goes through each
 * form's own service, which checks every field the photo filled (SlipLink).
 *
 * Both readers are deployed here. Being deployed is one part of the one test of whether the readers
 * are on (AiSpend::readersOn): until the platform admin has made a dry run and released the readers,
 * and the company's plan has the AI features, no form offers to read a photo and nothing is sent.
 * A bill is read on the web only, so it has no offer for the field app.
 */
return [
    'read_kinds' => [SlipReadKind::class, BillReadKind::class],

    'reader_offers' => ['slip_challan' => SlipChallanOffer::class],

    // The challan date a photo shows is marked when it is older than this many days.
    'old_after_days' => 30,

    // A material whose name shares words with a line is offered; at most this many.
    'material_suggestions' => 5,

    // The shortest side, in pixels, a photo of a slip may have. A phone photo of a slip is kept at 1600 pixels.
    'min_short_side' => 480,
];
