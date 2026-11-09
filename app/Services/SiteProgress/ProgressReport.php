<?php

namespace App\Services\SiteProgress;

use App\Models\BoqItem;
use App\Models\BoqSection;
use App\Models\FloorWorkPlan;
use App\Models\MepStageCell;
use App\Models\ProgressEntry;
use App\Models\Project;
use App\Models\ProjectFloor;
use App\Services\SiteControl\WorkProgress;
use App\Support\Dec;
use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Everything the progress screens and the mobile API read. It shows quantities and percentages only.
 * Rates are read to weight a percentage, as WorkProgress does, and are never put into anything returned from here.
 */
class ProgressReport
{
    public function __construct(private WorkProgress $progress, private ProgressEntryService $entries) {}

    /**
     * Every BOQ line with what is done so far.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function items(Project $project): Collection
    {
        $done = $this->doneByItem($project);
        $sections = BoqSection::where('project_id', $project->id)->pluck('name', 'id');

        // The columns are named so that the rate is never loaded for a screen that shows quantities.
        return BoqItem::where('project_id', $project->id)->orderBy('sort')->orderBy('id')
            ->get(['id', 'boq_section_id', 'code', 'description', 'unit', 'qty', 'sort'])
            ->map(function (BoqItem $item) use ($done, $sections) {
                $doneQty = Dec::of($done[$item->id] ?? 0);
                $balance = Dec::of($item->qty)->minus($doneQty);

                return [
                    'id' => $item->id,
                    'section' => $sections[$item->boq_section_id] ?? null,
                    'code' => $item->code,
                    'description' => $item->description,
                    'unit' => $item->unit,
                    'qty' => Dec::qty($item->qty),
                    'done_qty' => Dec::qty($doneQty),
                    'balance_qty' => Dec::qty($balance->isNegative() ? 0 : $balance),
                    'percent' => $this->progress->percent($doneQty, $item->qty),
                ];
            });
    }

    /**
     * The project as a whole, and how time compares with work.
     *
     * @return array<string, mixed>
     */
    public function summary(Project $project): array
    {
        $work = $this->progress->projectPercent($project);
        $time = $this->progress->timeUsedPercent($project);
        $lag = $this->progress->scheduleLag($project);
        $today = now()->startOfDay();
        $start = $project->start_date ? Carbon::parse($project->start_date)->startOfDay() : null;
        $deadline = $project->deadline_date ? Carbon::parse($project->deadline_date)->startOfDay() : null;
        $left = Dec::of(100)->minus(Dec::of($work));

        return [
            'work_percent' => $work,
            'time_percent' => $time,
            'lag' => $lag,
            'status' => $lag === null ? null : (Dec::of($lag)->isPositive() ? 'behind' : 'on_track'),
            // A large gap is urgent; a small one is a nudge.
            'severity' => $lag === null ? null : (Dec::of($lag)->isGreaterThanOrEqualTo(10) ? 'high' : (Dec::of($lag)->isPositive() ? 'low' : 'none')),
            'planned_days' => $project->planned_days ? (int) $project->planned_days : null,
            'elapsed_days' => $start ? max(0, (int) $start->diffInDays($today, false)) : null,
            'start_date' => $start?->toDateString(),
            'deadline_date' => $deadline?->toDateString(),
            'days_to_deadline' => $deadline ? (int) $today->diffInDays($deadline, false) : null,
            'work_left_percent' => Dec::money($left->isNegative() ? 0 : $left),
            'open_pending' => $this->openPendingQuery($project)->count(),
        ];
    }

    /**
     * Floors with their percentages. A floor's figure includes the flats under it.
     *
     * @return list<array<string, mixed>>
     */
    public function floors(Project $project): array
    {
        $data = $this->floorData($project);
        $result = [];

        foreach ($data['floors']->whereNull('parent_id') as $floor) {
            $children = $data['floors']->where('parent_id', $floor->id)->values();
            $row = $this->floorRow($floor, [$floor->id, ...$children->pluck('id')->all()], $data);
            $row['children'] = $children->map(fn (ProjectFloor $child) => $this->floorRow($child, [$child->id], $data))->all();
            $result[] = $row;
        }

        return $result;
    }

    /**
     * One floor: its percentage, line by line, and what is pending on it.
     *
     * @return array<string, mixed>
     */
    public function floorDetail(ProjectFloor $floor): array
    {
        $project = $floor->project;
        $data = $this->floorData($project);
        $ids = [$floor->id, ...$data['floors']->where('parent_id', $floor->id)->pluck('id')->all()];
        $stats = $this->stats($ids, $data);

        return [
            ...$this->floorRow($floor, $ids, $data),
            'lines' => $stats['lines'],
            'children' => $data['floors']->where('parent_id', $floor->id)->map(fn (ProjectFloor $child) => $this->floorRow($child, [$child->id], $data))->values()->all(),
            'pending_entries' => $this->pending($project, $ids),
            'unplanned' => collect($stats['lines'])->filter(fn (array $line) => $line['planned_qty'] === null)->values()->all(),
        ];
    }

    /**
     * Open pending remarks: the latest word on an item is "pending" and nothing has closed it.
     *
     * @param  list<int>|null  $floorIds
     * @return Collection<int, ProgressEntry>
     */
    public function pending(Project $project, ?array $floorIds = null): Collection
    {
        return $this->openPendingQuery($project)
            ->when($floorIds !== null, fn (Builder $query) => $query->whereIn('project_floor_id', $floorIds))
            ->with([
                'boqItem' => fn ($query) => $query->withTrashed()->select(['id', 'code', 'description', 'unit']),
                'floor:id,name,parent_id',
                'floor.parent:id,name',
                'enteredBy:id,name',
                'photos',
            ])
            ->orderBy('work_date')->orderBy('id')
            ->get();
    }

    /**
     * The week that holds a day: what was done, what is pending, and what is likely next.
     * There is no scheduling here. "Next" is the work already under way, with what is left of it.
     *
     * @return array<string, mixed>
     */
    public function week(Project $project, Carbon $day): array
    {
        $start = $day->copy()->startOfWeek(Carbon::MONDAY)->startOfDay();
        $end = $start->copy()->addDays(6);

        $rows = ProgressEntry::where('project_id', $project->id)
            ->whereBetween('work_date', [$start->toDateString(), $end->toDateString()])
            ->selectRaw('boq_item_id, project_floor_id, SUM(qty_done) as done, COUNT(*) as entries')
            ->groupBy('boq_item_id', 'project_floor_id')
            ->get();

        $items = $this->items($project)->keyBy('id');
        $floors = ProjectFloor::where('project_id', $project->id)->with('parent:id,name')->get(['id', 'name', 'parent_id'])->keyBy('id');

        $done = $rows->groupBy('boq_item_id')->map(function (Collection $group, $itemId) use ($items, $floors) {
            $item = $items[$itemId] ?? null;
            $total = $group->reduce(fn (BigDecimal $sum, $row) => $sum->plus(Dec::of(Dec::qty($row->done))), BigDecimal::zero());

            if ($item === null || ! $total->isPositive()) {
                return null;
            }

            return [
                ...$item,
                'week_qty' => Dec::qty($total),
                'floors' => $group->filter(fn ($row) => $row->project_floor_id && Dec::of(Dec::qty($row->done))->isPositive())
                    ->map(fn ($row) => ['name' => $this->floorLabel($floors[$row->project_floor_id] ?? null), 'qty' => Dec::qty($row->done)])
                    ->values()->all(),
            ];
        })->filter()->sortBy('code')->values();

        $underway = $items->filter(fn (array $item) => Dec::of($item['percent'])->isGreaterThan(0) && Dec::of($item['percent'])->isLessThan(100))
            ->sort(fn (array $a, array $b) => Dec::of($b['percent'])->compareTo(Dec::of($a['percent'])))->values();

        return [
            'start' => $start,
            'end' => $end,
            'is_current' => $day->copy()->startOfWeek(Carbon::MONDAY)->isSameDay(now()->startOfWeek(Carbon::MONDAY)),
            'entry_count' => (int) $rows->sum('entries'),
            'done' => $done,
            'pending' => $this->pending($project),
            'next' => $underway->take(15),
            'next_more' => max(0, $underway->count() - 15),
            'not_started' => $items->filter(fn (array $item) => Dec::of($item['done_qty'])->isZero() && Dec::of($item['qty'])->isPositive())->count(),
        ];
    }

    /**
     * Where one BOQ line stands: quantity done and the percentage.
     *
     * @return array{boq_item_id: int, done_qty: string, balance_qty: string, percent: string}|null
     */
    public function itemStanding(int $boqItemId): ?array
    {
        $item = BoqItem::withTrashed()->find($boqItemId, ['id', 'qty']);

        if ($item === null) {
            return null;
        }

        $done = $this->progress->completedQty($item->id);
        $balance = Dec::of($item->qty)->minus($done);

        return [
            'boq_item_id' => $item->id,
            'done_qty' => Dec::qty($done),
            'balance_qty' => Dec::qty($balance->isNegative() ? 0 : $balance),
            'percent' => $this->progress->itemPercent($item),
        ];
    }

    /** The latest entries, newest first, with the names a list needs. */
    public function recent(Project $project, int $limit): Collection
    {
        return ProgressEntry::where('project_id', $project->id)
            ->with([
                'boqItem' => fn ($query) => $query->withTrashed()->select(['id', 'code', 'description', 'unit']),
                'floor:id,name,parent_id',
                'floor.parent:id,name',
                'enteredBy:id,name',
            ])
            ->latest('id')->limit($limit)->get();
    }

    /** Pending MEP cells per floor id, for the cards that warn before finishing starts. */
    public function mepPendingByFloor(Project $project): array
    {
        return MepStageCell::where('project_id', $project->id)->where('status', 'pending')
            ->selectRaw('project_floor_id, COUNT(*) as total')->groupBy('project_floor_id')->pluck('total', 'project_floor_id')
            ->map(fn ($total) => (int) $total)->all();
    }

    public function floorLabel(?ProjectFloor $floor): string
    {
        if ($floor === null) {
            return '—';
        }

        return $floor->parent_id && $floor->relationLoaded('parent') && $floor->parent ? $floor->parent->name.' › '.$floor->name : $floor->name;
    }

    /** A percentage the way people say it: 35, 35.5, 35.25. */
    public static function show(?string $percent): string
    {
        return $percent === null ? '—' : rtrim(rtrim($percent, '0'), '.').'%';
    }

    /** Width of a progress bar, held between 0 and 100. */
    public static function bar(?string $percent): string
    {
        $value = Dec::of($percent ?? 0);

        return Dec::money($value->isNegative() ? 0 : ($value->isGreaterThan(100) ? 100 : $value));
    }

    private function openPendingQuery(Project $project): Builder
    {
        return ProgressEntry::query()
            ->where('project_id', $project->id)
            ->where('is_pending', true)
            ->whereNotIn('id', ProgressEntry::query()->whereNotNull('resolves_entry_id')->select('resolves_entry_id'));
    }

    /** @return array<int, string> */
    private function doneByItem(Project $project): array
    {
        return ProgressEntry::where('project_id', $project->id)
            ->selectRaw('boq_item_id, SUM(qty_done) as done')
            ->groupBy('boq_item_id')
            ->pluck('done', 'boq_item_id')
            ->map(fn ($done) => Dec::qty($done))
            ->all();
    }

    /**
     * Everything the floor percentages need, loaded once for the project.
     *
     * @return array{floors: Collection, items: array, plans: array, done: array, pending: array, mep: array}
     */
    private function floorData(Project $project): array
    {
        $items = [];

        foreach (BoqItem::where('project_id', $project->id)->orderBy('sort')->orderBy('id')->get(['id', 'code', 'description', 'unit', 'qty', 'rate', 'sort']) as $item) {
            $items[$item->id] = [
                'id' => $item->id, 'code' => $item->code, 'description' => $item->description, 'unit' => $item->unit,
                'qty' => Dec::of($item->qty),
                // Only ever used as a weight inside this class.
                'weight' => Dec::of($item->rate),
            ];
        }

        $plans = [];

        foreach (FloorWorkPlan::where('project_id', $project->id)->get(['project_floor_id', 'boq_item_id', 'planned_qty']) as $plan) {
            $plans[$plan->project_floor_id][$plan->boq_item_id] = Dec::of($plan->planned_qty);
        }

        $done = [];

        foreach (ProgressEntry::where('project_id', $project->id)->whereNotNull('project_floor_id')
            ->selectRaw('project_floor_id, boq_item_id, SUM(qty_done) as done')->groupBy('project_floor_id', 'boq_item_id')->get() as $row) {
            $done[$row->project_floor_id][$row->boq_item_id] = Dec::of(Dec::qty($row->done));
        }

        $pending = $this->openPendingQuery($project)->whereNotNull('project_floor_id')
            ->selectRaw('project_floor_id, COUNT(*) as total')->groupBy('project_floor_id')->pluck('total', 'project_floor_id')
            ->map(fn ($total) => (int) $total)->all();

        return [
            'floors' => ProjectFloor::where('project_id', $project->id)->orderBy('sort')->orderBy('id')->get(),
            'items' => $items,
            'plans' => $plans,
            'done' => $done,
            'pending' => $pending,
            'mep' => $this->mepPendingByFloor($project),
        ];
    }

    /** @param  list<int>  $ids */
    private function floorRow(ProjectFloor $floor, array $ids, array $data): array
    {
        $stats = $this->stats($ids, $data);

        return [
            'id' => $floor->id,
            'name' => $floor->name,
            'parent_id' => $floor->parent_id,
            'percent' => $stats['percent'],
            'targets' => $stats['targets'],
            'pending' => array_sum(array_map(fn (int $id) => $data['pending'][$id] ?? 0, $ids)),
            'mep_pending' => array_sum(array_map(fn (int $id) => $data['mep'][$id] ?? 0, $ids)),
        ];
    }

    /**
     * Percentage for a set of floors (a floor with its flats). It is measured against the quantity planned for them,
     * weighted by value in the way WorkProgress weights the project, and capped line by line.
     *
     * @param  list<int>  $ids
     * @return array{percent: string, targets: int, lines: list<array<string, mixed>>}
     */
    private function stats(array $ids, array $data): array
    {
        $planned = [];
        $done = [];

        foreach ($ids as $id) {
            foreach ($data['plans'][$id] ?? [] as $itemId => $qty) {
                $planned[$itemId] = ($planned[$itemId] ?? BigDecimal::zero())->plus($qty);
            }

            foreach ($data['done'][$id] ?? [] as $itemId => $qty) {
                $done[$itemId] = ($done[$itemId] ?? BigDecimal::zero())->plus($qty);
            }
        }

        $total = BigDecimal::zero();
        $earned = BigDecimal::zero();
        $lines = [];
        $targets = 0;

        foreach ($data['items'] as $itemId => $item) {
            if (! isset($planned[$itemId]) && ! isset($done[$itemId])) {
                continue;
            }

            $target = $planned[$itemId] ?? null;
            $doneQty = $done[$itemId] ?? BigDecimal::zero();

            if ($target !== null && $target->isPositive()) {
                $targets++;
                $total = $total->plus($target->multipliedBy($item['weight']));
                $earned = $earned->plus($this->capped($doneQty, $target)->multipliedBy($item['weight']));
            }

            $balance = $target === null ? null : $target->minus($doneQty);

            $lines[] = [
                'id' => $itemId,
                'code' => $item['code'],
                'description' => $item['description'],
                'unit' => $item['unit'],
                'planned_qty' => $target === null ? null : Dec::qty($target),
                'done_qty' => Dec::qty($doneQty),
                'balance_qty' => $balance === null ? null : Dec::qty($balance->isNegative() ? 0 : $balance),
                'percent' => $target === null ? null : $this->progress->percent($doneQty, $target),
            ];
        }

        return ['percent' => $this->progress->percent($earned, $total), 'targets' => $targets, 'lines' => $lines];
    }

    private function capped(BigDecimal $done, BigDecimal $qty): BigDecimal
    {
        if ($done->isNegative()) {
            return BigDecimal::zero();
        }

        return $done->isGreaterThan($qty) ? $qty : $done;
    }
}
