<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ApprovalStep extends Model
{
    use BelongsToOrganization;

    public const PROJECT_VIA = 'approval';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['acted_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function approval(): BelongsTo
    {
        return $this->belongsTo(Approval::class);
    }

    public function actorName(): string
    {
        return $this->user?->name ?? $this->actor_name ?? '—';
    }
}
