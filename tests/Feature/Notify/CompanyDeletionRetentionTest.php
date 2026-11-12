<?php

namespace Tests\Feature\Notify;

use App\Models\BillingPayment;
use App\Models\Organization;
use App\Services\Notify\CompanyDeletionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
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
        $this->assertSame('subscription', $kept[0]->source);
    }

    public function test_paid_ai_credit_packs_outlive_an_erased_company_too(): void
    {
        $organization = $this->tenant('Gone Co');
        $organization->forceFill(['gstin' => '27ABCDE1234F1Z5'])->save();
        $owner = $this->member($organization, 'owner');
        $paidAt = now()->subDays(3)->startOfSecond();
        $topups = [];

        foreach (['paid' => 'medium', 'created' => 'small'] as $status => $pack) {
            $topups[$status] = DB::table('credit_topups')->insertGetId([
                'organization_id' => $organization->id, 'user_id' => $owner->id, 'pack' => $pack, 'credits' => '2000.0000',
                'base_amount' => '1900.00', 'gst_amount' => '342.00', 'total_amount' => '2242.00', 'currency' => 'INR',
                'gateway_order_id' => 'order_'.$status, 'gateway_payment_id' => $status === 'paid' ? 'pay_credits' : null,
                'status' => $status, 'paid_at' => $status === 'paid' ? $paidAt : null, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        app(CompanyDeletionService::class)->request($organization, $owner, 'Gone Co');
        $this->travel(CompanyDeletionService::GRACE_DAYS + 1)->days();

        $this->assertSame(1, app(CompanyDeletionService::class)->purgeDue());
        $this->assertSame(0, DB::table('credit_topups')->count());

        $kept = DB::table('retained_billing_records')->sole();
        $this->assertSame('ai_credits', $kept->source);
        $this->assertEquals($topups['paid'], $kept->original_payment_id);
        $this->assertSame('Gone Co', $kept->company_name);
        $this->assertSame('27ABCDE1234F1Z5', $kept->gstin);
        $this->assertSame('medium', $kept->plan);
        $this->assertSame('one_off', $kept->cycle);
        $this->assertEquals('1900.00', $kept->base_amount);
        $this->assertEquals('342.00', $kept->gst_amount);
        $this->assertEquals('2242.00', $kept->total_amount);
        $this->assertSame('INR', $kept->currency);
        $this->assertSame('order_paid', $kept->gateway_order_id);
        $this->assertSame('pay_credits', $kept->gateway_payment_id);
        $this->assertSame($paidAt->toDateTimeString(), Carbon::parse($kept->paid_at)->toDateTimeString());
    }

    public function test_the_retained_source_column_rolls_back(): void
    {
        $migration = require database_path('migrations/2027_01_04_100002_add_source_to_retained_billing_records.php');

        $migration->down();
        $this->assertFalse(Schema::hasColumn('retained_billing_records', 'source'));

        $migration->up();
        $this->assertTrue(Schema::hasColumn('retained_billing_records', 'source'));
    }
}
