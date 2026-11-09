<?php

namespace App\Services\Crm;

use App\Models\CrmLead;
use App\Models\CrmTask;
use App\Models\CrmTender;
use App\Models\CrmTenderPayment;
use App\Models\User;
use App\Support\Dec;

/** What the Sales overview page shows: pipeline, deadlines, the user's follow-ups and money held. */
class OverviewService
{
    public function __construct(
        private TenderService $tenders,
        private TaskService $tasks,
        private TenderPaymentService $payments,
    ) {}

    public function summary(int $organizationId, User $user): array
    {
        $board = $this->tenders->board($organizationId);

        $pipeline = collect(CrmTender::OPEN_STAGES)->mapWithKeys(fn ($stage) => [$stage => ['count' => $board[$stage]['count'], 'value' => $board[$stage]['value']]])->all();

        $openValue = Dec::money(collect($pipeline)->reduce(fn ($sum, $row) => $sum->plus(Dec::of($row['value'])), Dec::of(0)));

        $deadlines = CrmTender::query()
            ->with('party')
            ->where('organization_id', $organizationId)
            ->whereIn('stage', ['enquiry', 'bidding'])
            ->whereNotNull('due_at')
            ->where('due_at', '<=', now()->addDays(14))
            ->orderBy('due_at')
            ->limit(8)
            ->get();

        $preBids = CrmTender::query()
            ->where('organization_id', $organizationId)
            ->whereIn('stage', ['enquiry', 'bidding'])
            ->whereNotNull('pre_bid_at')
            ->where('pre_bid_at', '>=', now())
            ->where('pre_bid_at', '<=', now()->addDays(14))
            ->orderBy('pre_bid_at')
            ->limit(5)
            ->get();

        $dueTasks = CrmTask::query()
            ->where('organization_id', $organizationId)
            ->where('assigned_to', $user->id)
            ->where('status', 'open')
            ->whereDate('due_on', '<=', today()->addDays(2))
            ->orderBy('due_on')
            ->limit(8)
            ->get();

        $held = CrmTenderPayment::query()->with('tender')->whereHas('tender')->where('crm_tender_payments.organization_id', $organizationId)->where('status', 'paid')->get();

        return [
            'pipeline' => $pipeline,
            'open_count' => collect($pipeline)->sum('count'),
            'open_value' => $openValue,
            'deadlines' => $deadlines,
            'pre_bids' => $preBids,
            'task_counts' => $this->tasks->counts($organizationId, $user),
            'due_tasks' => $dueTasks,
            'money' => $this->payments->totals($held),
            'refunds_due' => $held->filter(fn (CrmTenderPayment $payment) => $payment->refundIsDue())->count(),
            'expiring' => $held->filter(fn (CrmTenderPayment $payment) => $payment->valid_until !== null && $payment->valid_until->lte(today()->addDays(30)))->sortBy('valid_until')->values(),
            'new_leads' => CrmLead::query()->where('organization_id', $organizationId)->where('status', 'new')->count(),
            'open_leads' => CrmLead::query()->where('organization_id', $organizationId)->whereIn('status', CrmLead::OPEN_STATUSES)->count(),
        ];
    }
}
