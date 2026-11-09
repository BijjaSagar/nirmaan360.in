<?php

namespace Tests\Feature;

use App\Models\BillingPayment;
use App\Models\ContactRequest;
use App\Models\Organization;
use App\Models\User;
use App\Services\BillingService;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\BuildsTenant;
use Tests\TestCase;

class BillingTest extends TestCase
{
    use BuildsTenant, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function withKeys(): void
    {
        config([
            'services.razorpay.key_id' => 'rzp_test_key',
            'services.razorpay.key_secret' => 'test_secret',
            'services.razorpay.webhook_secret' => 'hook_secret',
        ]);
    }

    private function paidOrder(Organization $organization, User $owner, string $plan = 'contractor', string $cycle = 'monthly'): BillingPayment
    {
        $this->withKeys();
        Http::fake(['api.razorpay.com/*' => fn () => Http::response(['id' => 'order_'.uniqid()])]);

        return app(BillingService::class)->startCheckout($organization, $owner, $plan, $cycle);
    }

    private function signature(string $orderId, string $paymentId): string
    {
        return hash_hmac('sha256', $orderId.'|'.$paymentId, 'test_secret');
    }

    public function test_public_pages_load_and_pricing_shows_prices(): void
    {
        foreach (['/ai', '/contact', '/privacy', '/terms'] as $url) {
            $this->get($url)->assertOk();
        }

        $this->get('/pricing')->assertOk()->assertSee('₹2,999')->assertSee('₹79,990');
    }

    public function test_ai_is_never_presented_as_live(): void
    {
        $this->get('/ai')->assertOk()->assertSee('None of them are live yet');
    }

    public function test_contact_form_saves_and_ignores_bots(): void
    {
        $this->post('/contact', ['type' => 'sales', 'name' => 'Priya', 'email' => 'p@example.com'])->assertRedirect();
        $this->assertSame(1, ContactRequest::count());

        $this->post('/contact', ['type' => 'sales', 'name' => 'Bot', 'email' => 'b@example.com', 'website' => 'spam.test'])->assertRedirect();
        $this->assertSame(1, ContactRequest::count());
    }

    public function test_registration_uses_the_chosen_plan(): void
    {
        $this->post('/register', ['plan' => 'business', 'company' => 'Big Co', 'name' => 'A', 'email' => 'a@example.com', 'password' => 'a-long-password', 'password_confirmation' => 'a-long-password']);
        $organization = User::where('email', 'a@example.com')->first()->organization;
        $this->assertSame('business', $organization->plan);
        $this->assertSame('trial', $organization->plan_status);

        $this->post('/logout');

        $this->post('/register', ['plan' => 'free', 'company' => 'Small Co', 'name' => 'B', 'email' => 'b@example.com', 'password' => 'a-long-password', 'password_confirmation' => 'a-long-password']);
        $organization = User::where('email', 'b@example.com')->first()->organization;
        $this->assertSame('free', $organization->plan);
        $this->assertSame('active', $organization->plan_status);
        $this->assertNull($organization->trial_ends_at);
    }

    public function test_enterprise_cannot_be_chosen_at_registration(): void
    {
        $this->post('/register', ['plan' => 'enterprise', 'company' => 'X', 'name' => 'A', 'email' => 'a@example.com', 'password' => 'a-long-password', 'password_confirmation' => 'a-long-password'])
            ->assertSessionHasErrors('plan');
    }

    public function test_price_can_already_include_gst_and_parts_add_back(): void
    {
        config(['buildos.subscription_gst_mode' => 'included']);

        $this->assertSame(['base' => '2541.53', 'gst' => '457.47', 'total' => '2999.00'], app(BillingService::class)->quote('contractor', 'monthly'));
    }

    public function test_plan_can_be_sold_without_gst(): void
    {
        config(['buildos.subscription_gst_mode' => 'none']);

        $this->assertSame(['base' => '2999.00', 'gst' => '0.00', 'total' => '2999.00'], app(BillingService::class)->quote('contractor', 'monthly'));
    }

    public function test_quote_adds_gst_exactly(): void
    {
        $this->assertSame(['base' => '2999.00', 'gst' => '539.82', 'total' => '3538.82'], app(BillingService::class)->quote('contractor', 'monthly'));
    }

    public function test_checkout_is_blocked_until_keys_are_set(): void
    {
        $organization = $this->tenant();
        $this->actingAs($this->member($organization, 'owner'));

        $this->post('/billing/checkout', ['plan' => 'contractor', 'cycle' => 'monthly'])->assertSessionHasErrors('plan');
        $this->assertSame(0, BillingPayment::count());
    }

    public function test_checkout_creates_an_order_for_the_right_amount(): void
    {
        $organization = $this->tenant();
        $this->actingAs($this->member($organization, 'owner'));
        $this->withKeys();
        Http::fake(['api.razorpay.com/*' => Http::response(['id' => 'order_abc'])]);

        $this->post('/billing/checkout', ['plan' => 'contractor', 'cycle' => 'monthly'])->assertOk()->assertSee('order_abc');

        Http::assertSent(fn ($request) => $request['amount'] === 353882 && $request['currency'] === 'INR');
        $this->assertSame('order_abc', BillingPayment::first()->gateway_order_id);
    }

    public function test_free_and_enterprise_cannot_be_bought_online(): void
    {
        $organization = $this->tenant();
        $this->actingAs($this->member($organization, 'owner'));
        $this->withKeys();

        $this->post('/billing/checkout', ['plan' => 'enterprise', 'cycle' => 'monthly'])->assertSessionHasErrors('plan');
        $this->post('/billing/checkout', ['plan' => 'free', 'cycle' => 'monthly'])->assertSessionHasErrors('plan');
    }

    public function test_valid_signature_activates_the_plan_once(): void
    {
        $organization = $this->tenant('Acme', 'free');
        $owner = $this->member($organization, 'owner');
        $this->actingAs($owner);
        $payment = $this->paidOrder($organization, $owner);
        $fields = ['razorpay_order_id' => $payment->gateway_order_id, 'razorpay_payment_id' => 'pay_1', 'razorpay_signature' => $this->signature($payment->gateway_order_id, 'pay_1')];

        $this->post('/billing/confirm', $fields)->assertRedirect('/billing');

        $organization->refresh();
        $this->assertSame('contractor', $organization->plan);
        $this->assertSame('active', $organization->plan_status);
        $until = $organization->paid_until->toDateTimeString();

        $this->post('/billing/confirm', $fields)->assertRedirect('/billing');
        $this->assertSame($until, $organization->fresh()->paid_until->toDateTimeString(), 'A repeat must not add more time');
    }

    public function test_forged_signature_does_not_activate(): void
    {
        $organization = $this->tenant('Acme', 'free');
        $owner = $this->member($organization, 'owner');
        $this->actingAs($owner);
        $payment = $this->paidOrder($organization, $owner);

        $this->post('/billing/confirm', ['razorpay_order_id' => $payment->gateway_order_id, 'razorpay_payment_id' => 'pay_1', 'razorpay_signature' => 'forged'])->assertSessionHasErrors('payment');

        $this->assertSame('free', $organization->fresh()->plan);
        $this->assertSame('created', $payment->fresh()->status);
    }

    public function test_one_company_cannot_confirm_anothers_order(): void
    {
        $first = $this->tenant('First');
        $owner = $this->member($first, 'owner');
        $this->actingAs($owner);
        $payment = $this->paidOrder($first, $owner);

        $this->actingAs($this->member($this->tenant('Second'), 'owner'));

        $this->post('/billing/confirm', ['razorpay_order_id' => $payment->gateway_order_id, 'razorpay_payment_id' => 'pay_1', 'razorpay_signature' => $this->signature($payment->gateway_order_id, 'pay_1')])->assertNotFound();
    }

    public function test_webhook_activates_and_checks_signature_and_amount(): void
    {
        $organization = $this->tenant('Acme', 'free');
        $owner = $this->member($organization, 'owner');
        $this->actingAs($owner);
        $payment = $this->paidOrder($organization, $owner);
        $this->app['auth']->forgetGuards();

        $send = function (array $body, ?string $signature = null) {
            $raw = json_encode($body);

            return $this->call('POST', '/api/v1/webhooks/razorpay', [], [], [], [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_X_RAZORPAY_SIGNATURE' => $signature ?? hash_hmac('sha256', $raw, 'hook_secret'),
            ], $raw);
        };
        $event = fn (int $amount) => ['event' => 'payment.captured', 'payload' => ['payment' => ['entity' => ['id' => 'pay_9', 'order_id' => $payment->gateway_order_id, 'amount' => $amount]]]];

        $send($event(353882), 'wrong')->assertStatus(400);
        $send($event(100))->assertStatus(422);
        $this->assertSame('created', $payment->fresh()->status);

        $send($event(353882))->assertOk();
        $this->assertSame('paid', $payment->fresh()->status);
        $this->assertSame('contractor', $organization->fresh()->plan);
    }

    public function test_renewing_the_same_plan_adds_to_the_time_left(): void
    {
        $organization = $this->tenant('Acme', 'free');
        $owner = $this->member($organization, 'owner');
        $this->actingAs($owner);
        $billing = app(BillingService::class);

        $billing->markPaid($this->paidOrder($organization, $owner), 'pay_1');
        $first = $organization->fresh()->paid_until;

        $billing->markPaid($this->paidOrder($organization->fresh(), $owner), 'pay_2');
        $this->assertTrue($organization->fresh()->paid_until->equalTo($first->copy()->addMonthNoOverflow()));
    }

    public function test_lapsed_plan_falls_back_to_free_after_grace_days(): void
    {
        $organization = $this->tenant('Acme', 'business');

        $organization->update(['paid_until' => now()->subDays(2)]);
        $this->assertSame('business', $organization->fresh()->effectivePlan());

        $organization->update(['paid_until' => now()->subDays(4)]);
        $this->assertSame('free', $organization->fresh()->effectivePlan());
    }

    public function test_only_company_admins_reach_billing(): void
    {
        $organization = $this->tenant();

        $this->actingAs($this->member($organization, 'engineer'))->get('/billing')->assertForbidden();
        $this->actingAs($this->member($organization, 'owner'))->get('/billing')->assertOk();
    }

    public function test_platform_admin_is_separate_from_company_owner(): void
    {
        $organization = $this->tenant();
        $owner = $this->member($organization, 'owner');

        $this->actingAs($owner)->get('/admin')->assertNotFound();
        $this->patch("/admin/organizations/{$organization->id}", ['plan' => 'enterprise', 'plan_status' => 'active'])->assertNotFound();
        $this->assertSame('business', $organization->fresh()->plan);

        config(['buildos.platform_admins' => [strtolower($owner->email)]]);

        $this->get('/admin')->assertOk();
        $this->patch("/admin/organizations/{$organization->id}", ['plan' => 'enterprise', 'plan_status' => 'active'])->assertRedirect();
        $this->assertSame('enterprise', $organization->fresh()->plan);
    }

    public function test_password_can_be_reset_with_the_emailed_token(): void
    {
        Notification::fake();
        $user = $this->member($this->tenant(), 'owner');

        $this->post('/forgot-password', ['email' => $user->email])->assertRedirect();

        Notification::assertSentTo($user, ResetPassword::class, function ($notification) use ($user) {
            $this->post('/reset-password', ['token' => $notification->token, 'email' => $user->email, 'password' => 'brand-new-password', 'password_confirmation' => 'brand-new-password'])->assertRedirect('/login');

            return Hash::check('brand-new-password', $user->fresh()->password);
        });
    }

    public function test_forgot_password_does_not_reveal_who_is_registered(): void
    {
        $this->post('/forgot-password', ['email' => 'nobody@example.com'])->assertRedirect()->assertSessionHas('success');
    }

    public function test_reset_is_hidden_in_production_without_a_real_mailer(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        config(['mail.default' => 'log']);

        $this->get('/forgot-password')->assertNotFound();
    }
}
