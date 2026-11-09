<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgentToolCall extends Model
{
    use BelongsToOrganization;

    public const PROJECT_VIA = 'run';

    public const PENDING = 'PENDING';

    public const OK = 'OK';

    public const ERROR = 'ERROR';

    public const REFUSED = 'REFUSED';

    public const WAITING_APPROVAL = 'WAITING_APPROVAL';

    public const REJECTED = 'REJECTED';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'input' => 'array',
            'output' => 'array',
            'sources' => 'array',
            'injection_flags' => 'array',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(AgentRun::class, 'agent_run_id');
    }

    public function step(): BelongsTo
    {
        return $this->belongsTo(AgentStep::class, 'agent_step_id');
    }
}
