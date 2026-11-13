<?php

/*
 * Reading a photo of a delivery challan or a weighbridge slip to fill the challan form before it is
 * saved. The photo is read by App\Services\SlipReading\SlipReadKind under the "slip" figures in
 * config/modules/document_reading.php; saving goes through the challan's own service, which checks
 * every field the photo filled (SlipLink).
 *
 * The reader is built but not registered yet, so no company is offered it and nothing changes on
 * any screen. Registering it is these two lines:
 *
 *     'read_kinds' => [\App\Services\SlipReading\SlipReadKind::class],
 *     'reader_offers' => ['slip_challan' => \App\Services\SlipReading\SlipChallanOffer::class],
 *
 * It waits because the shared reader tests stand in for a "slip" kind of their own and assume that
 * no reader ships; those tests have to make room for a real one in the same change.
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
