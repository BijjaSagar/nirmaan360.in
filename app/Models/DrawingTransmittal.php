<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\Immutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A record of what was sent to whom. Once issued, only its acknowledgement or cancellation can change. */
class DrawingTransmittal extends Model
{
    use Auditable, BelongsToOrganization, Immutable;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['issued_at' => 'datetime', 'acknowledged_on' => 'date', 'cancelled_at' => 'datetime'];
    }

    public function lockedStatuses(): array
    {
        return ['issued', 'acknowledged', 'cancelled'];
    }

    public function mutableAfterApproval(): array
    {
        return ['status', 'acknowledged_on', 'acknowledged_by', 'cancelled_at', 'cancel_reason'];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class);
    }

    public function issuer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(DrawingTransmittalItem::class);
    }
}
