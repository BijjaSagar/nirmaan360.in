<?php

namespace App\Services\Workforce;

use App\Models\Attendance;
use App\Models\BoqItem;
use App\Models\DprWorkItem;
use App\Models\LabourAllocation;
use App\Models\Project;
use App\Models\User;
use App\Models\Worker;
use App\Support\Dec;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Productivity is work done divided by man-days, for each activity (BOQ item).
 * Work done comes from submitted and approved daily reports. Man-days come from labour allocations:
 * the part of each worker's attendance that was spent on that activity.
 */
class ProductivityService
{
    /** Daily reports that count as work done. A draft or rejected report is not yet a claim of work. */
    public const REPORT_STATUSES = ['IN_REVIEW', 'APPROVED'];

    /**
     * @return array{rows: Collection<int, array<string, mixed>>, attendance_days: string, allocated_days: string, unallocated_days: string}
     */
    public function report(Project $project, string $from, string $to): array
    {
        $work = DprWorkItem::withoutGlobalScopes()
            ->where('organization_id', $project->organization_id)
            ->whereNotNull('boq_item_id')
            ->whereIn('dpr_id', fn ($query) => $query->select('id')->from('dprs')
                ->where('project_id', $project->id)
                ->whereNull('deleted_at')
                ->whereIn('status', self::REPORT_STATUSES)
                ->whereDate('report_date', '>=', $from)
                ->whereDate('report_date', '<=', $to))
            ->get(['boq_item_id', 'qty'])
            ->groupBy('boq_item_id')
            ->map(fn ($rows) => $rows->reduce(fn (BigDecimal $sum, $row) => $sum->plus(Dec::of($row->qty)), Dec::of(0)));

        $labour = $this->allocations($project, $from, $to)->get(['boq_item_id', 'man_days'])
            ->groupBy('boq_item_id')
            ->map(fn ($rows) => $rows->reduce(fn (BigDecimal $sum, $row) => $sum->plus(Dec::of($row->man_days)), Dec::of(0)));

        $items = BoqItem::withTrashed()->withoutGlobalScopes()
            ->where('organization_id', $project->organization_id)
            ->whereIn('id', $work->keys()->merge($labour->keys())->unique())
            ->orderBy('sort')->orderBy('id')
            ->get();

        $rows = $items->map(function (BoqItem $item) use ($work, $labour) {
            $done = $work->get($item->id, Dec::of(0));
            $days = $labour->get($item->id, Dec::of(0));

            return [
                'item' => $item,
                'work_done' => Dec::qty($done),
                'man_days' => Dec::qty($days),
                'productivity' => $days->isPositive() ? Dec::qty($done->dividedBy($days, 6, RoundingMode::HalfUp)) : null,
            ];
        });

        $attendanceDays = $this->attendanceDays($project, $from, $to);
        $allocated = $labour->reduce(fn (BigDecimal $sum, BigDecimal $days) => $sum->plus($days), Dec::of(0));

        return [
            'rows' => $rows->values(),
            'attendance_days' => Dec::qty($attendanceDays),
            'allocated_days' => Dec::qty($allocated),
            'unallocated_days' => Dec::qty($attendanceDays->minus($allocated)),
        ];
    }

    /**
     * Each present worker on a date, with how much of the day is still free to allocate.
     *
     * @return Collection<int, array{worker: Worker, payable: string, allocated: string, free: string}>
     */
    public function dayRoster(Project $project, string $date): Collection
    {
        $marks = Attendance::withoutGlobalScopes()
            ->where('organization_id', $project->organization_id)
            ->where('project_id', $project->id)
            ->whereDate('attendance_date', $date)
            ->where('status', '!=', 'absent')
            ->get();

        $workers = Worker::withoutGlobalScopes()->where('organization_id', $project->organization_id)->whereIn('id', $marks->pluck('worker_id'))->orderBy('name')->get();
        $allocated = $this->allocations($project, $date, $date)->get(['worker_id', 'man_days'])->groupBy('worker_id');

        return $workers->map(function (Worker $worker) use ($marks, $allocated) {
            $payable = $this->payableDays($marks->firstWhere('worker_id', $worker->id)->status);
            $used = ($allocated->get($worker->id) ?? collect())->reduce(fn (BigDecimal $sum, $row) => $sum->plus(Dec::of($row->man_days)), Dec::of(0));

            return [
                'worker' => $worker,
                'payable' => Dec::qty($payable),
                'allocated' => Dec::qty($used),
                'free' => Dec::qty($payable->minus($used)),
            ];
        })->values();
    }

    /**
     * Save labour allocations for one date. A worker can give an activity no more than the day's attendance
     * allows, counting what is already allocated.
     *
     * @param  list<array{worker_id:int, boq_item_id:int, man_days:mixed}>  $rows
     * @return Collection<int, LabourAllocation>
     */
    public function allocate(Project $project, string $date, array $rows, User $by): Collection
    {
        return DB::transaction(function () use ($project, $date, $rows, $by) {
            $roster = $this->dayRoster($project, $date)->keyBy(fn ($row) => $row['worker']->id);
            $requested = [];
            $saved = collect();

            foreach ($rows as $row) {
                $days = Dec::of($row['man_days']);
                $workerId = (int) $row['worker_id'];

                if (! $roster->has($workerId)) {
                    throw ValidationException::withMessages(['allocations' => __('Only workers marked present on this date can be allocated.')]);
                }

                $requested[$workerId] = ($requested[$workerId] ?? Dec::of(0))->plus($days);

                if ($requested[$workerId]->isGreaterThan(Dec::of($roster[$workerId]['free']))) {
                    throw ValidationException::withMessages(['allocations' => __(':name has only :free man-days left on this date.', [
                        'name' => $roster[$workerId]['worker']->name,
                        'free' => rtrim(rtrim($roster[$workerId]['free'], '0'), '.') ?: '0',
                    ])]);
                }

                $saved->push(LabourAllocation::create([
                    'organization_id' => $project->organization_id,
                    'project_id' => $project->id,
                    'worker_id' => $workerId,
                    'boq_item_id' => $row['boq_item_id'],
                    'work_date' => $date,
                    'man_days' => Dec::qty($days),
                    'created_by' => $by->id,
                ]));
            }

            return $saved;
        });
    }

    public function remove(LabourAllocation $allocation): void
    {
        $allocation->delete();
    }

    public function payableDays(string $status): BigDecimal
    {
        return match ($status) {
            'absent' => Dec::of(0),
            'half' => Dec::of('0.5'),
            default => Dec::of(1),
        };
    }

    private function attendanceDays(Project $project, string $from, string $to): BigDecimal
    {
        return Attendance::withoutGlobalScopes()
            ->where('organization_id', $project->organization_id)
            ->where('project_id', $project->id)
            ->whereDate('attendance_date', '>=', $from)
            ->whereDate('attendance_date', '<=', $to)
            ->pluck('status')
            ->reduce(fn (BigDecimal $sum, string $status) => $sum->plus($this->payableDays($status)), Dec::of(0));
    }

    private function allocations(Project $project, string $from, string $to): Builder
    {
        return LabourAllocation::withoutGlobalScopes()
            ->where('organization_id', $project->organization_id)
            ->where('project_id', $project->id)
            ->whereDate('work_date', '>=', $from)
            ->whereDate('work_date', '<=', $to);
    }
}
