<?php

namespace App\Services\Agents\Tools;

use App\Models\AgentRun;
use App\Models\Project;
use App\Models\User;
use Ramsey\Uuid\Uuid;

final readonly class ToolContext
{
    public function __construct(
        public AgentRun $run,
        public User $user,
        public string $toolUseId,
    ) {}

    /** A project in this company, and inside the chat's project if the chat has one. */
    public function project(int $id): Project
    {
        if ($this->run->project_id !== null && $this->run->project_id !== $id) {
            throw new ToolRefused('project_scope', 'This conversation is limited to project id '.$this->run->project_id.'.');
        }

        return Project::where('organization_id', $this->run->organization_id)->find($id)
            ?? throw new ToolFailure('There is no project with id '.$id.' in this company.');
    }

    public function organizationId(): int
    {
        return $this->run->organization_id;
    }

    /** The same on a retry, so a repeated call returns the first record instead of a second one. */
    public function clientUuid(): string
    {
        return Uuid::uuid5(Uuid::NAMESPACE_URL, 'agent-run:'.$this->run->id.':'.$this->toolUseId)->toString();
    }
}
