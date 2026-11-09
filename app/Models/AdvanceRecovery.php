<?php

namespace App\Models;

use App\Exceptions\ImmutableRecordException;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** The part of an advance taken back in one wage run. */
class AdvanceRecovery extends Model
{
    use BelongsToOrganization;

    protected $guarded = [];

    protected static function booted(): void
    {
        static::deleting(function (self $recovery) {
            if (WageRun::withoutGlobalScopes()->find($recovery->wage_run_id)?->isLocked()) {
                throw new ImmutableRecordException;
            }
        });
    }

    protected function casts(): array
    {
        return ['amount' => 'decimal:2'];
    }

    public function advance(): BelongsTo
    {
        return $this->belongsTo(WorkerAdvance::class, 'worker_advance_id');
    }
}
