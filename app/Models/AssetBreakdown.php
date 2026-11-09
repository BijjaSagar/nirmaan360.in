<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToOrganization;
use App\Support\Dec;
use Brick\Math\RoundingMode;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AssetBreakdown extends Model
{
    use Auditable, BelongsToOrganization;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'reported_at' => 'datetime',
            'resolved_at' => 'datetime',
            'repair_cost' => 'decimal:2',
        ];
    }

    public function isOpen(): bool
    {
        return $this->status === 'open';
    }

    /** Hours the machine stood idle to one decimal, or null while it is still down. */
    public function downtimeHours(): ?string
    {
        return $this->resolved_at
            ? (string) Dec::of((int) $this->reported_at->diffInMinutes($this->resolved_at))->dividedBy(60, 1, RoundingMode::HalfUp)
            : null;
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reported_by');
    }
}
