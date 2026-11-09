<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One phone allowed to run the field app on the offline plan.
 * The licence the phone keeps is a signed token; this row only remembers that it was issued.
 * Nothing secret is stored here, so Auditable can copy the row into the audit log.
 */
class DeviceLicence extends Model
{
    use Auditable, BelongsToOrganization;

    public const COMPANY_WIDE = true;

    public const ACTIVE = 'active';

    public const REVOKED = 'revoked';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'slot' => 'integer',
            'activated_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function revoker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revoked_by');
    }

    public function isActive(): bool
    {
        return $this->status === self::ACTIVE;
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where($this->qualifyColumn('status'), self::ACTIVE);
    }
}
