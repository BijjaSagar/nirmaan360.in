<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\Immutable;
use App\Support\Dec;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/** Money received against a client RA bill. Corrected only by a reversal. */
class ClientReceipt extends Model
{
    use Auditable, BelongsToOrganization, Immutable;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['received_on' => 'date', 'amount' => 'decimal:2', 'tds_amount' => 'decimal:2'];
    }

    public function lockedStatuses(): array
    {
        return ['POSTED'];
    }

    public function voucherNo(): string
    {
        return 'RCT-'.str_pad((string) $this->id, 6, '0', STR_PAD_LEFT);
    }

    public function isReversal(): bool
    {
        return $this->reversal_of_id !== null;
    }

    /** What this receipt settles on the bill: money in the bank plus TDS the client kept. */
    public function settled(): string
    {
        return Dec::money(Dec::of($this->amount)->plus(Dec::of($this->tds_amount)));
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class)->withTrashed();
    }

    public function raBill(): BelongsTo
    {
        return $this->belongsTo(RaBill::class);
    }

    public function reversal(): HasOne
    {
        return $this->hasOne(self::class, 'reversal_of_id');
    }
}
