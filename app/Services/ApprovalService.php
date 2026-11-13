<?php

namespace App\Services;

use App\Models\Approval;
use App\Models\ApprovalRule;
use App\Models\ApprovalStep;
use App\Models\AuditLog;
use App\Models\Concerns\ProjectAccessScope;
use App\Models\User;
use App\Services\Notify\WorkflowNotifier;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ApprovalService
{
    public function __construct(private WorkflowNotifier $workflow) {}

    /** @return list<string> Ordered approver roles for a document type. */
    public function levelsFor(string $docType, int $organizationId): array
    {
        $rule = ApprovalRule::withoutGlobalScopes()
            ->where('organization_id', $organizationId)
            ->where('doc_type', $docType)
            ->where('active', true)
            ->first();

        return $rule?->levels ?? config("buildos.approval_defaults.{$docType}", []);
    }

    /**
     * Send a DRAFT or RETURNED document for approval.
     * With no approval levels configured, the document is approved immediately.
     *
     * @param  list<string>  $extraLevels  Roles appended to the chain, e.g. PM on a deviation.
     */
    public function submit(Model $document, User $by, array $extraLevels = []): ?Approval
    {
        if (! $document->isEditable()) {
            throw ValidationException::withMessages(['status' => __('Only draft or returned documents can be submitted.')]);
        }

        return DB::transaction(function () use ($document, $by, $extraLevels) {
            $levels = array_values(array_unique([
                ...$this->levelsFor($document->approvalDocType(), $document->organization_id),
                ...$extraLevels,
            ]));

            // With no approver configured the document would pass on its own. One its guard would stop goes
            // to the owner instead, so a company's empty approval chain cannot get round the rule.
            if ($levels === [] && $this->guardFor($document)?->blocks($document)) {
                $levels = ['owner'];
            }

            if ($levels === []) {
                $document->onApprovalApproved();
                AuditLog::record('approval.auto_approved', $document);
                $this->workflow->approvedWithoutApproval($document);

                return null;
            }

            $approval = Approval::create([
                'organization_id' => $document->organization_id,
                'approvable_type' => $document->getMorphClass(),
                'approvable_id' => $document->getKey(),
                'doc_type' => $document->approvalDocType(),
                'status' => 'IN_REVIEW',
                'current_level' => 1,
                'levels' => $levels,
                'submitted_by' => $by->id,
                'submitted_at' => now(),
            ]);

            $this->logStep($approval, 0, $by->role, $by, 'submit', null);
            $document->update(['status' => 'IN_REVIEW']);
            AuditLog::record('approval.submit', $document, null, ['approval_id' => $approval->id, 'levels' => $levels]);
            $this->workflow->approvalWaiting($approval, $document);

            return $approval;
        });
    }

    public function canAct(Approval $approval, User $user): bool
    {
        // A client level is only ever signed through the client's own link.
        if ($approval->status !== 'IN_REVIEW' || $approval->currentRole() === 'client') {
            return false;
        }

        if (! $user->hasPermission($approval->doc_type.'.approve')) {
            return false;
        }

        if ($user->isApprovalOverride()) {
            return true;
        }

        return $approval->submitted_by !== $user->id && $user->role === $approval->currentRole();
    }

    /**
     * Everything in review, split into what this user can act on and the rest.
     *
     * @return array{0: Collection<int, Approval>, 1: Collection<int, Approval>}
     */
    public function inbox(User $user): array
    {
        [$mine, $waiting] = Approval::with('approvable', 'submitter')
            ->where('status', 'IN_REVIEW')
            ->latest('id')
            ->get()
            ->partition(fn (Approval $approval) => $this->canAct($approval, $user));

        return [$mine->values(), $waiting->values()];
    }

    /**
     * Apply approve, return or reject. Return and reject need a remark.
     * A repeated $clientUuid returns the approval as it stands and applies nothing,
     * even though the caller usually cannot act on it any more.
     *
     * @param  array<string, mixed>  $context  Passed to the document's ApprovalGuard at the final approval, e.g. an owner's override reason.
     */
    public function act(Approval $approval, User $by, string $action, ?string $remark = null, ?string $clientUuid = null, array $context = []): Approval
    {
        if (! in_array($action, ['approve', 'return', 'reject'], true)) {
            throw ValidationException::withMessages(['action' => __('Unknown action.')]);
        }

        if ($action !== 'approve' && blank($remark)) {
            throw ValidationException::withMessages(['remark' => __('A remark is required to return or reject.')]);
        }

        return DB::transaction(function () use ($approval, $by, $action, $remark, $clientUuid, $context) {
            $approval = Approval::lockForUpdate()->findOrFail($approval->id);

            if ($clientUuid !== null && $this->alreadyApplied($approval, $clientUuid)) {
                return $approval;
            }

            abort_unless($this->canAct($approval, $by), 403, __('You cannot act on this approval.'));

            $this->applyAction($approval, $action, $remark, $by->role, $by, null, $clientUuid, $context);

            return $approval->refresh();
        });
    }

    /** Record a client sign-off made through a signed link, with no user account. */
    public function actAsClient(Approval $approval, string $clientName, string $action, ?string $remark = null): Approval
    {
        if (! in_array($action, ['approve', 'return'], true)) {
            throw ValidationException::withMessages(['action' => __('Unknown action.')]);
        }

        if ($action === 'return' && blank($remark)) {
            throw ValidationException::withMessages(['remark' => __('A remark is required to return or reject.')]);
        }

        return DB::transaction(function () use ($approval, $clientName, $action, $remark) {
            $approval = Approval::withoutGlobalScopes()->lockForUpdate()->findOrFail($approval->id);

            abort_unless($approval->status === 'IN_REVIEW' && $approval->currentRole() === 'client', 409, __('This document is not waiting for client sign-off.'));

            $this->applyAction($approval, $action, $remark, 'client', null, $clientName);

            return $approval->refresh();
        });
    }

    private function alreadyApplied(Approval $approval, string $clientUuid): bool
    {
        // Sees steps on projects out of reach too, so their key is refused below instead of hitting the unique index.
        $step = ApprovalStep::withoutGlobalScope(ProjectAccessScope::class)->where('client_uuid', $clientUuid)->first();

        if ($step && $step->approval_id !== $approval->id) {
            throw ValidationException::withMessages(['client_uuid' => __('This request id was already used on another approval.')]);
        }

        return $step !== null;
    }

    private function applyAction(Approval $approval, string $action, ?string $remark, string $role, ?User $by, ?string $actorName = null, ?string $clientUuid = null, array $context = []): void
    {
        $level = $approval->current_level;
        $document = $approval->approvable()->withoutGlobalScopes()->firstOrFail();

        // The final approve is what makes the document count, so that is where its rule is checked.
        if ($action === 'approve' && $approval->isFinalLevel()) {
            $this->guardFor($document)?->check($document, $by, $context);
        }

        $this->logStep($approval, $level, $role, $by, $action, $remark, $actorName, $clientUuid);
        AuditLog::record('approval.'.$action, $document, null, ['level' => $level, 'remark' => $remark, 'actor' => $by?->name ?? $actorName]);

        match (true) {
            $action === 'return' => $this->close($approval, 'RETURNED', fn () => $document->onApprovalReturned()),
            $action === 'reject' => $this->close($approval, 'REJECTED', fn () => $document->onApprovalRejected()),
            $approval->isFinalLevel() => $this->close($approval, 'APPROVED', fn () => $document->onApprovalApproved()),
            default => $approval->update(['current_level' => $level + 1]),
        };

        if ($action !== 'approve' || $approval->status === 'APPROVED') {
            $this->workflow->approvalDecided($approval, $document, $action, $remark);
        } else {
            $this->workflow->approvalWaiting($approval, $document);
        }
    }

    private function guardFor(Model $document): ?ApprovalGuard
    {
        $class = method_exists($document, 'approvalGuard') ? $document->approvalGuard() : null;

        return $class === null ? null : app($class);
    }

    private function close(Approval $approval, string $status, callable $apply): void
    {
        $approval->update(['status' => $status, 'completed_at' => now()]);
        $apply();
    }

    private function logStep(Approval $approval, int $level, ?string $role, ?User $by, string $action, ?string $remark, ?string $actorName = null, ?string $clientUuid = null): void
    {
        $approval->steps()->create([
            'organization_id' => $approval->organization_id,
            'level' => $level,
            'role' => $role,
            'user_id' => $by?->id,
            'actor_name' => $actorName,
            'action' => $action,
            'remark' => $remark,
            'acted_at' => now(),
            'client_uuid' => $clientUuid,
        ]);
    }
}
