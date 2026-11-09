<?php

namespace App\Services\Procurement;

use App\Models\BoqMaterialNorm;
use App\Models\IndentItem;
use App\Models\Material;
use App\Models\MaterialStore;
use App\Models\MaterialUnit;
use App\Models\MaterialUnitConversion;
use App\Models\Project;
use App\Models\StockLedgerEntry;
use App\Models\StockLevel;
use App\Support\Dec;
use App\Support\Idempotent;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Materials, units, conversions, stores, reorder levels and BOQ norms. */
class MaterialCatalogService
{
    /** A company starts with the units the product already uses elsewhere. */
    public function units(int $organizationId): Collection
    {
        $units = MaterialUnit::where('organization_id', $organizationId)->orderBy('code')->get();

        if ($units->isNotEmpty()) {
            return $units;
        }

        MaterialUnit::withoutGlobalScopes()->insertOrIgnore(array_map(fn (string $code) => [
            'organization_id' => $organizationId,
            'code' => $code,
            'created_at' => now(),
            'updated_at' => now(),
        ], config('buildos.units')));

        return MaterialUnit::where('organization_id', $organizationId)->orderBy('code')->get();
    }

    public function unitRules(): array
    {
        return [
            'code' => ['required', 'string', 'max:20', Rule::unique('material_units', 'code')->where('organization_id', Auth::user()->organization_id)],
            'name' => ['nullable', 'string', 'max:60'],
        ];
    }

    public function createUnit(array $data): MaterialUnit
    {
        return MaterialUnit::create($data);
    }

    public function materialRules(?Material $material = null): array
    {
        return [
            'code' => ['required', 'string', 'max:40', Rule::unique('materials', 'code')->where('organization_id', Auth::user()->organization_id)->ignore($material)],
            'name' => ['required', 'string', 'max:160'],
            'category' => ['nullable', 'string', 'max:60'],
            'base_unit_id' => ['bail', 'required', 'integer', org_exists('material_units')],
            'hsn_code' => ['nullable', 'string', 'max:12'],
            'gst_pct' => ['required', 'numeric', 'between:0,100', 'decimal:0,2'],
            'is_active' => ['sometimes', 'boolean'],
            'client_uuid' => ProcurementRules::clientUuid(),
        ];
    }

    public function createMaterial(array $data): Material
    {
        $organizationId = Auth::user()->organization_id;
        $uuid = $data['client_uuid'] ?? null;
        $find = fn () => $uuid ? Material::where('organization_id', $organizationId)->where('client_uuid', $uuid)->first() : null;

        if ($existing = $find()) {
            return $existing;
        }

        return Idempotent::create(fn () => Material::create($data), $find);
    }

    /** The base unit is fixed once anything has been recorded in it. */
    public function updateMaterial(Material $material, array $data): Material
    {
        unset($data['client_uuid']);

        if ((int) $data['base_unit_id'] !== (int) $material->base_unit_id && $this->isInUse($material)) {
            throw ValidationException::withMessages(['base_unit_id' => __('The base unit cannot change once this material has been used.')]);
        }

        $material->update($data);

        return $material;
    }

    public function conversionRules(): array
    {
        return [
            'unit_id' => ['bail', 'required', 'integer', org_exists('material_units')],
            'factor' => ['required', 'numeric', 'gt:0', 'decimal:0,6', 'max:999999999999'],
        ];
    }

    public function saveConversion(Material $material, array $data): MaterialUnitConversion
    {
        if ((int) $data['unit_id'] === (int) $material->base_unit_id) {
            throw ValidationException::withMessages(['unit_id' => __('Choose a unit other than the base unit.')]);
        }

        return MaterialUnitConversion::updateOrCreate(
            ['material_id' => $material->id, 'unit_id' => $data['unit_id']],
            ['organization_id' => $material->organization_id, 'factor' => $data['factor']],
        );
    }

    public function storeRules(Project $project): array
    {
        return [
            'name' => ['required', 'string', 'max:120', Rule::unique('material_stores', 'name')->where('project_id', $project->id)],
            'site_id' => ['bail', 'nullable', 'integer', Rule::exists('sites', 'id')->where('project_id', $project->id)->whereNull('deleted_at')],
            'client_uuid' => ProcurementRules::clientUuid(),
        ];
    }

    public function createStore(Project $project, array $data): MaterialStore
    {
        $uuid = $data['client_uuid'] ?? null;
        $find = fn () => $uuid ? MaterialStore::where('organization_id', $project->organization_id)->where('client_uuid', $uuid)->first() : null;

        if ($existing = $find()) {
            return $existing;
        }

        return Idempotent::create(
            fn () => MaterialStore::create([...$data, 'organization_id' => $project->organization_id, 'project_id' => $project->id]),
            $find,
        );
    }

    public function reorderRules(): array
    {
        return ['reorder_level' => ProcurementRules::optionalQty()];
    }

    public function setReorderLevel(MaterialStore $store, Material $material, mixed $level): StockLevel
    {
        return StockLevel::updateOrCreate(
            ['store_id' => $store->id, 'material_id' => $material->id],
            ['organization_id' => $store->organization_id, 'reorder_level' => blank($level) ? null : Dec::qty($level)],
        );
    }

    public function normRules(Project $project): array
    {
        return [
            'boq_item_id' => ['bail', 'required', 'integer', Rule::exists('boq_items', 'id')->where('project_id', $project->id)->whereNull('deleted_at')],
            'material_id' => ['bail', 'required', 'integer', org_exists('materials')],
            'qty_per_unit' => ['required', 'numeric', 'gt:0', 'decimal:0,6', 'max:99999999'],
            'wastage_pct' => ['nullable', 'numeric', 'between:0,100', 'decimal:0,2'],
        ];
    }

    public function saveNorm(Project $project, array $data): BoqMaterialNorm
    {
        return BoqMaterialNorm::updateOrCreate(
            ['boq_item_id' => $data['boq_item_id'], 'material_id' => $data['material_id']],
            [
                'organization_id' => $project->organization_id,
                'project_id' => $project->id,
                'qty_per_unit' => $data['qty_per_unit'],
                'wastage_pct' => $data['wastage_pct'] ?? 0,
            ],
        );
    }

    private function isInUse(Material $material): bool
    {
        return StockLedgerEntry::where('material_id', $material->id)->exists()
            || IndentItem::where('material_id', $material->id)->exists();
    }
}
