<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgentVersion extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'tools' => 'array',
            'output_schema' => 'array',
            'max_credits' => 'decimal:4',
        ];
    }

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }
}
