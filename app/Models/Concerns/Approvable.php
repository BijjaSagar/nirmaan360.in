<?php

namespace App\Models\Concerns;

use App\Models\Approval;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;

/** A document that moves through the approval engine using its status column. */
trait Approvable
{
    abstract public function approvalDocType(): string;

    abstract public function approvalTitle(): string;

    public function approvals(): MorphMany
    {
        return $this->morphMany(Approval::class, 'approvable')->latest('id');
    }

    public function currentApproval(): MorphOne
    {
        return $this->morphOne(Approval::class, 'approvable')->latestOfMany();
    }

    public function isEditable(): bool
    {
        return in_array($this->status, ['DRAFT', 'RETURNED'], true);
    }

    public function onApprovalApproved(): void
    {
        $this->update(['status' => 'APPROVED']);
    }

    public function onApprovalReturned(): void
    {
        $this->update(['status' => 'RETURNED']);
    }

    public function onApprovalRejected(): void
    {
        $this->update(['status' => 'REJECTED']);
    }
}
