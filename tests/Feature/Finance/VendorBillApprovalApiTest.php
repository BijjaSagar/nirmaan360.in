<?php

namespace Tests\Feature\Finance;

use App\Models\CustomRole;
use App\Models\User;
use App\Models\VendorBill;
use App\Services\Finance\VendorBillService;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;

/** A vendor bill in the phone's approvals inbox: GET /approvals and POST /approvals/{id}/act. */
class VendorBillApprovalApiTest extends FinanceTestCase
{
    private const MONEY_KEYS = ['description', 'remarks', 'taxable_amount', 'gst_amount', 'total_amount', 'tds_amount', 'net_payable'];

    private function waiting(): VendorBill
    {
        $bill = $this->draftVendorBill(['description' => 'Cement 200 bags at 380', 'remarks' => 'Rate as per PO']);
        app(VendorBillService::class)->submit($bill, $this->accounts);

        return $bill->refresh();
    }

    private function inbox(User $user): TestResponse
    {
        Sanctum::actingAs($user);

        return $this->getJson('/api/v1/approvals')->assertOk();
    }

    public function test_the_project_manager_sees_the_bill_with_its_figures(): void
    {
        $bill = $this->waiting();

        $row = $this->inbox($this->pm)->json('data.0');
        $doc = $row['document'];

        $this->assertSame('vendor_bill', $row['doc_type']);
        $this->assertSame(['pm', 'accounts'], $row['levels']);
        $this->assertSame($bill->id, $doc['id']);
        $this->assertSame('SSC-101', $doc['number']);
        $this->assertSame('Sai Steel & Cement', $doc['party_name']);
        $this->assertSame('2026-11-05', $doc['bill_date']);
        $this->assertSame('2026-12-05', $doc['due_date']);
        $this->assertSame('Materials', $doc['cost_head']);
        $this->assertSame('intra', $doc['supply_type']);
        $this->assertSame('18.00', $doc['gst_pct']);
        $this->assertSame('194C', $doc['tds_section']);
        $this->assertSame('100000.00', $doc['taxable_amount']);
        $this->assertSame('18000.00', $doc['gst_amount']);
        $this->assertSame('118000.00', $doc['total_amount']);
        $this->assertSame('1000.00', $doc['tds_amount']);
        $this->assertSame('117000.00', $doc['net_payable']);
        $this->assertSame('Cement 200 bags at 380', $doc['description']);
    }

    public function test_an_approver_without_money_view_gets_null_figures(): void
    {
        $this->waiting();
        $role = CustomRole::create([
            'organization_id' => $this->organization->id, 'name' => 'Site head', 'approval_role' => 'pm',
            'permissions' => ['project.view', 'finance.view', 'vendor_bill.approve'], 'created_by' => $this->owner->id,
        ]);
        $approver = $this->member($this->organization, 'pm');
        $approver->forceFill(['custom_role_id' => $role->id])->save();

        $response = $this->inbox($approver->refresh());
        $doc = $response->json('data.0.document');

        $this->assertSame('SSC-101', $doc['number']);
        foreach (self::MONEY_KEYS as $key) {
            $this->assertArrayHasKey($key, $doc);
            $this->assertNull($doc[$key], "{$key} went to someone without money.view.");
        }
        foreach (['100000', '117000', '118000', '380'] as $figure) {
            $this->assertStringNotContainsString($figure, $response->getContent());
        }
    }

    public function test_the_manager_rejects_it_with_a_remark(): void
    {
        $bill = $this->waiting();
        Sanctum::actingAs($this->pm);

        $this->postJson('/api/v1/approvals/'.$bill->currentApproval->id.'/act', ['action' => 'reject'])
            ->assertUnprocessable()->assertJsonValidationErrors('remark');
        $this->postJson('/api/v1/approvals/'.$bill->currentApproval->id.'/act', ['action' => 'reject', 'remark' => 'Duplicate of SSC-99', 'client_uuid' => (string) Str::uuid()])
            ->assertOk()->assertJsonPath('data.status', 'REJECTED');

        $this->assertSame('REJECTED', $bill->fresh()->status);
    }

    public function test_another_company_never_sees_the_bill(): void
    {
        $approvalId = $this->waiting()->currentApproval->id;
        $outsider = $this->member($this->tenant('Other Co'), 'owner');

        $this->inbox($outsider)->assertJsonCount(0, 'data');
        $this->postJson('/api/v1/approvals/'.$approvalId.'/act', ['action' => 'approve'])->assertNotFound();
    }
}
