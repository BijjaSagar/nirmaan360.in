<?php

namespace App\Services;

use App\Models\Worker;
use App\Support\Idempotent;
use Illuminate\Validation\Rule;

class WorkerService
{
    /** One rule set for the web form and the REST API. */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'phone' => ['nullable', 'string', 'max:20'],
            'trade' => ['required', 'string', 'max:60'],
            'type' => ['required', Rule::in(['own', 'contractor'])],
            'contractor_id' => ['bail', 'nullable', 'required_if:type,contractor', 'integer', org_exists('parties')],
            'daily_wage' => ['required', 'numeric', 'min:0', 'max:99999999'],
            'client_uuid' => ['nullable', 'uuid'],
        ];
    }

    /** Add a worker. A repeated client_uuid returns the original row. */
    public function create(array $data): Worker
    {
        $uuid = $data['client_uuid'] ?? null;

        if ($uuid && ($existing = $this->findByClientUuid($uuid))) {
            return $existing;
        }

        return Idempotent::create(
            fn () => Worker::create(['type' => 'own', 'daily_wage' => 0, 'is_active' => true, ...$data]),
            fn () => $this->findByClientUuid((string) $uuid),
        );
    }

    /** Trashed rows count: the unique key still holds their client_uuid. */
    private function findByClientUuid(string $uuid): ?Worker
    {
        return Worker::withTrashed()->where('client_uuid', $uuid)->first();
    }
}
