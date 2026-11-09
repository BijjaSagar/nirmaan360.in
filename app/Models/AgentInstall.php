<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A company's own settings for one agent. With no row the agent's defaults apply. */
class AgentInstall extends Model
{
    use Auditable, BelongsToOrganization;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'daily_brief' => 'boolean',
            'max_credits' => 'decimal:4',
        ];
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }
}
