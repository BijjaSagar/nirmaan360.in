<?php

namespace Tests\Feature\ContractorControl;

use App\Exceptions\ImmutableRecordException;
use App\Models\Approval;
use App\Models\ContractorPayment;
use App\Models\User;
use App\Services\ApprovalService;
use App\Services\ContractorControl\ContractorPaymentService;
use App\Services\ContractorControl\MilestoneService;
use App\Services\ContractorControl\WorkPaymentRatio;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/** A supervisor asks, the project manager decides with the ratio in front of him. */
class ContractorPaymentFlowTest extends TestCase
{
    use BuildsContractorControl, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake();
        $this->site();
    }

    public function test_a_request_starts_in_review_and_does_not_count(): void
    {
        $order = $this->contract();
        $payment = $this->raise($order, '5000');

        $this->assertSame('IN_REVIEW', $payment->status);
        $this->assertSame('contractor', $payment->currentApproval->doc_type);
        $this->assertSame(['pm'], $payment->currentApproval->levels);
        $this->assertSame('0.00', app(WorkPaymentRatio::class)->forContract($order)['paid']);
    }

    public function test_the_manager_approves_and_it_counts(): void
    {
        $order = $this->contract();
        $this->approve($this->raise($order, '5000'));

        $this->assertSame('5000.00', app(WorkPaymentRatio::class)->forContract($order)['paid']);
    }

    public function test_a_rejected_request_never_counts(): void
    {
        $order = $this->contract();
        $payment = $this->raise($order, '5000');

        app(ApprovalService::class)->act($payment->currentApproval, $this->manager, 'reject', 'Work is behind');

        $this->assertSame('REJECTED', $payment->refresh()->status);
        $this->assertSame('0.00', app(WorkPaymentRatio::class)->forContract($order)['paid']);
    }

    public function test_someone_cannot_approve_their_own_request(): void
    {
        $payment = app(ContractorPaymentService::class)->raise($this->contract(), $this->paymentData(), null, $this->manager);

        $this->assertFalse(app(ApprovalService::class)->canAct($payment->currentApproval, $this->manager));
        $this->assertTrue(app(ApprovalService::class)->canAct($payment->currentApproval, $this->owner));
    }

    public function test_a_supervisor_cannot_approve(): void
    {
        $payment = $this->raise($this->contract(), '5000');

        $this->assertFalse(app(ApprovalService::class)->canAct($payment->currentApproval, $this->member($this->organization, 'engineer')));
    }

    public function test_an_approved_payment_is_immutable(): void
    {
        $payment = $this->paid($this->contract(), '5000');

        $this->expectException(ImmutableRecordException::class);
        $payment->update(['amount' => '1']);
    }

    public function test_a_returned_request_can_be_corrected_and_sent_again(): void
    {
        $order = $this->contract();
        $payment = $this->raise($order, '5000');
        app(ApprovalService::class)->act($payment->currentApproval, $this->manager, 'return', 'Wrong amount');

        $this->actingAs($this->supervisor)
            ->patch(route('contractor-control.payments.update', $payment), ['paid_on' => '2026-11-01', 'amount' => '4000', 'mode' => 'cash'])
            ->assertRedirect();

        $payment->refresh();
        $this->assertSame('IN_REVIEW', $payment->status);
        $this->assertSame('4000.00', $payment->amount);
        $this->assertSame('cash', $payment->mode);
        $this->assertSame(2, Approval::where('approvable_id', $payment->id)->count());
    }

    public function test_only_the_person_who_raised_it_can_correct_it(): void
    {
        $payment = $this->raise($this->contract(), '5000');
        app(ApprovalService::class)->act($payment->currentApproval, $this->manager, 'return', 'Wrong amount');
        $another = $this->member($this->organization, 'engineer');

        $this->actingAs($another)->get(route('contractor-control.payments.edit', $payment))->assertForbidden();
    }

    public function test_a_repeated_client_uuid_returns_the_original_payment(): void
    {
        $order = $this->contract();
        $uuid = (string) Str::uuid();

        $one = $this->raise($order, '5000', ['client_uuid' => $uuid]);
        $two = $this->raise($order, '5000', ['client_uuid' => $uuid]);

        $this->assertSame($one->id, $two->id);
        $this->assertSame(1, ContractorPayment::count());
    }

    public function test_a_repeat_does_not_leave_a_second_bill_behind(): void
    {
        $order = $this->contract();
        $uuid = (string) Str::uuid();
        $service = app(ContractorPaymentService::class);

        $service->raise($order, $this->paymentData(['client_uuid' => $uuid]), UploadedFile::fake()->image('bill.jpg'), $this->supervisor);
        $service->raise($order, $this->paymentData(['client_uuid' => $uuid]), UploadedFile::fake()->image('bill.jpg'), $this->supervisor);

        $this->assertCount(1, Storage::allFiles());
    }

    public function test_a_payment_can_be_tied_to_an_item_or_a_part_but_not_both(): void
    {
        $order = $this->contract();
        $part = app(MilestoneService::class)->add($order, ['title' => 'Plinth', 'amount' => '100'], $this->manager);

        $this->actingAs($this->supervisor)
            ->post(route('contractor-control.payments.store', $order), [...$this->paymentData(), 'against' => 'item:'.$this->item->id])
            ->assertRedirect();

        $this->assertSame($this->item->id, ContractorPayment::latest('id')->first()->boq_item_id);

        $this->actingAs($this->supervisor)
            ->post(route('contractor-control.payments.store', $order), [...$this->paymentData(), 'against' => 'part:'.$part->id])
            ->assertRedirect();

        $this->assertSame($part->id, ContractorPayment::latest('id')->first()->contractor_milestone_id);

        $this->expectException(ValidationException::class);
        validator(['boq_item_id' => $this->item->id, 'contractor_milestone_id' => $part->id, ...$this->paymentData()], app(ContractorPaymentService::class)->rules($order))->validate();
    }

    public function test_an_item_from_another_contract_is_refused(): void
    {
        $order = $this->contract();
        $elsewhere = $this->makeBoqItem($this->project);

        $this->actingAs($this->supervisor)
            ->post(route('contractor-control.payments.store', $order), [...$this->paymentData(), 'against' => 'item:'.$elsewhere->id])
            ->assertSessionHasErrors('boq_item_id');
    }

    public function test_a_closed_contract_takes_no_more_payments(): void
    {
        $order = $this->contract();
        $order->update(['status' => 'CLOSED']);

        $this->expectException(ValidationException::class);
        $this->raise($order->refresh(), '100');
    }

    public function test_amounts_are_checked(): void
    {
        $order = $this->contract();

        foreach (['0', '-1', '12.345'] as $amount) {
            $this->actingAs($this->supervisor)
                ->post(route('contractor-control.payments.store', $order), [...$this->paymentData(), 'amount' => $amount])
                ->assertSessionHasErrors('amount');
        }
    }

    public function test_mode_must_be_cash_bank_or_upi(): void
    {
        $order = $this->contract();

        foreach (['cash', 'bank', 'upi'] as $mode) {
            $this->assertArrayHasKey($mode, config('modules.contractor_control.payment_modes'));
        }

        $this->actingAs($this->supervisor)
            ->post(route('contractor-control.payments.store', $order), [...$this->paymentData(), 'mode' => 'cheque'])
            ->assertSessionHasErrors('mode');
    }

    public function test_paid_to_defaults_to_the_contractor(): void
    {
        $order = $this->contract();

        $this->assertSame($order->party->name, $this->raise($order, '100')->paid_to);
        $this->assertSame('Labour head', $this->raise($order, '100', ['paid_to' => 'Labour head'])->paid_to);
    }

    public function test_the_person_who_entered_it_is_recorded(): void
    {
        $this->assertSame($this->supervisor->id, $this->raise($this->contract(), '100')->entered_by);
    }

    public function test_the_manager_sees_work_against_paid_while_approving(): void
    {
        $order = $this->contract();
        $this->workDone($this->item, '70');
        $this->paid($order, '60000');
        $payment = $this->raise($order, '30000');

        $this->actingAs($this->manager)
            ->get(route('contractor-control.payments.show', $payment))
            ->assertOk()
            ->assertSee('Paid 60%, Work 70%')
            ->assertSee('If approved: Paid 90%, Work 70%');

        $this->actingAs($this->manager)
            ->get(route('approvals.index'))
            ->assertOk()
            ->assertSee('Paid 60%, Work 70%')
            ->assertSee('If approved: Paid 90%, Work 70%');
    }

    public function test_the_approvals_page_shows_nothing_about_money_to_site_staff(): void
    {
        $order = $this->contract();
        $this->paid($order, '60000');
        $this->raise($order, '30000');

        $response = $this->actingAs($this->supervisor)->get(route('approvals.index'))->assertOk();

        $response->assertDontSee('30,000');
        $response->assertDontSee('Paid 60%');
    }

    public function test_the_approval_title_carries_no_amount(): void
    {
        $payment = $this->raise($this->contract(), '30000');

        $this->assertStringNotContainsString('30000', $payment->approvalTitle());
    }

    public function test_the_approval_links_to_the_payment(): void
    {
        $payment = $this->raise($this->contract(), '30000');

        $this->assertSame(route('contractor-control.payments.show', $payment), $payment->currentApproval->documentUrl());
    }

    public function test_a_bill_is_stored_privately_and_only_money_people_can_open_it(): void
    {
        $order = $this->contract();

        $this->actingAs($this->supervisor)
            ->post(route('contractor-control.payments.store', $order), [...$this->paymentData(), 'bill' => UploadedFile::fake()->create('bill.pdf', 100, 'application/pdf')])
            ->assertRedirect();

        $payment = ContractorPayment::latest('id')->first();
        $this->assertNotNull($payment->bill_path);
        Storage::assertExists($payment->bill_path);

        $this->actingAs($this->manager)->get(route('contractor-control.payments.bill', $payment))->assertOk();
        $this->actingAs($this->supervisor)->get(route('contractor-control.payments.bill', $payment))->assertForbidden();
    }

    public function test_only_images_and_pdfs_are_accepted_as_bills(): void
    {
        $this->actingAs($this->supervisor)
            ->post(route('contractor-control.payments.store', $this->contract()), [...$this->paymentData(), 'bill' => UploadedFile::fake()->create('evil.html', 10, 'text/html')])
            ->assertSessionHasErrors('bill');
    }

    public function test_a_supervisor_can_see_only_their_own_requests_and_no_amounts(): void
    {
        $order = $this->contract();
        $mine = $this->raise($order, '30000');
        $theirs = app(ContractorPaymentService::class)->raise($order, $this->paymentData(['amount' => '40000']), null, $this->manager);

        $response = $this->actingAs($this->supervisor)->get(route('contractor-control.payments.index', $this->project))->assertOk();

        $response->assertSee($mine->number)->assertDontSee($theirs->number)->assertDontSee('30,000');

        $this->actingAs($this->supervisor)->get(route('contractor-control.payments.show', $mine))->assertOk()->assertDontSee('30,000');
        $this->actingAs($this->supervisor)->get(route('contractor-control.payments.show', $theirs))->assertForbidden();
    }

    public function test_an_accountant_can_enter_a_payment_but_not_approve_it(): void
    {
        $accounts = User::factory()->create(['organization_id' => $this->organization->id, 'role' => 'accounts', 'is_active' => true]);
        $order = $this->contract();

        $this->actingAs($accounts)
            ->post(route('contractor-control.payments.store', $order), $this->paymentData(['amount' => '700']))
            ->assertRedirect();

        $payment = ContractorPayment::latest('id')->first();

        $this->assertSame($accounts->id, $payment->entered_by);
        $this->assertFalse(app(ApprovalService::class)->canAct($payment->currentApproval, $accounts));
    }

    public function test_reversing_needs_a_reason_and_the_approve_permission(): void
    {
        $payment = $this->paid($this->contract(), '5000');

        $this->actingAs($this->supervisor)->post(route('contractor-control.payments.reverse', $payment), ['reason' => 'x'])->assertForbidden();
        $this->actingAs($this->manager)->post(route('contractor-control.payments.reverse', $payment), [])->assertSessionHasErrors('reason');
        $this->actingAs($this->manager)->post(route('contractor-control.payments.reverse', $payment), ['reason' => 'Wrong contractor'])->assertRedirect();

        $reversal = ContractorPayment::where('reversal_of_id', $payment->id)->first();
        $this->assertSame('-5000.00', $reversal->amount);
        $this->assertSame('APPROVED', $reversal->status);
    }
}
