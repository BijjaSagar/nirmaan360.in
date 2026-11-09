<?php

namespace Tests\Feature\SiteAlerts;

use App\Models\Party;
use App\Models\Project;
use App\Models\WorkOrder;
use App\Services\SiteAlerts\Spend\SubcontractorBillSpend;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\BuildsTenant;
use Tests\TestCase;

class ContractorSpendTest extends TestCase
{
    use BuildsTenant, RefreshDatabase;

    private $organization;

    private Project $project;

    private WorkOrder $order;

    protected function setUp(): void
    {
        parent::setUp();
        $this->organization = $this->tenant();
        $this->actingAs($this->member($this->organization, 'owner'));
        $this->project = $this->makeProject($this->organization);
        $this->order = $this->contract($this->project);
    }

    private function contract(Project $project): WorkOrder
    {
        $party = Party::create(['organization_id' => $this->organization->id, 'type' => 'subcontractor', 'name' => 'Contractor '.Str::random(4)]);

        return WorkOrder::create([
            'organization_id' => $this->organization->id, 'project_id' => $project->id, 'party_id' => $party->id,
            'number' => 'WO-'.Str::random(5), 'title' => 'Flooring', 'status' => 'ACTIVE',
        ]);
    }

    private function payment(WorkOrder $order, string $amount, string $status = 'APPROVED'): void
    {
        DB::table('contractor_payments')->insert([
            'organization_id' => $this->organization->id, 'project_id' => $order->project_id, 'work_order_id' => $order->id,
            'party_id' => $order->party_id, 'number' => 'P-'.Str::random(6), 'paid_on' => '2026-11-01', 'amount' => $amount,
            'mode' => 'bank', 'paid_to' => 'Contractor', 'status' => $status, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function bill(WorkOrder $order, string $gross, string $status = 'APPROVED'): void
    {
        DB::table('ra_bills')->insert([
            'organization_id' => $this->organization->id, 'project_id' => $order->project_id, 'work_order_id' => $order->id,
            'party_id' => $order->party_id, 'type' => 'subcontractor', 'bill_no' => random_int(1, 99999), 'number' => 'SB-'.Str::random(6),
            'period_from' => '2026-11-01', 'period_to' => '2026-11-30', 'status' => $status, 'gross_amount' => $gross,
            'deduction_total' => '0', 'gst_amount' => '0', 'net_payable' => $gross, 'terms' => '{}', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function spent(): string
    {
        return (string) app(SubcontractorBillSpend::class)->spent($this->project);
    }

    public function test_payments_count_when_there_are_no_bills(): void
    {
        $this->payment($this->order, '90000.00');

        $this->assertSame('90000.00', $this->spent());
    }

    public function test_only_approved_payments_count(): void
    {
        $this->payment($this->order, '50000.00');
        $this->payment($this->order, '30000.00', 'IN_REVIEW');
        $this->payment($this->order, '20000.00', 'REJECTED');

        $this->assertSame('50000.00', $this->spent());
    }

    public function test_a_contract_both_paid_and_billed_counts_once_at_the_larger_figure(): void
    {
        $this->payment($this->order, '90000.00');
        $this->bill($this->order, '70000.00');

        $this->assertSame('90000.00', $this->spent());

        $this->bill($this->order, '40000.00');

        $this->assertSame('110000.00', $this->spent());
    }

    public function test_each_contract_is_counted_separately(): void
    {
        $second = $this->contract($this->project);
        $this->payment($this->order, '60000.00');
        $this->bill($second, '25000.00');

        $this->assertSame('85000.00', $this->spent());
    }

    public function test_a_reversal_cancels_a_payment_and_other_projects_are_left_out(): void
    {
        $this->payment($this->order, '60000.00');
        $this->payment($this->order, '-10000.00');
        $this->payment($this->contract($this->makeProject($this->organization)), '99999.00');

        $this->assertSame('50000.00', $this->spent());
    }
}
