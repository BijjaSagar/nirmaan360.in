<?php

namespace App\Services\MaterialControl;

use App\Models\Material;
use App\Models\MaterialStockCheck;
use App\Models\Project;
use App\Models\User;
use App\Services\Procurement\ProcurementRules;
use App\Services\Procurement\StockLedgerService;
use App\Services\Procurement\UnitConverter;
use App\Support\ClientUuidRepeat;
use App\Support\Dec;
use App\Support\Idempotent;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * A physical count. The ledger is brought into line with it, so stock that is gone cannot
 * later be issued against the book to hide the loss. The missing quantity stays visible as
 * unaccounted material: received - used - stock on site.
 */
class StockCheckService
{
    public function __construct(
        private StockLedgerService $ledger,
        private UnitConverter $units,
        private SiteStores $stores,
    ) {}

    public function rules(Project $project): array
    {
        return [
            'material_id' => ['bail', 'required', 'integer', org_exists('materials')],
            'physical_qty' => ['required', 'numeric', 'min:0', 'decimal:0,3', 'max:99999999999'],
            'unit_id' => ['bail', 'nullable', 'integer', org_exists('material_units')],
            'store_id' => ['bail', 'nullable', 'integer', Rule::exists('material_stores', 'id')->where('project_id', $project->id)],
            'check_date' => ['required', 'date', 'before_or_equal:today'],
            'remarks' => ['nullable', 'string', 'max:500'],
            'client_uuid' => ProcurementRules::clientUuid(),
        ];
    }

    public function create(Project $project, array $data, User $by): MaterialStockCheck
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

    public function reverse(MaterialStockCheck $check, string $reason, User $by): MaterialStockCheck
    {
        return DB::transaction(function () use ($check, $reason, $by) {
            $check = MaterialStockCheck::lockForUpdate()->findOrFail($check->id);

            if ($check->isReversed()) {
                throw ValidationException::withMessages(['status' => __('This document has already been reversed.')]);
            }

            $this->ledger->reverse($check, $by);
            $check->update(['status' => 'REVERSED', 'reversed_by' => $by->id, 'reversed_at' => now(), 'reversal_reason' => $reason]);

            return $check;
        });
    }

    public function find(int $organizationId, string $uuid): ?MaterialStockCheck
    {
        return ClientUuidRepeat::reachable(MaterialStockCheck::withoutGlobalScopes()->where('organization_id', $organizationId)->where('client_uuid', $uuid)->first());
    }

    private function post(Project $project, array $data, User $by): MaterialStockCheck
    {
        $organizationId = $project->organization_id;
        $material = Material::with('conversions')->findOrFail($data['material_id']);
        $store = $this->stores->resolve($project, $data['store_id'] ?? null);
        $unitId = $data['unit_id'] ?? $material->base_unit_id;

        // Counting nothing is a real answer, and the unit converter refuses a zero quantity.
        $physical = Dec::of($data['physical_qty'])->isZero() ? Dec::qty(0) : $this->units->toBase($material, $data['physical_qty'], $unitId);

        // Held from here to commit, so the book balance cannot move while we compare against it.
        $this->ledger->lockPairs($organizationId, [['store_id' => $store->id, 'material_id' => $material->id]]);

        $book = $this->ledger->balance($store->id, $material->id);
        $variance = Dec::qty(Dec::of($physical)->minus(Dec::of($book)));

        $check = MaterialStockCheck::create([
            'organization_id' => $organizationId,
            'project_id' => $project->id,
            'store_id' => $store->id,
            'material_id' => $material->id,
            'unit_id' => $unitId,
            'check_date' => $data['check_date'],
            'physical_qty' => Dec::qty($data['physical_qty']),
            'physical_base_qty' => $physical,
            'book_qty' => $book,
            'variance_qty' => $variance,
            'remarks' => $data['remarks'] ?? null,
            'status' => 'POSTED',
            'client_uuid' => $data['client_uuid'] ?? null,
            'created_by' => $by->id,
        ]);

        if (! Dec::of($variance)->isZero()) {
            $this->ledger->post($organizationId, [[
                'project_id' => $project->id,
                'store_id' => $store->id,
                'material_id' => $material->id,
                'type' => StockLedgerService::ADJUSTMENT,
                'qty' => $variance,
                'date' => $data['check_date'],
                'source' => $check,
                'key' => 'stock_check:'.$check->id,
                'activity' => 'Physical stock check',
            ]], $by);
        }

        return $check;
    }
}
