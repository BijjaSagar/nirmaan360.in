<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One entry in the platform-wide agent catalogue. Definitions live in app/Services/Agents/Definitions. */
class Agent extends Model
{
    protected $guarded = [];

    public function versions(): HasMany
    {
        return $this->hasMany(AgentVersion::class)->orderByDesc('version');
    }

    public function currentVersion(): BelongsTo
    {
        return $this->belongsTo(AgentVersion::class, 'current_version_id');
    }
}
