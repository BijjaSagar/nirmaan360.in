<?php

namespace App\Services\Crm;

use App\Models\CrmTask;
use App\Models\CrmTender;
use App\Models\Organization;
use App\Models\User;
use App\Notifications\CrmReminder;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Sends the in-app reminders that are due: follow-up tasks at their reminder time and tenders whose
 * submission deadline is within two days. Runs from the console with no signed-in user, so every
 * query filters by organisation itself and each reminder is sent once.
 */
class ReminderService
{
    /** How far ahead a tender deadline starts to warn. */
    public const DEADLINE_WARNING_HOURS = 48;

    /** @var array<int,Organization|null> */
    private array $organizations = [];

    /** @return array{tasks:int,tenders:int} How many reminders went out. */
    public function run(?CarbonInterface $now = null): array
    {
        $now ??= now();

        return ['tasks' => $this->remindTasks($now), 'tenders' => $this->warnTenders($now)];
    }

    private function remindTasks(CarbonInterface $now): int
    {
        $sent = 0;

        CrmTask::query()
            ->where('status', 'open')
            ->whereNotNull('remind_at')
            ->whereNull('reminded_at')
            ->where('remind_at', '<=', $now)
            ->eachById(function (CrmTask $task) use ($now, &$sent) {
                // A company that no longer has the CRM gets no reminders, and is not retried every run.
                if ($this->crmOn($task->organization_id)) {
                    $subject = Subjects::find($task->subject_type, $task->subject_id, $task->organization_id);
                    $body = trim(($subject ? Subjects::title($subject).' · ' : '').__('Due :date', ['date' => $task->due_on->format('d M').($task->due_time ? ' '.$task->due_time : '')]));

                    foreach ($this->recipients($task->organization_id, $task->assigned_to ?? $task->created_by) as $user) {
                        $user->notify(new CrmReminder(__('Follow-up: :title', ['title' => $task->title]), $body, route('crm.tasks.index')));
                        $sent++;
                    }
                }

                $task->forceFill(['reminded_at' => $now])->saveQuietly();
            });

        return $sent;
    }

    private function warnTenders(CarbonInterface $now): int
    {
        $sent = 0;

        CrmTender::query()
            ->whereIn('stage', ['enquiry', 'bidding'])
            ->whereNotNull('due_at')
            ->whereNull('due_alerted_at')
            ->where('due_at', '>', $now)
            ->where('due_at', '<=', $now->copy()->addHours(self::DEADLINE_WARNING_HOURS))
            ->eachById(function (CrmTender $tender) use ($now, &$sent) {
                if ($this->crmOn($tender->organization_id)) {
                    foreach ($this->recipients($tender->organization_id, $tender->owner_id ?? $tender->created_by) as $user) {
                        $user->notify(new CrmReminder(
                            __('Tender due soon: :title', ['title' => $tender->title]),
                            __('Submission closes :when', ['when' => $tender->due_at->format('d M, h:i A')]),
                            route('crm.tenders.show', $tender->id),
                        ));
                        $sent++;
                    }
                }

                $tender->forceFill(['due_alerted_at' => $now])->saveQuietly();
            });

        return $sent;
    }

    /**
     * The person the reminder is for, or the company's owners if that person is gone or switched off.
     *
     * @return Collection<int,User>
     */
    private function recipients(int $organizationId, ?int $userId): Collection
    {
        $user = $userId ? User::query()->where('organization_id', $organizationId)->where('is_active', true)->find($userId) : null;

        return $user
            ? collect([$user])
            : User::query()->where('organization_id', $organizationId)->where('role', 'owner')->where('is_active', true)->get();
    }

    private function crmOn(int $organizationId): bool
    {
        return ($this->organizations[$organizationId] ??= Organization::find($organizationId))?->allowsFeature('crm') ?? false;
    }
}
