<?php

namespace App\Services\SiteProgress;

use App\Models\BoqItem;
use App\Models\FloorWorkPlan;
use App\Models\MepStageCell;
use App\Models\ProgressEntry;
use App\Models\Project;
use App\Models\ProjectFloor;
use App\Support\Dec;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Floors and segments of a project, and how much of each BOQ line belongs to each of them. */
class FloorService
{
    public function rules(Project $project): array
    {
        return [
            'name' => ['required', 'string', 'max:80'],
            // A flat sits under a floor; a floor does not sit under anything.
            'parent_id' => ['bail', 'nullable', 'integer', Rule::exists('project_floors', 'id')->where('project_id', $project->id)->whereNull('parent_id')],
        ];
    }

    public function create(Project $project, array $data): ProjectFloor
    {
        $parentId = $data['parent_id'] ?? null;
        $name = trim($data['name']);
        $this->assertNameFree($project, $parentId, $name);

        return ProjectFloor::create([
            'organization_id' => $project->organization_id,
            'project_id' => $project->id,
            'parent_id' => $parentId,
            'name' => $name,
            'sort' => ((int) ProjectFloor::where('project_id', $project->id)->where('parent_id', $parentId)->max('sort')) + 1,
        ]);
    }

    public function update(ProjectFloor $floor, array $data): ProjectFloor
    {
        $parentId = $data['parent_id'] ?? null;
        $name = trim($data['name']);

        if ($parentId !== null && (int) $parentId === $floor->id) {
            throw ValidationException::withMessages(['parent_id' => __('A floor cannot sit under itself.')]);
        }

        if ($parentId !== null && ProjectFloor::where('parent_id', $floor->id)->exists()) {
            throw ValidationException::withMessages(['parent_id' => __('This floor has flats under it, so it cannot become a flat.')]);
        }

        $this->assertNameFree($floor->project, $parentId, $name, $floor->id);
        $floor->update(['name' => $name, 'parent_id' => $parentId]);

        return $floor;
    }

    /** Only a floor with no history can go: entries and stage updates must stay attached to where the work was. */
    public function delete(ProjectFloor $floor): void
    {
        if (ProjectFloor::where('parent_id', $floor->id)->exists()) {
            throw ValidationException::withMessages(['floor' => __('Remove the flats under this floor first.')]);
        }

        $hasHistory = ProgressEntry::where('project_floor_id', $floor->id)->exists()
            || MepStageCell::where('project_floor_id', $floor->id)->where(fn ($query) => $query->where('status', '!=', 'not_started')->orWhereHas('photos'))->exists();

        if ($hasHistory) {
            throw ValidationException::withMessages(['floor' => __('Work has been recorded on this floor, so it cannot be removed.')]);
        }

        DB::transaction(function () use ($floor) {
            FloorWorkPlan::where('project_floor_id', $floor->id)->get()->each->delete();
            MepStageCell::where('project_floor_id', $floor->id)->get()->each->delete();
            $floor->delete();
        });
    }

    /** Set how much of a BOQ line is meant to be done on a floor. Zero removes the target. */
    public function setTarget(ProjectFloor $floor, BoqItem $item, string $qty): ?FloorWorkPlan
    {
        abort_unless($item->project_id === $floor->project_id, 404);

        $existing = FloorWorkPlan::where('project_floor_id', $floor->id)->where('boq_item_id', $item->id)->first();

        if (Dec::of($qty)->isZero()) {
            $existing?->delete();

            return null;
        }

        if ($existing) {
            $existing->update(['planned_qty' => Dec::qty($qty)]);

            return $existing;
        }

        return FloorWorkPlan::create([
            'organization_id' => $floor->organization_id,
            'project_id' => $floor->project_id,
            'project_floor_id' => $floor->id,
            'boq_item_id' => $item->id,
            'planned_qty' => Dec::qty($qty),
        ]);
    }

    /**
     * Share whole BOQ lines equally across floors. Any target the line had on other floors is dropped, so the
     * result always adds up to the BOQ quantity. The last floor takes the rounding remainder.
     *
     * @param  list<int>  $itemIds
     * @param  list<int>  $floorIds
     */
    public function splitEqually(Project $project, array $itemIds, array $floorIds): int
    {
        $floors = ProjectFloor::where('project_id', $project->id)->whereIn('id', $floorIds)->orderBy('sort')->orderBy('id')->get();
        $items = BoqItem::where('project_id', $project->id)->whereIn('id', $itemIds)->get(['id', 'qty']);

        if ($floors->isEmpty() || $items->isEmpty()) {
            throw ValidationException::withMessages(['floor_ids' => __('Choose at least one BOQ item and one floor.')]);
        }

        return DB::transaction(function () use ($project, $floors, $items) {
            $count = 0;

            foreach ($items as $item) {
                FloorWorkPlan::where('project_id', $project->id)->where('boq_item_id', $item->id)->get()->each->delete();

                $total = Dec::of($item->qty);

                if (! $total->isPositive()) {
                    continue;
                }

                $share = $total->dividedBy($floors->count(), 3, RoundingMode::Down);
                $given = Dec::of(0);

                foreach ($floors->values() as $index => $floor) {
                    $qty = $index === $floors->count() - 1 ? $total->minus($given) : $share;
                    $given = $given->plus($qty);

                    FloorWorkPlan::create([
                        'organization_id' => $project->organization_id,
                        'project_id' => $project->id,
                        'project_floor_id' => $floor->id,
                        'boq_item_id' => $item->id,
                        'planned_qty' => Dec::qty($qty),
                    ]);
                }

                $count++;
            }

            return $count;
        });
    }

    private function assertNameFree(Project $project, mixed $parentId, string $name, ?int $ignore = null): void
    {
        $taken = ProjectFloor::where('project_id', $project->id)
            ->where('parent_id', $parentId)
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
            ->when($ignore, fn ($query) => $query->where('id', '!=', $ignore))
            ->exists();

        if ($taken) {
            throw ValidationException::withMessages(['name' => __('There is already a floor with this name here.')]);
        }
    }
}
