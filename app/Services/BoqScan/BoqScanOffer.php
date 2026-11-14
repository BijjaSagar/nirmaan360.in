<?php

namespace App\Services\BoqScan;

use App\Models\User;
use App\Services\DocumentReading\Contracts\ReaderOffer;
use App\Services\DocumentReading\ReadKinds;

/**
 * Whether the field app may offer this person "Scan BOQ pages": this module's BOQ reader is deployed, the
 * company's plan has reports, and the person manages the BOQ and may see money, as every route of
 * the module asks.
 */
class BoqScanOffer implements ReaderOffer
{
    public function __construct(private ReadKinds $kinds) {}

    public function allows(User $user): bool
    {
        // This module's own reader, not just any kind registered under its key.
        return $this->kinds->find(BoqReadKind::KEY) instanceof BoqReadKind
            && (bool) $user->organization?->allowsFeature('reports')
            && $user->hasPermission('boq.manage')
            && $user->hasPermission('money.view');
    }
}
