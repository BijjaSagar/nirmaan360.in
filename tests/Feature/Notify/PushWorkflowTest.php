<?php

namespace Tests\Feature\Notify;

use App\Jobs\Notify\DeliverNotification;
use App\Models\ApprovalRule;
use App\Models\ContractorPayment;
use App\Models\Party;
use App\Models\RaBill;
use App\Models\User;
use App\Services\ApprovalService;
use App\Services\ContractorControl\ContractorPaymentService;
use App\Services\MeasurementService;
use App\Services\Notify\ContractorNotifier;
use App\Services\RaBillService;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\ContractorPortal\BuildsContractorPortal;

/**
 * Approvals, contractor payments and the contractor's own login as pushes. The company has two
 * subcontractors on one project. Every figure that must stay private carries eight digits found
 * nowhere else (BuildsContractorPortal), so a push that leaks one is caught by its digits.
 */
class PushWorkflowTest extends NotifyTestCase
{
    use BuildsContractorPortal;

    private User $qs;

    private User $store;

    private User $viewer;

    private User $rivalLogin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildPortalWorld();
        $this->qs = $this->member($this->organization, 'qs');
        $this->store = $this->member($this->organization, 'store');
        $this->viewer = $this->member($this->organization, 'viewer');
        $this->rivalLogin = $this->contractorLogin($this->rival, 'Rival login');

        Queue::fake();
        $this->connectFcm();
        $this->at('2026-11-10 11:00');

        foreach ([$this->owner, $this->manager, $this->engineer, $this->qs, $this->store, $this->viewer, $this->contractor, $this->rivalLogin] as $user) {
            $this->phone($user, 'phone-'.$user->id);
        }
    }

    /** No push to this person names a rupee figure or carries the digits of one. */
    private function assertNoMoneyPushed(User $user, string ...$figures): void
    {
        foreach ($this->pushesFor($user) as $job) {
            $words = $this->wordsOf($job).' | '.json_encode($this->dataOf($job));

            $this->assertDoesNotMatchRegularExpression('/(₹|\bRs\.?|\bINR)\s*-?\d/iu', $words, $user->role.' was pushed a rupee figure');

            foreach ($figures as $figure) {
                $this->assertStringNotContainsString(substr($figure, 0, 5), (string) preg_replace('/[,.]/', '', $words), $user->role.' was pushed the figure '.$figure);
            }
        }
    }

    public function test_an_approval_waiting_is_pushed_to_whoever_can_act_next_and_opens_the_approval(): void
    {
        $this->actingAs($this->engineer);
        $entry = $this->draftEntry($this->project, $this->item, $this->engineer);

        $approval = app(MeasurementService::class)->submit($entry, $this->engineer);

        $push = $this->pushesFor($this->qs)->sole();
        $this->assertStringStartsWith('Approval needed:', $push->message->title);
        $this->assertSame('Sent by '.$this->engineer->name, $push->message->body);
        $this->assertSame([
            'approval_id' => (string) $approval->id,
            'level' => '1',
            'status' => 'waiting',
            'doc_type' => 'measurement',
            'doc_id' => (string) $entry->id,
            'type' => 'approval',
            'id' => (string) $approval->id,
            'category' => 'approvals',
            'project_id' => (string) $this->project->id,
            'event' => 'approval.waiting',
            'critical' => '0',
            'url' => route('approvals.index'),
        ], $this->dataOf($push));

        // It is not the manager's turn yet, the sender is not told about their own document, and nobody else is concerned.
        foreach ([$this->manager, $this->owner, $this->engineer, $this->store, $this->viewer, $this->contractor] as $user) {
            $this->assertCount(0, $this->pushesFor($user));
        }

        app(ApprovalService::class)->act($approval, $this->qs, 'approve');
        $this->assertSame('2', $this->dataOf($this->pushesFor($this->manager)->sole())['level']);

        app(ApprovalService::class)->act($approval->refresh(), $this->manager, 'approve');
        $decided = $this->pushesFor($this->engineer)->sole();
        $this->assertStringStartsWith('Approved:', $decided->message->title);
        $this->assertSame(['approved', 'approval', 'approvals'], [$this->dataOf($decided)['status'], $this->dataOf($decided)['type'], $this->dataOf($decided)['category']]);
        $this->assertStringContainsString('/measurements/'.$entry->id, $this->dataOf($decided)['url']);
    }

    public function test_an_approver_limited_to_another_project_is_not_pushed_and_the_owner_hears_instead(): void
    {
        $this->limitTo($this->qs, $this->otherProject);
        $this->actingAs($this->engineer);

        app(MeasurementService::class)->submit($this->draftEntry($this->project, $this->item, $this->engineer), $this->engineer);

        $this->assertCount(0, $this->pushesFor($this->qs));
        $this->assertSame(0, $this->qs->notifications()->count());
        $this->assertCount(1, $this->pushesFor($this->owner));
    }

    public function test_an_approval_in_one_company_never_reaches_another(): void
    {
        $other = $this->tenant('Other Infra');
        $theirQs = $this->member($other, 'qs');
        $theirOwner = $this->member($other, 'owner');
        $this->phone($theirQs, 'their-qs-phone');
        $this->phone($theirOwner, 'their-owner-phone');
        $this->actingAs($this->engineer);

        app(MeasurementService::class)->submit($this->draftEntry($this->project, $this->item, $this->engineer), $this->engineer);

        $this->assertCount(1, $this->pushesFor($this->qs));
        $this->assertCount(0, $this->pushesFor($theirQs));
        $this->assertCount(0, $this->pushesFor($theirOwner));
    }

    public function test_a_bill_amount_is_pushed_to_an_approver_who_sees_money_and_never_to_site_staff(): void
    {
        // The draft bill is for ₹7,31,05,007.00. Whoever sent it for approval, a site engineer is not told the amount.
        $this->actingAs($this->engineer);
        $approval = app(ApprovalService::class)->submit($this->draftBill, $this->engineer);

        $forEstimator = $this->pushesFor($this->qs)->sole();
        $this->assertStringContainsString(inr(self::DRAFT_BILL), $forEstimator->message->title);

        while ($approval->refresh()->status === 'IN_REVIEW') {
            app(ApprovalService::class)->act($approval, $this->owner, 'approve');
        }

        $forEngineer = $this->pushesFor($this->engineer)->sole();
        $this->assertSame('Approved: RA Bill '.$this->draftBill->number, $forEngineer->message->title);
        $this->assertNoMoneyPushed($this->engineer, self::DRAFT_BILL);
    }

    public function test_a_contractor_payment_raised_is_pushed_to_its_approver_with_the_amount_they_may_see(): void
    {
        $payment = app(ContractorPaymentService::class)->raise($this->workOrder, ['paid_on' => '2026-11-05', 'amount' => '73105099.00', 'mode' => 'bank'], null, $this->engineer);

        $push = $this->pushesFor($this->manager)->sole();
        $this->assertSame('Payment to approve: '.$payment->number, $push->message->title);
        $this->assertSame(inr('73105099.00').' to Shree Formwork · Riverside Tower', $push->message->body);
        $this->assertSame([
            'approval_id' => (string) $payment->currentApproval->id,
            'level' => '1',
            'status' => 'waiting',
            'doc_type' => 'contractor',
            'doc_id' => (string) $payment->id,
            'type' => 'contractor_payment',
            'id' => (string) $payment->id,
            'category' => 'payments',
            'project_id' => (string) $this->project->id,
            'event' => 'approval.waiting',
            'critical' => '0',
            'url' => route('approvals.index'),
        ], $this->dataOf($push));

        // Nobody else: not the person who raised it, not the other site roles, and never the contractor while it is still the company's business.
        foreach ([$this->owner, $this->engineer, $this->qs, $this->store, $this->viewer, $this->contractor, $this->rivalLogin] as $user) {
            $this->assertCount(0, $this->pushesFor($user));
        }
    }

    public function test_an_approved_payment_is_pushed_to_whoever_raised_it_without_the_amount_for_a_site_role(): void
    {
        app(ApprovalService::class)->act($this->pendingPayment->currentApproval, $this->manager, 'approve');

        $push = $this->pushesFor($this->engineer)->sole();
        $this->assertSame('Payment approved: '.$this->pendingPayment->number, $push->message->title);
        $this->assertSame('Shree Formwork · Riverside Tower', $push->message->body);
        $this->assertSame(['contractor_payment', (string) $this->pendingPayment->id, 'payments', 'approved'], [$this->dataOf($push)['type'], $this->dataOf($push)['id'], $this->dataOf($push)['category'], $this->dataOf($push)['status']]);
        $this->assertNoMoneyPushed($this->engineer, self::PENDING_PAYMENT);

        // The same event raised by someone who may see money names the amount.
        $byManager = app(ContractorPaymentService::class)->raise($this->workOrder, ['paid_on' => '2026-11-05', 'amount' => '73105098.00', 'mode' => 'bank'], null, $this->manager);
        app(ApprovalService::class)->act($byManager->currentApproval, $this->owner, 'approve');

        $this->assertSame(inr('73105098.00').' to Shree Formwork · Riverside Tower', $this->pushesFor($this->manager)->last()->message->body);
    }

    public function test_a_rejected_or_returned_payment_is_pushed_to_whoever_raised_it_with_the_reason(): void
    {
        app(ApprovalService::class)->act($this->pendingPayment->currentApproval, $this->manager, 'reject', 'Work on the slab is not finished');
        $returned = app(ContractorPaymentService::class)->raise($this->workOrder, ['paid_on' => '2026-11-05', 'amount' => '73105097.00', 'mode' => 'bank'], null, $this->engineer);
        app(ApprovalService::class)->act($returned->currentApproval, $this->manager, 'return', 'Attach the bill');

        [$rejection, $sentBack] = $this->pushesFor($this->engineer)->all();
        $this->assertSame('Payment rejected: '.$this->pendingPayment->number, $rejection->message->title);
        $this->assertSame('Work on the slab is not finished', $rejection->message->body);
        $this->assertSame('rejected', $this->dataOf($rejection)['status']);
        $this->assertSame('Payment sent back: '.$returned->number, $sentBack->message->title);
        $this->assertSame('Attach the bill', $sentBack->message->body);
        $this->assertNoMoneyPushed($this->engineer, self::PENDING_PAYMENT, '73105097.00');

        // A rejection is the company's own business: the contractor hears nothing.
        $this->assertCount(0, $this->pushesFor($this->contractor));
    }

    public function test_payment_pushes_are_written_in_the_readers_language(): void
    {
        $this->manager->update(['locale' => 'hi']);
        $this->engineer->update(['locale' => 'mr']);
        $payment = app(ContractorPaymentService::class)->raise($this->workOrder, ['paid_on' => '2026-11-05', 'amount' => '500.00', 'mode' => 'bank'], null, $this->engineer);

        $this->assertSame('मंज़ूरी के लिए भुगतान: '.$payment->number, $this->pushesFor($this->manager)->sole()->message->title);
        $this->assertSame('Shree Formwork को '.inr('500.00').' · Riverside Tower', $this->pushesFor($this->manager)->sole()->message->body);

        app(ApprovalService::class)->act($payment->currentApproval, $this->manager, 'approve');

        $this->assertSame('देयक मंजूर: '.$payment->number, $this->pushesFor($this->engineer)->sole()->message->title);
    }

    public function test_switching_contractor_payments_off_leaves_other_approvals_on(): void
    {
        $this->prefer($this->manager, ['push_categories' => ['payments' => false]]);
        $this->actingAs($this->engineer);

        app(ContractorPaymentService::class)->raise($this->workOrder, ['paid_on' => '2026-11-05', 'amount' => '500.00', 'mode' => 'bank'], null, $this->engineer);
        $this->assertCount(0, $this->pushesFor($this->manager));

        $approval = app(MeasurementService::class)->submit($this->draftEntry($this->project, $this->item, $this->engineer), $this->engineer);
        app(ApprovalService::class)->act($approval, $this->qs, 'approve');
        $this->assertSame('approvals', $this->dataOf($this->pushesFor($this->manager)->sole())['category']);
    }

    public function test_a_contractor_login_is_pushed_when_its_payment_is_approved(): void
    {
        app(ApprovalService::class)->act($this->pendingPayment->currentApproval, $this->manager, 'approve');

        $push = $this->pushesFor($this->contractor)->sole();
        $this->assertSame('Payment approved: '.$this->pendingPayment->number, $push->message->title);
        // The amount is the contractor's own, exactly as the portal shows it to them.
        $this->assertSame(inr(self::PENDING_PAYMENT).' · WO-OURS · Riverside Tower', $push->message->body);
        $this->assertSame([
            'work_order_id' => (string) $this->workOrder->id,
            'type' => 'portal_payment',
            'id' => (string) $this->pendingPayment->id,
            'category' => 'contractor',
            'event' => 'contractor.payment_approved',
            'critical' => '0',
            'url' => route('contractor-portal.payments'),
        ], $this->dataOf($push));

        // The other contractor on the same project and the same BOQ line hears nothing, and the bell and email stay out of it.
        $this->assertCount(0, $this->pushesFor($this->rivalLogin));
        $this->assertSame(0, $this->contractor->notifications()->count());
        $this->assertCount(0, Queue::pushed(DeliverNotification::class, fn (DeliverNotification $job) => $job->userId === $this->contractor->id && $job->channel !== 'push'));
    }

    public function test_a_contractor_login_is_pushed_when_a_payment_needs_no_approval_at_all(): void
    {
        ApprovalRule::updateOrCreate(['organization_id' => $this->organization->id, 'doc_type' => 'contractor'], ['levels' => [], 'active' => true]);

        $payment = app(ContractorPaymentService::class)->raise($this->workOrder, ['paid_on' => '2026-11-05', 'amount' => '900.00', 'mode' => 'upi'], null, $this->manager);

        $this->assertSame('APPROVED', $payment->status);
        $this->assertSame('Payment approved: '.$payment->number, $this->pushesFor($this->contractor)->sole()->message->title);
    }

    public function test_a_contractor_login_is_pushed_when_its_bill_is_paid(): void
    {
        app(RaBillService::class)->markPaid($this->bill);

        $push = $this->pushesFor($this->contractor)->sole();
        $this->assertSame('Bill paid: '.$this->bill->number, $push->message->title);
        $this->assertSame(inr($this->bill->refresh()->paid_amount).' · WO-OURS · Riverside Tower', $push->message->body);
        $this->assertSame(['portal_bill', (string) $this->bill->id, 'contractor', 'contractor.bill_paid'], [$this->dataOf($push)['type'], $this->dataOf($push)['id'], $this->dataOf($push)['category'], $this->dataOf($push)['event']]);
        $this->assertStringEndsWith('/contractor-portal/bills/'.$this->bill->id, $this->dataOf($push)['url']);

        // Paying the other contractor's bill tells the other contractor, and only them.
        app(RaBillService::class)->markPaid($this->rivalBill);
        $this->assertCount(1, $this->pushesFor($this->contractor));
        $this->assertSame('Bill paid: '.$this->rivalBill->number, $this->pushesFor($this->rivalLogin)->sole()->message->title);

        // Staff are not pushed about a bill being paid.
        foreach ([$this->owner, $this->manager, $this->engineer] as $staff) {
            $this->assertCount(0, $this->pushesFor($staff));
        }
    }

    public function test_a_contractor_never_hears_about_anyone_elses_money(): void
    {
        // The rival's payment, a client bill on the same project, and a payment on another company's contract.
        app(ApprovalService::class)->act(
            app(ContractorPaymentService::class)->raise($this->rivalWorkOrder, ['paid_on' => '2026-11-05', 'amount' => self::RIVAL_PAYMENT, 'mode' => 'bank'], null, $this->engineer)->currentApproval,
            $this->manager,
            'approve',
        );
        $clientBill = RaBill::create([
            'organization_id' => $this->organization->id, 'project_id' => $this->project->id, 'type' => 'client', 'bill_no' => 9, 'number' => 'CL/RA/9',
            'period_from' => '2026-10-01', 'period_to' => '2026-10-31', 'status' => 'APPROVED', 'gross_amount' => self::BUDGET, 'deduction_total' => '0.00', 'gst_amount' => '0.00', 'net_payable' => self::BUDGET,
            'terms' => ['gst_pct' => '0'],
        ]);
        app(RaBillService::class)->markPaid($clientBill);

        $this->assertCount(0, $this->pushesFor($this->contractor));
        $this->assertSame(inr(self::RIVAL_PAYMENT).' · WO-RIVAL · Riverside Tower', $this->pushesFor($this->rivalLogin)->sole()->message->body);
    }

    public function test_a_record_the_portal_would_not_show_either_contractor_is_pushed_to_neither(): void
    {
        // Filed under our contractor's name but on the other contractor's contract. The portal reads a payment
        // through its contract as well as its party, so neither of them is shown it, and neither is pushed.
        $stray = ContractorPayment::create([
            'organization_id' => $this->organization->id, 'project_id' => $this->project->id, 'work_order_id' => $this->rivalWorkOrder->id, 'party_id' => $this->party->id,
            'number' => 'CP-STRAY', 'paid_on' => '2026-11-05', 'amount' => self::RIVAL_PAYMENT, 'mode' => 'bank', 'paid_to' => 'Unknown', 'status' => 'APPROVED',
        ]);

        app(ContractorNotifier::class)->paymentApproved($stray);

        $this->assertCount(0, $this->pushesFor($this->contractor));
        $this->assertCount(0, $this->pushesFor($this->rivalLogin));
    }

    public function test_a_contractor_login_that_cannot_use_the_portal_is_not_pushed(): void
    {
        $revoked = $this->contractorLogin($this->party, 'Revoked login');
        $revoked->update(['is_active' => false]);
        $unlinked = $this->contractorLogin($this->party, 'Unlinked login');
        $unlinked->forceFill(['contractor_party_id' => null])->save();
        $staffNamedContractor = $this->member($this->organization, 'engineer');
        $staffNamedContractor->forceFill(['contractor_party_id' => $this->party->id])->save();
        collect([$revoked, $unlinked, $staffNamedContractor])->each(fn (User $user) => $this->phone($user, 'extra-'.$user->id));

        app(RaBillService::class)->markPaid($this->bill);

        $this->assertCount(1, $this->pushesFor($this->contractor));
        $this->assertCount(0, $this->pushesFor($revoked));
        $this->assertCount(0, $this->pushesFor($unlinked));
        $this->assertCount(0, $this->pushesFor($staffNamedContractor));

        // Once the subcontractor is no longer one, or the plan drops the portal, nothing more is pushed.
        Party::withoutGlobalScopes()->whereKey($this->rival->id)->update(['type' => 'vendor']);
        app(RaBillService::class)->markPaid($this->rivalBill);
        $this->assertCount(0, $this->pushesFor($this->rivalLogin));
    }

    public function test_a_company_whose_plan_has_no_portal_pushes_nothing_to_contractor_logins(): void
    {
        $this->organization->update(['plan' => 'free']);

        app(RaBillService::class)->markPaid($this->bill);

        $this->assertCount(0, $this->pushesFor($this->contractor));
    }

    public function test_a_contractor_can_switch_its_pushes_off(): void
    {
        $this->prefer($this->contractor, ['push_categories' => ['contractor' => false]]);

        app(RaBillService::class)->markPaid($this->bill);

        $this->assertCount(0, $this->pushesFor($this->contractor));
    }

    public function test_a_reversal_is_not_announced_to_the_contractor(): void
    {
        $reversal = app(ContractorPaymentService::class)->reverse($this->payment, 'Entered twice', $this->manager);

        $this->assertSame('APPROVED', $reversal->status);
        $this->assertCount(0, $this->pushesFor($this->contractor));
        $this->assertSame(1, ContractorPayment::where('reversal_of_id', $this->payment->id)->count());
    }
}
