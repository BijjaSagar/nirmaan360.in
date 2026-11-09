<?php

namespace App\Console\Commands;

use App\Models\AgentInstall;
use App\Models\User;
use App\Services\Agents\AgentOrchestrator;
use App\Services\Agents\AgentRegistry;
use App\Services\Agents\AgentRunBlocked;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Ramsey\Uuid\Uuid;

/** Starts the Owner's Copilot brief for companies that switched it on. Runs as the company's owner. */
class SendAgentDailyBriefs extends Command
{
    protected $signature = 'agents:daily-briefs';

    protected $description = 'Start the daily Owner\'s Copilot brief for companies that switched it on';

    public function handle(AgentRegistry $registry, AgentOrchestrator $orchestrator): int
    {
        $agent = $registry->find('owners_copilot');
        $today = now('Asia/Kolkata')->toDateString();

        $installs = AgentInstall::withoutGlobalScopes()->where('agent_id', $agent->id)->where('enabled', true)->where('daily_brief', true)->get();

        foreach ($installs as $install) {
            $owner = User::where('organization_id', $install->organization_id)->where('role', 'owner')->where('is_active', true)->orderBy('id')->first();

            if ($owner === null) {
                continue;
            }

            try {
                // The same id all day, so running the command twice cannot charge for two briefs.
                $orchestrator->start(
                    $agent,
                    $owner,
                    __('Give me today\'s brief.', [], $owner->locale ?: 'en'),
                    clientUuid: Uuid::uuid5(Uuid::NAMESPACE_URL, "daily-brief:{$install->organization_id}:{$today}")->toString(),
                    channel: 'schedule',
                );
            } catch (AgentRunBlocked $blocked) {
                Log::info('Daily brief skipped', ['organization' => $install->organization_id, 'reason' => $blocked->reason]);
            }
        }

        return self::SUCCESS;
    }
}
