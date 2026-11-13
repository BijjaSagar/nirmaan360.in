<?php

namespace Tests\Feature\DocumentReading\Support;

use App\Models\User;
use App\Services\DocumentReading\Contracts\ReaderOffer;

/** A stand-in for a feature's offer to the field app: whoever may change the BOQ may scan one. */
class FakeOffer implements ReaderOffer
{
    public function allows(User $user): bool
    {
        return $user->hasPermission('boq.manage');
    }
}
