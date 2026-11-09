<?php

namespace App\Services\SiteProgress;

use App\Models\MepStageCell;
use App\Models\MepStageEvent;
use App\Models\Project;
use App\Models\ProjectFloor;
use App\Models\User;
use App\Services\SiteControl\WorkProgress;
use App\Support\ClientTime;
use App\Support\ClientUuidRepeat;
use App\Support\Idempotent;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The plumbing and electrical stage matrix: floors or flats down the side, stages across the top.
 * A stage marked done while an earlier stage on the same row is pending is accepted with a warning,
 * because the point is to make the gap visible, not to stop the supervisor writing down what happened.
 */
class MepTracker
{
    public function __construct(private WorkProgress $progress) {}

    /** @return array<string, array{label: string, stages: array<string, array<string, mixed>>}> */
    public function trades(): array
    {
        return config('modules.site_progress.mep_trades');
    }

    /** @return array<string, string> */
    public function statuses(): array
    {
        return config('modules.site_progress.mep_statuses');
    }

    /** Statuses a stage can take. "Not applicable" is only for a stage the list marks optional. */
    public function statusesFor(string $trade, string $stage): array
    {
        $optional = $this->trades()[$trade]['stages'][$stage]['optional'] ?? false;

        return array_filter($this->statuses(), fn (string $key) => $key !== 'na' || $optional, ARRAY_FILTER_USE_KEY);
    }

    public function rules(Project $project): array
    {
        return [
            'project_floor_id' => ['bail', 'required', 'integer', Rule::exists('project_floors', 'id')->where('project_id', $project->id)],
            'trade' => ['required', Rule::in(array_keys($this->trades()))],
            'stage' => ['required', 'string', 'max:32'],
            'status' => ['required', Rule::in(array_keys($this->statuses()))],
            'remark' => ['nullable', 'string', 'max:1000'],
            'occurred_at' => ['nullable', 'date'],
            'client_uuid' => ['nullable', 'uuid'],
        ];
    }

    /**
     * Set a stage's status. A repeated client_uuid returns the first result. A change that is older than what
     * the cell already holds (a phone that was offline) is kept in the history but does not overwrite it.
     *
     * @return array{cell: MepStageCell, event: MepStageEvent, warnings: list<array<string, string>>, repeat: bool}
     */
    public function update(Project $project, array $data, User $user): array
    {
        $trade = $data['trade'];
        $stage = $data['stage'];
        $status = $data['status'];
        $uuid = $data['client_uuid'] ?? null;

        if ($uuid && ($existing = $this->findEvent($project->organization_id, $uuid))) {
            $cell = $existing->cell;

            if ($cell->project_id !== $project->id || $cell->project_floor_id !== (int) $data['project_floor_id'] || $cell->trade !== $trade || $cell->stage !== $stage) {
                throw ValidationException::withMessages(['client_uuid' => [__('Record not found on the server.')]]);
            }

            return ['cell' => $cell, 'event' => $existing, 'warnings' => $this->warningsFor($cell), 'repeat' => true];
        }

        if (! isset($this->trades()[$trade]['stages'][$stage])) {
            throw ValidationException::withMessages(['stage' => __('This stage does not belong to the chosen trade.')]);
        }

        if (! array_key_exists($status, $this->statusesFor($trade, $stage))) {
            throw ValidationException::withMessages(['status' => __('This stage cannot be marked not applicable.')]);
        }

        $remark = trim((string) ($data['remark'] ?? '')) ?: null;

        if ($status === 'pending' && $remark === null) {
            throw ValidationException::withMessages(['remark' => __('Say what is pending.')]);
        }

        $floor = ProjectFloor::where('project_id', $project->id)->findOrFail($data['project_floor_id']);
        $occurredAt = ClientTime::parse($data['occurred_at'] ?? null);

        $event = Idempotent::create(function () use ($project, $floor, $trade, $stage, $status, $remark, $occurredAt, $user, $uuid) {
            $cell = MepStageCell::createOrFirst(
                ['project_floor_id' => $floor->id, 'trade' => $trade, 'stage' => $stage],
                ['organization_id' => $project->organization_id, 'project_id' => $project->id, 'status' => 'not_started'],
            );
            $cell = MepStageCell::whereKey($cell->id)->lockForUpdate()->firstOrFail();

            $stale = $occurredAt !== null && $cell->status_at !== null && $occurredAt->lt($cell->status_at);
            $earlier = $status === 'done' && ! $stale ? $this->earlierPending($cell) : [];

            $event = MepStageEvent::create([
                'organization_id' => $project->organization_id,
                'project_id' => $project->id,
                'mep_stage_cell_id' => $cell->id,
                'from_status' => $cell->status,
                'status' => $status,
                'remark' => $remark,
                'out_of_order_after' => $earlier[0] ?? null,
                'applied' => ! $stale,
                'occurred_at' => $occurredAt,
                'entered_by' => $user->id,
                'client_uuid' => $uuid,
            ]);

            if (! $stale) {
                $cell->update(['status' => $status, 'remark' => $remark, 'status_at' => $occurredAt ?? now(), 'updated_by' => $user->id]);
            }

            return $event;
        }, fn () => $this->findEvent($project->organization_id, (string) $uuid));

        $cell = $event->cell()->first();

        return ['cell' => $cell, 'event' => $event, 'warnings' => $this->warningsFor($cell), 'repeat' => ! $event->wasRecentlyCreated];
    }

    /**
     * Warnings that apply to a cell as it stands: it is done, but an earlier stage on the same row is pending.
     *
     * @return list<array<string, string>>
     */
    public function warningsFor(MepStageCell $cell): array
    {
        if ($cell->status !== 'done') {
            return [];
        }

        $floor = $cell->floor()->first();
        $trade = $this->trades()[$cell->trade];

        return array_map(fn (string $earlier) => [
            'code' => 'earlier_stage_pending',
            'stage' => $earlier,
            'message' => __(':earlier is still pending on :floor, but :stage is marked done.', [
                'earlier' => __($trade['stages'][$earlier]['label']),
                'floor' => $floor?->name ?? '',
                'stage' => __($trade['stages'][$cell->stage]['label']),
            ]),
        ], $this->earlierPending($cell));
    }

    /**
     * The whole matrix for a project, ready to draw.
     *
     * @return array<string, mixed>
     */
    public function matrix(Project $project): array
    {
        $floors = ProjectFloor::where('project_id', $project->id)->orderBy('sort')->orderBy('id')->get();
        $cells = MepStageCell::where('project_id', $project->id)->with('photos:id,mep_stage_cell_id')->get()->groupBy('project_floor_id');

        $rows = [];

        foreach ($floors->whereNull('parent_id') as $floor) {
            $rows[] = $this->row($floor, 0, $cells);

            foreach ($floors->where('parent_id', $floor->id) as $child) {
                $rows[] = $this->row($child, 1, $cells, $floor->name);
            }
        }

        // A flat whose floor has gone would otherwise vanish from the matrix.
        foreach ($floors->whereNotNull('parent_id')->reject(fn (ProjectFloor $flat) => $floors->contains('id', $flat->parent_id)) as $orphan) {
            $rows[] = $this->row($orphan, 0, $cells);
        }

        return ['rows' => $rows];
    }

    /**
     * Every pending plumbing or electrical item on a floor and its flats, to read before plaster, POP or paint starts.
     *
     * @return array<string, mixed>
     */
    public function preFinishing(ProjectFloor $floor): array
    {
        $rows = ProjectFloor::where('project_id', $floor->project_id)->where(fn ($query) => $query->whereKey($floor->id)->orWhere('parent_id', $floor->id))
            ->orderByRaw('CASE WHEN id = ? THEN 0 ELSE 1 END', [$floor->id])->orderBy('sort')->orderBy('id')->get();
        $cells = MepStageCell::whereIn('project_floor_id', $rows->pluck('id'))->withCount('photos')->get()->groupBy('project_floor_id');

        $pending = [];
        $unfinished = [];

        foreach ($rows as $row) {
            $label = $row->id === $floor->id ? $row->name : $floor->name.' › '.$row->name;

            foreach ($this->trades() as $tradeKey => $trade) {
                foreach ($trade['stages'] as $stageKey => $stage) {
                    $cell = ($cells[$row->id] ?? collect())->first(fn (MepStageCell $cell) => $cell->trade === $tradeKey && $cell->stage === $stageKey);
                    $status = $cell?->status ?? 'not_started';

                    if (in_array($status, ['done', 'na'], true)) {
                        continue;
                    }

                    $item = [
                        'cell_id' => $cell?->id,
                        'floor_id' => $row->id,
                        'row' => $label,
                        'trade' => $tradeKey,
                        'stage' => $stageKey,
                        'status' => $status,
                        'remark' => $cell?->remark,
                        'photos' => (int) ($cell?->photos_count ?? 0),
                        'since' => $cell?->status_at?->toIso8601String(),
                    ];

                    if ($status === 'pending') {
                        $pending[] = $item;
                    } else {
                        $unfinished[] = $item;
                    }
                }
            }
        }

        return [
            'floor_id' => $floor->id,
            'floor' => $floor->name,
            'pending' => $pending,
            'unfinished' => $unfinished,
            'ready' => $pending === [] && $unfinished === [],
        ];
    }

    /** @return list<string> Earlier stages of the same trade, on the same row, that are pending. Nearest to the cell first. */
    private function earlierPending(MepStageCell $cell): array
    {
        $order = array_keys($this->trades()[$cell->trade]['stages']);
        $earlier = array_slice($order, 0, (int) array_search($cell->stage, $order, true));

        if ($earlier === []) {
            return [];
        }

        $pending = MepStageCell::where('project_floor_id', $cell->project_floor_id)->where('trade', $cell->trade)
            ->whereIn('stage', $earlier)->where('status', 'pending')->pluck('stage')->all();

        return array_values(array_reverse(array_values(array_intersect($earlier, $pending))));
    }

    private function row(ProjectFloor $floor, int $level, $cells, ?string $parentName = null): array
    {
        $own = ($cells[$floor->id] ?? collect())->keyBy(fn (MepStageCell $cell) => $cell->trade.'.'.$cell->stage);
        $row = [
            'id' => $floor->id,
            'name' => $floor->name,
            'label' => $parentName ? $parentName.' › '.$floor->name : $floor->name,
            'parent_id' => $floor->parent_id,
            'level' => $level,
            'cells' => [],
            'percent' => [],
            'pending' => 0,
        ];

        foreach ($this->trades() as $tradeKey => $trade) {
            $done = 0;
            $applicable = 0;
            $pendingBefore = false;

            foreach ($trade['stages'] as $stageKey => $stage) {
                $cell = $own[$tradeKey.'.'.$stageKey] ?? null;
                $status = $cell?->status ?? 'not_started';

                $row['cells'][$tradeKey][$stageKey] = [
                    'id' => $cell?->id,
                    'status' => $status,
                    'remark' => $cell?->remark,
                    'photos' => $cell ? $cell->photos->pluck('id')->all() : [],
                    'status_at' => $cell?->status_at?->toIso8601String(),
                    // Done, but something before it on this row is still pending.
                    'warning' => $status === 'done' && $pendingBefore,
                ];

                if ($status !== 'na') {
                    $applicable++;
                }

                if ($status === 'done') {
                    $done++;
                }

                if ($status === 'pending') {
                    $pendingBefore = true;
                    $row['pending']++;
                }
            }

            $row['percent'][$tradeKey] = $this->progress->percent($done, $applicable);
        }

        return $row;
    }

    private function findEvent(int $organizationId, string $uuid): ?MepStageEvent
    {
        return ClientUuidRepeat::reachable(MepStageEvent::withoutGlobalScopes()->where('organization_id', $organizationId)->where('client_uuid', $uuid)->first());
    }
}
