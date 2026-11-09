<?php

namespace App\Services\Planning;

use App\Models\AuditLog;
use App\Models\BoqItem;
use App\Models\PlanActivity;
use App\Models\PlanBaseline;
use App\Models\PlanBoqLink;
use App\Models\PlanBudgetLine;
use App\Models\PlanDependency;
use App\Models\PlanWbsNode;
use App\Models\Project;
use App\Models\User;
use App\Support\Dec;
use Brick\Math\BigDecimal;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * WBS, activities, links and the baseline. Dates are whole days and both ends are worked
 * days, so a finish-to-start successor begins the day after its predecessor ends. A milestone
 * takes up no day of its own, so work on either side of one can share its date.
 * Calendar days are used throughout: there are no working-day calendars.
 */
class ScheduleService
{
    public const LINK_TYPES = ['FS', 'SS', 'FF', 'SF'];

    private const LONGEST_ACTIVITY_DAYS = 3660;

    public function nodeRules(Project $project, ?PlanWbsNode $node = null): array
    {
        return [
            'code' => ['required', 'string', 'max:40', Rule::unique('plan_wbs_nodes', 'code')->where('project_id', $project->id)->ignore($node?->id)],
            'name' => ['required', 'string', 'max:255'],
            'parent_id' => ['bail', 'nullable', 'integer', Rule::exists('plan_wbs_nodes', 'id')->where('project_id', $project->id)],
            'client_uuid' => ['nullable', 'uuid'],
        ];
    }

    public function activityRules(Project $project, ?PlanActivity $activity = null): array
    {
        return [
            'code' => ['nullable', 'string', 'max:40', Rule::unique('plan_activities', 'code')->where('project_id', $project->id)->ignore($activity?->id)],
            'name' => ['required', 'string', 'max:255'],
            'wbs_node_id' => ['bail', 'nullable', 'integer', Rule::exists('plan_wbs_nodes', 'id')->where('project_id', $project->id)],
            'planned_start' => ['required', 'date', 'after_or_equal:2000-01-01', 'before:2100-01-01'],
            'planned_end' => ['nullable', 'required_unless:is_milestone,1', 'date', 'after_or_equal:planned_start', 'before:2100-01-01'],
            'is_milestone' => ['sometimes', 'boolean'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'client_uuid' => ['nullable', 'uuid'],
        ];
    }

    public function dependencyRules(Project $project): array
    {
        return [
            'predecessor_id' => ['bail', 'required', 'integer', Rule::exists('plan_activities', 'id')->where('project_id', $project->id)],
            'type' => ['required', Rule::in(self::LINK_TYPES)],
            'lag_days' => ['nullable', 'integer', 'min:-365', 'max:365'],
        ];
    }

    public function boqLinkRules(Project $project): array
    {
        return [
            'boq_item_id' => ['bail', 'required', 'integer', Rule::exists('boq_items', 'id')->where('project_id', $project->id)->whereNull('deleted_at')],
            'qty' => ['nullable', 'numeric', 'gt:0', 'max:99999999999'],
        ];
    }

    /** Add a WBS node. A repeated client_uuid returns the original row. */
    public function createNode(Project $project, array $data): PlanWbsNode
    {
        return DB::transaction(function () use ($project, $data) {
            $project = Project::lockForUpdate()->findOrFail($project->id);
            $uuid = $data['client_uuid'] ?? null;

            if ($uuid && ($existing = PlanWbsNode::where('organization_id', $project->organization_id)->where('client_uuid', $uuid)->first())) {
                return $existing;
            }

            return PlanWbsNode::create([
                ...$data,
                'organization_id' => $project->organization_id,
                'project_id' => $project->id,
                'sort' => (int) PlanWbsNode::where('project_id', $project->id)->max('sort') + 1,
            ]);
        });
    }

    public function updateNode(PlanWbsNode $node, array $data): PlanWbsNode
    {
        unset($data['client_uuid']);

        return DB::transaction(function () use ($node, $data) {
            Project::lockForUpdate()->findOrFail($node->project_id);

            if (! empty($data['parent_id']) && in_array((int) $data['parent_id'], $this->nodeAndDescendants($node), true)) {
                throw ValidationException::withMessages(['parent_id' => __('A WBS node cannot sit inside itself.')]);
            }

            $node->update($data);

            return $node;
        });
    }

    public function deleteNode(PlanWbsNode $node): void
    {
        if ($node->children()->exists() || $node->activities()->exists()) {
            throw ValidationException::withMessages(['wbs' => __('Move or remove what is under this WBS node first.')]);
        }

        $node->delete();
    }

    /** Add an activity. A repeated client_uuid returns the original row. */
    public function createActivity(Project $project, array $data): PlanActivity
    {
        [$start, $end] = $this->dates($data);

        return DB::transaction(function () use ($project, $data, $start, $end) {
            $project = Project::lockForUpdate()->findOrFail($project->id);
            $uuid = $data['client_uuid'] ?? null;

            if ($uuid && ($existing = PlanActivity::where('organization_id', $project->organization_id)->where('client_uuid', $uuid)->first())) {
                return $existing;
            }

            return PlanActivity::create([
                ...$data,
                'organization_id' => $project->organization_id,
                'project_id' => $project->id,
                'code' => filled($data['code'] ?? null) ? $data['code'] : $this->nextCode($project),
                'planned_start' => $start,
                'planned_end' => $end,
                'is_milestone' => (bool) ($data['is_milestone'] ?? false),
                'sort' => (int) PlanActivity::where('project_id', $project->id)->max('sort') + 1,
            ]);
        });
    }

    /**
     * Save new dates and push later work back to fit. An activity may not be moved ahead of
     * what it depends on, and work already sequenced after it is never pulled earlier.
     *
     * @return int How many other activities moved.
     */
    public function updateActivity(PlanActivity $activity, array $data): int
    {
        [$start, $end] = $this->dates($data);
        unset($data['client_uuid']);

        return DB::transaction(function () use ($activity, $data, $start, $end) {
            Project::lockForUpdate()->findOrFail($activity->project_id);

            $activity->fill([
                ...$data,
                'planned_start' => $start,
                'planned_end' => $end,
                'is_milestone' => (bool) ($data['is_milestone'] ?? false),
            ]);

            if (blank($activity->code)) {
                $activity->code = $activity->getOriginal('code');
            }

            $this->assertFitsPredecessors($activity, Days::of($start), Days::of($end));
            $activity->save();

            return $this->reflow($activity->project_id, $activity->successorLinks()->pluck('successor_id')->all());
        });
    }

    public function deleteActivity(PlanActivity $activity): void
    {
        DB::transaction(function () use ($activity) {
            $activity->boqLinks()->get()->each->delete();
            $activity->predecessorLinks()->get()->each->delete();
            $activity->successorLinks()->get()->each->delete();
            $activity->delete();
        });
    }

    /**
     * Make one activity wait on another. Saving the same pair again changes its type and lag.
     * The activity moves back if the new link requires it, and so does anything after it.
     *
     * @return array{0:PlanDependency, 1:int} The link and how many activities moved.
     */
    public function addDependency(PlanActivity $successor, array $data): array
    {
        return DB::transaction(function () use ($successor, $data) {
            Project::lockForUpdate()->findOrFail($successor->project_id);

            $predecessor = PlanActivity::where('project_id', $successor->project_id)->find($data['predecessor_id']);

            if (! $predecessor) {
                throw ValidationException::withMessages(['predecessor_id' => __('Choose an activity from this project.')]);
            }

            if ($predecessor->id === $successor->id) {
                throw ValidationException::withMessages(['predecessor_id' => __('An activity cannot depend on itself.')]);
            }

            $this->assertNoLoop($successor->project_id, $predecessor->id, $successor->id);

            $link = PlanDependency::updateOrCreate(
                ['predecessor_id' => $predecessor->id, 'successor_id' => $successor->id],
                [
                    'organization_id' => $successor->organization_id,
                    'project_id' => $successor->project_id,
                    'type' => $data['type'] ?? 'FS',
                    'lag_days' => (int) ($data['lag_days'] ?? 0),
                ],
            );

            return [$link, $this->reflow($successor->project_id, [$successor->id])];
        });
    }

    /**
     * Tie a BOQ line to an activity. Saving the same pair again changes the quantity, and a
     * blank quantity takes all that is left. A line's links can never exceed its BOQ quantity.
     */
    public function linkBoqItem(PlanActivity $activity, array $data): PlanBoqLink
    {
        return DB::transaction(function () use ($activity, $data) {
            Project::lockForUpdate()->findOrFail($activity->project_id);

            $item = BoqItem::where('project_id', $activity->project_id)->findOrFail($data['boq_item_id']);

            $taken = PlanBoqLink::where('boq_item_id', $item->id)
                ->where('plan_activity_id', '!=', $activity->id)
                ->pluck('qty')
                ->reduce(fn (BigDecimal $sum, $qty) => $sum->plus(Dec::of($qty)), BigDecimal::zero());

            $free = Dec::of($item->qty)->minus($taken);
            $qty = filled($data['qty'] ?? null) ? Dec::of($data['qty']) : $free;

            if ($free->isNegativeOrZero()) {
                throw ValidationException::withMessages(['boq_item_id' => __('This BOQ item is already fully linked to other activities.')]);
            }

            if ($qty->isGreaterThan($free)) {
                throw ValidationException::withMessages(['qty' => __('Only :qty :unit of this BOQ item is still free to link.', ['qty' => fmt_qty($free), 'unit' => $item->unit])]);
            }

            return PlanBoqLink::updateOrCreate(
                ['plan_activity_id' => $activity->id, 'boq_item_id' => $item->id],
                [
                    'organization_id' => $activity->organization_id,
                    'project_id' => $activity->project_id,
                    'qty' => Dec::qty($qty),
                ],
            );
        });
    }

    /** Freeze the current plan and budget as the baseline that progress is judged against. */
    public function takeBaseline(Project $project, User $by): PlanBaseline
    {
        return DB::transaction(function () use ($project, $by) {
            $project = Project::lockForUpdate()->findOrFail($project->id);

            PlanActivity::where('project_id', $project->id)->update([
                'baseline_start' => DB::raw('planned_start'),
                'baseline_end' => DB::raw('planned_end'),
            ]);

            PlanBudgetLine::where('project_id', $project->id)->update(['baseline_amount' => DB::raw('amount')]);

            $budget = PlanBudgetLine::where('project_id', $project->id)
                ->pluck('amount')
                ->reduce(fn (BigDecimal $sum, $amount) => $sum->plus(Dec::of($amount)), BigDecimal::zero());

            $baseline = PlanBaseline::create([
                'organization_id' => $project->organization_id,
                'project_id' => $project->id,
                'taken_by' => $by->id,
                'taken_at' => now(),
                'activity_count' => PlanActivity::where('project_id', $project->id)->count(),
                'budget_total' => Dec::money($budget),
            ]);

            AuditLog::record('planning.baseline', $project, null, $baseline->only('activity_count', 'budget_total'));

            return $baseline;
        });
    }

    /**
     * Reject a new link that would close a loop, naming the activities in it.
     *
     * @throws ValidationException
     */
    public function assertNoLoop(int $projectId, int $predecessorId, int $successorId, string $field = 'predecessor_id'): void
    {
        $outgoing = [];

        foreach (PlanDependency::where('project_id', $projectId)->get(['predecessor_id', 'successor_id']) as $edge) {
            $outgoing[$edge->predecessor_id][] = $edge->successor_id;
        }

        $path = $this->findPath($outgoing, $successorId, $predecessorId);

        if ($path === null) {
            return;
        }

        $codes = PlanActivity::whereIn('id', $path)->pluck('code', 'id');
        $names = array_map(fn (int $id) => $codes[$id], [$predecessorId, ...$path]);

        throw ValidationException::withMessages([$field => __('This link would make a loop: :path.', ['path' => implode(' → ', $names)])]);
    }

    /**
     * Activity ids in an order where every activity comes after what it depends on.
     * Returns null when the links contain a loop.
     *
     * @param  iterable<int>  $ids
     * @param  iterable<PlanDependency>  $edges
     * @return list<int>|null
     */
    public function dependencyOrder(iterable $ids, iterable $edges): ?array
    {
        $waiting = [];
        $outgoing = [];

        foreach ($ids as $id) {
            $waiting[$id] = 0;
        }

        foreach ($edges as $edge) {
            $outgoing[$edge->predecessor_id][] = $edge->successor_id;
            $waiting[$edge->successor_id] = ($waiting[$edge->successor_id] ?? 0) + 1;
            $waiting[$edge->predecessor_id] ??= 0;
        }

        $ready = array_keys(array_filter($waiting, fn (int $count) => $count === 0));
        $order = [];

        while ($ready !== []) {
            $id = array_shift($ready);
            $order[] = $id;

            foreach ($outgoing[$id] ?? [] as $next) {
                if (--$waiting[$next] === 0) {
                    $ready[] = $next;
                }
            }
        }

        return count($order) === count($waiting) ? $order : null;
    }

    /**
     * The earliest start a link allows its successor.
     *
     * @param  int  $duration  The successor's finish minus its start.
     */
    public function earliestStart(string $type, int $lag, int $predecessorStart, int $predecessorEnd, int $duration, bool $touchesMilestone = false): int
    {
        return match ($type) {
            'SS' => $predecessorStart + $lag,
            'FF' => $predecessorEnd + $lag - $duration,
            'SF' => $predecessorStart + $lag - $duration - ($touchesMilestone ? 0 : 1),
            default => $predecessorEnd + $lag + ($touchesMilestone ? 0 : 1),
        };
    }

    /**
     * Check the dates and return them as Y-m-d. A milestone finishes the day it starts.
     *
     * @return array{0:string, 1:string}
     */
    private function dates(array $data): array
    {
        $start = Carbon::parse($data['planned_start'])->toDateString();
        $end = ! empty($data['is_milestone']) ? $start : Carbon::parse($data['planned_end'] ?? $start)->toDateString();

        if (Days::of($end) < Days::of($start)) {
            throw ValidationException::withMessages(['planned_end' => __('The finish date cannot be before the start date.')]);
        }

        if (Days::of($end) - Days::of($start) > self::LONGEST_ACTIVITY_DAYS) {
            throw ValidationException::withMessages(['planned_end' => __('An activity cannot run longer than ten years.')]);
        }

        return [$start, $end];
    }

    private function assertFitsPredecessors(PlanActivity $activity, int $start, int $end): void
    {
        $links = PlanDependency::with('predecessor')->where('successor_id', $activity->id)->get();

        foreach ($links as $link) {
            $earliest = $this->earliestStart(
                $link->type,
                $link->lag_days,
                Days::of($link->predecessor->planned_start),
                Days::of($link->predecessor->planned_end),
                $end - $start,
                $link->predecessor->is_milestone || $activity->is_milestone,
            );

            if ($start < $earliest) {
                throw ValidationException::withMessages(['planned_start' => __('This must start on or after :date because it follows :activity.', [
                    'date' => Carbon::parse(Days::toDate($earliest))->format('d M Y'),
                    'activity' => $link->predecessor->code,
                ])]);
            }
        }
    }

    /**
     * Walk forward through the links and move the given activities, and anything after them,
     * as late as their predecessors require. Durations are kept and nothing moves earlier.
     *
     * @param  list<int>  $seeds
     * @return int How many activities moved.
     */
    private function reflow(int $projectId, array $seeds): int
    {
        if ($seeds === []) {
            return 0;
        }

        $activities = PlanActivity::where('project_id', $projectId)->get()->keyBy('id');
        $edges = PlanDependency::where('project_id', $projectId)->get();

        $starts = $activities->map(fn (PlanActivity $a) => Days::of($a->planned_start))->all();
        $ends = $activities->map(fn (PlanActivity $a) => Days::of($a->planned_end))->all();

        $incoming = $edges->groupBy('successor_id');
        $outgoing = $edges->groupBy('predecessor_id');
        $pending = array_fill_keys($seeds, true);
        $moved = [];

        foreach ($this->dependencyOrder($activities->keys()->all(), $edges) ?? [] as $id) {
            if (! isset($pending[$id])) {
                continue;
            }

            $shift = $this->requiredShift($activities, $incoming->get($id, new Collection), $id, $starts, $ends);

            if ($shift <= 0) {
                continue;
            }

            $starts[$id] += $shift;
            $ends[$id] += $shift;
            $moved[$id] = true;

            foreach ($outgoing->get($id, new Collection) as $edge) {
                $pending[$edge->successor_id] = true;
            }
        }

        foreach (array_keys($moved) as $id) {
            $activities[$id]->update(['planned_start' => Days::toDate($starts[$id]), 'planned_end' => Days::toDate($ends[$id])]);
        }

        return count($moved);
    }

    /**
     * @param  Collection<int, PlanActivity>  $activities
     * @param  Collection<int, PlanDependency>  $links
     * @param  array<int, int>  $starts
     * @param  array<int, int>  $ends
     */
    private function requiredShift(Collection $activities, Collection $links, int $id, array $starts, array $ends): int
    {
        $shift = 0;

        foreach ($links as $link) {
            $predecessor = $link->predecessor_id;
            $earliest = $this->earliestStart(
                $link->type,
                $link->lag_days,
                $starts[$predecessor],
                $ends[$predecessor],
                $ends[$id] - $starts[$id],
                $activities[$predecessor]->is_milestone || $activities[$id]->is_milestone,
            );

            $shift = max($shift, $earliest - $starts[$id]);
        }

        return $shift;
    }

    /**
     * @param  array<int, list<int>>  $outgoing
     * @return list<int>|null
     */
    private function findPath(array $outgoing, int $from, int $to): ?array
    {
        $stack = [[$from, [$from]]];
        $seen = [];

        while ($stack !== []) {
            [$node, $path] = array_pop($stack);

            if ($node === $to) {
                return $path;
            }

            if (isset($seen[$node])) {
                continue;
            }

            $seen[$node] = true;

            foreach ($outgoing[$node] ?? [] as $next) {
                $stack[] = [$next, [...$path, $next]];
            }
        }

        return null;
    }

    /** @return list<int> */
    private function nodeAndDescendants(PlanWbsNode $node): array
    {
        $children = PlanWbsNode::where('project_id', $node->project_id)->get(['id', 'parent_id'])->groupBy(fn (PlanWbsNode $n) => $n->parent_id ?? 0);
        $found = [];
        $stack = [$node->id];

        while ($stack !== []) {
            $id = array_pop($stack);
            $found[] = $id;

            foreach ($children->get($id, new Collection) as $child) {
                $stack[] = $child->id;
            }
        }

        return $found;
    }

    private function nextCode(Project $project): string
    {
        $highest = PlanActivity::where('project_id', $project->id)
            ->pluck('code')
            ->map(fn (string $code) => preg_match('/^A-(\d{1,9})$/', $code, $match) === 1 ? (int) $match[1] : 0)
            ->max() ?? 0;

        return sprintf('A-%03d', $highest + 1);
    }
}
