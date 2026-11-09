<?php

namespace App\Services\SiteAlerts\Sources;

use App\Models\Alert;
use App\Models\BoqItem;
use App\Models\MepStageCell;
use App\Models\ProgressEntry;
use App\Models\Project;
use App\Models\ProjectFloor;
use App\Services\SiteAlerts\Contracts\AlertSource;
use App\Services\SiteAlerts\Finding;
use App\Services\SiteProgress\MepTracker;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Arr;

/**
 * Plumbing or electrical work not done on a floor where finishing has already started, so a wall
 * is not plastered over a missing fitting. Finishing has started on a floor once work is entered
 * there on a plaster or finishing BOQ line. The pending items come from site_progress's MepTracker,
 * and only floors where the stage tracker is in use are checked, so a project that does not use
 * it hears nothing.
 */
class MepPendingSource implements AlertSource
{
    public const TYPE = 'mep.pending_before_finishing';

    public function __construct(private MepTracker $tracker) {}

    public function types(): array
    {
        return [self::TYPE];
    }

    public function findings(Project $project, CarbonInterface $today): iterable
    {
        $floors = ProjectFloor::where('project_id', $project->id)->get(['id', 'name', 'parent_id', 'project_id']);

        if ($floors->isEmpty()) {
            return;
        }

        $tracked = $this->topLevel($floors, MepStageCell::where('project_id', $project->id)->distinct()->pluck('project_floor_id')->all());
        $started = $this->topLevel($floors, $this->floorsWithFinishing($project));

        foreach ($floors->whereIn('id', array_intersect($tracked, $started)) as $floor) {
            $check = $this->tracker->preFinishing($floor);
            $pending = count($check['pending']);
            $unfinished = count($check['unfinished']);

            if ($pending + $unfinished === 0) {
                continue;
            }

            yield new Finding(
                type: self::TYPE,
                title: 'Finishing started with plumbing or electrical not done',
                message: 'Finishing work has started on :floor with :pending plumbing or electrical items pending and :unfinished not yet done.',
                params: ['floor' => $floor->name, 'pending' => $pending, 'unfinished' => $unfinished],
                reference: 'floor:'.$floor->id,
                severity: $pending > 0 ? Alert::CRITICAL : Alert::WARNING,
                data: [
                    'floor_id' => $floor->id,
                    'pending' => $pending,
                    'unfinished' => $unfinished,
                    'items' => array_map(fn (array $item) => Arr::only($item, ['row', 'trade', 'stage', 'status', 'remark']), [...$check['pending'], ...$check['unfinished']]),
                ],
                notify: 'progress.create',
                url: route('site-stages.checklist', $floor),
            );
        }
    }

    /** @return list<int> Floor ids with finishing work entered on them or on their flats. */
    private function floorsWithFinishing(Project $project): array
    {
        $categories = config('modules.site_alerts.finishing_categories', []);
        $words = array_map('mb_strtolower', config('modules.site_alerts.finishing_match', []));

        $items = BoqItem::where('project_id', $project->id)->get(['id', 'category', 'code', 'description'])
            ->filter(function (BoqItem $item) use ($categories, $words) {
                if (filled($item->category)) {
                    return in_array($item->category, $categories, true);
                }

                $text = mb_strtolower($item->code.' '.$item->description);

                foreach ($words as $word) {
                    if ($word !== '' && str_contains($text, $word)) {
                        return true;
                    }
                }

                return false;
            });

        if ($items->isEmpty()) {
            return [];
        }

        return ProgressEntry::where('project_id', $project->id)
            ->whereIn('boq_item_id', $items->pluck('id'))
            ->whereNotNull('project_floor_id')
            ->selectRaw('project_floor_id, SUM(qty_done) as done')
            ->groupBy('project_floor_id')
            ->havingRaw('SUM(qty_done) > 0')
            ->pluck('project_floor_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * The floor each id belongs to: itself, or its parent when it is a flat whose floor still exists.
     *
     * @param  Collection<int, ProjectFloor>  $floors
     * @param  list<int>  $ids
     * @return list<int>
     */
    private function topLevel(Collection $floors, array $ids): array
    {
        $tops = [];

        foreach ($ids as $id) {
            $floor = $floors->firstWhere('id', $id);

            if ($floor === null) {
                continue;
            }

            $tops[] = $floor->parent_id && $floors->contains('id', $floor->parent_id) ? (int) $floor->parent_id : (int) $floor->id;
        }

        return array_values(array_unique($tops));
    }
}
