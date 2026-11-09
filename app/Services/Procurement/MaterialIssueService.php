<?php

namespace App\Services\Procurement;

use App\Models\AuditLog;
use App\Models\Material;
use App\Models\MaterialIssue;
use App\Models\Project;
use App\Models\User;
use App\Support\Dec;
use App\Support\Idempotent;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Material leaving a store for work: to a BOQ item, an activity, or both. */
class MaterialIssueService
{
    public function __construct(
        private StockLedgerService $ledger,
        private ReconciliationService $reconciliation,
        private UnitConverter $units,
        private DocumentNumbers $numbers,
    ) {}

    public function rules(Project $project): array
    {
        return [
            'store_id' => ['bail', 'required', 'integer', Rule::exists('material_stores', 'id')->where('project_id', $project->id)],
            'issue_date' => ['required', 'date', 'before_or_equal:today'],
            'issued_to' => ['nullable', 'string', 'max:160'],
            'remarks' => ['nullable', 'string', 'max:2000'],
            'client_uuid' => ProcurementRules::clientUuid(),
            'items' => ['required', 'array', 'min:1', 'max:200'],
            'items.*.material_id' => ['bail', 'required', 'integer', org_exists('materials')],
            'items.*.qty' => ProcurementRules::qty(),
            'items.*.unit_id' => ['bail', 'nullable', 'integer', org_exists('material_units')],
            'items.*.boq_item_id' => ['bail', 'nullable', 'integer', 'required_without:items.*.activity', Rule::exists('boq_items', 'id')->where('project_id', $project->id)->whereNull('deleted_at')],
            'items.*.activity' => ['nullable', 'string', 'max:160'],
        ];
    }

    /** Post an issue. A repeated client_uuid returns the original and removes no stock. */
    public function create(Project $project, array $data, User $by): MaterialIssue
    {
        $uuid = $data['client_uuid'] ?? null;

        if ($uuid && ($existing = $this->find($project->organization_id, $uuid))) {
            return $existing;
        }

        return DB::transaction(fn () => Idempotent::create(
            fn () => $this->post($project, $data, $by),
            fn () => $this->find($project->organization_id, (string) $uuid),
        ), 3);
    }

    public function reverse(MaterialIssue $issue, string $reason, User $by): MaterialIssue
    {
        return DB::transaction(function () use ($issue, $reason, $by) {
            $issue = MaterialIssue::lockForUpdate()->findOrFail($issue->id);

            if ($issue->isReversed()) {
                throw ValidationException::withMessages(['status' => __('This document has already been reversed.')]);
            }

            if ($issue->returns()->where('status', 'POSTED')->exists()) {
                throw ValidationException::withMessages(['status' => __('Reverse the returns on this issue first.')]);
            }

            $this->ledger->reverse($issue, $by);
            $issue->update(['status' => 'REVERSED', 'reversed_by' => $by->id, 'reversed_at' => now(), 'reversal_reason' => $reason]);

            return $issue;
        });
    }

    public function find(int $organizationId, string $uuid): ?MaterialIssue
    {
        return MaterialIssue::where('organization_id', $organizationId)->where('client_uuid', $uuid)->first();
    }

    private function post(Project $project, array $data, User $by): MaterialIssue
    {
        $organizationId = $project->organization_id;
        $uuid = $data['client_uuid'] ?? null;
        $materials = Material::with('conversions')->whereIn('id', array_column($data['items'], 'material_id'))->get()->keyBy('id');

        // Held from here to commit: the same material on two devices waits its turn.
        $this->ledger->lockPairs($organizationId, array_map(
            fn ($line) => ['store_id' => $data['store_id'], 'material_id' => $line['material_id']],
            $data['items'],
        ));

        if ($uuid && ($existing = $this->find($organizationId, $uuid))) {
            return $existing;
        }

        $issue = MaterialIssue::create([
            'organization_id' => $organizationId,
            'project_id' => $project->id,
            'store_id' => $data['store_id'],
            'number' => $this->numbers->next($organizationId, 'MI'),
            'issue_date' => $data['issue_date'],
            'issued_to' => $data['issued_to'] ?? null,
            'remarks' => $data['remarks'] ?? null,
            'status' => 'POSTED',
            'client_uuid' => $uuid,
            'created_by' => $by->id,
        ]);

        $movements = [];

        foreach ($data['items'] as $line) {
            $material = $materials->get($line['material_id']);
            $unitId = $line['unit_id'] ?? $material->base_unit_id;
            $base = $this->units->toBase($material, $line['qty'], $unitId);

            $item = $issue->items()->create([
                'organization_id' => $organizationId,
                'material_id' => $material->id,
                'qty' => Dec::qty($line['qty']),
                'unit_id' => $unitId,
                'base_qty' => $base,
                'boq_item_id' => $line['boq_item_id'] ?? null,
                'activity' => $line['activity'] ?? null,
            ]);

            $movements[] = [
                'project_id' => $project->id,
                'store_id' => $issue->store_id,
                'material_id' => $material->id,
                'type' => StockLedgerService::ISSUE,
                'qty' => Dec::qty(Dec::of($base)->negated()),
                'date' => $data['issue_date'],
                'source' => $issue,
                'key' => 'issue_item:'.$item->id,
                'boq_item_id' => $item->boq_item_id,
                'activity' => $item->activity,
            ];
        }

        $rows = $this->ledger->post($organizationId, $movements, $by);
        $this->reconciliation->alertOnIssue($project, $rows);
        AuditLog::record('material_issue.posted', $issue, null, ['lines' => count($movements)]);

        return $issue;
    }
}
