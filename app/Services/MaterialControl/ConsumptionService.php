<?php

namespace App\Services\MaterialControl;

use App\Models\MaterialExtraWork;
use App\Models\MaterialIssue;
use App\Models\Project;
use App\Models\User;
use App\Services\Procurement\MaterialIssueService;
use App\Services\Procurement\ProcurementRules;
use App\Services\Procurement\UnitConverter;
use App\Support\ClientUuidRepeat;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/** One line of "how much was used on what", posted as a normal material issue. */
class ConsumptionService
{
    public function __construct(
        private MaterialIssueService $issues,
        private SiteStores $stores,
        private UnitConverter $units,
    ) {}

    public function rules(Project $project): array
    {
        return [
            'material_id' => ['bail', 'required', 'integer', org_exists('materials')],
            'qty' => ProcurementRules::qty(),
            'unit_id' => ['bail', 'nullable', 'integer', org_exists('material_units')],
            'store_id' => ['bail', 'nullable', 'integer', Rule::exists('material_stores', 'id')->where('project_id', $project->id)],
            'boq_item_id' => ['bail', 'nullable', 'required_without:extra_work', 'integer', Rule::exists('boq_items', 'id')->where('project_id', $project->id)->whereNull('deleted_at')],
            'stage' => ['nullable', 'string', 'max:160'],
            'used_on' => ['required', 'date', 'before_or_equal:today'],
            'extra_work' => ['nullable', 'string', 'max:500'],
            'client_uuid' => ProcurementRules::clientUuid(),
        ];
    }

    /** A repeated client_uuid returns the original and removes no stock. */
    public function create(Project $project, array $data, User $by): MaterialIssue
    {
        $uuid = $data['client_uuid'] ?? null;

        if ($uuid && ($existing = $this->find($project->organization_id, $uuid))) {
            return $existing;
        }

        $extra = filled($data['extra_work'] ?? null) ? trim($data['extra_work']) : null;

        return DB::transaction(function () use ($project, $data, $by, $uuid, $extra) {
            $issue = $this->issues->create($project, [
                'store_id' => $this->stores->resolve($project, $data['store_id'] ?? null)->id,
                'issue_date' => $data['used_on'],
                'client_uuid' => $uuid,
                'items' => [[
                    'material_id' => $data['material_id'],
                    'qty' => $data['qty'],
                    'unit_id' => $data['unit_id'] ?? null,
                    'boq_item_id' => $data['boq_item_id'] ?? null,
                    'activity' => $data['stage'] ?? ($extra ? Str::limit($extra, 157, '...') : null),
                ]],
            ], $by);

            if ($extra && $issue->wasRecentlyCreated) {
                $this->recordExtra($project, $issue, $data, $extra, $by);
            }

            return $issue;
        });
    }

    public function find(int $organizationId, string $uuid): ?MaterialIssue
    {
        return ClientUuidRepeat::reachable(MaterialIssue::withoutGlobalScopes()->where('organization_id', $organizationId)->where('client_uuid', $uuid)->first());
    }

    private function recordExtra(Project $project, MaterialIssue $issue, array $data, string $extra, User $by): void
    {
        $line = $issue->items()->with('material.conversions')->first();

        MaterialExtraWork::create([
            'organization_id' => $project->organization_id,
            'project_id' => $project->id,
            'material_id' => $line->material_id,
            'unit_id' => $line->unit_id,
            'boq_item_id' => $line->boq_item_id,
            'material_issue_id' => $issue->id,
            'description' => $extra,
            'qty' => $line->qty,
            'base_qty' => $line->base_qty,
            'work_date' => $data['used_on'],
            'status' => 'POSTED',
            'created_by' => $by->id,
        ]);
    }
}
