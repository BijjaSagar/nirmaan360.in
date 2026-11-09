<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\Immutable;
use App\Support\Dec;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A tax invoice issued for a client RA bill. Keeps its own copy of the names and tax figures. */
class GstInvoice extends Model
{
    use Auditable, BelongsToOrganization, Immutable;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'invoice_date' => 'date',
            'taxable_amount' => 'decimal:2',
            'gst_pct' => 'decimal:2',
            'cgst_amount' => 'decimal:2',
            'sgst_amount' => 'decimal:2',
            'igst_amount' => 'decimal:2',
            'invoice_value' => 'decimal:2',
            'irn_ack_date' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function lockedStatuses(): array
    {
        return ['ISSUED', 'CANCELLED'];
    }

    public function mutableAfterApproval(): array
    {
        return ['status', 'cancelled_at', 'irn', 'irn_ack_no', 'irn_ack_date'];
    }

    public function taxAmount(): string
    {
        return Dec::money(Dec::of($this->cgst_amount)->plus(Dec::of($this->sgst_amount))->plus(Dec::of($this->igst_amount)));
    }

    public function isInterState(): bool
    {
        return $this->supply_type === 'inter';
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function raBill(): BelongsTo
    {
        return $this->belongsTo(RaBill::class);
    }
}
