<?php

namespace App\Services\Planning;

use App\Models\BoqItem;
use App\Models\PlanActivity;
use App\Models\PlanBoqLink;
use App\Models\PlanDependency;
use App\Models\PlanWbsNode;
use App\Models\Project;
use App\Support\Dec;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Collection;

/**
 * A project's plan as it stands on one day: progress per activity, the roll-up,
 * the S-curve and the Gantt rows. Every figure comes from the rows loaded once by
 * ProgressService, so the numbers on a page always agree with each other.
 *
 * Measurements are booked against a BOQ line, not an activity. When a line feeds several
 * activities, its approved quantity fills them in schedule order, earliest first.
 */
final class PlanSnapshot
{
    private const WEEKLY_LIMIT_DAYS = 126;

    /** @var array<int, ActivityProgress> Keyed by activity id. */
    public readonly array $progress;

    /** @var array<int, list<PlanBoqLink>> Links to live BOQ lines, by activity id. */
    private array $links = [];

    /** @var array<int, BigDecimal> Exact planned value by activity id. */
    private array $values = [];

    /** @var array<int, BigDecimal> Exact earned value by activity id. */
    private array $earned = [];

    /** @var array<int, BigDecimal> Approved quantity placed on each link. */
    private array $placed = [];

    /**
     * @param  Collection<int, PlanActivity>  $activities  In schedule order, with boqLinks loaded.
     * @param  Collection<int, BoqItem>  $items  Keyed by id, with ledger sums loaded.
     * @param  list<array{item:int, day:int, qty:BigDecimal}>  $entries  Approved measurements by date.
     * @param  Collection<int, PlanWbsNode>  $nodes
     * @param  Collection<int, PlanDependency>  $dependencies
     */
    public function __construct(
        public readonly Project $project,
        public readonly Collection $activities,
        public readonly Collection $items,
        private readonly array $entries,
        public readonly Collection $nodes,
        public readonly Collection $dependencies,
        public readonly int $today,
    ) {
        foreach ($activities as $activity) {
            $this->links[$activity->id] = $activity->boqLinks
                ->filter(fn (PlanBoqLink $link) => $items->has($link->boq_item_id))
                ->values()
                ->all();
        }

        $this->progress = $this->computeProgress();
    }

    /** Approved quantity counted on one BOQ link, at quantity precision. */
    public function placedQty(int $linkId): string
    {
        return Dec::qty($this->placed[$linkId] ?? 0);
    }

    /** @return array<string, mixed> The project roll-up for the panels. */
    public function summary(): array
    {
        $states = array_count_values(array_map(fn (ActivityProgress $p) => $p->state, $this->progress));
        $kinds = array_count_values(array_filter(array_map(fn (ActivityProgress $p) => $p->delayKind, $this->progress)));

        $total = $this->sum($this->values);
        $earned = $this->sum($this->earned);
        $planned = $this->plannedValueAt($this->today);

        $plannedPct = $this->percent($planned, $total);
        $actualPct = $this->percent($earned, $total);

        return [
            'activities' => count($this->progress),
            'unlinked' => $states[ActivityProgress::UNLINKED] ?? 0,
            'complete' => $states[ActivityProgress::COMPLETE] ?? 0,
            'in_progress' => $states[ActivityProgress::IN_PROGRESS] ?? 0,
            'not_started' => $states[ActivityProgress::NOT_STARTED] ?? 0,
            'overdue' => $kinds[ActivityProgress::OVERDUE] ?? 0,
            'late_start' => $kinds[ActivityProgress::LATE_START] ?? 0,
            'delayed' => array_sum($kinds),
            'total' => Dec::money($total),
            'planned' => Dec::money($planned),
            'earned' => Dec::money($earned),
            'planned_pct' => $plannedPct,
            'actual_pct' => $actualPct,
            'variance_pct' => (string) Dec::of($actualPct)->minus(Dec::of($plannedPct)),
            'spi' => $planned->isPositive() ? (string) $earned->dividedBy($planned, 2, RoundingMode::HalfUp) : null,
        ];
    }

    /** @return Collection<int, PlanActivity> Delayed activities, the worst first. */
    public function delayed(int $limit = 8): Collection
    {
        return $this->activities
            ->filter(fn (PlanActivity $a) => $this->progress[$a->id]->isDelayed())
            ->sortByDesc(fn (PlanActivity $a) => $this->progress[$a->id]->delayDays)
            ->take($limit)
            ->values();
    }

    /** @return Collection<int, PlanActivity> Unfinished activities starting in the coming days. */
    public function upcoming(int $days = 14, int $limit = 6): Collection
    {
        return $this->activities
            ->filter(function (PlanActivity $a) use ($days) {
                $start = Days::of($a->planned_start);

                return ! $this->progress[$a->id]->isComplete() && $start >= $this->today && $start <= $this->today + $days;
            })
            ->take($limit)
            ->values();
    }

    /**
     * Cumulative planned and earned value by date. Planned value uses the baseline dates
     * where they exist and is spread evenly over each activity's days. Earned value is the
     * approved quantity as measured on each date, so it stops at today.
     *
     * @return array{grain:?string, total:string, points:list<array<string, mixed>>}
     */
    public function sCurve(): array
    {
        $tracked = $this->activities->filter(fn (PlanActivity $a) => $this->links[$a->id] !== []);

        if ($tracked->isEmpty()) {
            return ['grain' => null, 'total' => '0.00', 'points' => []];
        }

        [$from, $to] = $this->curveRange($tracked);
        $grain = $to - $from <= self::WEEKLY_LIMIT_DAYS ? 'week' : 'month';
        $total = $this->sum($this->values);

        $points = [];

        foreach ($this->curveDays($from, $to, $grain) as $day) {
            $planned = $this->plannedValueAt($day);
            $actual = $day <= $this->today ? $this->earnedValueAt($day) : null;

            $points[] = [
                'date' => Days::toDate($day),
                'day' => $day,
                'is_today' => $day === $this->today,
                'planned' => Dec::money($planned),
                'planned_pct' => $this->percent($planned, $total),
                'actual' => $actual === null ? null : Dec::money($actual),
                'actual_pct' => $actual === null ? null : $this->percent($actual, $total),
            ];
        }

        return ['grain' => $grain, 'total' => Dec::money($total), 'points' => $points];
    }

    /**
     * Rows and range for the Gantt chart, in WBS order. Positions are day offsets from the origin.
     *
     * @return array{origin:int, days:int, today:?int, zoom:string, rows:list<array<string, mixed>>}
     */
    public function gantt(): array
    {
        $rows = [];
        $childNodes = $this->nodes->groupBy(fn (PlanWbsNode $n) => $n->parent_id ?? 0);
        $byNode = $this->activities->groupBy(fn (PlanActivity $a) => $a->wbs_node_id ?? 0);
        $incoming = $this->dependencies->groupBy('successor_id');

        $firstDay = function (int $node) use (&$firstDay, $childNodes, $byNode): int {
            $days = $byNode->get($node, collect())->map(fn (PlanActivity $a) => Days::of($a->planned_start))->all();

            foreach ($childNodes->get($node, collect()) as $child) {
                $days[] = $firstDay($child->id);
            }

            return $days === [] ? PHP_INT_MAX : min($days);
        };

        // Sub-nodes and activities sit together in date order, as they run on site.
        $walk = function (int $parent, int $depth, array $ancestors) use (&$walk, &$rows, $childNodes, $byNode, $incoming, $firstDay) {
            $children = collect();

            foreach ($childNodes->get($parent, collect()) as $node) {
                $children->push(['day' => $firstDay($node->id), 'code' => $node->code, 'node' => $node, 'activity' => null]);
            }

            foreach ($byNode->get($parent, collect()) as $activity) {
                $children->push(['day' => Days::of($activity->planned_start), 'code' => $activity->code, 'node' => null, 'activity' => $activity]);
            }

            foreach ($children->sortBy([['day', 'asc'], ['code', 'asc']]) as $child) {
                if ($child['activity']) {
                    $rows[] = $this->activityRow($child['activity'], $depth, $ancestors, $incoming);

                    continue;
                }

                $at = count($rows);
                $rows[] = [];
                $walk($child['node']->id, $depth + 1, [...$ancestors, $child['node']->id]);
                $rows[$at] = $this->nodeRow($child['node'], $depth, $ancestors, array_slice($rows, $at + 1));
            }
        };

        $walk(0, 0, []);

        return $this->placeRows($rows);
    }

    /** @return array<int, ActivityProgress> */
    private function computeProgress(): array
    {
        $this->placed = $this->allocate($this->items
            ->map(fn (BoqItem $item) => Dec::of(Dec::qty($item->approved_qty ?? 0)))
            ->all());

        $dates = $this->actualDates();
        $result = [];

        foreach ($this->activities as $activity) {
            $result[$activity->id] = $this->activityProgress($activity, $dates);
        }

        return $result;
    }

    /** @param array<int, array{start:?int, finish:?int}> $dates */
    private function activityProgress(PlanActivity $activity, array $dates): ActivityProgress
    {
        $slip = $activity->baseline_end ? Days::of($activity->planned_end) - Days::of($activity->baseline_end) : null;
        $links = $this->links[$activity->id];

        if ($links === []) {
            $this->values[$activity->id] = BigDecimal::zero();
            $this->earned[$activity->id] = BigDecimal::zero();

            return new ActivityProgress(state: ActivityProgress::UNLINKED, baselineSlip: $slip);
        }

        $value = $earned = $qty = $earnedQty = BigDecimal::zero();
        $starts = $finishes = [];

        foreach ($links as $link) {
            $rate = Dec::of($this->items->get($link->boq_item_id)->rate);
            $placed = $this->placed[$link->id] ?? BigDecimal::zero();

            $value = $value->plus(Dec::of($link->qty)->multipliedBy($rate));
            $earned = $earned->plus($placed->multipliedBy($rate));
            $qty = $qty->plus(Dec::of($link->qty));
            $earnedQty = $earnedQty->plus($placed);

            $starts[] = $dates[$link->id]['start'] ?? null;
            $finishes[] = $dates[$link->id]['finish'] ?? null;
        }

        $this->values[$activity->id] = $value;
        $this->earned[$activity->id] = $earned;

        $complete = $earnedQty->isGreaterThanOrEqualTo($qty);
        $percent = $value->isPositive() ? $this->percent($earned, $value) : $this->percent($earnedQty, $qty);

        // A zero-rate line can leave the value full while the work is not.
        if (! $complete && $percent === '100.00') {
            $percent = '99.99';
        }

        $started = array_filter($starts, fn ($day) => $day !== null);
        [$kind, $days] = $this->delay($activity, $complete, $earnedQty->isPositive());

        return new ActivityProgress(
            state: match (true) {
                $complete => ActivityProgress::COMPLETE,
                $earnedQty->isPositive() => ActivityProgress::IN_PROGRESS,
                default => ActivityProgress::NOT_STARTED,
            },
            percent: $percent,
            expected: $this->expectedPercent($activity),
            value: Dec::money($value),
            earned: Dec::money($earned),
            delayKind: $kind,
            delayDays: $days,
            actualStart: $started === [] ? null : Days::toDate(min($started)),
            actualFinish: $complete && ! in_array(null, $finishes, true) ? Days::toDate(max($finishes)) : null,
            baselineSlip: $slip,
        );
    }

    /** @return array{0:?string, 1:int} */
    private function delay(PlanActivity $activity, bool $complete, bool $started): array
    {
        if ($complete) {
            return [null, 0];
        }

        $start = Days::of($activity->planned_start);
        $end = Days::of($activity->planned_end);

        if ($this->today > $end) {
            return [ActivityProgress::OVERDUE, $this->today - $end];
        }

        if (! $started && $this->today > $start) {
            return [ActivityProgress::LATE_START, $this->today - $start];
        }

        return [null, 0];
    }

    private function expectedPercent(PlanActivity $activity): string
    {
        $start = Days::of($activity->planned_start);
        $end = Days::of($activity->planned_end);

        return $this->percent(
            BigDecimal::of(max(0, min($this->today, $end) - $start + 1)),
            BigDecimal::of($end - $start + 1),
        );
    }

    /**
     * Pour each line's approved quantity into its links in schedule order.
     *
     * @param  array<int, BigDecimal>  $approvedByItem
     * @return array<int, BigDecimal> Quantity placed, by link id.
     */
    private function allocate(array $approvedByItem): array
    {
        $left = array_map(fn (BigDecimal $qty) => $qty->isNegative() ? BigDecimal::zero() : $qty, $approvedByItem);
        $placed = [];

        foreach ($this->activities as $activity) {
            foreach ($this->links[$activity->id] as $link) {
                $available = $left[$link->boq_item_id] ?? BigDecimal::zero();
                $take = $available->isLessThan(Dec::of($link->qty)) ? $available : Dec::of($link->qty);

                $placed[$link->id] = $take;
                $left[$link->boq_item_id] = $available->minus($take);
            }
        }

        return $placed;
    }

    /**
     * Replay approved measurements in date order to find when each link first received
     * work and when it filled up. A later reversal undoes either date.
     *
     * @return array<int, array{start:?int, finish:?int}> By link id.
     */
    private function actualDates(): array
    {
        $bands = [];
        $cursor = [];

        foreach ($this->activities as $activity) {
            foreach ($this->links[$activity->id] as $link) {
                $from = $cursor[$link->boq_item_id] ?? BigDecimal::zero();
                $to = $from->plus(Dec::of($link->qty));
                $cursor[$link->boq_item_id] = $to;
                $bands[$link->boq_item_id][] = ['link' => $link->id, 'from' => $from, 'to' => $to];
            }
        }

        $total = [];
        $dates = [];

        foreach ($this->entries as $entry) {
            if (! isset($bands[$entry['item']])) {
                continue;
            }

            $total[$entry['item']] = ($total[$entry['item']] ?? BigDecimal::zero())->plus($entry['qty']);

            foreach ($bands[$entry['item']] as $band) {
                $date = $dates[$band['link']] ?? ['start' => null, 'finish' => null];
                $date['start'] = $total[$entry['item']]->isGreaterThan($band['from']) ? ($date['start'] ?? $entry['day']) : null;
                $date['finish'] = $total[$entry['item']]->isGreaterThanOrEqualTo($band['to']) ? ($date['finish'] ?? $entry['day']) : null;
                $dates[$band['link']] = $date;
            }
        }

        return $dates;
    }

    private function plannedValueAt(int $day): BigDecimal
    {
        $total = BigDecimal::zero();

        foreach ($this->activities as $activity) {
            $value = $this->values[$activity->id];

            if ($value->isZero()) {
                continue;
            }

            $start = Days::of($activity->baseline_start ?? $activity->planned_start);
            $end = Days::of($activity->baseline_end ?? $activity->planned_end);

            if ($day >= $end) {
                $total = $total->plus($value);
            } elseif ($day >= $start) {
                $total = $total->plus($value->multipliedBy($day - $start + 1)->dividedBy($end - $start + 1, 6, RoundingMode::HalfUp));
            }
        }

        return $total;
    }

    private function earnedValueAt(int $day): BigDecimal
    {
        $approved = [];

        foreach ($this->entries as $entry) {
            if ($entry['day'] > $day) {
                break;
            }

            $approved[$entry['item']] = ($approved[$entry['item']] ?? BigDecimal::zero())->plus($entry['qty']);
        }

        $placed = $this->allocate($approved);
        $total = BigDecimal::zero();

        foreach ($this->activities as $activity) {
            foreach ($this->links[$activity->id] as $link) {
                $total = $total->plus($placed[$link->id]->multipliedBy(Dec::of($this->items->get($link->boq_item_id)->rate)));
            }
        }

        return $total;
    }

    /**
     * @param  Collection<int, PlanActivity>  $tracked
     * @return array{0:int, 1:int}
     */
    private function curveRange(Collection $tracked): array
    {
        $from = $tracked->min(fn (PlanActivity $a) => Days::of($a->baseline_start ?? $a->planned_start));
        $to = $tracked->max(fn (PlanActivity $a) => Days::of($a->baseline_end ?? $a->planned_end));

        $items = $tracked->flatMap(fn (PlanActivity $a) => array_map(fn (PlanBoqLink $link) => $link->boq_item_id, $this->links[$a->id]))->flip();
        $measured = array_values(array_filter($this->entries, fn (array $entry) => $items->has($entry['item'])));

        if ($measured !== []) {
            $from = min($from, $measured[0]['day']);
            $to = max($to, $measured[count($measured) - 1]['day']);
        }

        // A running late project keeps its curve open up to today.
        if ($this->today > $to && $tracked->contains(fn (PlanActivity $a) => ! $this->progress[$a->id]->isComplete())) {
            $to = $this->today;
        }

        return [$from, $to];
    }

    /** @return list<int> Day before the start, each period end, the last day, and today. */
    private function curveDays(int $from, int $to, string $grain): array
    {
        $days = [$from - 1, $to];
        $cursor = $grain === 'week' ? Days::endOfWeek($from) : Days::endOfMonth($from);

        while ($cursor < $to) {
            $days[] = $cursor;
            $cursor = $grain === 'week' ? $cursor + 7 : Days::endOfMonth($cursor + 1);
        }

        if ($this->today >= $from && $this->today <= $to) {
            $days[] = $this->today;
        }

        $days = array_values(array_unique($days));
        sort($days);

        return $days;
    }

    /**
     * @param  array<int, int>  $ancestors
     * @param  Collection<int, Collection<int, PlanDependency>>  $incoming
     * @return array<string, mixed>
     */
    private function activityRow(PlanActivity $activity, int $depth, array $ancestors, Collection $incoming): array
    {
        $progress = $this->progress[$activity->id];

        return [
            'key' => 'a'.$activity->id,
            'kind' => 'activity',
            'id' => $activity->id,
            'code' => $activity->code,
            'name' => $activity->name,
            'depth' => $depth,
            'ancestors' => $ancestors,
            'start' => Days::of($activity->planned_start),
            'end' => Days::of($activity->planned_end),
            'bstart' => $activity->baseline_start ? Days::of($activity->baseline_start) : null,
            'bend' => $activity->baseline_end ? Days::of($activity->baseline_end) : null,
            'pct' => $progress->percent,
            'state' => $progress->state,
            'delay' => $progress->delayKind,
            'delayDays' => $progress->delayDays,
            'milestone' => $activity->is_milestone,
            'url' => route('planning.activities.show', $activity),
            'startLabel' => $activity->planned_start->format('d M Y'),
            'endLabel' => $activity->planned_end->format('d M Y'),
            'deps' => $incoming->get($activity->id, collect())
                ->map(fn (PlanDependency $d) => ['from' => 'a'.$d->predecessor_id, 'type' => $d->type])
                ->values()
                ->all(),
        ];
    }

    /**
     * @param  array<int, int>  $ancestors
     * @param  list<array<string, mixed>>  $descendants
     * @return array<string, mixed>
     */
    private function nodeRow(PlanWbsNode $node, int $depth, array $ancestors, array $descendants): array
    {
        $activities = array_values(array_filter($descendants, fn (array $row) => $row['kind'] === 'activity'));
        $value = $earned = BigDecimal::zero();

        foreach ($activities as $row) {
            $value = $value->plus($this->values[$row['id']]);
            $earned = $earned->plus($this->earned[$row['id']]);
        }

        return [
            'key' => 'w'.$node->id,
            'kind' => 'wbs',
            'id' => $node->id,
            'code' => $node->code,
            'name' => $node->name,
            'depth' => $depth,
            'ancestors' => $ancestors,
            'start' => $activities === [] ? null : min(array_column($activities, 'start')),
            'end' => $activities === [] ? null : max(array_column($activities, 'end')),
            'pct' => $value->isPositive() ? $this->percent($earned, $value) : null,
            'state' => null,
            'delay' => null,
            'delayDays' => 0,
            'milestone' => false,
            'url' => null,
            'deps' => [],
        ];
    }

    /**
     * Turn absolute days into offsets and pick the range and starting zoom.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array{origin:int, days:int, today:?int, zoom:string, rows:list<array<string, mixed>>}
     */
    private function placeRows(array $rows): array
    {
        $bounds = [];

        foreach ($rows as $row) {
            foreach (['start', 'end', 'bstart', 'bend'] as $field) {
                if (isset($row[$field])) {
                    $bounds[] = $row[$field];
                }
            }
        }

        if ($bounds === []) {
            return ['origin' => $this->today, 'days' => 30, 'today' => 0, 'zoom' => 'week', 'rows' => $rows];
        }

        $first = min($bounds);
        $last = max($bounds);
        $span = $last - $first;

        // A today far from the work would only stretch the chart.
        if ($this->today >= $first - 45 && $this->today <= $last + 45) {
            $first = min($first, $this->today);
            $last = max($last, $this->today);
        }

        $origin = $first - 3;
        $days = $last - $origin + 7;

        foreach ($rows as &$row) {
            foreach (['start', 'end', 'bstart', 'bend'] as $field) {
                if (isset($row[$field])) {
                    $row[$field] -= $origin;
                }
            }
        }
        unset($row);

        return [
            'origin' => $origin,
            'days' => $days,
            'today' => $this->today >= $origin && $this->today < $origin + $days ? $this->today - $origin : null,
            'zoom' => $span <= 45 ? 'day' : ($span <= 270 ? 'week' : 'month'),
            'rows' => $rows,
        ];
    }

    /** @param array<int, BigDecimal> $values */
    private function sum(array $values): BigDecimal
    {
        return array_reduce($values, fn (BigDecimal $sum, BigDecimal $value) => $sum->plus($value), BigDecimal::zero());
    }

    /** A share as a percentage to two places. It only reads 100.00 when the share is whole. */
    private function percent(BigDecimal $part, BigDecimal $whole): string
    {
        if ($whole->isZero()) {
            return '0.00';
        }

        $percent = $part->multipliedBy(100)->dividedBy($whole, 2, RoundingMode::HalfUp);

        if ($percent->isGreaterThanOrEqualTo(100)) {
            return $part->isLessThan($whole) ? '99.99' : '100.00';
        }

        return (string) $percent;
    }
}
