<?php

namespace App\Services\Notify;

use App\Models\Approval;
use App\Models\ContractorPayment;
use App\Models\Issue;
use App\Models\Party;
use App\Models\Project;
use App\Models\User;
use App\Services\ApprovalService;
use App\Support\Money;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Decides who hears about approval and issue events, and in what words. Each update is written
 * by a closure that is handed a language, so the bell and the email use the person's own and each
 * phone is pushed in the language its app is set to. A contractor payment travels the same
 * approval chain, with its own words and its own switch in the profile.
 */
class WorkflowNotifier
{
    private const URGENT_PRIORITIES = ['high', 'critical'];

    public function __construct(private Notifier $notifier, private ContractorNotifier $contractors) {}

    /** After a submit, or after one level approves: tell whoever can act next. */
    public function approvalWaiting(Approval $approval, Model $document): void
    {
        $this->notifier->whenCommitted(function () use ($approval, $document) {
            $sender = (string) User::find($approval->submitted_by)?->name;
            $level = (int) $approval->current_level;

            foreach ($this->nextApprovers($approval, $document) as $user) {
                // Either way the amount is written only for a recipient who may see money.
                $this->notifier->send($user, fn (string $locale) => new NotificationMessage(
                    event: 'approval.waiting',
                    title: $document instanceof ContractorPayment
                        ? __('Payment to approve: :number', ['number' => $document->number], $locale)
                        : __('Approval needed: :document', ['document' => $document->approvalTitleFor($user)], $locale),
                    body: $document instanceof ContractorPayment
                        ? $this->paymentDetails($document, $user, $locale)
                        : __('Sent by :name', ['name' => $sender], $locale),
                    url: route('approvals.index'),
                    push: $this->target($approval, $document, 'waiting', [$approval->doc_type.'.approve'], $level),
                ));
            }
        });
    }

    public function approvalDecided(Approval $approval, Model $document, string $action, ?string $remark): void
    {
        $this->notifier->whenCommitted(function () use ($approval, $document, $action, $remark) {
            if ($action === 'approve' && $document instanceof ContractorPayment) {
                $this->contractors->paymentApproved($document);
            }

            $submitter = User::find($approval->submitted_by);

            if (! $submitter) {
                return;
            }

            [$event, $status] = match ($action) {
                'approve' => ['approval.approved', 'approved'],
                'return' => ['approval.returned', 'returned'],
                'reject' => ['approval.rejected', 'rejected'],
            };

            // A return or a rejection says why. A payment approved without a remark says what was approved.
            $describePayment = $document instanceof ContractorPayment && blank($remark);

            $this->notifier->send($submitter, fn (string $locale) => new NotificationMessage(
                event: $event,
                title: $this->decisionTitle($document, $submitter, $action, $locale),
                body: $describePayment ? $this->paymentDetails($document, $submitter, $locale) : (string) $remark,
                url: $approval->documentUrl(),
                push: $this->target($approval, $document, $status),
            ));
        });
    }

    /** A document that needed no approval is approved the moment it is sent. Only a contractor has anything to hear. */
    public function approvedWithoutApproval(Model $document): void
    {
        if ($document instanceof ContractorPayment) {
            $this->contractors->paymentApproved($document);
        }
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

            // A manager limited to other projects cannot open this one, so is not told about it.
            $managers = User::where('organization_id', $issue->organization_id)->where('is_active', true)
                ->whereIn('role', ['owner', 'pm'])->whereNotIn('id', array_filter($told))->get()
                ->filter(fn (User $user) => Audience::seesProject($user, $issue->project_id));

            foreach ($managers as $user) {
                $this->notifier->send($user, fn (string $locale) => new NotificationMessage(
                    event: 'issue.raised',
                    title: __('New :priority issue: :title', ['priority' => __(Str::headline($issue->priority), [], $locale), 'title' => $issue->title], $locale),
                    body: (string) $issue->project?->name,
                    url: route('issues.index', $issue->project_id),
                    push: $this->issueTarget($issue),
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

            $this->notifier->send($raiser, fn (string $locale) => new NotificationMessage(
                event: 'issue.status_changed',
                title: __('Issue :status: :title', ['status' => __(Str::headline($issue->status), [], $locale), 'title' => $issue->title], $locale),
                body: (string) $issue->project?->name,
                url: route('issues.index', $issue->project_id),
                push: $this->issueTarget($issue),
            ));
        });
    }

    /**
     * Whoever can act on the approval now and can see the project it is on. Someone limited to
     * other projects could not open the document, so they are left out and the owners hear instead.
     *
     * @return Collection<int, User>
     */
    private function nextApprovers(Approval $approval, Model $document): Collection
    {
        $role = $approval->currentRole();

        // A client signs through their own link and has no account to notify.
        if ($role === null || $role === 'client') {
            return new Collection;
        }

        $service = app(ApprovalService::class);
        $projectId = $this->projectOf($document);

        $canAct = User::where('organization_id', $approval->organization_id)->where('is_active', true)
            ->where('id', '!=', $approval->submitted_by)->get()
            ->filter(fn (User $user) => $service->canAct($approval, $user) && Audience::seesProject($user, $projectId));

        // Owners may act at any level, so they only hear about it when nobody holds the role.
        $holders = $canAct->where('role', $role);

        return $holders->isNotEmpty() ? $holders->values() : $canAct->values();
    }

    private function decisionTitle(Model $document, User $reader, string $action, string $locale): string
    {
        if ($document instanceof ContractorPayment) {
            return match ($action) {
                'approve' => __('Payment approved: :number', ['number' => $document->number], $locale),
                'return' => __('Payment sent back: :number', ['number' => $document->number], $locale),
                'reject' => __('Payment rejected: :number', ['number' => $document->number], $locale),
            };
        }

        $replace = ['document' => $document->approvalTitleFor($reader)];

        return match ($action) {
            'approve' => __('Approved: :document', $replace, $locale),
            'return' => __('Returned: :document', $replace, $locale),
            'reject' => __('Rejected: :document', $replace, $locale),
        };
    }

    /** Who is paid and where. The amount is added only for a reader who may see money, so site staff never get it. */
    private function paymentDetails(ContractorPayment $payment, User $reader, string $locale): string
    {
        // Read without the tenant scope: this also runs from the queue, where nobody is signed in.
        $replace = [
            'party' => (string) Party::withoutGlobalScopes()->find($payment->party_id)?->name,
            'project' => (string) Project::withoutGlobalScopes()->find($payment->project_id)?->name,
        ];

        return Money::visible($reader)
            ? __(':amount to :party · :project', [...$replace, 'amount' => inr($payment->amount)], $locale)
            : __(':party · :project', $replace, $locale);
    }

    /**
     * What the push opens and which switch it obeys. A contractor payment opens the payment and
     * falls under "Contractor payments"; anything else opens the approval. While it waits on
     * someone the level is carried too, so a push held overnight is dropped if the approval has
     * moved on by morning.
     *
     * @param  list<string>  $permissions
     */
    private function target(Approval $approval, Model $document, string $status, array $permissions = [], ?int $level = null): PushTarget
    {
        $payment = $document instanceof ContractorPayment;

        return new PushTarget(
            category: $payment ? PushCategory::PAYMENTS : PushCategory::APPROVALS,
            type: $payment ? 'contractor_payment' : 'approval',
            id: $payment ? $document->getKey() : $approval->id,
            organizationId: (int) $approval->organization_id,
            projectId: $this->projectOf($document),
            permissions: $permissions,
            dedupeKey: 'approval:'.$approval->id.':'.$status.($level === null ? '' : ':'.$level),
            extra: [
                'approval_id' => $approval->id,
                'level' => $level,
                'status' => $status,
                'doc_type' => $approval->doc_type,
                'doc_id' => $document->getKey(),
                'doc_uuid' => $document->getAttribute('client_uuid'),
            ],
        );
    }

    private function issueTarget(Issue $issue): PushTarget
    {
        return new PushTarget(
            category: PushCategory::ISSUES,
            type: 'issue',
            id: $issue->id,
            organizationId: (int) $issue->organization_id,
            projectId: (int) $issue->project_id,
            permissions: ['issue.view'],
            extra: ['uuid' => $issue->client_uuid, 'status' => $issue->status],
        );
    }

    private function projectOf(Model $document): ?int
    {
        $projectId = $document->getAttribute('project_id');

        return $projectId === null ? null : (int) $projectId;
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
        $this->notifier->send($assignee, fn (string $locale) => new NotificationMessage(
            event: 'issue.assigned',
            title: __('Issue assigned to you: :title', ['title' => $issue->title], $locale),
            body: (string) $issue->project?->name,
            url: route('issues.index', $issue->project_id),
            push: $this->issueTarget($issue),
        ));
    }
}
