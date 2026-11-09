<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\Immutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StockTransfer extends Model
{
    use Auditable, BelongsToOrganization, Immutable;

    public const PROJECT_VIA_ANY = ['fromProject', 'toProject'];

    protected $guarded = [];

    protected function casts(): array
    {
        return ['dispatch_date' => 'date', 'receive_date' => 'date', 'reversed_at' => 'datetime'];
    }

    /** @return list<string> */
    public function lockedStatuses(): array
    {
        return ['DISPATCHED', 'RECEIVED', 'REVERSED'];
    }

    /** @return list<string> */
    public function mutableAfterApproval(): array
    {
        return ['status', 'receive_date', 'received_by', 'reversed_by', 'reversed_at', 'reversal_reason'];
    }

    public function fromStore(): BelongsTo
    {
        return $this->belongsTo(MaterialStore::class, 'from_store_id');
    }

    public function toStore(): BelongsTo
    {
        return $this->belongsTo(MaterialStore::class, 'to_store_id');
    }

    public function fromProject(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'from_project_id');
    }

    public function toProject(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'to_project_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(StockTransferItem::class)->orderBy('id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    public function isReversed(): bool
    {
        return $this->status === 'REVERSED';
    }
}
