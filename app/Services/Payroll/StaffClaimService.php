<?php

namespace App\Services\Payroll;

use App\Models\AuditLog;
use App\Models\ExpenseClaim;
use App\Models\StaffLeave;
use App\Models\User;
use App\Support\Dec;
use App\Support\Idempotent;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/** Leave requests and expense claims from office and site staff, decided by someone else. */
class StaffClaimService
{
    public const LEAVE_TYPES = ['casual', 'sick', 'earned', 'unpaid'];

    public function leaveRules(): array
    {
        return [
            'leave_type' => ['required', 'in:'.implode(',', self::LEAVE_TYPES)],
            'from_date' => ['required', 'date'],
            'to_date' => ['required', 'date', 'after_or_equal:from_date'],
            'half_day' => ['nullable', 'boolean'],
            'reason' => ['nullable', 'string', 'max:190'],
            'client_uuid' => ['nullable', 'uuid'],
        ];
    }

    public function claimRules(): array
    {
        return [
            'claim_date' => ['required', 'date', 'before_or_equal:today'],
            'category' => ['required', 'in:'.implode(',', config('buildos.expense_categories'))],
            'amount' => ['required', 'numeric', 'gt:0', 'max:99999999'],
            'project_id' => ['nullable', 'integer', org_exists('projects')->whereNull('deleted_at')],
            'description' => ['nullable', 'string', 'max:190'],
            'client_uuid' => ['nullable', 'uuid'],
        ];
    }

    public function requestLeave(User $user, array $data): StaffLeave
    {
        $uuid = $data['client_uuid'] ?? null;

        if ($uuid && ($existing = StaffLeave::withoutGlobalScopes()->where('organization_id', $user->organization_id)->where('client_uuid', $uuid)->first())) {
            return $existing;
        }

        $from = Carbon::parse($data['from_date'])->startOfDay();
        $to = Carbon::parse($data['to_date'])->startOfDay();
        $half = (bool) ($data['half_day'] ?? false);

        if ($half && ! $from->equalTo($to)) {
            throw ValidationException::withMessages(['half_day' => __('A half day leave must start and end on the same date.')]);
        }

        $clash = StaffLeave::withoutGlobalScopes()
            ->where('organization_id', $user->organization_id)
            ->where('user_id', $user->id)
            ->whereIn('status', ['PENDING', 'APPROVED'])
            ->whereDate('from_date', '<=', $to->toDateString())
            ->whereDate('to_date', '>=', $from->toDateString())
            ->exists();

        if ($clash) {
            throw ValidationException::withMessages(['from_date' => __('You already have leave on some of these dates.')]);
        }

        return Idempotent::create(
            fn () => StaffLeave::create([
                'organization_id' => $user->organization_id,
                'user_id' => $user->id,
                'leave_type' => $data['leave_type'],
                'from_date' => $from->toDateString(),
                'to_date' => $to->toDateString(),
                'half_day' => $half,
                'days' => $half ? '0.500' : Dec::qty($from->diffInDays($to) + 1),
                'reason' => $data['reason'] ?? null,
                'client_uuid' => $uuid,
            ]),
            fn () => StaffLeave::withoutGlobalScopes()->where('organization_id', $user->organization_id)->where('client_uuid', (string) $uuid)->first(),
        );
    }

    /** @param  'approve'|'reject'  $action */
    public function decideLeave(StaffLeave $leave, User $by, string $action, ?string $note = null): StaffLeave
    {
        $this->assertCanDecide($leave->user_id, $by, $leave->status, 'PENDING');
        $this->assertNote($action, $note);

        $leave->update([
            'status' => $action === 'approve' ? 'APPROVED' : 'REJECTED',
            'decided_by' => $by->id,
            'decided_at' => now(),
            'decision_note' => $note,
        ]);

        return $leave;
    }

    /** The person who asked can withdraw a request that nobody has decided yet. */
    public function cancelLeave(StaffLeave $leave, User $by): StaffLeave
    {
        abort_unless($leave->user_id === $by->id, 403);

        if ($leave->status !== 'PENDING') {
            throw ValidationException::withMessages(['status' => __('Only a request that is still waiting can be withdrawn.')]);
        }

        $leave->update(['status' => 'CANCELLED']);

        return $leave;
    }

    public function submitClaim(User $user, array $data): ExpenseClaim
    {
        $uuid = $data['client_uuid'] ?? null;

        if ($uuid && ($existing = ExpenseClaim::withoutGlobalScopes()->where('organization_id', $user->organization_id)->where('client_uuid', $uuid)->first())) {
            return $existing;
        }

        return Idempotent::create(
            fn () => ExpenseClaim::create([
                'organization_id' => $user->organization_id,
                'user_id' => $user->id,
                'project_id' => $data['project_id'] ?? null,
                'claim_date' => $data['claim_date'],
                'category' => $data['category'],
                'amount' => $data['amount'],
                'description' => $data['description'] ?? null,
                'client_uuid' => $uuid,
            ]),
            fn () => ExpenseClaim::withoutGlobalScopes()->where('organization_id', $user->organization_id)->where('client_uuid', (string) $uuid)->first(),
        );
    }

    /** @param  'approve'|'reject'  $action */
    public function decideClaim(ExpenseClaim $claim, User $by, string $action, ?string $note = null): ExpenseClaim
    {
        $this->assertCanDecide($claim->user_id, $by, $claim->status, 'PENDING');
        $this->assertNote($action, $note);

        $claim->update([
            'status' => $action === 'approve' ? 'APPROVED' : 'REJECTED',
            'decided_by' => $by->id,
            'decided_at' => now(),
            'decision_note' => $note,
        ]);
        AuditLog::record('expense_claim.'.$action, $claim, null, ['amount' => $claim->amount]);

        return $claim;
    }

    public function markClaimPaid(ExpenseClaim $claim): ExpenseClaim
    {
        if ($claim->status !== 'APPROVED') {
            throw ValidationException::withMessages(['status' => __('Only an approved claim can be marked as paid.')]);
        }

        $claim->update(['status' => 'PAID', 'paid_at' => now()]);

        return $claim;
    }

    public function cancelClaim(ExpenseClaim $claim, User $by): void
    {
        abort_unless($claim->user_id === $by->id, 403);

        if ($claim->status !== 'PENDING') {
            throw ValidationException::withMessages(['status' => __('Only a claim that is still waiting can be withdrawn.')]);
        }

        $claim->delete();
    }

    /** A request waits for someone else. Owners and admins may decide any request, as on approvals. */
    private function assertCanDecide(int $requesterId, User $by, string $status, string $needed): void
    {
        if ($status !== $needed) {
            throw ValidationException::withMessages(['status' => __('This request has already been decided.')]);
        }

        if ($requesterId === $by->id && ! $by->isApprovalOverride()) {
            throw ValidationException::withMessages(['status' => __('You cannot decide your own request.')]);
        }
    }

    private function assertNote(string $action, ?string $note): void
    {
        if (! in_array($action, ['approve', 'reject'], true)) {
            throw ValidationException::withMessages(['action' => __('Unknown action.')]);
        }

        if ($action === 'reject' && blank($note)) {
            throw ValidationException::withMessages(['note' => __('Say why the request is rejected.')]);
        }
    }
}
