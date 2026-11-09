<?php

namespace App\Services\Notify;

use App\Models\Approval;
use App\Models\Issue;
use App\Models\User;
use App\Services\ApprovalService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/** Decides who hears about approval and issue events, and in what words. */
class WorkflowNotifier
{
    private const URGENT_PRIORITIES = ['high', 'critical'];

    public function __construct(private Notifier $notifier) {}

    /** After a submit, or after one level approves: tell whoever can act next. */
    public function approvalWaiting(Approval $approval, Model $document): void
    {
        $this->notifier->whenCommitted(function () use ($approval, $document) {
            foreach ($this->nextApprovers($approval) as $user) {
                $locale = $this->localeOf($user);

                $this->notifier->send($user, new NotificationMessage(
                    event: 'approval.waiting',
                    title: __('Approval needed: :document', ['document' => $document->approvalTitle()], $locale),
                    body: __('Sent by :name', ['name' => (string) User::find($approval->submitted_by)?->name], $locale),
                    url: route('approvals.index'),
                ));
            }
        });
    }

    public function approvalDecided(Approval $approval, Model $document, string $action, ?string $remark): void
    {
        $this->notifier->whenCommitted(function () use ($approval, $document, $action, $remark) {
            $submitter = User::find($approval->submitted_by);

            if (! $submitter) {
                return;
            }

            $replace = ['document' => $document->approvalTitle()];
            $locale = $this->localeOf($submitter);

            [$event, $title] = match ($action) {
                'approve' => ['approval.approved', __('Approved: :document', $replace, $locale)],
                'return' => ['approval.returned', __('Returned: :document', $replace, $locale)],
                'reject' => ['approval.rejected', __('Rejected: :document', $replace, $locale)],
            };

            $this->notifier->send($submitter, new NotificationMessage(
                event: $event,
                title: $title,
                body: (string) $remark,
                url: $approval->documentUrl(),
            ));
        });
    }

    public function issueRaised(Issue $issue): void
    {
        $this->notifier->whenCommitted(function () use ($issue) {
            $told = [$issue->raised_by];

            if ($assignee = $this->assigneeToTell($issue, $issue->raised_by)) {
                $this->tellAssignee($issue, $assignee);
                $told[] = $assignee->id;
            }

            if (! in_array($issue->priority, self::URGENT_PRIORITIES, true)) {
                return;
            }

            $managers = User::where('organization_id', $issue->organization_id)->where('is_active', true)
                ->whereIn('role', ['owner', 'pm'])->whereNotIn('id', array_filter($told))->get();

            foreach ($managers as $user) {
                $locale = $this->localeOf($user);

                $this->notifier->send($user, new NotificationMessage(
                    event: 'issue.raised',
                    title: __('New :priority issue: :title', ['priority' => __(Str::headline($issue->priority), [], $locale), 'title' => $issue->title], $locale),
                    body: (string) $issue->project?->name,
                    url: route('issues.index', $issue->project_id),
                ));
            }
        });
    }

    /** Reads what the last save changed, so call it straight after the update. */
    public function issueChanged(Issue $issue, ?int $actorId): void
    {
        $reassigned = $issue->wasChanged('assigned_to');
        $statusChanged = $issue->wasChanged('status');

        if (! $reassigned && ! $statusChanged) {
            return;
        }

        $this->notifier->whenCommitted(function () use ($issue, $actorId, $reassigned, $statusChanged) {
            $assignee = $reassigned ? $this->assigneeToTell($issue, $actorId) : null;

            if ($assignee) {
                $this->tellAssignee($issue, $assignee);
            }

            $raiser = $statusChanged && $issue->raised_by !== $actorId && $issue->raised_by !== $assignee?->id ? User::find($issue->raised_by) : null;

            if (! $raiser) {
                return;
            }

            $locale = $this->localeOf($raiser);

            $this->notifier->send($raiser, new NotificationMessage(
                event: 'issue.status_changed',
                title: __('Issue :status: :title', ['status' => __(Str::headline($issue->status), [], $locale), 'title' => $issue->title], $locale),
                body: (string) $issue->project?->name,
                url: route('issues.index', $issue->project_id),
            ));
        });
    }

    /** @return Collection<int, User> */
    private function nextApprovers(Approval $approval): Collection
    {
        $role = $approval->currentRole();

        // A client signs through their own link and has no account to notify.
        if ($role === null || $role === 'client') {
            return new Collection;
        }

        $service = app(ApprovalService::class);
        $canAct = User::where('organization_id', $approval->organization_id)->where('is_active', true)
            ->where('id', '!=', $approval->submitted_by)->get()
            ->filter(fn (User $user) => $service->canAct($approval, $user));

        // Owners may act at any level, so they only hear about it when nobody holds the role.
        $holders = $canAct->where('role', $role);

        return $holders->isNotEmpty() ? $holders->values() : $canAct->values();
    }

    private function assigneeToTell(Issue $issue, ?int $actorId): ?User
    {
        if (! $issue->assigned_to || $issue->assigned_to === $actorId) {
            return null;
        }

        return User::find($issue->assigned_to);
    }

    private function tellAssignee(Issue $issue, User $assignee): void
    {
        $locale = $this->localeOf($assignee);

        $this->notifier->send($assignee, new NotificationMessage(
            event: 'issue.assigned',
            title: __('Issue assigned to you: :title', ['title' => $issue->title], $locale),
            body: (string) $issue->project?->name,
            url: route('issues.index', $issue->project_id),
        ));
    }

    private function localeOf(User $user): string
    {
        return $user->locale ?: config('app.locale');
    }
}
