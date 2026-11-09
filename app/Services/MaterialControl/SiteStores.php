<?php

namespace App\Services\MaterialControl;

use App\Models\MaterialStore;
use App\Models\Project;
use Illuminate\Support\Collection;

/** Quick site entry should not stop to ask which store when a project has only one. */
class SiteStores
{
    /** The store named, the project's first store, or a new default one if it has none. */
    public function resolve(Project $project, ?int $storeId): MaterialStore
    {
        if ($storeId) {
            return MaterialStore::where('project_id', $project->id)->findOrFail($storeId);
        }

        return MaterialStore::where('project_id', $project->id)->orderBy('id')->first()
            ?? MaterialStore::firstOrCreate(
                ['project_id' => $project->id, 'name' => 'Site store'],
                ['organization_id' => $project->organization_id],
            );
    }

    /** @return Collection<int, MaterialStore> */
    public function forProject(Project $project): Collection
    {
        return MaterialStore::where('project_id', $project->id)->orderBy('name')->get();
    }
}
