<?php

namespace App\Services\Payroll;

use App\Models\User;
use App\Models\WorkerAdvance;
use App\Support\Idempotent;
use Illuminate\Validation\ValidationException;

class AdvanceService
{
    public function rules(): array
    {
        return [
            'worker_id' => ['bail', 'required', 'integer', org_exists('workers')->whereNull('deleted_at')],
            'amount' => ['required', 'numeric', 'gt:0', 'max:99999999'],
            'given_on' => ['required', 'date', 'before_or_equal:today'],
            'recovery_per_run' => ['nullable', 'numeric', 'min:0', 'max:99999999'],
            'reason' => ['nullable', 'string', 'max:190'],
            'client_uuid' => ['nullable', 'uuid'],
        ];
    }

    /** Record an advance. A repeated client_uuid returns the original row. */
    public function give(array $data, User $by): WorkerAdvance
    {
        $uuid = $data['client_uuid'] ?? null;

        if ($uuid && ($existing = $this->findByClientUuid($by->organization_id, $uuid))) {
            return $existing;
        }

        return Idempotent::create(
            fn () => WorkerAdvance::create([
                'organization_id' => $by->organization_id,
                'worker_id' => $data['worker_id'],
                'amount' => $data['amount'],
                'given_on' => $data['given_on'],
                'recovery_per_run' => $data['recovery_per_run'] ?? 0,
                'reason' => $data['reason'] ?? null,
                'given_by' => $by->id,
                'client_uuid' => $uuid,
            ]),
            fn () => $this->findByClientUuid($by->organization_id, (string) $uuid),
        );
    }

    /** An entry made by mistake can go, but not once wages have started taking it back. */
    public function remove(WorkerAdvance $advance): void
    {
        if ($advance->recoveries()->exists()) {
            throw ValidationException::withMessages(['advance' => __('This advance is already part of a wage run and cannot be removed.')]);
        }

        $advance->delete();
    }

    private function findByClientUuid(int $organizationId, string $uuid): ?WorkerAdvance
    {
        return WorkerAdvance::withoutGlobalScopes()->where('organization_id', $organizationId)->where('client_uuid', $uuid)->first();
    }
}
