<?php

namespace App\Services\VoiceDpr;

use App\Models\DprVoiceDraft;
use App\Services\DocumentReading\Contracts\Retains;

/**
 * Blanks the words of a spoken report, the proposal made from them and the checks, 30 days after it
 * arrived when no DPR was made from it and 90 days after when one was (config voice_dpr.keep_days).
 * The row stays, because its text call carries the credits it used.
 *
 * Run once a minute by the command that tends document reads, across every company.
 */
class VoiceDraftRetention implements Retains
{
    public function purgeOld(): int
    {
        $days = (array) config('modules.voice_dpr.keep_days');
        $blank = ['transcript' => null, 'proposal' => null, 'checks' => null, 'text_purged_at' => now()];

        $unlinked = DprVoiceDraft::withoutGlobalScopes()
            ->whereNull('text_purged_at')
            ->whereNull('dpr_client_uuid')
            ->where('created_at', '<', now()->subDays((int) $days['unlinked']))
            ->update($blank);

        $linked = DprVoiceDraft::withoutGlobalScopes()
            ->whereNull('text_purged_at')
            ->whereNotNull('dpr_client_uuid')
            ->where('created_at', '<', now()->subDays((int) $days['linked']))
            ->update($blank);

        return $unlinked + $linked;
    }
}
