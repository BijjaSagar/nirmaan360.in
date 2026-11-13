<?php

namespace App\Services\BillCheck;

use App\Models\AuditLog;
use App\Models\BillCheck;
use App\Models\BillCheckAck;
use App\Models\BillCheckPolicy;
use App\Models\RaBill;
use App\Models\User;
use App\Support\Idempotent;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Checking a bill, acknowledging what a check found, and the company's choice to require it.
 */
class BillChecks
{
    public const REMARK_MIN = 5;

    public function __construct(private RaBillPreCheck $preCheck) {}

    /** The newest check of a bill, which is the one the bill page shows. */
    public function latest(RaBill $bill): ?BillCheck
    {
        return BillCheck::withoutGlobalScopes()
            ->where('organization_id', $bill->organization_id)
            ->where('ra_bill_id', $bill->id)
            ->latest('id')
            ->first();
    }

    /**
     * Check a bill that can still be submitted. Returns the check and whether it is a new one: when
     * nothing at all has changed since the newest check, that check stands with its acknowledgements
     * and no second copy is written.
     *
     * @return array{0: BillCheck, 1: bool}
     *
     * @throws ValidationException
     */
    public function check(RaBill $bill, User $by): array
    {
        return DB::transaction(function () use ($bill, $by) {
            $bill = $this->locked($bill);

            if (! $bill->isEditable()) {
                throw ValidationException::withMessages(['check' => __('Only a draft or returned bill can be checked. This one has already been submitted.')]);
            }

            $result = $this->preCheck->inspect($bill);
            $latest = $this->latest($bill);

            if ($latest !== null && $latest->flags_hash === $result->hash() && $latest->expected == $result->expected) {
                return [$latest, false];
            }

            return [$this->preCheck->store($bill, $result, $by), true];
        });
    }

    /** @return array<string, list<mixed>> */
    public function ackRules(): array
    {
        return [
            'flag_key' => ['required', 'string', 'max:80'],
            'remark' => ['required', 'string', 'min:'.self::REMARK_MIN, 'max:500'],
        ];
    }

    /**
     * Record that this person has seen one finding of a check, with their remark. Only the newest
     * check of a bill that can still be submitted takes acknowledgements, and only for a finding
     * that needs one. Acknowledging the same finding again returns the first acknowledgement.
     *
     * @throws ValidationException
     */
    public function acknowledge(BillCheck $check, string $flagKey, string $remark, User $by): BillCheckAck
    {
        return DB::transaction(function () use ($check, $flagKey, $remark, $by) {
            $bill = $this->locked($check->ra_bill_id);
            $stored = $check->flag($flagKey);

            if ($stored === null) {
                throw ValidationException::withMessages(['flag_key' => __('This finding is not part of this check.')]);
            }

            if (! Flag::fromArray($stored)->needsAck()) {
                throw ValidationException::withMessages(['flag_key' => __('This finding is for information and needs no acknowledgement.')]);
            }

            $find = fn () => BillCheckAck::withoutGlobalScopes()->where('bill_check_id', $check->id)->where('flag_key', $flagKey)->first();

            if ($existing = $find()) {
                return $existing;
            }

            if (! $bill->isEditable()) {
                throw ValidationException::withMessages(['flag_key' => __('This bill has already been submitted, so its check can no longer be acknowledged.')]);
            }

            if ($this->latest($bill)?->id !== $check->id) {
                throw ValidationException::withMessages(['flag_key' => __('This bill has been checked again since. Acknowledge the findings of the newest check.')]);
            }

            $ack = Idempotent::create(fn () => BillCheckAck::create([
                'organization_id' => $check->organization_id,
                'project_id' => $check->project_id,
                'bill_check_id' => $check->id,
                'flag_key' => $flagKey,
                'remark' => $remark,
                'user_id' => $by->id,
            ]), $find);

            if ($ack->wasRecentlyCreated) {
                AuditLog::record('bill_check.acknowledged', $check, null, ['ra_bill_id' => $bill->id, 'flag_key' => $flagKey, 'remark' => $remark], $by);
            }

            return $ack;
        });
    }

    /** Whether the company wants every finding acknowledged before a bill is submitted. Off until an owner or admin turns it on. */
    public function isRequired(int $organizationId): bool
    {
        return (bool) BillCheckPolicy::withoutGlobalScopes()->where('organization_id', $organizationId)->value('required');
    }

    public function setRequired(int $organizationId, bool $required, User $by): BillCheckPolicy
    {
        return DB::transaction(function () use ($organizationId, $required, $by) {
            $find = fn () => BillCheckPolicy::withoutGlobalScopes()->where('organization_id', $organizationId)->lockForUpdate()->first();

            $policy = $find() ?? Idempotent::create(
                fn () => BillCheckPolicy::create(['organization_id' => $organizationId, 'required' => $required, 'updated_by' => $by->id]),
                $find,
            );

            if ($policy->required !== $required) {
                $policy->update(['required' => $required, 'updated_by' => $by->id]);
            }

            return $policy;
        });
    }

    /** The bill as it is saved now, held until the surrounding transaction ends, so two people cannot check or acknowledge it at once. */
    public function locked(RaBill|int $bill): RaBill
    {
        $id = $bill instanceof RaBill ? $bill->id : $bill;

        return RaBill::withoutGlobalScopes()->whereKey($id)->lockForUpdate()->first()
            ?? throw (new ModelNotFoundException)->setModel(RaBill::class, [$id]);
    }
}
