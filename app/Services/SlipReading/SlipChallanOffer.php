<?php

namespace App\Services\SlipReading;

use App\Models\User;
use App\Services\DocumentReading\Contracts\ReaderOffer;

/** The field app's "Read from photo" on the challan form: whoever may enter a challan, on a plan with site control. */
class SlipChallanOffer implements ReaderOffer
{
    public function allows(User $user): bool
    {
        return $user->hasPermission('material_control.challan') && (bool) $user->organization?->allowsFeature('site_control');
    }
}
