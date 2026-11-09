<?php

namespace App\Models\Concerns;

use App\Models\Approval;
use App\Models\User;
use App\Support\Money;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;

/** A document that moves through the approval engine using its status column. */
trait Approvable
{
    abstract public function approvalDocType(): string;

    /** Names the document without any money figure, so every role can read it. */
    abstract public function approvalTitle(): string;

    /** The rupee figure that goes with the title, or null for a document that carries none. */
    public function approvalAmount(): ?string
    {
        return null;
    }

    /** The title with its amount for someone who may see money, and without it for everyone else. */
    public function approvalTitleFor(?User $viewer = null): string
    {
        $amount = $this->approvalAmount();

        return $amount !== null && Money::visible($viewer)
            ? $this->approvalTitle().' · '.inr($amount)
            : $this->approvalTitle();
    }

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
