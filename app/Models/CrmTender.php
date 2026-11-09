<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToOrganization;
use App\Support\Dec;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class CrmTender extends Model
{
    use Auditable, BelongsToOrganization, SoftDeletes;

    /** Kanban columns, left to right. */
    public const STAGES = ['enquiry', 'bidding', 'submitted', 'won', 'lost', 'no_bid'];

    public const OPEN_STAGES = ['enquiry', 'bidding', 'submitted'];

    public const CLOSED_STAGES = ['won', 'lost', 'no_bid'];

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'estimated_value' => 'decimal:2',
            'bid_value' => 'decimal:2',
            'awarded_value' => 'decimal:2',
            'winning_value' => 'decimal:2',
            'published_on' => 'date',
            'pre_bid_at' => 'datetime',
            'due_at' => 'datetime',
            'opens_at' => 'datetime',
            'due_alerted_at' => 'datetime',
            'stage_changed_at' => 'datetime',
            'closed_on' => 'date',
            'converted_at' => 'datetime',
            'tags' => 'array',
            'custom_fields' => 'array',
        ];
    }

    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class);
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(CrmLead::class, 'lead_id');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function reason(): BelongsTo
    {
        return $this->belongsTo(CrmOutcomeReason::class, 'outcome_reason_id');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(CrmTenderPayment::class, 'tender_id');
    }

    public function boqItems(): HasMany
    {
        return $this->hasMany(CrmTenderBoqItem::class, 'tender_id')->orderBy('sort')->orderBy('id');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(CrmTenderDocument::class, 'tender_id');
    }

    public function isOpen(): bool
    {
        return in_array($this->stage, self::OPEN_STAGES, true);
    }

    /** The value that best describes the tender now: what was awarded, else what we quoted, else the estimate. */
    public function headlineValue(): string
    {
        foreach ([$this->stage === 'won' ? $this->awarded_value : null, $this->bid_value, $this->estimated_value] as $value) {
            if ($value !== null && Dec::of($value)->isPositive()) {
                return Dec::money($value);
            }
        }

        return Dec::money(0);
    }

    public function isConverted(): bool
    {
        return $this->project_id !== null;
    }

    /** Whether the submission deadline has passed or is close, for a tender still being worked. */
    public function deadlineState(): ?string
    {
        if (! $this->due_at || ! in_array($this->stage, ['enquiry', 'bidding'], true)) {
            return null;
        }

        return match (true) {
            $this->due_at->isPast() => 'overdue',
            $this->due_at->lte(now()->addDays(3)) => 'soon',
            default => 'ok',
        };
    }
}
