<?php

namespace App\Services;

use App\Exceptions\ImmutableRecordException;
use App\Models\Approval;
use App\Models\BoqItem;
use App\Models\MeasurementEntry;
use App\Models\Project;
use App\Models\User;
use App\Support\ClientUuidRepeat;
use App\Support\Dec;
use App\Support\Idempotent;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class MeasurementService
{
    public function __construct(private ApprovalService $approvals) {}

    /** One rule set for the web form, the REST API and offline sync. */
    public function rules(Project $project): array
    {
        return [
            'boq_item_id' => ['bail', 'required', 'integer', Rule::exists('boq_items', 'id')->where('project_id', $project->id)->whereNull('deleted_at')],
            // A closed work order can take no further bills, so work measured against it could never be paid.
            'work_order_id' => ['bail', 'nullable', 'integer', Rule::exists('work_orders', 'id')->where('project_id', $project->id)->where('status', 'ACTIVE')],
            'measured_on' => ['required', 'date', 'before_or_equal:today'],
            'description' => ['nullable', 'string', 'max:255'],
            'location' => ['nullable', 'string', 'max:120'],
            'nos' => ['required', 'numeric', 'gt:0', 'max:99999999'],
            'length' => ['nullable', 'numeric', 'gt:0', 'max:99999999'],
            'breadth' => ['nullable', 'numeric', 'gt:0', 'max:99999999'],
            'depth' => ['nullable', 'numeric', 'gt:0', 'max:99999999'],
            'is_deduction' => ['sometimes', 'boolean'],
            'remarks' => ['nullable', 'string', 'max:500'],
            'client_uuid' => ['nullable', 'uuid'],
        ];
    }

    /**
     * Create an entry. A repeated client_uuid returns the original row,
     * so a device that retries a sync cannot create duplicates.
     */
    public function create(Project $project, array $data, User $user): MeasurementEntry
    {
        if (! empty($data['client_uuid'])) {
            $existing = $this->findByClientUuid($project->organization_id, $data['client_uuid']);

            if ($existing) {
                return $existing;
            }
        }

        return Idempotent::create(
            fn () => MeasurementEntry::create([
                ...$data,
                'organization_id' => $project->organization_id,
                'project_id' => $project->id,
                'status' => 'DRAFT',
                'created_by' => $user->id,
            ]),
            fn () => $this->findByClientUuid($project->organization_id, $data['client_uuid'] ?? ''),
        );
    }

    /** Without a client stamp, the edit's own updated_at is what later sync writes compare against. */
    public function update(MeasurementEntry $entry, array $data): MeasurementEntry
    {
        $this->assertEditable($entry);
        $entry->update(['client_updated_at' => null, ...$data]);

        return $entry;
    }

    public function isDeviation(MeasurementEntry $entry): bool
    {
        if (Dec::of($entry->qty)->isNegativeOrZero()) {
            return false;
        }

        $item = BoqItem::withTrashed()->findOrFail($entry->boq_item_id);
        $tolerance = $entry->project->measurement_tolerance_pct ?? config('buildos.measurement_tolerance_pct');

        $approved = MeasurementEntry::where('boq_item_id', $entry->boq_item_id)->where('status', 'APPROVED')->sum('qty');
        $cumulative = Dec::of($approved)->plus(Dec::of($entry->qty));
        $limit = Dec::of($item->qty)->plus(Dec::of(Dec::percentOf($item->qty, $tolerance)));

        return $cumulative->isGreaterThan($limit);
    }

    /** Flag a deviation if there is one and send the entry for approval. */
    public function submit(MeasurementEntry $entry, User $user): ?Approval
    {
        $this->assertEditable($entry);

        return DB::transaction(function () use ($entry, $user) {
            $deviation = $this->isDeviation($entry);
            $entry->update(['deviation' => $deviation]);

            return $this->approvals->submit($entry, $user, $deviation ? ['pm'] : []);
        });
    }

    /** Correct an approved entry with a new draft entry of the opposite sign. */
    public function reverse(MeasurementEntry $entry, User $user, ?string $remarks = null): MeasurementEntry
    {
        if ($entry->status !== 'APPROVED') {
            throw ValidationException::withMessages(['status' => __('Only approved entries can be reversed.')]);
        }

        if ($entry->reversal_of_id !== null) {
            throw ValidationException::withMessages(['status' => __('A reversal entry cannot be reversed.')]);
        }

        if ($entry->reversal()->exists()) {
            throw ValidationException::withMessages(['status' => __('This entry has already been reversed.')]);
        }

        return MeasurementEntry::create([
            'organization_id' => $entry->organization_id,
            'project_id' => $entry->project_id,
            'boq_item_id' => $entry->boq_item_id,
            'work_order_id' => $entry->work_order_id,
            'measured_on' => now()->toDateString(),
            'description' => 'Reversal of entry #'.$entry->id,
            'location' => $entry->location,
            'nos' => $entry->nos,
            'length' => $entry->length,
            'breadth' => $entry->breadth,
            'depth' => $entry->depth,
            'is_deduction' => ! $entry->is_deduction,
            'status' => 'DRAFT',
            'reversal_of_id' => $entry->id,
            'remarks' => $remarks,
            'created_by' => $user->id,
        ]);
    }

    private function findByClientUuid(int $organizationId, string $uuid): ?MeasurementEntry
    {
        return ClientUuidRepeat::reachable(MeasurementEntry::withoutGlobalScopes()
            ->where('organization_id', $organizationId)
            ->where('client_uuid', $uuid)
            ->first());
    }

    private function assertEditable(MeasurementEntry $entry): void
    {
        if ($entry->status === 'APPROVED') {
            throw new ImmutableRecordException;
        }

        if (! $entry->isEditable()) {
            throw ValidationException::withMessages(['status' => __('This entry is under review and cannot be changed.')]);
        }
    }
}
