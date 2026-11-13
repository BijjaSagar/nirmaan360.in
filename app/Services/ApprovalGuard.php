<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * A rule a document must pass at its final approval, named by the document's approvalGuard().
 * ApprovalService runs it just before the last approve takes effect, inside the same transaction.
 */
interface ApprovalGuard
{
    /** Whether approving the document as it stands now would break the rule. */
    public function blocks(Model $document): bool;

    /**
     * Refuse the approval, or let it through. An override the rule allows is recorded here.
     *
     * @param  array<string, mixed>  $context  What the approver sent with the decision, such as an override reason.
     *
     * @throws ValidationException
     */
    public function check(Model $document, ?User $by, array $context): void;
}
