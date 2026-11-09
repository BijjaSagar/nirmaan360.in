<?php

namespace Tests\Feature\MaterialControl;

use App\Models\BoqItem;
use App\Models\BoqMaterialNorm;
use App\Models\DeliveryChallan;
use App\Models\Material;
use App\Models\MaterialIssue;
use App\Models\MaterialStockCheck;
use App\Models\MaterialUnit;
use App\Models\Party;
use App\Models\ProgressEntry;
use App\Services\MaterialControl\ConsumptionService;
use App\Services\MaterialControl\DeliveryChallanService;
use App\Services\MaterialControl\MaterialCheckService;
use App\Services\MaterialControl\StockCheckService;
use App\Services\SiteControl\WorkProgress;
use Tests\Feature\Procurement\BuildsProcurement;

/** The procurement world, plus helpers that tell the section 8 examples in the same words. */
trait BuildsMaterialControl
{
    use BuildsProcurement;

    protected function setUpControl(): void
    {
        $this->buildWorld();
    }

    /** A material counted directly in the unit the example speaks in. */
    protected function bulk(string $code, string $name, string $unitCode): Material
    {
        return $this->makeMaterial($this->organization, $code, $name, $this->unitNamed($unitCode));
    }

    protected function unitNamed(string $code)
    {
        return MaterialUnit::firstOrCreate(['organization_id' => $this->organization->id, 'code' => $code]);
    }

    /** A BOQ line that needs $perUnit of the material per unit of work. */
    protected function boqFor(Material $material, string $boqQty, string $perUnit): BoqItem
    {
        $item = $this->makeBoqItem($this->project, $boqQty, '1000.00');

        BoqMaterialNorm::create([
            'organization_id' => $this->organization->id,
            'project_id' => $this->project->id,
            'boq_item_id' => $item->id,
            'material_id' => $material->id,
            'qty_per_unit' => $perUnit,
            'wastage_pct' => '0',
        ]);

        return $item;
    }

    protected function workDone(BoqItem $item, string $qty): ProgressEntry
    {
        return ProgressEntry::create([
            'organization_id' => $this->organization->id,
            'project_id' => $this->project->id,
            'boq_item_id' => $item->id,
            'work_date' => '2026-11-05',
            'qty_done' => $qty,
        ]);
    }

    protected function deliver(Material $material, string $qty, ?Party $supplier = null, array $extra = []): DeliveryChallan
    {
        return app(DeliveryChallanService::class)->create($this->project, [
            'party_id' => ($supplier ?? $this->makeVendor('Birla Traders'))->id,
            'material_id' => $material->id,
            'qty' => $qty,
            'challan_date' => '2026-11-03',
            'vehicle_no' => 'MH12AB1234',
            ...$extra,
        ], $this->owner);
    }

    protected function useOn(BoqItem $item, Material $material, string $qty, array $extra = []): MaterialIssue
    {
        return app(ConsumptionService::class)->create($this->project, [
            'material_id' => $material->id,
            'qty' => $qty,
            'boq_item_id' => $item->id,
            'stage' => 'Slab 1',
            'used_on' => '2026-11-05',
            ...$extra,
        ], $this->owner);
    }

    protected function countStock(Material $material, string $qty): MaterialStockCheck
    {
        return app(StockCheckService::class)->create($this->project, [
            'material_id' => $material->id,
            'physical_qty' => $qty,
            'check_date' => '2026-11-06',
        ], $this->owner);
    }

    /** The check row for one material. */
    protected function rowFor(Material $material, bool $withMoney = false): array
    {
        return collect(app(MaterialCheckService::class)->check($this->project, $withMoney)['rows'])->firstWhere('material_id', $material->id);
    }

    protected function flagOf(array $row, string $type): ?array
    {
        return collect($row['flags'])->firstWhere('type', $type);
    }

    protected function wastagePercent(): ?string
    {
        return app(MaterialCheckService::class)->check($this->project)['wastage_pct'];
    }

    protected function progressPercent(BoqItem $item): string
    {
        return app(WorkProgress::class)->itemPercent($item);
    }
}
