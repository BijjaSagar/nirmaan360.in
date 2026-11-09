<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/** EMD or a fee paid for a tender, and whether it has come back. */
class CrmTenderPayment extends Model
{
    use Auditable, BelongsToOrganization, SoftDeletes;

    public const KINDS = ['emd', 'tender_fee', 'processing_fee', 'other'];

    public const MODES = ['online', 'dd', 'bg', 'fdr', 'cheque', 'cash', 'other'];

    public const STATUSES = ['planned', 'paid', 'refunded', 'forfeited', 'adjusted'];

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'refunded_amount' => 'decimal:2',
            'paid_on' => 'date',
            'valid_until' => 'date',
            'refund_expected_on' => 'date',
            'settled_on' => 'date',
            'refundable' => 'boolean',
        ];
    }

    public function tender(): BelongsTo
    {
        return $this->belongsTo(CrmTender::class, 'tender_id');
    }

    /** Paid, refundable, and the tender is over, so the money should be coming back. */
    public function refundIsDue(): bool
    {
        return $this->status === 'paid'
            && $this->refundable
            && $this->tender !== null
            && in_array($this->tender->stage, ['lost', 'no_bid'], true);
    }

    public function refundIsOverdue(): bool
    {
        return $this->refundIsDue() && $this->refund_expected_on !== null && $this->refund_expected_on->isPast();
    }
}
