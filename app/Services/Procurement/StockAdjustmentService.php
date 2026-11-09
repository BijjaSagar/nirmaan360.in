<?php

namespace App\Services\Procurement;

use App\Models\Material;
use App\Models\Project;
use App\Models\StockAdjustment;
use App\Models\StockLedgerEntry;
use App\Models\User;
use App\Support\Dec;
use App\Support\Idempotent;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Opening stock and physical-count corrections. */
class StockAdjustmentService
{
    public function __construct(
        private StockLedgerService $ledger,
        private UnitConverter $units,
        private DocumentNumbers $numbers,
    ) {}

    public function rules(Project $project): array
    {
        return [
            'store_id' => ['bail', 'required', 'integer', Rule::exists('material_stores', 'id')->where('project_id', $project->id)],
            'material_id' => ['bail', 'required', 'integer', org_exists('materials')],
            'adjustment_type' => ['required', Rule::in(['opening', 'increase', 'decrease'])],
            'qty' => ProcurementRules::qty(),
            'unit_id' => ['bail', 'nullable', 'integer', org_exists('material_units')],
            'adjustment_date' => ['required', 'date', 'before_or_equal:today'],
            'reason' => ['required', 'string', 'max:255'],
            'client_uuid' => ProcurementRules::clientUuid(),
        ];
    }

    public function create(Project $project, array $data, User $by): StockAdjustment
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

    public function reverse(StockAdjustment $adjustment, string $reason, User $by): StockAdjustment
    {
        return DB::transaction(function () use ($adjustment, $reason, $by) {
            $adjustment = StockAdjustment::lockForUpdate()->findOrFail($adjustment->id);

            if ($adjustment->isReversed()) {
                throw ValidationException::withMessages(['status' => __('This document has already been reversed.')]);
            }

            $this->ledger->reverse($adjustment, $by);
            $adjustment->update(['status' => 'REVERSED', 'reversed_by' => $by->id, 'reversed_at' => now(), 'reversal_reason' => $reason]);

            return $adjustment;
        });
    }

    private function post(Project $project, array $data, User $by): StockAdjustment
    {
        $material = Material::with('conversions')->findOrFail($data['material_id']);
        $base = $this->units->toBase($material, $data['qty'], $data['unit_id'] ?? null);
        $signed = $data['adjustment_type'] === 'decrease' ? Dec::qty(Dec::of($base)->negated()) : $base;
        $pair = ['store_id' => $data['store_id'], 'material_id' => $material->id];

        $this->ledger->lockPairs($project->organization_id, [$pair]);

        if ($data['adjustment_type'] === 'opening' && StockLedgerEntry::where($pair)->exists()) {
            throw ValidationException::withMessages(['adjustment_type' => __('This store already has movements for this material. Use an increase or decrease instead.')]);
        }

        $adjustment = StockAdjustment::create([
            'organization_id' => $project->organization_id,
            'project_id' => $project->id,
            'store_id' => $data['store_id'],
            'material_id' => $material->id,
            'number' => $this->numbers->next($project->organization_id, 'ADJ'),
            'adjustment_type' => $data['adjustment_type'],
            'qty' => Dec::qty($data['qty']),
            'unit_id' => $data['unit_id'] ?? $material->base_unit_id,
            'base_qty' => $signed,
            'adjustment_date' => $data['adjustment_date'],
            'reason' => $data['reason'],
            'status' => 'POSTED',
            'client_uuid' => $data['client_uuid'] ?? null,
            'created_by' => $by->id,
        ]);

        $this->ledger->post($project->organization_id, [[
            'project_id' => $project->id,
            'store_id' => $adjustment->store_id,
            'material_id' => $material->id,
            'type' => $data['adjustment_type'] === 'opening' ? StockLedgerService::OPENING : StockLedgerService::ADJUSTMENT,
            'qty' => $signed,
            'date' => $data['adjustment_date'],
            'source' => $adjustment,
            'key' => 'adjustment:'.$adjustment->id,
        ]], $by);

        return $adjustment;
    }

    private function find(int $organizationId, string $uuid): ?StockAdjustment
    {
        return StockAdjustment::where('organization_id', $organizationId)->where('client_uuid', $uuid)->first();
    }
}
