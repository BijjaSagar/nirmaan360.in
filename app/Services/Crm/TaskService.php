<?php

namespace App\Services\Crm;

use App\Models\CrmTask;
use App\Models\User;
use App\Support\Idempotent;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Follow-up tasks. Each can point at a lead, tender or company and can carry a reminder time. */
class TaskService
{
    /** The hour a reminder goes out when a task has a due date but no time. */
    public const DEFAULT_REMIND_TIME = '09:00';

    public function __construct(private ActivityService $activities) {}

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:200'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'subject_type' => ['nullable', Rule::in(Subjects::types()), 'required_with:subject_id'],
            'subject_id' => ['nullable', 'integer', 'required_with:subject_type'],
            'due_on' => ['required', 'date'],
            'due_time' => ['nullable', 'date_format:H:i'],
            'remind_at' => ['nullable', 'date'],
            'assigned_to' => ['nullable', org_exists('users')],
            'client_uuid' => ['nullable', 'uuid'],
        ];
    }

    /** @return Builder<CrmTask> */
    public function query(int $organizationId, User $user, array $filters = []): Builder
    {
        $query = CrmTask::query()->with('assignee')->where('organization_id', $organizationId);

        if (($filters['scope'] ?? 'mine') === 'mine') {
            $query->where('assigned_to', $user->id);
        } elseif (! empty($filters['assignee'])) {
            $query->where('assigned_to', (int) $filters['assignee']);
        }

        $query->where('status', ($filters['status'] ?? 'open') === 'done' ? 'done' : 'open');

        match ($filters['when'] ?? null) {
            'overdue' => $query->whereDate('due_on', '<', today()),
            'today' => $query->whereDate('due_on', today()),
            'week' => $query->whereDate('due_on', '>=', today())->whereDate('due_on', '<=', today()->addDays(7)),
            default => null,
        };

        return ($filters['status'] ?? 'open') === 'done'
            ? $query->orderByDesc('completed_at')
            : $query->orderBy('due_on')->orderByRaw('due_time IS NULL')->orderBy('due_time')->orderBy('id');
    }

    /**
     * The tasks on one lead, tender or company: open ones first, soonest due first.
     *
     * @return Collection<int,CrmTask>
     */
    public function forSubject(Model $subject): Collection
    {
        return CrmTask::query()
            ->with('assignee')
            ->where('organization_id', $subject->getAttribute('organization_id'))
            ->where('subject_type', Subjects::typeOf($subject))
            ->where('subject_id', $subject->getKey())
            ->orderByRaw("status = 'done'")
            ->orderBy('due_on')
            ->limit(30)
            ->get();
    }

    /** Create a task. A repeated client_uuid returns the original row. */
    public function create(array $data, User $user): CrmTask
    {
        $organizationId = $user->organization_id;
        $uuid = $data['client_uuid'] ?? null;

        if ($uuid && ($existing = $this->findByClientUuid($organizationId, $uuid))) {
            return $existing;
        }

        $this->assertSubject($data, $organizationId);

        return Idempotent::create(
            fn () => CrmTask::create([
                ...$this->attributes($data),
                'organization_id' => $organizationId,
                'assigned_to' => $data['assigned_to'] ?? $user->id,
                'status' => 'open',
                'created_by' => $user->id,
                'client_uuid' => $uuid,
            ]),
            fn () => $this->findByClientUuid($organizationId, (string) $uuid),
        );
    }

    public function update(CrmTask $task, array $data): CrmTask
    {
        $this->assertSubject($data, $task->organization_id);

        $task->fill([...$this->attributes($data), 'assigned_to' => $data['assigned_to'] ?? $task->assigned_to]);

        // A new date or reminder time should fire again.
        if ($task->isDirty(['due_on', 'due_time', 'remind_at'])) {
            $task->reminded_at = null;
        }

        $task->save();

        return $task;
    }

    public function complete(CrmTask $task, User $user): CrmTask
    {
        if (! $task->isOpen()) {
            return $task;
        }

        $task->update(['status' => 'done', 'completed_at' => now(), 'completed_by' => $user->id]);

        if ($subject = Subjects::find($task->subject_type, $task->subject_id, $task->organization_id)) {
            $this->activities->log($subject, 'system', __('Follow-up done: :title', ['title' => $task->title]), $user);
        }

        return $task;
    }

    public function reopen(CrmTask $task): CrmTask
    {
        if ($task->isOpen()) {
            return $task;
        }

        $task->update(['status' => 'open', 'completed_at' => null, 'completed_by' => null]);

        return $task;
    }

    public function delete(CrmTask $task): void
    {
        $task->delete();
    }

    /** Open tasks assigned to the user, by urgency, for the overview. */
    public function counts(int $organizationId, User $user): array
    {
        $open = CrmTask::query()->where('organization_id', $organizationId)->where('assigned_to', $user->id)->where('status', 'open');

        return [
            'overdue' => (clone $open)->whereDate('due_on', '<', today())->count(),
            'today' => (clone $open)->whereDate('due_on', today())->count(),
            'open' => (clone $open)->count(),
        ];
    }

    private function findByClientUuid(int $organizationId, string $uuid): ?CrmTask
    {
        return CrmTask::withTrashed()->where('organization_id', $organizationId)->where('client_uuid', $uuid)->first();
    }

    private function assertSubject(array $data, int $organizationId): void
    {
        if (filled($data['subject_type'] ?? null) && ! Subjects::find($data['subject_type'], $data['subject_id'] ?? null, $organizationId)) {
            throw ValidationException::withMessages(['subject_id' => __('That record was not found.')]);
        }
    }

    private function attributes(array $data): array
    {
        $dueTime = filled($data['due_time'] ?? null) ? $data['due_time'] : null;

        return [
            'title' => $data['title'],
            'notes' => filled($data['notes'] ?? null) ? $data['notes'] : null,
            'subject_type' => filled($data['subject_type'] ?? null) ? $data['subject_type'] : null,
            'subject_id' => filled($data['subject_type'] ?? null) ? $data['subject_id'] : null,
            'due_on' => $data['due_on'],
            'due_time' => $dueTime,
            'remind_at' => filled($data['remind_at'] ?? null) ? $data['remind_at'] : $this->defaultRemindAt($data['due_on'], $dueTime),
        ];
    }

    /** Nine in the morning on the due date, unless that time has already gone by. */
    private function defaultRemindAt(string $dueOn, ?string $dueTime): ?CarbonImmutable
    {
        $at = CarbonImmutable::parse(substr($dueOn, 0, 10).' '.($dueTime ?: self::DEFAULT_REMIND_TIME));

        return $at->isFuture() ? $at : null;
    }
}
