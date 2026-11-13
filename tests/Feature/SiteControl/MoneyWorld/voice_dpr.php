<?php

use App\Models\DprVoiceDraft;
use Illuminate\Support\Str;

/*
 * A spoken report the owner made, with its proposal ready, so the walks open the phone's status
 * route for it. Only its own person reads it; everyone else is told it does not exist. A proposal
 * holds counts and quantities, never a rate or an amount. It has no text call, so nothing is tended.
 */
return function (): void {
    $this->rows[DprVoiceDraft::class] = DprVoiceDraft::create([
        'organization_id' => $this->organization->id,
        'project_id' => $this->project->id,
        'user_id' => $this->users['owner']->id,
        'client_uuid' => (string) Str::uuid(),
        'report_date' => now()->toDateString(),
        'locale' => 'mr-IN',
        'transcript' => 'सहा गवंडी, बारा हेल्पर.',
        'speech' => ['engine' => 'android_on_device', 'os' => 'android 14 (sdk 34)', 'offline_checked' => true],
        'platform' => 'android',
        'app_version' => '1.0.1+20',
        'status' => DprVoiceDraft::READY,
        'proposal' => [
            'weather' => '',
            'manpower' => [
                ['trade' => 'Mason', 'trade_spoken' => 'गवंडी', 'count' => '6', 'count_spoken' => 'सहा', 'heard' => true, 'anchored' => true],
                ['trade' => 'Helper', 'trade_spoken' => 'हेल्पर', 'count' => '12', 'count_spoken' => 'बारा', 'heard' => true, 'anchored' => true],
            ],
            'work' => [],
            'hindrances' => '',
            'summary' => '',
            'unused_numbers' => [],
            'text_has_unheard_number' => false,
        ],
        'checks' => ['refs' => []],
    ]);
};
