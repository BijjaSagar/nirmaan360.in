<?php

namespace App\Models;

use App\Jobs\ResolveAgentAction;
use App\Models\Concerns\Approvable;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** An agent's request to do something a person must allow first. It goes through the approval engine. */
class AgentActionRequest extends Model
{
    use Approvable, BelongsToOrganization;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['input' => 'array'];
    }

    public function approvalDocType(): string
    {
        return 'agent_action';
    }

    public function approvalTitle(): string
    {
        return __('AI agent request').' · '.$this->summary;
    }

    public function onApprovalApproved(): void
    {
        $this->resolveAs('APPROVED');
    }

    public function onApprovalReturned(): void
    {
        $this->resolveAs('RETURNED');
    }

    public function onApprovalRejected(): void
    {
        $this->resolveAs('REJECTED');
    }

    /** The approval engine calls this inside its transaction, so the run continues after commit. */
    private function resolveAs(string $status): void
    {
        $this->update(['status' => $status]);
        ResolveAgentAction::dispatch($this->id)->afterCommit();
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(AgentRun::class, 'agent_run_id');
    }

    public function toolCall(): BelongsTo
    {
        return $this->belongsTo(AgentToolCall::class, 'agent_tool_call_id');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
