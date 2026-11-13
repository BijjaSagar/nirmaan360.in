<?php

namespace Tests\Feature\ContractorControl;

use App\Models\Approval;
use App\Models\CustomRole;
use App\Models\User;
use App\Services\ContractorControl\ContractorPaymentService;
use App\Services\ContractorControl\MilestoneService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * A contractor payment in the phone's approvals inbox: the document the app draws, who sees its money,
 * and that the generic act route decides it. No new route: GET /approvals and POST /approvals/{id}/act.
 */
class ContractorPaymentApprovalApiTest extends TestCase
{
    use BuildsContractorControl, RefreshDatabase;

    private const API = '/api/v1';

    /** Keys whose value is a rupee figure, or text that may quote one. */
    private const MONEY_KEYS = ['amount', 'is_advance', 'mode', 'paid_to', 'notes', 'has_bill', 'paid_pct', 'impact'];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake();
        $this->site();
    }

    private function inbox(User $user): TestResponse
    {
        Sanctum::actingAs($user);

        return $this->getJson(self::API.'/approvals')->assertOk();
    }

    /** The one contractor payment in this person's inbox. */
    private function document(User $user): array
    {
        $rows = collect($this->inbox($user)->json('data'))->where('doc_type', 'contractor')->values();
        $this->assertCount(1, $rows, 'Expected exactly one contractor payment in the inbox.');

        return $rows[0];
    }

    /** Someone who may approve contractor payments at the PM level but may not see money. */
    private function approverWithoutMoney(): User
    {
        $role = CustomRole::create([
            'organization_id' => $this->organization->id,
            'name' => 'Site head',
            'permissions' => ['project.view', 'contractor.view', 'contractor.approve'],
            'approval_role' => 'pm',
            'created_by' => $this->owner->id,
        ]);
        $user = $this->member($this->organization, 'pm');
        $user->forceFill(['custom_role_id' => $role->id])->save();

        return $user->refresh();
    }

    public function test_the_manager_sees_the_payment_with_work_against_paid_and_what_approving_would_do(): void
    {
        $order = $this->contract();
        $this->workDone($this->item, '70');
        $this->paid($order, '60000', ['boq_item_id' => $this->item->id]);
        $payment = $this->raise($order, '20000', ['boq_item_id' => $this->item->id, 'notes' => 'Second running bill', 'paid_to' => 'Ganesh', 'mode' => 'upi']);

        $row = $this->document($this->manager);
        $doc = $row['document'];

        $this->assertTrue($row['can_act']);
        $this->assertSame(['pm'], $row['levels']);
        $this->assertSame($payment->id, $doc['id']);
        $this->assertSame($payment->number, $doc['number']);
        $this->assertSame('IN_REVIEW', $doc['status']);
        $this->assertSame(['id' => $order->id, 'number' => $order->number, 'title' => 'Flooring'], $doc['work_order']);
        $this->assertSame($order->party->name, $doc['contractor']);
        $this->assertSame([
            'type' => 'boq_item',
            'code' => $this->item->code,
            'description' => $this->item->description,
            'unit' => $this->item->unit,
            'work_pct' => '70.00',
        ], $doc['against']);
        $this->assertSame('2026-11-01', $doc['paid_on']);
        $this->assertSame($this->supervisor->name, $doc['entered_by']);

        // The same numbers as the contractor board, and the web's "If approved".
        $this->assertSame('70.00', $doc['work_pct']);
        $this->assertSame('30.00', $doc['balance_work_pct']);
        $this->assertSame('60.00', $doc['paid_pct']);
        $this->assertSame('20000.00', $doc['amount']);
        $this->assertFalse($doc['is_advance']);
        $this->assertSame('upi', $doc['mode']);
        $this->assertSame('Ganesh', $doc['paid_to']);
        $this->assertSame('Second running bill', $doc['notes']);
        $this->assertFalse($doc['has_bill']);

        $this->assertSame('Paid 60%, Work 70%', $doc['impact']['now']['label']);
        $this->assertSame('100000.00', $doc['impact']['now']['contract_value']);
        $this->assertSame('green', $doc['impact']['now']['ratio_status']);
        $this->assertSame('80.00', $doc['impact']['after']['paid_pct']);
        $this->assertSame('80000.00', $doc['impact']['after']['paid']);
        $this->assertSame('10.00', $doc['impact']['after']['gap_pct']);
        $this->assertSame('red', $doc['impact']['after']['ratio_status']);
        $this->assertTrue($doc['impact']['after']['over_limit']);
        $this->assertSame('Paid 80%, Work 70%', $doc['impact']['after']['label']);
        $this->assertSame('70.00', $doc['impact']['item']['work_pct']);
        $this->assertSame('60.00', $doc['impact']['item']['paid_pct']);

        $board = $this->getJson(self::API.'/projects/'.$this->project->id.'/contractor-control')->assertOk()->json('data.0');
        $this->assertSame($board['work_pct'], $doc['work_pct']);
        $this->assertSame($board['balance_work_pct'], $doc['balance_work_pct']);
        $this->assertSame($board['paid_pct'], $doc['paid_pct']);
        foreach (['work_pct', 'paid_pct', 'paid', 'contract_value', 'gap_pct', 'label', 'ratio_status', 'over_limit', 'limit_pct', 'balance_money'] as $key) {
            $this->assertSame($board[$key], $doc['impact']['now'][$key], "The approval's {$key} differs from the board's.");
        }

        $this->assertSame(['needed' => false, 'allowed' => false, 'message' => null], $doc['owner_override']);
    }

    public function test_an_advance_with_a_bill_against_the_contract_in_general(): void
    {
        $order = $this->contract();
        app(ContractorPaymentService::class)->raise($order, $this->paymentData(['amount' => '5000', 'is_advance' => true]), UploadedFile::fake()->image('bill.jpg'), $this->supervisor);

        $doc = $this->document($this->manager)['document'];

        $this->assertSame(['type' => 'contract'], $doc['against']);
        $this->assertTrue($doc['is_advance']);
        $this->assertTrue($doc['has_bill']);
        $this->assertSame('0.00', $doc['work_pct']);
        $this->assertNull($doc['impact']['item']);
        $this->assertSame('5.00', $doc['impact']['after']['paid_pct']);
    }

    public function test_a_payment_against_a_part_names_the_part(): void
    {
        $order = $this->contract();
        $part = app(MilestoneService::class)->add($order, ['title' => 'Plinth', 'amount' => '25000'], $this->manager);
        $this->raise($order, '10000', ['contractor_milestone_id' => $part->id]);

        $doc = $this->document($this->manager)['document'];

        $this->assertSame(['type' => 'part', 'title' => 'Plinth', 'done' => false], $doc['against']);
    }

    public function test_an_approver_without_money_view_gets_work_percent_and_null_for_every_money_field(): void
    {
        $order = $this->contract();
        $this->workDone($this->item, '70');
        $this->paid($order, '61234');
        $this->raise($order, '27777', ['boq_item_id' => $this->item->id, 'notes' => 'Pay 27777 today', 'paid_to' => 'Ganesh']);

        $approver = $this->approverWithoutMoney();
        $response = $this->inbox($approver);
        $row = collect($response->json('data'))->firstWhere('doc_type', 'contractor');
        $doc = $row['document'];

        $this->assertTrue($row['can_act']);
        $this->assertSame('70.00', $doc['work_pct']);
        $this->assertSame('70.00', $doc['against']['work_pct']);
        foreach (self::MONEY_KEYS as $key) {
            $this->assertArrayHasKey($key, $doc, "The key {$key} should stay, with null in it.");
            $this->assertNull($doc[$key], "{$key} went to someone without money.view.");
        }

        $json = $response->getContent();
        foreach (['27777', '61234', 'Ganesh', 'contract_value', 'balance_money'] as $leak) {
            $this->assertStringNotContainsString($leak, $json, "{$leak} reached someone without money.view.");
        }
    }

    public function test_the_pull_carries_the_same_document(): void
    {
        $order = $this->contract();
        $this->raise($order, '20000');

        Sanctum::actingAs($this->manager);
        $approvals = collect($this->getJson(self::API.'/sync/pull')->assertOk()->json('approvals'));

        $this->assertSame('20000.00', $approvals->firstWhere('doc_type', 'contractor')['document']['amount']);
    }

    public function test_the_person_who_raised_it_and_site_staff_never_see_it_to_act_on(): void
    {
        $this->raise($this->contract(), '20000');

        $this->inbox($this->supervisor)->assertJsonCount(0, 'data')->assertJsonPath('waiting_count', 1);
        $this->inbox($this->member($this->organization, 'qs'))->assertJsonCount(0, 'data');
        $this->inbox($this->member($this->organization, 'accounts'))->assertJsonCount(0, 'data');
    }

    public function test_the_manager_approves_it_from_the_phone_and_it_counts_as_paid(): void
    {
        $order = $this->contract();
        $payment = $this->raise($order, '20000');
        Sanctum::actingAs($this->manager);

        $this->postJson(self::API."/approvals/{$payment->currentApproval->id}/act", ['action' => 'approve', 'client_uuid' => (string) Str::uuid()])
            ->assertOk()
            ->assertJsonPath('data.status', 'APPROVED')
            ->assertJsonPath('data.doc_type', 'contractor')
            ->assertJsonPath('data.document.status', 'APPROVED')
            ->assertJsonPath('data.document.impact.after', null)
            ->assertJsonPath('data.document.impact.now.paid', '20000.00');

        $this->assertSame('APPROVED', $payment->fresh()->status);
    }

    public function test_return_and_reject_need_a_remark_and_a_rejected_payment_is_locked(): void
    {
        $order = $this->contract();
        $returned = $this->raise($order, '20000');
        $rejected = $this->raise($order, '30000');
        Sanctum::actingAs($this->manager);

        foreach (['return', 'reject'] as $action) {
            $this->postJson(self::API."/approvals/{$returned->currentApproval->id}/act", ['action' => $action])
                ->assertUnprocessable()->assertJsonValidationErrors('remark');
        }

        $this->postJson(self::API."/approvals/{$returned->currentApproval->id}/act", ['action' => 'return', 'remark' => 'Attach the bill'])
            ->assertOk()->assertJsonPath('data.status', 'RETURNED')->assertJsonPath('data.steps.1.remark', 'Attach the bill');
        $this->postJson(self::API."/approvals/{$rejected->currentApproval->id}/act", ['action' => 'reject', 'remark' => 'Already paid in cash'])
            ->assertOk()->assertJsonPath('data.status', 'REJECTED')->assertJsonPath('data.steps.1.action', 'reject');

        $this->assertSame('RETURNED', $returned->fresh()->status);
        $this->assertSame('REJECTED', $rejected->fresh()->status);
        $this->assertContains('REJECTED', $rejected->lockedStatuses());
    }

    public function test_an_engineer_cannot_act_on_a_payment(): void
    {
        $payment = $this->raise($this->contract(), '20000');
        Sanctum::actingAs($this->member($this->organization, 'engineer'));

        $this->postJson(self::API."/approvals/{$payment->currentApproval->id}/act", ['action' => 'approve'])->assertForbidden();
        $this->assertSame('IN_REVIEW', $payment->fresh()->status);
    }

    public function test_another_company_never_sees_the_payment_and_cannot_act_on_it(): void
    {
        $payment = $this->raise($this->contract(), '20000');
        $approval = $payment->currentApproval;
        $other = $this->tenant('Other Co');
        $outsider = $this->member($other, 'owner');
        $otherManager = $this->member($other, 'pm');

        foreach ([$outsider, $otherManager] as $user) {
            $response = $this->inbox($user)->assertJsonCount(0, 'data')->assertJsonPath('waiting_count', 0);
            $this->assertStringNotContainsString($payment->number, $response->getContent());
            $this->postJson(self::API."/approvals/{$approval->id}/act", ['action' => 'approve'])->assertNotFound();
        }

        $this->assertSame('IN_REVIEW', $payment->fresh()->status);
        $this->assertSame(1, Approval::withoutGlobalScopes()->count());
    }
}
