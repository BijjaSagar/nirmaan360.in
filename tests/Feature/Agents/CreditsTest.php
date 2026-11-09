<?php

namespace Tests\Feature\Agents;

use App\Models\AgentCreditLedger;
use App\Models\AgentCreditTopup;
use App\Models\AgentCreditWallet;
use App\Models\AgentRun;
use App\Services\Agents\CreditService;
use App\Support\Dec;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

/** E5-07, with exact-decimal checks on every balance. */
class CreditsTest extends AgentTestCase
{
    private function razorpay(): void
    {
        config(['services.razorpay.key_id' => 'rzp_test_key', 'services.razorpay.key_secret' => 'rzp_test_secret', 'services.razorpay.webhook_secret' => 'hook_secret']);
    }

    private function fakeOrder(string $id = 'order_abc'): void
    {
        Http::fake(['api.razorpay.com/*' => Http::response(['id' => $id])]);
    }

    private function signature(string $orderId, string $paymentId): string
    {
        return hash_hmac('sha256', $orderId.'|'.$paymentId, 'rzp_test_secret');
    }

    private function wallet($organization): AgentCreditWallet
    {
        return app(CreditService::class)->wallet($organization);
    }

    public function test_each_plan_gets_its_monthly_allowance_once(): void
    {
        $business = $this->tenant('Big Co', 'business');
        $enterprise = $this->tenant('Bigger Co', 'enterprise');
        $service = app(CreditService::class);

        $this->assertSame('500.0000', $service->wallet($business)->available());
        $this->assertSame('2000.0000', $service->wallet($enterprise)->available());

        $service->wallet($business);
        $this->assertSame(1, AgentCreditLedger::withoutGlobalScopes()->where('organization_id', $business->id)->where('type', 'included_grant')->count());
    }

    public function test_unused_included_credits_lapse_but_bought_credits_stay(): void
    {
        $organization = $this->tenant();
        $service = app(CreditService::class);
        Carbon::setTestNow('2026-11-15 12:00:00');
        $service->wallet($organization);
        $this->setCredits($organization, '120.0000', '75.0000');

        Carbon::setTestNow('2026-12-02 12:00:00');
        $wallet = $service->wallet($organization);
        Carbon::setTestNow();

        $this->assertSame('500.0000', $wallet->included_balance);
        $this->assertSame('75.0000', $wallet->purchased_balance);
        $this->assertSame('-120.0000', AgentCreditLedger::withoutGlobalScopes()->where('type', 'included_lapsed')->value('credits'));
    }

    public function test_month_turns_over_on_indian_time(): void
    {
        $organization = $this->tenant();
        $service = app(CreditService::class);

        // 20:00 UTC on 30 November is already 1 December in India.
        Carbon::setTestNow(Carbon::parse('2026-11-30 20:00:00', 'UTC'));
        $service->wallet($organization);
        Carbon::setTestNow();

        $this->assertSame('2026-12', $this->wallet($organization)->included_period);
    }

    public function test_included_credits_are_used_before_bought_ones(): void
    {
        $organization = $this->tenant();
        $this->connect();
        $this->setCredits($organization, '0.5000', '10.0000');
        $this->fakeClaude([$this->finalAnswer('ok')]);

        $run = $this->runAgent('project_brain', $this->member($organization, 'owner'));

        // 1000 in + 100 out on Sonnet = $0.003 = 0.3510 credits, then 0.0010 for the chat title.
        $this->assertSame('COMPLETED', $run->status);
        $wallet = $this->wallet($organization);
        $this->assertSame('0.1480', $wallet->included_balance);
        $this->assertSame('10.0000', $wallet->purchased_balance);
    }

    public function test_a_charge_spills_from_included_into_bought(): void
    {
        $organization = $this->tenant();
        $this->connect();
        $this->setCredits($organization, '0.1000', '10.0000');
        $this->fakeClaude([$this->finalAnswer('ok')]);

        $this->runAgent('project_brain', $this->member($organization, 'owner'));

        $wallet = $this->wallet($organization);
        $this->assertSame('0.0000', $wallet->included_balance);
        $this->assertTrue(Dec::of($wallet->purchased_balance)->isLessThan('10.0000'));
    }

    public function test_ledger_always_matches_the_wallet(): void
    {
        $organization = $this->tenant();
        $this->connect();
        $this->fakeClaude([$this->finalAnswer('ok')]);
        $owner = $this->member($organization, 'owner');

        $this->runAgent('project_brain', $owner);
        $this->runAgent('project_brain', $owner);

        $total = AgentCreditLedger::withoutGlobalScopes()->where('organization_id', $organization->id)->get()
            ->reduce(fn ($sum, $row) => $sum->plus(Dec::of($row->credits)), Dec::of(0));

        $this->assertSame($this->wallet($organization)->available(), (string) $total->toScale(4));
    }

    public function test_wallet_never_goes_negative(): void
    {
        $organization = $this->tenant();
        $this->connect();
        $this->setCredits($organization, '0.0100');
        $this->fakeClaude([$this->finalAnswer('ok')]);

        $this->runAgent('project_brain', $this->member($organization, 'owner'));

        $this->assertSame('0.0000', $this->wallet($organization)->available());
    }

    public function test_no_credits_means_no_run(): void
    {
        $organization = $this->tenant();
        $this->connect();
        $this->setCredits($organization, '0');
        Http::fake();

        $this->actingAs($this->member($organization, 'owner'))
            ->post('/agents/project_brain/chat', ['message' => 'hello'])
            ->assertSessionHasErrors('message');

        $this->assertSame(0, AgentRun::count());
        Http::assertNothingSent();
    }

    public function test_quote_follows_the_platform_gst_setting(): void
    {
        $service = app(CreditService::class);

        $this->assertSame(['base' => '500.00', 'gst' => '90.00', 'total' => '590.00', 'credits' => 500], $service->quote('small'));

        config(['buildos.subscription_gst_mode' => 'included']);
        $this->assertSame(['base' => '423.73', 'gst' => '76.27', 'total' => '500.00', 'credits' => 500], $service->quote('small'));

        config(['buildos.subscription_gst_mode' => 'none']);
        $this->assertSame(['base' => '500.00', 'gst' => '0.00', 'total' => '500.00', 'credits' => 500], $service->quote('small'));
    }

    public function test_buying_credits_goes_through_razorpay_and_adds_them(): void
    {
        $organization = $this->tenant();
        $this->connect();
        $this->razorpay();
        $this->fakeOrder();
        $owner = $this->member($organization, 'owner');

        $this->actingAs($owner)->post('/agent-credits/checkout', ['pack' => 'small'])->assertOk()->assertSee('order_abc');

        $topup = AgentCreditTopup::sole();
        $this->assertSame('created', $topup->status);
        $this->assertSame('59000', $topup->paise());

        Http::assertSent(fn ($request) => $request->url() === 'https://api.razorpay.com/v1/orders' && $request['amount'] === 59000);

        $this->post('/agent-credits/confirm', [
            'razorpay_order_id' => 'order_abc',
            'razorpay_payment_id' => 'pay_1',
            'razorpay_signature' => $this->signature('order_abc', 'pay_1'),
        ])->assertRedirect('/agent-credits');

        $this->assertSame('paid', $topup->refresh()->status);
        $this->assertSame('500.0000', $this->wallet($organization)->purchased_balance);
    }

    public function test_a_forged_payment_adds_nothing(): void
    {
        $organization = $this->tenant();
        $this->connect();
        $this->razorpay();
        $this->fakeOrder();
        $owner = $this->member($organization, 'owner');
        $this->actingAs($owner)->post('/agent-credits/checkout', ['pack' => 'small']);

        $this->post('/agent-credits/confirm', ['razorpay_order_id' => 'order_abc', 'razorpay_payment_id' => 'pay_1', 'razorpay_signature' => 'wrong'])
            ->assertSessionHasErrors('payment');

        $this->assertSame('created', AgentCreditTopup::sole()->status);
        $this->assertSame('0.0000', $this->wallet($organization)->purchased_balance);
    }

    public function test_browser_and_webhook_reporting_one_payment_credit_it_once(): void
    {
        $organization = $this->tenant();
        $this->connect();
        $this->razorpay();
        $this->fakeOrder();
        $owner = $this->member($organization, 'owner');
        $this->actingAs($owner)->post('/agent-credits/checkout', ['pack' => 'small']);

        $this->post('/agent-credits/confirm', ['razorpay_order_id' => 'order_abc', 'razorpay_payment_id' => 'pay_1', 'razorpay_signature' => $this->signature('order_abc', 'pay_1')]);

        $payload = json_encode(['event' => 'payment.captured', 'payload' => ['payment' => ['entity' => ['id' => 'pay_1', 'order_id' => 'order_abc', 'amount' => 59000]]]]);

        auth()->logout();
        $this->call('POST', '/api/v1/webhooks/razorpay', [], [], [], ['HTTP_X-Razorpay-Signature' => hash_hmac('sha256', $payload, 'hook_secret'), 'CONTENT_TYPE' => 'application/json'], $payload)
            ->assertOk()
            ->assertJson(['status' => 'ok']);

        $this->assertSame('500.0000', $this->wallet($organization)->purchased_balance);
        $this->assertSame(1, AgentCreditLedger::withoutGlobalScopes()->where('type', 'topup')->count());
    }

    public function test_webhook_alone_adds_the_credits(): void
    {
        $organization = $this->tenant();
        $this->connect();
        $this->razorpay();
        $this->fakeOrder('order_web');
        $this->actingAs($this->member($organization, 'owner'))->post('/agent-credits/checkout', ['pack' => 'small']);
        auth()->logout();

        $payload = json_encode(['event' => 'payment.captured', 'payload' => ['payment' => ['entity' => ['id' => 'pay_9', 'order_id' => 'order_web', 'amount' => 59000]]]]);

        $this->call('POST', '/api/v1/webhooks/razorpay', [], [], [], ['HTTP_X-Razorpay-Signature' => hash_hmac('sha256', $payload, 'hook_secret'), 'CONTENT_TYPE' => 'application/json'], $payload)->assertOk();

        $this->assertSame('500.0000', $this->wallet($organization)->purchased_balance);
    }

    public function test_webhook_with_the_wrong_amount_adds_nothing(): void
    {
        $organization = $this->tenant();
        $this->connect();
        $this->razorpay();
        $this->fakeOrder('order_web');
        $this->actingAs($this->member($organization, 'owner'))->post('/agent-credits/checkout', ['pack' => 'small']);
        auth()->logout();

        $payload = json_encode(['event' => 'payment.captured', 'payload' => ['payment' => ['entity' => ['id' => 'pay_9', 'order_id' => 'order_web', 'amount' => 100]]]]);

        $this->call('POST', '/api/v1/webhooks/razorpay', [], [], [], ['HTTP_X-Razorpay-Signature' => hash_hmac('sha256', $payload, 'hook_secret'), 'CONTENT_TYPE' => 'application/json'], $payload)->assertStatus(422);

        $this->assertSame('0.0000', $this->wallet($organization)->purchased_balance);
    }

    public function test_cannot_buy_credits_while_payment_is_off(): void
    {
        $organization = $this->tenant();
        $this->connect();
        Http::fake();

        $this->actingAs($this->member($organization, 'owner'))->post('/agent-credits/checkout', ['pack' => 'small'])->assertSessionHasErrors('pack');

        $this->assertSame(0, AgentCreditTopup::count());
    }

    public function test_cannot_buy_credits_while_agents_are_not_connected(): void
    {
        $organization = $this->tenant();
        $this->razorpay();
        Http::fake();

        $this->actingAs($this->member($organization, 'owner'))->post('/agent-credits/checkout', ['pack' => 'small'])->assertSessionHasErrors('pack');

        $this->assertSame(0, AgentCreditTopup::count());
        Http::assertNothingSent();
    }

    public function test_one_company_cannot_confirm_anothers_order(): void
    {
        $organization = $this->tenant('Buyer');
        $this->connect();
        $this->razorpay();
        $this->fakeOrder();
        $this->actingAs($this->member($organization, 'owner'))->post('/agent-credits/checkout', ['pack' => 'small']);

        $this->actingAs($this->member($this->tenant('Other'), 'owner'))
            ->post('/agent-credits/confirm', ['razorpay_order_id' => 'order_abc', 'razorpay_payment_id' => 'pay_1', 'razorpay_signature' => $this->signature('order_abc', 'pay_1')])
            ->assertNotFound();
    }

    public function test_credits_screen_shows_the_balance_and_history(): void
    {
        $organization = $this->tenant();
        $this->connect();
        $owner = $this->member($organization, 'owner');
        $this->fakeClaude([$this->finalAnswer('ok')]);
        $run = $this->runAgent('project_brain', $owner);

        $this->actingAs($owner)->get('/agent-credits')->assertOk()->assertSee('Agent use')->assertSee('#'.$run->id)->assertSee('claude-sonnet-5-5');
    }
}
