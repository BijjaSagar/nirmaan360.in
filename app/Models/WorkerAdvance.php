<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToOrganization;
use App\Support\Dec;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Money given to a worker ahead of wages. Wage runs take it back in instalments. */
class WorkerAdvance extends Model
{
    use Auditable, BelongsToOrganization;

    public const COMPANY_WIDE = true;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'recovery_per_run' => 'decimal:2', 'given_on' => 'date'];
    }

    public function worker(): BelongsTo
    {
        return $this->belongsTo(Worker::class)->withTrashed();
    }

    public function recoveries(): HasMany
    {
        return $this->hasMany(AdvanceRecovery::class);
    }

    /** A list should load withSum('recoveries as recovered_amount', 'amount') first. */
    public function recovered(): string
    {
        return Dec::money($this->recovered_amount ?? $this->recoveries()->sum('amount'));
    }

    public function outstanding(): string
    {
        return Dec::money(Dec::of($this->amount)->minus(Dec::of($this->recovered())));
    }
}
