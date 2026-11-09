<?php

namespace App\Services;

use App\Models\Approval;
use App\Models\ApprovalRule;
use App\Models\AuditLog;
use App\Models\User;
use App\Notifications\ApprovalUpdate;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ApprovalService
{
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

            if ($levels === []) {
                $document->onApprovalApproved();
                AuditLog::record('approval.auto_approved', $document);

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

    /** Apply approve, return or reject. Return and reject need a remark. */
    public function act(Approval $approval, User $by, string $action, ?string $remark = null): Approval
    {
        if (! in_array($action, ['approve', 'return', 'reject'], true)) {
            throw ValidationException::withMessages(['action' => __('Unknown action.')]);
        }

        if ($action !== 'approve' && blank($remark)) {
            throw ValidationException::withMessages(['remark' => __('A remark is required to return or reject.')]);
        }

        return DB::transaction(function () use ($approval, $by, $action, $remark) {
            $approval = Approval::lockForUpdate()->findOrFail($approval->id);

            abort_unless($this->canAct($approval, $by), 403, __('You cannot act on this approval.'));

            $this->applyAction($approval, $action, $remark, $by->role, $by);

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

    private function applyAction(Approval $approval, string $action, ?string $remark, string $role, ?User $by, ?string $actorName = null): void
    {
        $level = $approval->current_level;
        $document = $approval->approvable()->withoutGlobalScopes()->firstOrFail();

        $this->logStep($approval, $level, $role, $by, $action, $remark, $actorName);
        AuditLog::record('approval.'.$action, $document, null, ['level' => $level, 'remark' => $remark, 'actor' => $by?->name ?? $actorName]);

        match (true) {
            $action === 'return' => $this->close($approval, 'RETURNED', fn () => $document->onApprovalReturned()),
            $action === 'reject' => $this->close($approval, 'REJECTED', fn () => $document->onApprovalRejected()),
            $approval->isFinalLevel() => $this->close($approval, 'APPROVED', fn () => $document->onApprovalApproved()),
            default => $approval->update(['current_level' => $level + 1]),
        };

        if ($action !== 'approve' || $approval->status === 'APPROVED') {
            $this->notifySubmitter($approval, $document, $action, $remark);
        }
    }

    private function close(Approval $approval, string $status, callable $apply): void
    {
        $approval->update(['status' => $status, 'completed_at' => now()]);
        $apply();
    }

    private function logStep(Approval $approval, int $level, ?string $role, ?User $by, string $action, ?string $remark, ?string $actorName = null): void
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
        ]);
    }

    private function notifySubmitter(Approval $approval, Model $document, string $action, ?string $remark): void
    {
        $submitter = User::find($approval->submitted_by);

        if (! $submitter) {
            return;
        }

        $verb = ['approve' => 'approved', 'return' => 'returned', 'reject' => 'rejected'][$action];

        $submitter->notify(new ApprovalUpdate(
            title: ucfirst($verb).': '.$document->approvalTitle(),
            body: (string) $remark,
        ));
    }
}
