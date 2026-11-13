<?php

namespace App\Models;

use App\Exceptions\ImmutableRecordException;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A cost no other register captures, counted in the project's actual cost. Once saved it is never
 * changed or removed: a mistake is cancelled by a reversal, a second entry with the opposite amount
 * that points back at it, so the two net to zero and both stay on record.
 */
class PlanCostEntry extends Model
{
    use Auditable, BelongsToOrganization;

    protected $guarded = [];

    protected static function booted(): void
    {
        static::updating(fn () => throw new ImmutableRecordException(__('A cost entry cannot be changed or removed. Reverse it instead.')));
        static::deleting(fn () => throw new ImmutableRecordException(__('A cost entry cannot be changed or removed. Reverse it instead.')));
    }

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'entry_date' => 'date'];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isReversal(): bool
    {
        return $this->reversal_of_id !== null;
    }

    /** The entry that cancels this one, if it has been reversed. */
    public function reversal(): HasOne
    {
        return $this->hasOne(self::class, 'reversal_of_id');
    }

    /** The entry this one cancels. */
    public function reversalOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reversal_of_id');
    }
}
