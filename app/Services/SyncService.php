<?php

namespace App\Services;

use App\Http\Presenters\ApprovalPresenter;
use App\Http\Presenters\AttendancePresenter;
use App\Http\Presenters\BoqItemPresenter;
use App\Http\Presenters\DprPresenter;
use App\Http\Presenters\IssuePresenter;
use App\Http\Presenters\MeasurementPresenter;
use App\Http\Presenters\PettyEntryPresenter;
use App\Http\Presenters\ProjectPresenter;
use App\Http\Presenters\SitePresenter;
use App\Http\Presenters\UserPresenter;
use App\Http\Presenters\WalletPresenter;
use App\Http\Presenters\WorkerPresenter;
use App\Models\Attendance;
use App\Models\AuditLog;
use App\Models\BoqItem;
use App\Models\Dpr;
use App\Models\Issue;
use App\Models\MeasurementEntry;
use App\Models\PettyCashEntry;
use App\Models\PettyCashWallet;
use App\Models\Project;
use App\Models\Site;
use App\Models\User;
use App\Models\Worker;
use App\Support\ClientTime;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

/** The field app's offline layer: outbox writes, file uploads and the delta pull. */
class SyncService
{
    /** Push type => [permission, plan feature]. */
    private const PUSH_TYPES = [
        'measurement' => ['measurement.create', 'measurement'],
        'measurement_submit' => ['measurement.create', 'measurement'],
        'dpr' => ['dpr.create', 'site'],
        'dpr_submit' => ['dpr.create', 'site'],
        'issue' => ['issue.manage', 'site'],
        'issue_status' => ['issue.manage', 'site'],
        'attendance' => ['attendance.manage', 'labour'],
        'petty_cash' => ['petty_cash.manage', 'petty_cash'],
    ];

    private const FINAL_DOCUMENT_STATUSES = ['APPROVED', 'REJECTED'];

    private const OPEN_ISSUE_STATUSES = ['open', 'in_progress'];

    public function __construct(
        private MeasurementService $measurements,
        private DprService $dprs,
        private IssueService $issues,
        private AttendanceService $attendance,
        private PettyCashService $pettyCash,
        private ApprovalService $approvals,
    ) {}

    /**
     * Apply a batch in order. One bad item is reported and the rest still run.
     *
     * @return list<array<string, mixed>>
     */
    public function push(array $items, User $user): array
    {
        return array_map(fn ($item) => $this->pushItem($item, $user), array_values($items));
    }

    /** Store one queued file against its parent record. */
    public function upload(array $data, UploadedFile $file, User $user): array
    {
        $isPhoto = $data['type'] === 'dpr_photo';

        abort_unless($user->hasPermission($isPhoto ? 'dpr.create' : 'petty_cash.manage'), 403);
        abort_unless($user->organization->allowsFeature($isPhoto ? 'site' : 'petty_cash'), 403, __('Your plan does not include this module. Upgrade to continue.'));

        $parent = $this->findByReference($isPhoto ? Dpr::query() : PettyCashEntry::query(), $data['parent_client_uuid'] ?? null, $data['parent_id'] ?? null);

        abort_unless($parent !== null, 404, __('The parent record has not reached the server yet.'));

        if (! $isPhoto) {
            return ['status' => $this->pettyCash->attachBill($parent, $file) ? 'created' : 'duplicate', 'id' => $parent->id];
        }

        $photo = $this->dprs->addPhoto($parent, $file, [
            'client_uuid' => $data['client_uuid'],
            'caption' => $data['caption'] ?? null,
            'taken_at' => ClientTime::parse($data['taken_at'] ?? null),
        ]);

        return ['status' => $photo->wasRecentlyCreated ? 'created' : 'duplicate', 'id' => $photo->id];
    }

    /** Everything the device needs that changed since its last pull. */
    public function pull(User $user, ?Carbon $since): array
    {
        // Before any query, so a row written during this pull is sent again by the next one.
        $serverTime = now()->toIso8601String();

        $organization = $user->organization;
        $can = fn (string $permission, ?string $feature = null): bool => $user->hasPermission($permission)
            && ($feature === null || $organization->allowsFeature($feature));
        $when = fn (bool $allowed, Closure $list) => $allowed ? $list()->values()->all() : [];
        $delta = fn (Builder $query) => $query->when($since, fn ($q) => $q->where('updated_at', '>=', $since));

        $canSeeMeasurements = $can('measurement.view', 'measurement');
        $removedDrafts = $this->removedDrafts($since);

        return [
            'server_time' => $serverTime,
            'user' => UserPresenter::present($user),
            'meta' => $this->meta(),
            'users' => User::where('organization_id', $user->organization_id)->where('is_active', true)->orderBy('name')
                ->get(['id', 'name', 'role'])
                ->map->only(['id', 'name', 'role'])->values()->all(),
            'projects' => $when($can('project.view'), fn () => $delta(Project::query())->get()->map(ProjectPresenter::present(...))),
            'sites' => $when($can('project.view'), fn () => $delta(Site::query())->get()->map(SitePresenter::present(...))),
            'boq_items' => $when($can('boq.view'), fn () => $delta(BoqItem::withLedger()->with('section'))->orderBy('sort')->orderBy('id')->get()->map(BoqItemPresenter::present(...))),
            'boq_balances' => $when($can('boq.view'), fn () => $this->movedBalances($since, $removedDrafts)),
            'workers' => $when($can('worker.view', 'labour'), fn () => $delta(Worker::query())->get()->map(WorkerPresenter::present(...))),
            'wallets' => $when($can('petty_cash.view', 'petty_cash'), fn () => PettyCashWallet::with('entries')->get()->map(WalletPresenter::present(...))),
            'dprs' => $when($can('dpr.view', 'site'), fn () => $this->window(Dpr::with(DprPresenter::RELATIONS), $since, fn ($q) => $q->whereNotIn('status', self::FINAL_DOCUMENT_STATUSES))->get()->map(DprPresenter::present(...))),
            'issues' => $when($can('issue.view', 'site'), fn () => $this->window(Issue::query(), $since, fn ($q) => $q->whereIn('status', self::OPEN_ISSUE_STATUSES))->get()->map(IssuePresenter::present(...))),
            'attendance' => $when($can('attendance.view', 'labour'), fn () => $this->window(Attendance::query(), $since)->get()->map(AttendancePresenter::present(...))),
            'petty_cash_entries' => $when($can('petty_cash.view', 'petty_cash'), fn () => $this->window(PettyCashEntry::query(), $since)->get()->map(PettyEntryPresenter::present(...))),
            'measurements' => $when($canSeeMeasurements, fn () => $this->window(MeasurementEntry::with(MeasurementPresenter::RELATIONS), $since, fn ($q) => $q->whereNotIn('status', self::FINAL_DOCUMENT_STATUSES))->get()->map(MeasurementPresenter::present(...))),
            'approvals' => ApprovalPresenter::collection($this->approvals->inbox($user)[0], $user),
            'deleted' => [
                'projects' => $can('project.view') ? $this->trashedIds(Project::class, $since) : [],
                'boq_items' => $can('boq.view') ? $this->trashedIds(BoqItem::class, $since) : [],
                'workers' => $can('worker.view', 'labour') ? $this->trashedIds(Worker::class, $since) : [],
                'issues' => $can('issue.view', 'site') ? $this->trashedIds(Issue::class, $since) : [],
                'dprs' => $can('dpr.view', 'site') ? $this->trashedIds(Dpr::class, $since) : [],
                'measurements' => $canSeeMeasurements ? $removedDrafts->pluck('auditable_id')->values()->all() : [],
            ],
        ];
    }

    private function meta(): array
    {
        return [
            'trades' => config('buildos.trades'),
            'units' => config('buildos.units'),
            'expense_categories' => config('buildos.expense_categories'),
            'issue_priorities' => config('buildos.issue_priorities'),
            'issue_statuses' => config('buildos.issue_statuses'),
            'attendance_statuses' => config('buildos.attendance_statuses'),
            'weather' => config('buildos.weather'),
            'measurement_tolerance_pct' => (string) config('buildos.measurement_tolerance_pct'),
        ];
    }

    /** Delta since the last pull. A first pull takes the sync window plus anything still open. */
    private function window(Builder $query, ?Carbon $since, ?Closure $stillOpen = null): Builder
    {
        if ($since) {
            return $query->where('updated_at', '>=', $since);
        }

        $from = now()->subDays(config('buildos.sync_window_days'));

        return $query->where(function (Builder $inner) use ($from, $stillOpen) {
            $inner->where('updated_at', '>=', $from);

            if ($stillOpen) {
                $inner->orWhere($stillOpen);
            }
        });
    }

    /** @param class-string<Model> $model */
    private function trashedIds(string $model, ?Carbon $since): array
    {
        return $since ? $model::onlyTrashed()->where('deleted_at', '>=', $since)->pluck('id')->all() : [];
    }

    /** Deleted drafts are hard-deleted, so only the audit log still knows about them. */
    private function removedDrafts(?Carbon $since): Collection
    {
        if (! $since) {
            return collect();
        }

        return AuditLog::where('action', 'deleted')
            ->where('auditable_type', (new MeasurementEntry)->getMorphClass())
            ->where('created_at', '>=', $since)
            ->get(['auditable_id', 'old_values']);
    }

    /** A measurement never touches boq_items.updated_at, so ledger moves are found from the entries. */
    private function movedBalances(?Carbon $since, Collection $removedDrafts): Collection
    {
        if (! $since) {
            return collect();
        }

        $moved = MeasurementEntry::where('updated_at', '>=', $since)->distinct()->pluck('boq_item_id')
            ->merge($removedDrafts->map(fn (AuditLog $log) => $log->old_values['boq_item_id'] ?? null)->filter())
            ->unique();

        return BoqItem::withLedger()->whereIn('id', $moved)->get()->map(BoqItemPresenter::balance(...));
    }

    private function pushItem(mixed $item, User $user): array
    {
        $result = [
            'client_uuid' => is_array($item) && is_string($item['client_uuid'] ?? null) ? $item['client_uuid'] : null,
            'status' => 'error',
            'id' => null,
            'server_status' => null,
            'reason' => null,
            'errors' => null,
        ];

        try {
            // Per item, so a failure leaves no half-written children and cannot affect the rest.
            return [...$result, ...DB::transaction(fn () => $this->applyItem(is_array($item) ? $item : [], $user))];
        } catch (ValidationException $exception) {
            return [...$result, 'errors' => $exception->errors()];
        } catch (Throwable $exception) {
            report($exception);

            return [...$result, 'errors' => ['item' => [__('This record could not be saved.')]]];
        }
    }

    private function applyItem(array $item, User $user): array
    {
        $item = $this->validated($item, [
            'type' => ['required', 'string', Rule::in(array_keys(self::PUSH_TYPES))],
            'client_uuid' => ['required', 'uuid'],
            'project_id' => ['required', 'integer'],
            'server_id' => ['nullable', 'integer'],
            'client_updated_at' => ['nullable', 'date'],
            'data' => ['required', 'array'],
        ]);

        [$permission, $feature] = self::PUSH_TYPES[$item['type']];

        if (! $user->hasPermission($permission)) {
            return ['status' => 'forbidden', 'reason' => 'permission'];
        }

        if (! $user->organization->allowsFeature($feature)) {
            return ['status' => 'forbidden', 'reason' => 'feature'];
        }

        $project = Project::find($item['project_id']);

        if (! $project) {
            throw ValidationException::withMessages(['project_id' => [__('Project not found.')]]);
        }

        $stamp = ClientTime::parse($item['client_updated_at'] ?? null);

        return match ($item['type']) {
            'measurement' => $this->pushMeasurement($item, $project, $stamp, $user),
            'measurement_submit' => $this->pushMeasurementSubmit($item, $project, $user),
            'dpr' => $this->pushDpr($item, $project, $stamp, $user),
            'dpr_submit' => $this->pushDprSubmit($item, $project, $user),
            'issue' => $this->pushIssue($item, $project, $stamp, $user),
            'issue_status' => $this->pushIssueStatus($item, $project, $stamp, $user),
            'attendance' => $this->pushAttendance($item, $project, $stamp, $user),
            'petty_cash' => $this->pushPettyCash($item, $project, $user),
        };
    }

    private function pushMeasurement(array $item, Project $project, ?Carbon $stamp, User $user): array
    {
        $values = function () use ($item, $project, $stamp): array {
            $data = $this->validated($item['data'], $this->measurements->rules($project));

            return [...$data, 'is_deduction' => (bool) ($data['is_deduction'] ?? false), 'client_updated_at' => $stamp];
        };

        $entry = $this->findRow(MeasurementEntry::query(), $item);

        if (! $entry) {
            $entry = $this->measurements->create($project, [...$values(), 'client_uuid' => $item['client_uuid']], $user);

            return $this->result($entry->wasRecentlyCreated ? 'created' : 'duplicate', $entry);
        }

        return $this->reconcileDocument($entry, $item, $project, $stamp, fn () => $this->measurements->update(
            $entry, [...$values(), 'client_uuid' => $entry->client_uuid ?? $item['client_uuid']],
        ));
    }

    private function pushDpr(array $item, Project $project, ?Carbon $stamp, User $user): array
    {
        $values = fn (): array => [...$this->validated($item['data'], $this->dprs->rules($project)), 'client_updated_at' => $stamp];

        $dpr = $this->findRow(Dpr::withTrashed(), $item);

        if (! $dpr) {
            $dpr = $this->dprs->create($project, [...$values(), 'client_uuid' => $item['client_uuid']], $user);

            return $this->result($dpr->wasRecentlyCreated ? 'created' : 'duplicate', $dpr);
        }

        return $this->reconcileDocument($dpr, $item, $project, $stamp, fn () => $this->dprs->update(
            $dpr, [...$values(), 'client_uuid' => $dpr->client_uuid ?? $item['client_uuid']],
        ));
    }

    private function pushMeasurementSubmit(array $item, Project $project, User $user): array
    {
        $entry = $this->findSubmitTarget(MeasurementEntry::query(), $item, 'entry_client_uuid', 'entry_id');

        return $this->submitDocument($entry, $item, $project, fn () => $this->measurements->submit($entry, $user));
    }

    private function pushDprSubmit(array $item, Project $project, User $user): array
    {
        $dpr = $this->findSubmitTarget(Dpr::query(), $item, 'dpr_client_uuid', 'dpr_id');

        return $this->submitDocument($dpr, $item, $project, fn () => $this->dprs->submit($dpr, $user));
    }

    private function pushIssue(array $item, Project $project, ?Carbon $stamp, User $user): array
    {
        $values = fn (): array => [...$this->validated($item['data'], $this->issues->rules($project, $user)), 'client_updated_at' => $stamp];

        $issue = $this->findRow(Issue::withTrashed(), $item);

        if (! $issue) {
            $issue = $this->issues->create($project, [...$values(), 'client_uuid' => $item['client_uuid']], $user);

            return $this->result($issue->wasRecentlyCreated ? 'created' : 'duplicate', $issue);
        }

        $this->assertSameProject($issue, $project);

        if ($issue->trashed()) {
            return $this->result('duplicate', $issue);
        }

        return $this->reconcile($issue, $item, $stamp, fn () => $this->issues->update(
            $issue, [...$values(), 'client_uuid' => $issue->client_uuid ?? $item['client_uuid']],
        ));
    }

    private function pushIssueStatus(array $item, Project $project, ?Carbon $stamp, User $user): array
    {
        $data = $this->validated($item['data'], [
            'issue_client_uuid' => ['nullable', 'uuid', 'required_without:issue_id'],
            'issue_id' => ['nullable', 'integer'],
            'status' => $this->issues->statusRules($user)['status'],
        ]);

        $issue = $this->findByReference(Issue::query(), $data['issue_client_uuid'] ?? null, $data['issue_id'] ?? null);

        if (! $issue) {
            throw ValidationException::withMessages(['issue_id' => [__('Record not found on the server.')]]);
        }

        $this->assertSameProject($issue, $project);

        $apply = fn () => $this->issues->update($issue, ['status' => $data['status'], 'client_updated_at' => $stamp]);

        if ($stamp === null && $issue->status !== $data['status']) {
            $apply();

            return $this->result('updated', $issue);
        }

        return $this->reconcile($issue, $item, $stamp, $apply);
    }

    private function pushAttendance(array $item, Project $project, ?Carbon $stamp, User $user): array
    {
        $replayed = $stamp ? Attendance::where('client_uuid', $item['client_uuid'])->first() : null;

        if ($replayed?->client_updated_at?->equalTo($stamp)) {
            return $this->result('duplicate', $replayed);
        }

        $data = $this->validated($item['data'], [
            ...$this->attendance->markRules(),
            'worker_id' => $this->attendance->workerRule(),
            'attendance_date' => ['required', 'date', 'before_or_equal:today'],
        ]);

        $date = Carbon::parse($data['attendance_date'])->toDateString();
        $mark = [...$data, 'client_uuid' => $item['client_uuid'], 'client_updated_at' => $stamp];
        $existing = $this->attendance->findForDay($project, (int) $data['worker_id'], $date);

        if (! $existing) {
            $row = $this->attendance->save($project, $date, $mark, $user);

            return $this->result($row->wasRecentlyCreated ? 'created' : 'duplicate', $row);
        }

        return $this->reconcile($existing, $item, $stamp, fn () => $this->attendance->save($project, $date, $mark, $user, $existing));
    }

    private function pushPettyCash(array $item, Project $project, User $user): array
    {
        $existing = $this->pettyCash->findByClientUuid($project->organization_id, $item['client_uuid']);

        if ($existing) {
            return $this->result('duplicate', $existing);
        }

        $data = $this->validated($item['data'], [
            ...$this->pettyCash->entryRules(),
            'wallet_id' => ['bail', 'required', 'integer', Rule::exists('petty_cash_wallets', 'id')->where('project_id', $project->id)],
        ]);

        $wallet = PettyCashWallet::findOrFail($data['wallet_id']);
        unset($data['wallet_id']);

        $entry = $this->pettyCash->addEntry($wallet, [...$data, 'client_uuid' => $item['client_uuid']], $user);

        return $this->result($entry->wasRecentlyCreated ? 'created' : 'duplicate', $entry);
    }

    /** Rules 2 to 5 for an approvable document that already exists on the server. */
    private function reconcileDocument(Model $row, array $item, Project $project, ?Carbon $stamp, Closure $apply): array
    {
        $this->assertSameProject($row, $project);

        // A retry of exactly the version already applied carries nothing new, so
        // it is settled before the status check. Otherwise a batch of dpr plus
        // dpr_submit whose response was lost would report a conflict on the retry.
        if ($stamp && $row->client_updated_at?->equalTo($stamp)) {
            return $this->result('duplicate', $row);
        }

        if (method_exists($row, 'trashed') && $row->trashed()) {
            return $this->result('duplicate', $row);
        }

        if (! $row->isEditable()) {
            return $this->conflict($row, $item);
        }

        return $this->reconcile($row, $item, $stamp, $apply);
    }

    /** Rules 3 to 5: last writer wins by client stamp, and every lost write is audited. */
    private function reconcile(Model $row, array $item, ?Carbon $stamp, Closure $apply): array
    {
        $stored = $row->client_updated_at ?? $row->updated_at;

        if ($stamp === null || $stamp->equalTo($stored)) {
            return $this->result('duplicate', $row);
        }

        if ($stamp->lessThan($stored)) {
            $this->audit('sync.stale', $row, $item);

            return $this->result('stale', $row);
        }

        $apply();

        return $this->result('updated', $row->refresh());
    }

    private function submitDocument(Model $row, array $item, Project $project, Closure $submit): array
    {
        $this->assertSameProject($row, $project);

        if (in_array($row->status, ['IN_REVIEW', 'APPROVED'], true)) {
            return $this->result('duplicate', $row);
        }

        if ($row->status === 'REJECTED') {
            return $this->conflict($row, $item);
        }

        $submit();

        return $this->result('updated', $row->refresh());
    }

    private function conflict(Model $row, array $item): array
    {
        $this->audit('sync.conflict', $row, $item);

        return $this->result('conflict', $row, strtolower($row->status));
    }

    private function audit(string $action, Model $row, array $item): void
    {
        AuditLog::record($action, $row, null, [
            'client_uuid' => $item['client_uuid'],
            'client_updated_at' => $item['client_updated_at'] ?? null,
            'data' => $item['data'],
        ]);
    }

    private function result(string $status, Model $row, ?string $reason = null): array
    {
        return ['status' => $status, 'id' => $row->getKey(), 'server_status' => $row->getAttribute('status'), 'reason' => $reason];
    }

    /** Find by server_id, else by client_uuid, locked so the status check and the write are atomic. */
    private function findRow(Builder $query, array $item): ?Model
    {
        if (! empty($item['server_id']) && ($row = (clone $query)->lockForUpdate()->find($item['server_id']))) {
            return $row;
        }

        return $query->where('client_uuid', $item['client_uuid'])->lockForUpdate()->first();
    }

    private function findSubmitTarget(Builder $query, array $item, string $uuidKey, string $idKey): Model
    {
        $data = $this->validated($item['data'], [
            $uuidKey => ['nullable', 'uuid', 'required_without:'.$idKey],
            $idKey => ['nullable', 'integer'],
        ]);

        return $this->findByReference($query, $data[$uuidKey] ?? null, $data[$idKey] ?? null)
            ?? throw ValidationException::withMessages([$idKey => [__('Record not found on the server.')]]);
    }

    private function findByReference(Builder $query, ?string $uuid, ?int $id): ?Model
    {
        return $uuid !== null
            ? $query->where('client_uuid', $uuid)->lockForUpdate()->first()
            : $query->lockForUpdate()->find($id);
    }

    private function assertSameProject(Model $row, Project $project): void
    {
        if ((int) $row->project_id !== $project->id) {
            throw ValidationException::withMessages(['project_id' => [__('This record belongs to another project.')]]);
        }
    }

    private function validated(array $data, array $rules): array
    {
        return Validator::make($data, $rules)->validate();
    }
}
