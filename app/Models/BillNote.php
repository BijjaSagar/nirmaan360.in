<?php

namespace App\Models;

use App\Models\Concerns\Approvable;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\Immutable;
use App\Support\Dec;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A credit or debit note against an approved RA bill. */
class BillNote extends Model
{
    use Approvable, Auditable, BelongsToOrganization, Immutable;

    public const KINDS = ['credit', 'debit'];

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'note_date' => 'date',
            'taxable_amount' => 'decimal:2',
            'gst_pct' => 'decimal:2',
            'gst_amount' => 'decimal:2',
            'total_amount' => 'decimal:2',
            'net_effect' => 'decimal:2',
            'approved_at' => 'datetime',
        ];
    }

    public function approvalDocType(): string
    {
        return 'bill_note';
    }

    public function approvalTitle(): string
    {
        return $this->label().' '.$this->number.' · '.inr($this->total_amount);
    }

    public function onApprovalApproved(): void
    {
        $this->update(['status' => 'APPROVED', 'approved_at' => now()]);
    }

    public function label(): string
    {
        return $this->kind === 'credit' ? 'Credit note' : 'Debit note';
    }

    public function isCancellable(): bool
    {
        return in_array($this->status, ['DRAFT', 'RETURNED', 'REJECTED'], true);
    }

    public function lowersPosition(): bool
    {
        return Dec::of($this->net_effect)->isNegative();
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function bill(): BelongsTo
    {
        return $this->belongsTo(RaBill::class, 'ra_bill_id');
    }

    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
