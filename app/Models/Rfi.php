<?php

namespace App\Models;

use App\Models\Concerns\Approvable;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\Immutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Carbon;

/**
 * A request for information. It goes through approval before it is sent out.
 * Once approved the question is locked, and only the answer and closing can follow.
 */
class Rfi extends Model
{
    use Approvable, Auditable, BelongsToOrganization, Immutable;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'required_by' => 'date',
            'responded_on' => 'date',
            'cost_impact' => 'boolean',
            'closed_at' => 'datetime',
        ];
    }

    public function mutableAfterApproval(): array
    {
        return ['response', 'responded_on', 'responded_by', 'cost_impact', 'time_impact_days', 'closed_at', 'closed_by'];
    }

    public function approvalDocType(): string
    {
        return 'rfi';
    }

    public function approvalTitle(): string
    {
        return $this->number.' · '.$this->subject;
    }

    /** Where the RFI stands, from the approval status and the answer. */
    public function stage(): string
    {
        return match (true) {
            $this->closed_at !== null => 'closed',
            $this->status === 'APPROVED' && $this->response !== null => 'answered',
            $this->status === 'APPROVED' => 'issued',
            default => strtolower($this->status),
        };
    }

    public function isClosed(): bool
    {
        return $this->closed_at !== null;
    }

    public function isOverdue(): bool
    {
        return $this->required_by !== null
            && $this->required_by->lt(Carbon::today())
            && in_array($this->stage(), ['issued', 'in_review'], true);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function raiser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'raised_by');
    }

    public function attachments(): MorphMany
    {
        return $this->morphMany(FieldAttachment::class, 'attachable');
    }
}
