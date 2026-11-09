<?php

namespace App\Services\Agents;

use App\Models\Agent;
use App\Models\AgentInstall;
use App\Models\AgentVersion;
use App\Models\Organization;
use App\Support\Dec;
use App\Support\Idempotent;
use Illuminate\Support\Collection;

/**
 * Agents are defined in files under Definitions/ and copied into the database.
 * Any change to a definition becomes a new version, and each run records the version it used.
 */
class AgentRegistry
{
    public const KEYS = ['site_reporter', 'project_brain', 'owners_copilot', 'measurement_billing'];

    /** @return Collection<int,Agent> */
    public function all(): Collection
    {
        $this->sync();

        return Agent::with('currentVersion')->whereIn('key', self::KEYS)->orderBy('id')->get();
    }

    public function find(string $key): Agent
    {
        abort_unless(in_array($key, self::KEYS, true), 404);
        $this->sync();

        return Agent::with('currentVersion')->where('key', $key)->firstOrFail();
    }

    public function sync(): void
    {
        foreach (self::KEYS as $key) {
            $this->syncAgent($key);
        }
    }

    private function syncAgent(string $key): void
    {
        $definition = require app_path("Services/Agents/Definitions/{$key}/agent.php");
        $prompt = (string) file_get_contents(app_path("Services/Agents/Definitions/{$key}/prompt.md"));
        $hash = hash('sha256', json_encode([$definition, $prompt], JSON_UNESCAPED_UNICODE));

        $details = ['name' => $definition['name'], 'description' => $definition['description']];
        $agent = Agent::with('currentVersion')->where('key', $key)->first() ?? Agent::createOrFirst(['key' => $key], $details);
        $agent->update($details);

        if ($agent->currentVersion?->content_hash === $hash) {
            return;
        }

        $number = (int) AgentVersion::where('agent_id', $agent->id)->max('version') + 1;

        $version = Idempotent::create(
            fn () => AgentVersion::create([
                'agent_id' => $agent->id,
                'version' => $number,
                'content_hash' => $hash,
                'system_prompt' => $prompt,
                'tools' => $definition['tools'],
                'permission' => $definition['permission'],
                'model' => $definition['model'],
                'effort' => $definition['effort'],
                'default_autonomy' => $definition['autonomy'],
                'max_autonomy' => $definition['max_autonomy'],
                'max_steps' => $definition['limits']['steps'],
                'max_seconds' => $definition['limits']['seconds'],
                'max_credits' => $definition['limits']['credits'],
                'output_schema' => $definition['output_schema'],
            ]),
            fn () => AgentVersion::where('agent_id', $agent->id)->where('version', $number)->first(),
        );

        $agent->update(['current_version_id' => $version->id]);
    }

    /** A company's settings row, or an unsaved one with no overrides. */
    public function install(Agent $agent, Organization $organization): AgentInstall
    {
        return AgentInstall::withoutGlobalScopes()->firstOrNew(['organization_id' => $organization->id, 'agent_id' => $agent->id]);
    }

    public function isEnabled(AgentInstall $install): bool
    {
        return $install->enabled ?? true;
    }

    /** The company's level, never above what the agent allows. */
    public function autonomy(AgentInstall $install, AgentVersion $version): string
    {
        $level = $install->autonomy ?? $version->default_autonomy;

        return Autonomy::rank($level) > Autonomy::rank($version->max_autonomy) ? $version->max_autonomy : $level;
    }

    /** @return array{max_steps:int,max_seconds:int,max_credits:string} A company can lower limits but not raise them. */
    public function limits(AgentInstall $install, AgentVersion $version): array
    {
        $credits = Dec::of($version->max_credits);

        if ($install->max_credits !== null && Dec::of($install->max_credits)->isLessThan($credits)) {
            $credits = Dec::of($install->max_credits);
        }

        return [
            'max_steps' => min($install->max_steps ?? $version->max_steps, $version->max_steps),
            'max_seconds' => min($install->max_seconds ?? $version->max_seconds, $version->max_seconds),
            'max_credits' => (string) $credits->toScale(4),
        ];
    }
}
