<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\BuildsTenant;
use Tests\TestCase;

class RefundPolicyTest extends TestCase
{
    use BuildsTenant, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_anyone_can_read_the_policy_with_the_live_trial_and_grace_days(): void
    {
        config(['buildos.trial_days' => 21, 'buildos.payment_grace_days' => 9, 'buildos.subscription_gst_mode' => 'extra']);

        $this->get('/refund-policy')->assertOk()
            ->assertSee('Refund and cancellation policy')
            ->assertSee('try a paid plan for 21 days')
            ->assertSee('within 9 days after that')
            ->assertSee('plus GST')
            ->assertSee('Plans do not renew automatically')
            ->assertSee('within 5 to 7 working days')
            ->assertSee('nothing is shipped')
            ->assertSee(route('contact', ['type' => 'support']), false);
    }

    public function test_the_contact_email_is_offered_when_the_site_has_one(): void
    {
        $this->get('/refund-policy')->assertDontSee('mailto:', false);

        $admin = $this->member($this->tenant(), 'owner');
        $admin->forceFill(['is_platform_admin' => true])->save();
        $this->actingAs($admin)->post('/admin/website', ['contact_email' => 'billing@example.com'])->assertSessionHasNoErrors();

        $this->get('/refund-policy')->assertSee('mailto:billing@example.com', false);
    }

    public function test_the_footer_and_the_terms_link_to_it(): void
    {
        $this->get('/')->assertOk()->assertSee(route('refunds'), false);
        $this->get('/terms')->assertOk()->assertSee(route('refunds'), false);
    }

    public function test_a_platform_admin_can_replace_the_built_in_text(): void
    {
        $admin = $this->member($this->tenant(), 'owner');
        $admin->forceFill(['is_platform_admin' => true])->save();

        $this->actingAs($admin)->get('/admin/website')->assertOk()->assertSee('name="refund_text"', false);
        $this->post('/admin/website', ['refund_text' => "## Our refunds\n\nRefunds within 3 days of payment."])->assertSessionHasNoErrors();

        $this->get('/refund-policy')->assertOk()
            ->assertSee('Our refunds')
            ->assertSee('Refunds within 3 days of payment.')
            ->assertDontSee('Plans do not renew automatically');
    }

    public function test_a_company_owner_who_is_not_a_platform_admin_cannot_change_it(): void
    {
        $this->actingAs($this->member($this->tenant(), 'owner'));

        // Admin screens answer 404 to anyone else, so they are not even seen to exist.
        $this->post('/admin/website', ['refund_text' => 'Everything is refunded.'])->assertNotFound();

        $this->get('/refund-policy')->assertDontSee('Everything is refunded.');
    }

    public function test_the_text_is_kept_free_of_html(): void
    {
        $admin = $this->member($this->tenant(), 'owner');
        $admin->forceFill(['is_platform_admin' => true])->save();
        $this->actingAs($admin)->post('/admin/website', ['refund_text' => 'Safe <script>alert(1)</script> text'])->assertSessionHasNoErrors();

        $this->get('/refund-policy')->assertSee('Safe')->assertDontSee('<script>alert(1)</script>', false);
    }
}
