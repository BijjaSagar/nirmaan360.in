<?php

namespace App\Services\SlipReading;

use App\Models\User;
use App\Services\DocumentReading\Contracts\ReaderOffer;
use App\Services\DocumentReading\ReadKinds;

/**
 * The field app's "Read from photo" on the challan form: this module's challan reader is deployed, the
 * company's plan has site control, and the person may enter a challan. While the readers are off for the
 * company (AiSpend::readersOn) the status answer says false here without asking (ReaderAvailability::can).
 */
class SlipChallanOffer implements ReaderOffer
{
    public function __construct(private ReadKinds $kinds) {}

    public function allows(User $user): bool
    {
        // This module's own reader, not just any kind registered under its key.
        return $this->kinds->find(SlipReadKind::KEY) instanceof SlipReadKind
            && $user->hasPermission('material_control.challan')
            && (bool) $user->organization?->allowsFeature('site_control');
    }
}
