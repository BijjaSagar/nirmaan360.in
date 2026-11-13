<?php

namespace App\Services\ContractorControl;

use App\Models\Project;
use App\Models\User;
use App\Support\Dec;
use Illuminate\Validation\ValidationException;

/**
 * A project's payment-ahead limit and deadline, set from the Work vs payment board.
 *
 * The limit (payment_gap_alert_pct) raises the payment-ahead alert and is also the line PaymentAheadGuard holds
 * at a contractor payment's final approval. So only the owner may change it, as only the owner may approve past
 * it: otherwise the project manager, who approves contractor payments, could lift the limit, approve a payment
 * ahead of the work and put it back. The deadline stays with everyone who holds contractor.manage. Project is
 * Auditable, so every change is in the audit log with its old and new values.
 */
class ProjectLimits
{
    public function canChangeLimit(User $user): bool
    {
        return $user->isOwner();
    }

    public function rules(User $user): array
    {
        return [
            'payment_gap_alert_pct' => [$this->canChangeLimit($user) ? 'required' : 'nullable', 'numeric', 'between:0,100', 'decimal:0,2'],
            'deadline_date' => ['nullable', 'date'],
        ];
    }

    /** A limit sent back unchanged, as a page opened before this rule would, is not a change. */
    public function update(Project $project, array $data, User $by): Project
    {
        $changes = array_intersect_key($data, ['deadline_date' => true]);
        $limit = $data['payment_gap_alert_pct'] ?? null;

        if ($limit !== null && ! Dec::of($limit)->isEqualTo(Dec::of($project->payment_gap_alert_pct))) {
            if (! $this->canChangeLimit($by)) {
                throw ValidationException::withMessages(['payment_gap_alert_pct' => __('Only the owner can change this limit, because it also decides which contractor payments need the owner\'s written reason.')]);
            }

            $changes['payment_gap_alert_pct'] = Dec::money($limit);
        }

        $project->update($changes);

        return $project;
    }
}
