<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Support\Dec;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AgentRun extends Model
{
    use BelongsToOrganization;

    public const QUEUED = 'QUEUED';

    public const RUNNING = 'RUNNING';

    public const WAITING_APPROVAL = 'WAITING_APPROVAL';

    public const COMPLETED = 'COMPLETED';

    public const FAILED = 'FAILED';

    public const LIMIT_REACHED = 'LIMIT_REACHED';

    public const CANCELLED = 'CANCELLED';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'messages' => 'array',
            'output' => 'array',
            'sources' => 'array',
            'max_credits' => 'decimal:4',
            'cost_usd' => 'decimal:6',
            'credits_used' => 'decimal:4',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /** People see their own runs. Managers see the whole company's. */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return $user->hasPermission('agents.manage') ? $query : $query->where('user_id', $user->id);
    }

    public function isVisibleTo(User $user): bool
    {
        return $user->hasPermission('agents.manage') || $this->user_id === $user->id;
    }

    public function isFinished(): bool
    {
        return in_array($this->status, [self::COMPLETED, self::FAILED, self::LIMIT_REACHED, self::CANCELLED], true);
    }

    public function creditsLeftInBudget(): bool
    {
        return Dec::of($this->credits_used)->isLessThan(Dec::of($this->max_credits));
    }

    /** @return list<array<string,mixed>> Records this run saved as drafts. */
    public function drafts(): array
    {
        return array_values(array_filter($this->sources ?? [], fn (array $source) => ! empty($source['draft'])));
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    public function agentVersion(): BelongsTo
    {
        return $this->belongsTo(AgentVersion::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(AgentConversation::class, 'agent_conversation_id');
    }

    public function steps(): HasMany
    {
        return $this->hasMany(AgentStep::class)->orderBy('number');
    }

    public function toolCalls(): HasMany
    {
        return $this->hasMany(AgentToolCall::class)->orderBy('id');
    }

    public function actionRequests(): HasMany
    {
        return $this->hasMany(AgentActionRequest::class)->orderBy('id');
    }
}
