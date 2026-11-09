<?php

namespace Tests\Feature\Notify;

use App\Models\BillingPayment;
use App\Models\Organization;
use App\Services\Notify\CompanyDeletionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\BuildsTenant;
use Tests\TestCase;

class CompanyDeletionRetentionTest extends TestCase
{
    use BuildsTenant, RefreshDatabase;

    public function test_paid_subscription_records_outlive_an_erased_company(): void
    {
        $organization = $this->tenant('Gone Co');
        $organization->forceFill(['gstin' => '27ABCDE1234F1Z5'])->save();
        $owner = $this->member($organization, 'owner');
        $this->actingAs($owner);

        foreach (['paid', 'created'] as $status) {
            $payment = BillingPayment::create([
                'organization_id' => $organization->id, 'user_id' => $owner->id, 'plan' => 'contractor', 'cycle' => 'monthly',
                'base_amount' => '2999.00', 'gst_amount' => '539.82', 'total_amount' => '3538.82',
            ]);
            $payment->forceFill(['status' => $status, 'gateway_payment_id' => 'pay_'.$status, 'paid_at' => $status === 'paid' ? now() : null])->save();
        }

        app(CompanyDeletionService::class)->request($organization, $owner, 'Gone Co');
        $this->travel(CompanyDeletionService::GRACE_DAYS + 1)->days();

        $this->assertSame(1, app(CompanyDeletionService::class)->purgeDue());

        $this->assertNull(Organization::find($organization->id));
        $this->assertSame(0, DB::table('billing_payments')->count());

        $kept = DB::table('retained_billing_records')->get();
        $this->assertCount(1, $kept);
        $this->assertSame('Gone Co', $kept[0]->company_name);
        $this->assertSame('27ABCDE1234F1Z5', $kept[0]->gstin);
        $this->assertSame('pay_paid', $kept[0]->gateway_payment_id);
        $this->assertEquals('3538.82', $kept[0]->total_amount);
    }
}
