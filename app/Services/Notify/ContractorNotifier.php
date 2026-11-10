<?php

namespace App\Services\Notify;

use App\Models\ContractorPayment;
use App\Models\Organization;
use App\Models\Project;
use App\Models\RaBill;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\ContractorPortal\ContractorPortalService;
use Closure;
use Illuminate\Support\Collection;

/**
 * Tells a subcontractor's own logins about their money: a payment to them approved, a bill of
 * theirs paid. Push only, and only what the contractor portal would show that login anyway, so
 * the amount named is the contractor's own and nobody else's record is ever mentioned.
 */
class ContractorNotifier
{
    public function __construct(private Notifier $notifier, private ContractorPortalService $portal) {}

    /** A payment that now counts as paid. A reversal is the company's correction and is not announced. */
    public function paymentApproved(ContractorPayment $payment): void
    {
        $this->notifier->whenCommitted(function () use ($payment) {
            $payment = ContractorPayment::withoutGlobalScopes()->find($payment->id);

            if ($payment === null || $payment->status !== 'APPROVED' || $payment->isReversal()) {
                return;
            }

            $logins = $this->logins($payment->organization_id, $payment->party_id, $payment->work_order_id);
            $where = $this->where($payment->work_order_id, $payment->project_id);

            $this->tell($logins, fn (string $locale) => new NotificationMessage(
                event: 'contractor.payment_approved',
                title: __('Payment approved: :number', ['number' => $payment->number], $locale),
                body: __(':amount · :contract · :project', [...$where, 'amount' => inr($payment->amount)], $locale),
                url: route('contractor-portal.payments'),
                push: $this->target('portal_payment', $payment->id, $payment->organization_id, $payment->work_order_id),
            ));
        });
    }

    public function billPaid(RaBill $bill): void
    {
        $this->notifier->whenCommitted(function () use ($bill) {
            $bill = RaBill::withoutGlobalScopes()->find($bill->id);

            // The portal shows a contractor its own bills in these statuses and no others.
            if ($bill === null || $bill->type !== 'subcontractor' || $bill->status !== 'PAID' || ! in_array('PAID', config('modules.contractor_portal.visible_bill_statuses', []), true)) {
                return;
            }

            $logins = $this->logins($bill->organization_id, $bill->party_id, $bill->work_order_id);
            $where = $this->where($bill->work_order_id, $bill->project_id);

            $this->tell($logins, fn (string $locale) => new NotificationMessage(
                event: 'contractor.bill_paid',
                title: __('Bill paid: :number', ['number' => $bill->number], $locale),
                body: __(':amount · :contract · :project', [...$where, 'amount' => inr($bill->paid_amount ?? $bill->net_payable)], $locale),
                url: route('contractor-portal.bills.show', $bill->id),
                push: $this->target('portal_bill', $bill->id, $bill->organization_id, $bill->work_order_id),
            ));
        });
    }

    /**
     * @param  Collection<int, User>  $logins
     * @param  Closure(string): NotificationMessage  $write
     */
    private function tell(Collection $logins, Closure $write): void
    {
        foreach ($logins as $login) {
            $this->notifier->pushOnly($login, $write);
        }
    }

    /** @return array{contract: string, project: string} Read without the tenant scope, as this also runs with nobody signed in. */
    private function where(?int $workOrderId, ?int $projectId): array
    {
        return [
            'contract' => (string) WorkOrder::withoutGlobalScopes()->find($workOrderId)?->number,
            'project' => (string) Project::withoutGlobalScopes()->find($projectId)?->name,
        ];
    }

    /**
     * The working logins of the subcontractor a record belongs to. The portal reads a payment or
     * a bill through its contract as well as its party, so the two must agree here too.
     *
     * @return Collection<int, User>
     */
    private function logins(int $organizationId, ?int $partyId, ?int $workOrderId): Collection
    {
        if ($partyId === null || $workOrderId === null) {
            return collect();
        }

        $ownContract = WorkOrder::withoutGlobalScopes()->whereKey($workOrderId)->where('organization_id', $organizationId)->where('party_id', $partyId)->exists();

        if (! $ownContract || ! Organization::find($organizationId)?->allowsFeature('contractor_portal')) {
            return collect();
        }

        return User::where('organization_id', $organizationId)
            ->where('role', User::CONTRACTOR_ROLE)
            ->where('contractor_party_id', $partyId)
            ->where('is_active', true)
            ->get()
            ->filter(fn (User $user) => (int) $this->portal->partyFor($user)?->id === (int) $partyId)
            ->values();
    }

    private function target(string $type, int $id, int $organizationId, ?int $workOrderId): PushTarget
    {
        return new PushTarget(
            category: PushCategory::CONTRACTOR,
            type: $type,
            id: $id,
            organizationId: $organizationId,
            permissions: ['contractor_portal.view'],
            dedupeKey: $type.':'.$id,
            extra: ['work_order_id' => $workOrderId],
            ownMoney: true,
        );
    }
}
