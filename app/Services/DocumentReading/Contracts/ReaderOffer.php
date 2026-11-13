<?php

namespace App\Services\DocumentReading\Contracts;

use App\Models\User;

/**
 * One AI feature the field app can offer a person, such as scanning BOQ pages or reading a challan
 * photo. A feature module writes the class and lists it under "reader_offers" in its own
 * config/modules file, by the name the app asks about:
 *
 *     'reader_offers' => ['boq_scan' => BoqScanOffer::class],
 *
 * The app's sign-in answer carries a fixed list of permissions, so the app asks the reader status
 * route instead, and that route asks this.
 */
interface ReaderOffer
{
    /**
     * Whether this person may use the feature: the feature's own permissions, and the plan feature
     * of the module it belongs to. The AI plan feature and "agents.use" are already checked before
     * this is asked, and whether the readers are released, connected and consented to is reported
     * beside it, so none of that belongs here.
     */
    public function allows(User $user): bool;
}
