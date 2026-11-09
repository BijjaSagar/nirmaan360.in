<?php

namespace Tests\Feature\ContractorControl;

use App\Models\ContractorPayment;
use App\Services\ContractorControl\ContractorPaymentService;
use App\Services\ContractorControl\MilestoneService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ContractorControlApiTest extends TestCase
{
    use BuildsContractorControl, RefreshDatabase;

    private const MONEY_KEYS = [
        'contract_value', 'paid', 'advance', 'paid_pct', 'gap_pct', 'ratio_status', 'over_limit', 'limit_pct', 'label',
        'balance_work_value', 'balance_money', 'short_by', 'amount', 'mode', 'paid_to', 'reference', 'notes', 'rate', 'impact', 'has_bill',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake();
        $this->site();
    }

    /** No money field name anywhere in the JSON, at any depth. */
    private function assertNoMoney(TestResponse $response): void
    {
        $response->assertOk();
        $json = $response->getContent();

        foreach (self::MONEY_KEYS as $key) {
            $this->assertStringNotContainsString('"'.$key.'"', $json, "Site staff received {$key}.");
        }

        $this->assertStringNotContainsString('90000', $json);
    }

    public function test_a_supervisor_gets_work_percent_only(): void
    {
        $order = $this->contract();
        $this->workDone($this->item, '70');
        $this->paid($order, '90000');

        Sanctum::actingAs($this->supervisor);

        $response = $this->getJson('/api/v1/projects/'.$this->project->id.'/contractor-control');

        $this->assertNoMoney($response);
        $response->assertJsonPath('data.0.work_pct', '70.00');
        $response->assertJsonPath('data.0.number', $order->number);
    }

    public function test_a_supervisor_sees_contract_lines_and_parts_with_no_money(): void
    {
        $order = $this->contract();
        app(MilestoneService::class)->add($order, ['title' => 'Plinth', 'amount' => '25000'], $this->manager);
        $this->workDone($this->item, '70');
        $this->paid($order, '90000');

        Sanctum::actingAs($this->supervisor);

        $response = $this->getJson('/api/v1/work-orders/'.$order->id.'/contractor-control');

        $this->assertNoMoney($response);
        $response->assertJsonPath('data.lines.0.work_pct', '70.00');
        $response->assertJsonPath('data.milestones.0.title', 'Plinth');
        $this->assertStringNotContainsString('25000', $response->getContent());
    }

    public function test_the_manager_gets_the_full_ratio(): void
    {
        $order = $this->contract();
        $this->workDone($this->item, '70');
        $this->paid($order, '90000');

        Sanctum::actingAs($this->manager);

        $this->getJson('/api/v1/projects/'.$this->project->id.'/contractor-control')
            ->assertOk()
            ->assertJsonPath('data.0.paid_pct', '90.00')
            ->assertJsonPath('data.0.work_pct', '70.00')
            ->assertJsonPath('data.0.gap_pct', '20.00')
            ->assertJsonPath('data.0.ratio_status', 'red')
            ->assertJsonPath('data.0.over_limit', true)
            ->assertJsonPath('data.0.label', 'Paid 90%, Work 70%')
            ->assertJsonPath('data.0.balance_money', '10000.00')
            ->assertJsonPath('data.0.balance_work_value', '30000.00');
    }

    public function test_a_supervisor_can_request_a_payment_and_gets_no_money_back(): void
    {
        $order = $this->contract();

        Sanctum::actingAs($this->supervisor);

        $response = $this->postJson('/api/v1/work-orders/'.$order->id.'/contractor-payments', [
            'paid_on' => '2026-11-01', 'amount' => 90000, 'mode' => 'upi', 'boq_item_id' => $this->item->id,
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.status', 'IN_REVIEW');
        $this->assertStringNotContainsString('90000', $response->getContent());
        $this->assertStringNotContainsString('"amount"', $response->getContent());

        $this->assertSame('90000.00', ContractorPayment::first()->amount);
    }

    public function test_a_request_can_carry_a_bill(): void
    {
        $order = $this->contract();
        Sanctum::actingAs($this->supervisor);

        $this->post('/api/v1/work-orders/'.$order->id.'/contractor-payments', [
            'paid_on' => '2026-11-01', 'amount' => 100, 'mode' => 'cash', 'bill' => UploadedFile::fake()->image('bill.jpg'),
        ], ['Accept' => 'application/json'])->assertCreated();

        Storage::assertExists(ContractorPayment::first()->bill_path);
    }

    public function test_a_repeated_client_uuid_returns_200_and_the_original(): void
    {
        $order = $this->contract();
        $uuid = (string) Str::uuid();
        Sanctum::actingAs($this->supervisor);
        $body = ['paid_on' => '2026-11-01', 'amount' => 100, 'mode' => 'cash', 'client_uuid' => $uuid];

        $first = $this->postJson('/api/v1/work-orders/'.$order->id.'/contractor-payments', $body)->assertCreated();
        $second = $this->postJson('/api/v1/work-orders/'.$order->id.'/contractor-payments', $body)->assertOk();

        $this->assertSame($first->json('data.id'), $second->json('data.id'));
        $this->assertSame(1, ContractorPayment::count());
    }

    public function test_validation_errors_come_back_as_422(): void
    {
        Sanctum::actingAs($this->supervisor);

        $this->postJson('/api/v1/work-orders/'.$this->contract()->id.'/contractor-payments', ['amount' => 0])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['paid_on', 'amount', 'mode']);
    }

    public function test_a_viewer_cannot_request_a_payment(): void
    {
        Sanctum::actingAs($this->member($this->organization, 'viewer'));

        $this->postJson('/api/v1/work-orders/'.$this->contract()->id.'/contractor-payments', [])->assertForbidden();
    }

    public function test_the_manager_sees_the_ratio_and_the_approval_id_when_deciding(): void
    {
        $order = $this->contract();
        $this->workDone($this->item, '70');
        $this->paid($order, '60000');
        $payment = $this->raise($order, '30000');

        Sanctum::actingAs($this->manager);

        $this->getJson('/api/v1/contractor-payments/'.$payment->id)
            ->assertOk()
            ->assertJsonPath('data.amount', '30000.00')
            ->assertJsonPath('data.approval_id', $payment->currentApproval->id)
            ->assertJsonPath('data.impact.now.label', 'Paid 60%, Work 70%')
            ->assertJsonPath('data.impact.after.label', 'Paid 90%, Work 70%');
    }

    public function test_the_manager_can_approve_on_the_existing_approvals_endpoint(): void
    {
        $order = $this->contract();
        $payment = $this->raise($order, '5000');

        Sanctum::actingAs($this->manager);

        $this->postJson('/api/v1/approvals/'.$payment->currentApproval->id.'/act', ['action' => 'approve'])->assertOk();

        $this->assertSame('APPROVED', $payment->refresh()->status);
    }

    public function test_a_supervisor_sees_their_own_request_without_money(): void
    {
        $order = $this->contract();
        $mine = $this->raise($order, '90000');
        $theirs = app(ContractorPaymentService::class)->raise($order, $this->paymentData(['amount' => '90000']), null, $this->manager);

        Sanctum::actingAs($this->supervisor);

        $this->assertNoMoney($this->getJson('/api/v1/contractor-payments/'.$mine->id));
        $this->getJson('/api/v1/contractor-payments/'.$theirs->id)->assertForbidden();

        $list = $this->getJson('/api/v1/projects/'.$this->project->id.'/contractor-payments');
        $this->assertNoMoney($list);
        $list->assertJsonCount(1, 'data');
    }

    public function test_the_manager_lists_every_payment_with_amounts(): void
    {
        $order = $this->contract();
        $this->raise($order, '100');
        app(ContractorPaymentService::class)->raise($order, $this->paymentData(['amount' => '200']), null, $this->manager);

        Sanctum::actingAs($this->manager);

        $this->getJson('/api/v1/projects/'.$this->project->id.'/contractor-payments')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.amount', '200.00');
    }

    public function test_a_bill_needs_money_view(): void
    {
        $payment = app(ContractorPaymentService::class)->raise($this->contract(), $this->paymentData(), UploadedFile::fake()->image('bill.jpg'), $this->supervisor);

        Sanctum::actingAs($this->supervisor);
        $this->getJson('/api/v1/contractor-payments/'.$payment->id.'/bill')->assertForbidden();

        Sanctum::actingAs($this->manager);
        $this->get('/api/v1/contractor-payments/'.$payment->id.'/bill')->assertOk();
    }

    public function test_another_company_gets_404(): void
    {
        $order = $this->contract();
        $payment = $this->raise($order, '100');

        Sanctum::actingAs($this->member($this->tenant('Other Co'), 'owner'));

        $this->getJson('/api/v1/projects/'.$this->project->id.'/contractor-control')->assertNotFound();
        $this->getJson('/api/v1/work-orders/'.$order->id.'/contractor-control')->assertNotFound();
        $this->getJson('/api/v1/contractor-payments/'.$payment->id)->assertNotFound();
        $this->postJson('/api/v1/work-orders/'.$order->id.'/contractor-payments', $this->paymentData())->assertNotFound();
    }

    public function test_the_free_plan_is_refused(): void
    {
        $free = $this->tenant('Small', 'free');
        $project = $this->makeProject($free);

        Sanctum::actingAs($this->member($free, 'owner'));

        $this->getJson('/api/v1/projects/'.$project->id.'/contractor-control')->assertForbidden();
    }
}
