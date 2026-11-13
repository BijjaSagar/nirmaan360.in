<?php

namespace App\Services\VoiceDpr;

use App\Models\User;
use App\Services\DocumentReading\Contracts\ReaderOffer;
use App\Services\DocumentReading\ReaderSettings;

/**
 * Whether the field app may show the Speak control to a person: voice notes are switched on (the
 * platform admin has recorded that speech stays on the phone, ReaderSettings::voiceOffered), the
 * company's plan has the site module, and the person may write DPRs.
 *
 * The phone still hides the control on a phone that cannot turn speech into text by itself.
 */
class VoiceDprOffer implements ReaderOffer
{
    public function __construct(private ReaderSettings $settings) {}

    public function allows(User $user): bool
    {
        return $this->settings->voiceOffered()
            && (bool) $user->organization?->allowsFeature('site')
            && $user->hasPermission('dpr.create');
    }
}
