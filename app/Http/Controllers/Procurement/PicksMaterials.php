<?php

namespace App\Http\Controllers\Procurement;

use App\Models\BoqItem;
use App\Models\Material;
use App\Models\MaterialStore;
use App\Models\Project;
use Illuminate\Support\Collection;

/** Option lists for the line-item forms. */
trait PicksMaterials
{
    /** @return list<array<string, mixed>> */
    private function materialOptions(bool $activeOnly = true): array
    {
        return Material::with('baseUnit', 'conversions.unit')
            ->when($activeOnly, fn ($query) => $query->where('is_active', true))
            ->orderBy('name')
            ->get()
            ->map(fn (Material $material) => [
                'id' => $material->id,
                'label' => $material->label(),
                'base_unit' => $material->baseUnit->code,
                'units' => $material->conversions
                    ->map(fn ($conversion) => ['id' => $conversion->unit_id, 'code' => $conversion->unit->code])
                    ->values()
                    ->all(),
            ])
            ->all();
    }

    private function boqOptions(Project $project): Collection
    {
        return BoqItem::where('project_id', $project->id)->orderBy('sort')->orderBy('id')->get(['id', 'code', 'description', 'unit']);
    }

    private function projectStores(Project $project): Collection
    {
        return MaterialStore::where('project_id', $project->id)->orderBy('name')->get();
    }
}
