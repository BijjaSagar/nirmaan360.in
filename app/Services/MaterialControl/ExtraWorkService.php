<?php

namespace App\Services\MaterialControl;

use App\Models\Material;
use App\Models\MaterialExtraWork;
use App\Models\Project;
use App\Models\User;
use App\Services\Procurement\ProcurementRules;
use App\Services\Procurement\UnitConverter;
use App\Support\ClientUuidRepeat;
use App\Support\Dec;
use App\Support\Idempotent;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Records what extra work a quantity of material was for, so it is left out of wastage. */
class ExtraWorkService
{
    public function __construct(private UnitConverter $units) {}

    public function rules(Project $project): array
    {
        return [
            'material_id' => ['bail', 'required', 'integer', org_exists('materials')],
            'qty' => ProcurementRules::qty(),
            'unit_id' => ['bail', 'nullable', 'integer', org_exists('material_units')],
            'boq_item_id' => ['bail', 'nullable', 'integer', Rule::exists('boq_items', 'id')->where('project_id', $project->id)->whereNull('deleted_at')],
            'description' => ['required', 'string', 'max:500'],
            'work_date' => ['required', 'date', 'before_or_equal:today'],
            'client_uuid' => ProcurementRules::clientUuid(),
        ];
    }

    public function create(Project $project, array $data, User $by): MaterialExtraWork
    {
        $uuid = $data['client_uuid'] ?? null;

        if ($uuid && ($existing = $this->find($project->organization_id, $uuid))) {
            return $existing;
        }

        return DB::transaction(fn () => Idempotent::create(function () use ($project, $data, $by) {
            $material = Material::with('conversions')->findOrFail($data['material_id']);
            $unitId = $data['unit_id'] ?? $material->base_unit_id;

            return MaterialExtraWork::create([
                'organization_id' => $project->organization_id,
                'project_id' => $project->id,
                'material_id' => $material->id,
                'unit_id' => $unitId,
                'boq_item_id' => $data['boq_item_id'] ?? null,
                'description' => $data['description'],
                'qty' => Dec::qty($data['qty']),
                'base_qty' => $this->units->toBase($material, $data['qty'], $unitId),
                'work_date' => $data['work_date'],
                'status' => 'POSTED',
                'client_uuid' => $data['client_uuid'] ?? null,
                'created_by' => $by->id,
            ]);
        }, fn () => $this->find($project->organization_id, (string) $uuid)), 3);
    }

    public function reverse(MaterialExtraWork $work, string $reason, User $by): MaterialExtraWork
    {
        return DB::transaction(function () use ($work, $reason, $by) {
            $work = MaterialExtraWork::lockForUpdate()->findOrFail($work->id);

            if ($work->isReversed()) {
                throw ValidationException::withMessages(['status' => __('This document has already been reversed.')]);
            }

            $work->update(['status' => 'REVERSED', 'reversed_by' => $by->id, 'reversed_at' => now(), 'reversal_reason' => $reason]);

            return $work;
        });
    }

    private function find(int $organizationId, string $uuid): ?MaterialExtraWork
    {
        return ClientUuidRepeat::reachable(MaterialExtraWork::withoutGlobalScopes()->where('organization_id', $organizationId)->where('client_uuid', $uuid)->first());
    }
}
