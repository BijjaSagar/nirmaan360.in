<?php

namespace Tests\Feature\Crm;

use App\Models\CrmTenderPayment;
use App\Services\Crm\TenderPaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\BuildsTenant;
use Tests\TestCase;

class TenderPaymentTest extends TestCase
{
    use BuildsCrm, BuildsTenant, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function totals(int $organizationId): array
    {
        $service = app(TenderPaymentService::class);

        return $service->totals($service->query($organizationId)->get());
    }

    public function test_emd_comes_back_by_default_and_fees_do_not(): void
    {
        $user = $this->crmUser();
        $tender = $this->makeTender($user->organization);

        $this->post(route('crm.payments.store', $tender), ['kind' => 'emd', 'amount' => '500000', 'mode' => 'bg', 'reference' => 'BG-1182', 'valid_until' => '2027-03-31'])->assertSessionHasNoErrors();
        $this->post(route('crm.payments.store', $tender), ['kind' => 'tender_fee', 'amount' => '2360.50', 'mode' => 'online'])->assertSessionHasNoErrors();

        $emd = CrmTenderPayment::where('kind', 'emd')->firstOrFail();
        $fee = CrmTenderPayment::where('kind', 'tender_fee')->firstOrFail();

        $this->assertTrue($emd->refundable);
        $this->assertFalse($fee->refundable);
        $this->assertSame('paid', $emd->status);
        $this->assertSame(today()->toDateString(), $emd->paid_on->toDateString());
        $this->assertSame('2027-03-31', $emd->valid_until->toDateString());
        $this->assertSame('2360.50', $fee->amount);
        $this->assertDatabaseHas('audit_logs', ['auditable_type' => $emd->getMorphClass(), 'auditable_id' => $emd->id, 'action' => 'created']);
    }

    public function test_an_amount_must_be_above_zero_and_a_future_payment_date_is_refused(): void
    {
        $user = $this->crmUser();
        $tender = $this->makeTender($user->organization);

        $this->post(route('crm.payments.store', $tender), ['kind' => 'emd', 'amount' => '0', 'mode' => 'online'])->assertSessionHasErrors('amount');
        $this->post(route('crm.payments.store', $tender), ['kind' => 'emd', 'amount' => '10', 'mode' => 'online', 'paid_on' => now()->addDay()->toDateString()])->assertSessionHasErrors('paid_on');
        $this->post(route('crm.payments.store', $tender), ['kind' => 'gift', 'amount' => '10', 'mode' => 'online'])->assertSessionHasErrors('kind');
        $this->assertSame(0, CrmTenderPayment::count());
    }

    public function test_money_with_the_authority_is_the_exact_sum_of_what_is_held(): void
    {
        $user = $this->crmUser();
        $tender = $this->makeTender($user->organization);
        $this->makePayment($tender, ['amount' => '0.10']);
        $this->makePayment($tender, ['amount' => '0.20']);
        $this->makePayment($tender, ['kind' => 'tender_fee', 'amount' => '2360.00', 'refundable' => false]);
        $this->makePayment($tender, ['amount' => '999.99', 'status' => 'planned', 'paid_on' => null]);

        $totals = $this->totals($user->organization_id);

        $this->assertSame('0.30', $totals['held']);
        $this->assertSame('2360.00', $totals['cost']);
        $this->assertSame('999.99', $totals['planned']);
        $this->assertSame('0.00', $totals['refunded']);
    }

    public function test_a_refund_is_due_only_once_the_tender_is_lost_or_not_bid(): void
    {
        $user = $this->crmUser();
        $open = $this->makeTender($user->organization, ['stage' => 'submitted']);
        $lost = $this->makeTender($user->organization, ['stage' => 'lost', 'closed_on' => today()]);
        $won = $this->makeTender($user->organization, ['stage' => 'won', 'closed_on' => today()]);
        $noBid = $this->makeTender($user->organization, ['stage' => 'no_bid', 'closed_on' => today()]);
        foreach ([$open, $lost, $won, $noBid] as $tender) {
            $this->makePayment($tender, ['amount' => '100000.00']);
        }

        $totals = $this->totals($user->organization_id);

        $this->assertSame('400000.00', $totals['held']);
        $this->assertSame('200000.00', $totals['refund_due']);
        $this->assertCount(2, app(TenderPaymentService::class)->query($user->organization_id, ['view' => 'refund_due'])->get());
    }

    public function test_a_refund_is_overdue_when_its_expected_date_has_passed(): void
    {
        $user = $this->crmUser();
        $lost = $this->makeTender($user->organization, ['stage' => 'lost', 'closed_on' => today()->subMonths(2)]);
        $late = $this->makePayment($lost, ['refund_expected_on' => today()->subDays(3)]);
        $onTime = $this->makePayment($lost, ['refund_expected_on' => today()->addDays(3)]);

        $this->assertTrue($late->load('tender')->refundIsOverdue());
        $this->assertFalse($onTime->load('tender')->refundIsOverdue());
        $this->get(route('crm.payments.index'))->assertOk()->assertSee('Refund overdue');
    }

    public function test_a_full_refund_is_recorded(): void
    {
        $user = $this->crmUser();
        $tender = $this->makeTender($user->organization, ['stage' => 'lost', 'closed_on' => today()]);
        $payment = $this->makePayment($tender, ['amount' => '500000.00']);

        $this->post(route('crm.payments.settle', $payment), ['outcome' => 'refunded', 'settled_on' => today()->toDateString()])->assertSessionHasNoErrors();

        $payment->refresh();
        $this->assertSame('refunded', $payment->status);
        $this->assertSame('500000.00', $payment->refunded_amount);
        $totals = $this->totals($user->organization_id);
        $this->assertSame('500000.00', $totals['refunded']);
        $this->assertSame('0.00', $totals['held']);
        $this->assertSame('0.00', $totals['cost']);
    }

    public function test_a_part_refund_leaves_the_rest_as_a_cost(): void
    {
        $user = $this->crmUser();
        $tender = $this->makeTender($user->organization, ['stage' => 'lost', 'closed_on' => today()]);
        $payment = $this->makePayment($tender, ['amount' => '500000.00']);

        $this->post(route('crm.payments.settle', $payment), ['outcome' => 'refunded', 'refunded_amount' => '487640.50'])->assertSessionHasNoErrors();

        $totals = $this->totals($user->organization_id);
        $this->assertSame('487640.50', $totals['refunded']);
        $this->assertSame('12359.50', $totals['cost']);
    }

    public function test_a_refund_cannot_be_more_than_was_paid_or_nothing(): void
    {
        $user = $this->crmUser();
        $tender = $this->makeTender($user->organization, ['stage' => 'lost', 'closed_on' => today()]);
        $payment = $this->makePayment($tender, ['amount' => '500000.00']);

        $this->post(route('crm.payments.settle', $payment), ['outcome' => 'refunded', 'refunded_amount' => '500000.01'])->assertSessionHasErrors('refunded_amount');
        $this->post(route('crm.payments.settle', $payment), ['outcome' => 'refunded', 'refunded_amount' => '0'])->assertSessionHasErrors('refunded_amount');
        $this->post(route('crm.payments.settle', $payment), ['outcome' => 'stolen'])->assertSessionHasErrors('outcome');

        $this->assertSame('paid', $payment->fresh()->status);
    }

    public function test_forfeited_money_is_a_cost_and_adjusted_money_is_kept_apart(): void
    {
        $user = $this->crmUser();
        $lost = $this->makeTender($user->organization, ['stage' => 'lost', 'closed_on' => today()]);
        $won = $this->makeTender($user->organization, ['stage' => 'won', 'closed_on' => today()]);
        $forfeited = $this->makePayment($lost, ['amount' => '100000.00']);
        $adjusted = $this->makePayment($won, ['amount' => '250000.00']);

        $this->post(route('crm.payments.settle', $forfeited), ['outcome' => 'forfeited']);
        $this->post(route('crm.payments.settle', $adjusted), ['outcome' => 'adjusted']);

        $totals = $this->totals($user->organization_id);
        $this->assertSame('100000.00', $totals['cost']);
        $this->assertSame('250000.00', $totals['adjusted']);
        $this->assertSame('0.00', $totals['held']);
        $this->assertSame('0.00', $forfeited->fresh()->refunded_amount);
    }

    public function test_only_money_that_has_been_paid_can_be_settled(): void
    {
        $user = $this->crmUser();
        $payment = $this->makePayment($this->makeTender($user->organization), ['status' => 'planned', 'paid_on' => null]);

        $this->post(route('crm.payments.settle', $payment), ['outcome' => 'refunded'])->assertSessionHasErrors('outcome');
        $this->assertSame('planned', $payment->fresh()->status);
    }

    public function test_a_settlement_can_be_undone_and_settled_money_cannot_be_edited_or_removed(): void
    {
        $user = $this->crmUser();
        $payment = $this->makePayment($this->makeTender($user->organization, ['stage' => 'lost', 'closed_on' => today()]));
        $this->post(route('crm.payments.settle', $payment), ['outcome' => 'refunded']);

        $this->patch(route('crm.payments.update', $payment), ['kind' => 'emd', 'amount' => '1', 'mode' => 'online'])->assertSessionHasErrors('outcome');
        $this->delete(route('crm.payments.destroy', $payment))->assertSessionHasErrors('outcome');
        $this->assertSame('500000.00', $payment->fresh()->amount);

        $this->post(route('crm.payments.undo', $payment))->assertSessionHasNoErrors();
        $payment->refresh();
        $this->assertSame('paid', $payment->status);
        $this->assertNull($payment->refunded_amount);
        $this->assertNull($payment->settled_on);
        $this->assertDatabaseHas('audit_logs', ['auditable_type' => $payment->getMorphClass(), 'auditable_id' => $payment->id, 'action' => 'updated']);
    }

    public function test_a_payment_that_is_not_settled_cannot_be_undone(): void
    {
        $user = $this->crmUser();
        $payment = $this->makePayment($this->makeTender($user->organization));

        $this->post(route('crm.payments.undo', $payment))->assertSessionHasErrors('outcome');
    }

    public function test_an_unsettled_payment_can_be_edited_and_removed(): void
    {
        $user = $this->crmUser();
        $payment = $this->makePayment($this->makeTender($user->organization), ['status' => 'planned', 'paid_on' => null]);

        $this->patch(route('crm.payments.update', $payment), ['kind' => 'emd', 'amount' => '750000.00', 'mode' => 'dd', 'status' => 'paid', 'refundable' => '1', 'reference' => 'DD 55'])->assertSessionHasNoErrors();

        $payment->refresh();
        $this->assertSame('750000.00', $payment->amount);
        $this->assertSame('paid', $payment->status);
        $this->assertSame(today()->toDateString(), $payment->paid_on->toDateString());

        $this->delete(route('crm.payments.destroy', $payment))->assertSessionHasNoErrors();
        $this->assertNull(CrmTenderPayment::find($payment->id));
    }

    public function test_accounts_can_settle_money_and_the_project_manager_can_too(): void
    {
        $organization = $this->tenant();
        $tender = $this->makeTender($organization, ['stage' => 'lost', 'closed_on' => today()]);
        $first = $this->makePayment($tender);
        $second = $this->makePayment($tender);

        $this->actingAs($this->member($organization, 'accounts'))->post(route('crm.payments.settle', $first), ['outcome' => 'refunded'])->assertSessionHasNoErrors();
        $this->actingAs($this->member($organization, 'qs'))->post(route('crm.payments.settle', $second), ['outcome' => 'refunded'])->assertForbidden();

        $this->assertSame('refunded', $first->fresh()->status);
        $this->assertSame('paid', $second->fresh()->status);
    }

    public function test_the_ledger_filters_by_what_is_held_or_settled(): void
    {
        $user = $this->crmUser();
        $held = $this->makeTender($user->organization, ['title' => 'Still held']);
        $settled = $this->makeTender($user->organization, ['title' => 'All settled', 'stage' => 'lost', 'closed_on' => today()]);
        $this->makePayment($held);
        $this->post(route('crm.payments.settle', $this->makePayment($settled)), ['outcome' => 'refunded']);

        $this->get(route('crm.payments.index', ['view' => 'held']))->assertSee('Still held')->assertDontSee('All settled');
        $this->get(route('crm.payments.index', ['view' => 'settled']))->assertSee('All settled')->assertDontSee('Still held');
    }

    public function test_the_service_raises_a_validation_error_for_a_bad_settlement(): void
    {
        $user = $this->crmUser();
        $payment = $this->makePayment($this->makeTender($user->organization));

        $this->expectException(ValidationException::class);
        app(TenderPaymentService::class)->settle($payment, 'refunded', ['refunded_amount' => '999999999'], $user);
    }
}
