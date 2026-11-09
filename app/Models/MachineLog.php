<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One shift or day of work for a machine: hours or km, plus any time lost to breakdown. */
class MachineLog extends Model
{
    use Auditable, BelongsToOrganization;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'log_date' => 'date',
            'opening_reading' => 'decimal:3',
            'closing_reading' => 'decimal:3',
            'hours' => 'decimal:3',
            'km' => 'decimal:3',
            'breakdown_hours' => 'decimal:3',
        ];
    }

    public function isBilled(): bool
    {
        return $this->hire_bill_id !== null;
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function hireBill(): BelongsTo
    {
        return $this->belongsTo(AssetHireBill::class);
    }
}
