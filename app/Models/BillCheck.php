<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One run of the pre-check on an RA bill, kept exactly as it was found. It is never changed: checking
 * the bill again writes a new row, and the newest row is the one the bill page shows.
 *
 * The findings carry rupee figures. Nothing reads them from here straight to a screen: every reader goes
 * through App\Services\BillCheck\CheckPresenter, which leaves the figures out for a person who may not see money.
 */
class BillCheck extends Model
{
    use BelongsToOrganization;

    public const UPDATED_AT = null;

    protected $guarded = [];

    protected $hidden = ['flags', 'flags_hash', 'summary', 'expected'];

    protected function casts(): array
    {
        return [
            'flags' => 'array',
            'summary' => 'array',
            'expected' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function bill(): BelongsTo
    {
        return $this->belongsTo(RaBill::class, 'ra_bill_id');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function runBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'run_by');
    }

    public function acks(): HasMany
    {
        return $this->hasMany(BillCheckAck::class)->orderBy('id');
    }

    /** The stored finding with this key, or null when this run did not find it. */
    public function flag(string $key): ?array
    {
        foreach ($this->flags ?? [] as $flag) {
            if (($flag['key'] ?? null) === $key) {
                return $flag;
            }
        }

        return null;
    }
}
