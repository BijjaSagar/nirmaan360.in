<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Approval extends Model
{
    use BelongsToOrganization;

    public const PROJECT_VIA_MORPH = 'approvable';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'levels' => 'array',
            'submitted_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function approvable(): MorphTo
    {
        return $this->morphTo();
    }

    public function steps(): HasMany
    {
        return $this->hasMany(ApprovalStep::class)->orderBy('id');
    }

    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function currentRole(): ?string
    {
        return $this->levels[$this->current_level - 1] ?? null;
    }

    public function currentRoleLabel(): string
    {
        $role = $this->currentRole();

        return config("buildos.approval_level_roles.{$role}", (string) $role);
    }

    public function documentUrl(): string
    {
        return match ($this->doc_type) {
            'vendor_bill' => route('finance.project.vendor-bills.show', $this->approvable_id),
            'rfi' => route('rfis.show', $this->approvable_id),
            'measurement' => route('measurements.show', $this->approvable_id),
            'ra_bill' => route('ra-bills.show', $this->approvable_id),
            'dpr' => route('dprs.show', $this->approvable_id),
            'bill_note' => route('bill-notes.show', $this->approvable_id),
            'indent' => route('procurement.indents.show', $this->approvable_id),
            default => route('approvals.index'),
        };
    }

    public function isFinalLevel(): bool
    {
        return $this->current_level >= count($this->levels);
    }
}
