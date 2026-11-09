<?php

namespace Tests\Feature\Finance;

use App\Models\ClientReceipt;
use App\Models\Organization;
use App\Models\Party;
use App\Models\PettyCashEntry;
use App\Models\PettyCashWallet;
use App\Models\Project;
use App\Models\RaBill;
use App\Models\User;
use App\Models\VendorBill;
use App\Models\VendorPayment;
use App\Models\WorkOrder;
use App\Services\ApprovalService;
use App\Services\Finance\SettlementService;
use App\Services\Finance\VendorBillService;
use App\Services\RaBillService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\BuildsTenant;
use Tests\TestCase;

abstract class FinanceTestCase extends TestCase
{
    use BuildsTenant, RefreshDatabase;

    protected Organization $organization;

    protected User $owner;

    protected User $accounts;

    protected User $pm;

    protected Project $project;

    protected Party $client;

    protected Party $vendor;

    protected Party $subcontractor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Carbon::setTestNow('2026-11-20 10:00:00');

        $this->organization = $this->tenant();
        $this->organization->update(['gstin' => '27AAACA1234A1Z5', 'state' => 'Maharashtra', 'address' => '12 Test Road, Pune']);
        $this->owner = $this->member($this->organization, 'owner');
        $this->accounts = $this->member($this->organization, 'accounts');
        $this->pm = $this->member($this->organization, 'pm');
        $this->actingAs($this->owner);

        $this->client = $this->party('client', 'Metro Developers', ['gstin' => '27AABCM1234D1Z2', 'pan' => 'AABCM1234D']);
        $this->vendor = $this->party('vendor', 'Sai Steel & Cement', ['gstin' => '27AAFCS5678E1Z9', 'pan' => 'AAFCS5678E']);
        $this->subcontractor = $this->party('subcontractor', 'Shree Formwork', ['pan' => 'AAQFS9012G']);
        $this->project = $this->projectIn('Maharashtra', $this->client);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    protected function party(string $type, string $name, array $attributes = []): Party
    {
        return Party::create(['organization_id' => $this->organization->id, 'type' => $type, 'name' => $name, ...$attributes]);
    }

    protected function projectIn(string $state, ?Party $client): Project
    {
        return $this->makeProject($this->organization, [
            'state' => $state,
            'client_id' => $client?->id,
            'retention_pct' => '5',
            'tds_pct' => '2',
            'gst_pct' => '18',
        ]);
    }

    /** ₹2,50,000 gross. Retention 12,500, TDS 5,000, GST 45,000, so net payable is 2,77,500. */
    protected function approvedClientBill(?Project $project = null): RaBill
    {
        $project ??= $this->project;
        $item = $this->makeBoqItem($project);
        $this->approveEntry($this->draftEntry($project, $item, $this->pm), $this->pm, $this->owner);

        return $this->approveDocument(app(RaBillService::class)->generate($project, 'client', '2026-11-01', '2026-11-30', $this->owner));
    }

    /** ₹60,000 gross. Retention 6,000, TDS 600, GST 10,800, so net payable is 64,200. */
    protected function approvedSubBill(): RaBill
    {
        $item = $this->makeBoqItem($this->project);
        $workOrder = WorkOrder::create([
            'organization_id' => $this->organization->id,
            'project_id' => $this->project->id,
            'party_id' => $this->subcontractor->id,
            'number' => 'WO-'.$item->id,
            'title' => 'Slab casting',
            'retention_pct' => '10',
            'tds_pct' => '1',
            'gst_pct' => '18',
        ]);
        $workOrder->items()->create(['organization_id' => $this->organization->id, 'boq_item_id' => $item->id, 'qty' => '100', 'rate' => '1200']);
        $this->approveEntry($this->draftEntry($this->project, $item, $this->pm, ['work_order_id' => $workOrder->id]), $this->pm, $this->owner);

        return $this->approveDocument(app(RaBillService::class)->generate($this->project, 'subcontractor', '2026-11-01', '2026-11-30', $this->owner, $workOrder));
    }

    protected function billData(array $overrides = []): array
    {
        return [
            'party_id' => $this->vendor->id,
            'bill_no' => 'SSC-101',
            'bill_date' => '2026-11-05',
            'due_date' => '2026-12-05',
            'cost_head' => 'Materials',
            'supply_type' => 'intra',
            'taxable_amount' => '100000',
            'gst_pct' => '18',
            'tds_section' => '194C',
            'tds_pct' => '1',
            ...$overrides,
        ];
    }

    protected function draftVendorBill(array $overrides = []): VendorBill
    {
        return app(VendorBillService::class)->create($this->project, $this->billData($overrides), $this->pm);
    }

    /** ₹1,00,000 taxable. GST 18,000, total 1,18,000, TDS 1,000, net payable 1,17,000. */
    protected function approvedVendorBill(array $overrides = []): VendorBill
    {
        $bill = $this->draftVendorBill($overrides);
        $approval = app(VendorBillService::class)->submit($bill, $this->pm);

        while ($approval->status === 'IN_REVIEW') {
            $approval = app(ApprovalService::class)->act($approval, $this->owner, 'approve');
        }

        return $bill->refresh();
    }

    protected function approveDocument(RaBill $bill): RaBill
    {
        $approval = app(ApprovalService::class)->submit($bill, $this->pm);

        while ($approval->status === 'IN_REVIEW') {
            $approval = app(ApprovalService::class)->act($approval, $this->owner, 'approve');
        }

        return $bill->refresh();
    }

    protected function pay(VendorBill $bill, string $amount, string $on = '2026-11-15'): VendorPayment
    {
        return app(SettlementService::class)->payVendorBill($bill, ['paid_on' => $on, 'amount' => $amount, 'mode' => 'bank'], $this->accounts);
    }

    protected function paySub(RaBill $bill, string $amount, string $on = '2026-11-15'): VendorPayment
    {
        return app(SettlementService::class)->paySubcontractorBill($bill, ['paid_on' => $on, 'amount' => $amount, 'mode' => 'bank'], $this->accounts);
    }

    protected function receive(RaBill $bill, string $amount, string $tds = '0', string $on = '2026-11-16'): ClientReceipt
    {
        return app(SettlementService::class)->recordReceipt($bill, ['received_on' => $on, 'amount' => $amount, 'tds_amount' => $tds, 'mode' => 'bank'], $this->accounts);
    }

    protected function wallet(?Project $project = null): PettyCashWallet
    {
        return PettyCashWallet::create(['organization_id' => $this->organization->id, 'project_id' => ($project ?? $this->project)->id, 'name' => 'Site cash']);
    }

    protected function petty(PettyCashWallet $wallet, string $type, string $amount, ?string $category = null, string $on = '2026-11-10'): PettyCashEntry
    {
        return PettyCashEntry::create(['organization_id' => $this->organization->id, 'petty_cash_wallet_id' => $wallet->id, 'type' => $type, 'amount' => $amount, 'category' => $category, 'entry_date' => $on]);
    }
}
