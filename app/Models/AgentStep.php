<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One call to the model, with the tokens and cost it used. Company metering sums these rows. */
class AgentStep extends Model
{
    use BelongsToOrganization;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['cost_usd' => 'decimal:6', 'credits' => 'decimal:4'];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(AgentRun::class, 'agent_run_id');
    }

    public function toolCalls(): HasMany
    {
        return $this->hasMany(AgentToolCall::class)->orderBy('id');
    }
}
