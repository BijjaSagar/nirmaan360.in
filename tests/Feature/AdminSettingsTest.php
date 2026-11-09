<?php

namespace Tests\Feature;

use App\Http\Controllers\AdminSettingsController;
use App\Models\PlatformSetting;
use App\Models\SiteText;
use App\Models\User;
use App\Services\BillingService;
use App\Services\RazorpayGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\BuildsTenant;
use Tests\TestCase;

class AdminSettingsTest extends TestCase
{
    use BuildsTenant, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function admin(): User
    {
        $user = $this->member($this->tenant(), 'owner');
        $user->forceFill(['is_platform_admin' => true])->save();
        $this->actingAs($user);

        return $user;
    }

    private function plansPayload(array $contractor = []): array
    {
        $plans = [];

        foreach (config('buildos.plans') as $key => $plan) {
            $plans[$key] = collect($plan)->only(['label', 'tagline', 'monthly', 'yearly', 'max_users', 'max_projects', 'features'])->all();

            // The form only offers the built-in modules. A plugged-in module adds its own feature to every plan it is on.
            $plans[$key]['features'] = array_values(array_intersect($plan['features'], array_keys(AdminSettingsController::FEATURES)));

            if (! $plan['buyable']) {
                unset($plans[$key]['monthly'], $plans[$key]['yearly']);
            }
        }

        $plans['contractor'] = [...$plans['contractor'], ...$contractor];

        return ['plans' => $plans];
    }

    public function test_company_owners_cannot_reach_any_settings_screen(): void
    {
        $this->actingAs($this->member($this->tenant(), 'owner'));

        foreach (['plans', 'payments', 'email', 'website', 'texts', 'admins'] as $screen) {
            $this->get("/admin/{$screen}")->assertNotFound();
        }

        $this->patch('/admin/payments', ['razorpay_key_id' => 'x', 'trial_days' => 1, 'subscription_gst_pct' => 1, 'payment_grace_days' => 1])->assertNotFound();
        $this->assertSame(0, PlatformSetting::count());
    }

    public function test_every_settings_screen_renders_for_an_admin(): void
    {
        $this->admin();

        foreach (['plans', 'payments', 'email', 'website', 'admins'] as $screen) {
            $this->get("/admin/{$screen}")->assertOk();
        }

        foreach (['home', 'pricing', 'ai', 'contact', 'calculators', 'signup'] as $page) {
            $this->get("/admin/texts?page={$page}")->assertOk();
        }
    }

    public function test_price_change_reaches_the_website_and_the_checkout(): void
    {
        $this->admin();

        $this->patch('/admin/plans', $this->plansPayload(['monthly' => 3499, 'yearly' => 34990, 'label' => 'Builder', 'max_projects' => 9]))->assertRedirect()->assertSessionHasNoErrors();

        $this->get('/pricing')->assertOk()->assertSee('₹3,499')->assertSee('₹34,990')->assertSee('Builder')->assertDontSee('₹2,999');
        $this->assertSame('3499.00', app(BillingService::class)->quote('contractor', 'monthly')['base']);
        $this->assertSame(9, config('buildos.plans.contractor.max_projects'));
    }

    public function test_paid_plans_must_keep_a_price(): void
    {
        $this->admin();

        $this->patch('/admin/plans', $this->plansPayload(['monthly' => null]))->assertSessionHasErrors('plans.contractor.monthly');
    }

    public function test_razorpay_keys_are_encrypted_never_shown_and_switch_payment_on(): void
    {
        $this->admin();
        $this->assertFalse(app(RazorpayGateway::class)->isLive());

        $this->patch('/admin/payments', ['razorpay_key_id' => 'rzp_test_abc', 'razorpay_key_secret' => 'super-secret-value', 'razorpay_webhook_secret' => 'hook-value', 'trial_days' => 14, 'subscription_gst_pct' => 18, 'subscription_gst_mode' => 'plus', 'payment_grace_days' => 5])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertTrue(app(RazorpayGateway::class)->isLive());
        $this->assertNotSame('super-secret-value', PlatformSetting::find('razorpay_key_secret')->value);
        $this->assertSame(14, config('buildos.trial_days'));
        $this->get('/admin/payments')->assertOk()->assertDontSee('super-secret-value')->assertDontSee('hook-value');

        // Saving with the secret box left empty keeps the saved secret.
        $this->patch('/admin/payments', ['razorpay_key_id' => 'rzp_test_abc', 'razorpay_key_secret' => '', 'trial_days' => 14, 'subscription_gst_pct' => 18, 'subscription_gst_mode' => 'plus', 'payment_grace_days' => 5]);
        $this->assertSame('super-secret-value', config('services.razorpay.key_secret'));

        $this->patch('/admin/payments', ['clear_keys' => 1, 'razorpay_key_id' => '', 'trial_days' => 14, 'subscription_gst_pct' => 18, 'subscription_gst_mode' => 'plus', 'payment_grace_days' => 5]);
        $this->assertFalse(app(RazorpayGateway::class)->isLive());
    }

    public function test_gst_choice_reaches_the_website_and_the_checkout(): void
    {
        $this->admin();
        $save = fn (string $mode) => $this->patch('/admin/payments', ['razorpay_key_id' => '', 'trial_days' => 30, 'subscription_gst_pct' => 18, 'subscription_gst_mode' => $mode, 'payment_grace_days' => 3])->assertSessionHasNoErrors();
        $quote = fn () => app(BillingService::class)->quote('contractor', 'monthly');

        $this->get('/pricing')->assertSee('Plus 18% GST');

        $save('none');
        $this->assertSame(['base' => '2999.00', 'gst' => '0.00', 'total' => '2999.00'], $quote());
        $this->get('/pricing')->assertSee('No GST charged')->assertDontSee('Plus 18% GST');
        $this->get('/')->assertSee('No GST charged');

        $save('included');
        $this->assertSame(['base' => '2541.53', 'gst' => '457.47', 'total' => '2999.00'], $quote());
        $this->get('/pricing')->assertSee('Including 18% GST');
        $this->get('/terms')->assertSee('including GST');
    }

    public function test_gst_choice_must_be_a_known_one(): void
    {
        $this->admin();

        $this->patch('/admin/payments', ['trial_days' => 30, 'subscription_gst_pct' => 18, 'subscription_gst_mode' => 'maybe', 'payment_grace_days' => 3])->assertSessionHasErrors('subscription_gst_mode');
    }

    public function test_trial_length_follows_the_setting_everywhere(): void
    {
        $this->admin();
        $this->patch('/admin/payments', ['trial_days' => 14, 'subscription_gst_pct' => 18, 'subscription_gst_mode' => 'plus', 'payment_grace_days' => 7])->assertSessionHasNoErrors();

        $this->get('/')->assertSee('Start a 14-day trial')->assertSee('You get 14 days')->assertDontSee('30-day');
        $this->get('/terms')->assertSee('Trials end after 14 days')->assertSee('within 7 days');
    }

    public function test_key_test_reports_what_razorpay_says(): void
    {
        $this->admin();
        config(['services.razorpay.key_id' => 'k', 'services.razorpay.key_secret' => 's']);

        Http::fake(['api.razorpay.com/*' => Http::response([], 401)]);
        $this->post('/admin/payments/test')->assertSessionHasErrors('razorpay_key_id');
    }

    public function test_email_settings_apply_and_turn_on_password_reset_in_production(): void
    {
        $this->admin();

        $this->patch('/admin/email', ['mail_host' => 'smtp.example.com', 'mail_port' => 465, 'mail_security' => 'ssl', 'mail_username' => 'u', 'mail_password' => 'mail-secret', 'mail_from_address' => 'hi@nirmaan360.in', 'mail_from_name' => 'Nirmaan360'])->assertSessionHasNoErrors();

        $this->assertSame('smtp', config('mail.default'));
        $this->assertSame('smtps', config('mail.mailers.smtp.scheme'));
        $this->assertSame('mail-secret', config('mail.mailers.smtp.password'));
        $this->assertSame('hi@nirmaan360.in', config('mail.from.address'));
        $this->get('/admin/email')->assertDontSee('mail-secret');

        auth()->logout();
        $this->app->detectEnvironment(fn () => 'production');
        $this->get('/forgot-password')->assertOk();
    }

    public function test_test_email_is_sent_to_the_admin(): void
    {
        $this->admin();
        Mail::fake();

        $this->post('/admin/email/test')->assertSessionHasNoErrors();
    }

    public function test_any_website_text_can_be_changed_per_language_and_reset(): void
    {
        $this->admin();
        $hash = sha1('One BOQ line.');

        $this->patch('/admin/texts', ['page' => 'home', 'locale' => 'en', 'texts' => [$hash => 'Every line accounted for.']])->assertSessionHasNoErrors();
        $this->get('/')->assertSee('Every line accounted for.')->assertDontSee('One BOQ line.');

        $this->get('/locale/hi');
        $this->get('/')->assertSee('एक BOQ लाइन।');
        $this->get('/locale/en');

        $this->patch('/admin/texts', ['page' => 'home', 'locale' => 'en', 'texts' => [$hash => '']]);
        $this->assertSame(0, SiteText::count());
        $this->get('/')->assertSee('One BOQ line.');
    }

    public function test_text_from_arrays_and_guest_only_links_is_listed(): void
    {
        $this->admin();

        $this->get('/admin/texts?page=home')->assertSee('Measurement book')->assertSee('Do I need a card to start?')->assertSee('Start free');
    }

    public function test_only_text_used_on_the_page_can_be_saved(): void
    {
        $this->admin();

        $this->patch('/admin/texts', ['page' => 'home', 'locale' => 'en', 'texts' => [sha1('Not on the site') => 'x']]);

        $this->assertSame(0, SiteText::count());
    }

    public function test_text_keeps_placeholders_working(): void
    {
        $this->admin();
        SiteText::create(['locale' => 'en', 'key_hash' => sha1('Plus :gst% GST'), 'source' => 'Plus :gst% GST', 'value' => 'Excludes :gst% GST']);
        app('translator')->forgetOverrides();

        $this->get('/pricing')->assertSee('Excludes 18% GST');
    }

    public function test_website_settings_show_on_the_site(): void
    {
        $this->admin();

        $this->post('/admin/website', [
            'site_name' => 'Acme Build', 'contact_email' => 'sales@example.com', 'contact_phone' => '+91 98765 43210',
            'announcement' => 'Monsoon offer', 'color_primary' => '#123456',
            'sections' => ['strip' => 1, 'stages' => 1, 'measurement' => 1, 'billing' => 1, 'roles' => 1, 'ai' => 1, 'pricing' => 1, 'faq' => 1],
        ])->assertSessionHasNoErrors();

        $this->get('/')->assertOk()->assertSee('Acme Build')->assertSee('sales@example.com')->assertSee('Monsoon offer')->assertSee('--color-laterite:#123456', false)->assertDontSee('Mobile app in testing');
    }

    public function test_supplied_logo_and_icons_are_the_default(): void
    {
        $this->get('/')->assertOk()
            ->assertSee('images/logo.webp', false)
            ->assertSee('images/logo-on-dark.webp', false)
            ->assertSee('favicon.png', false)
            ->assertSee('apple-touch-icon.png', false);

        foreach (['images/logo.webp', 'images/logo-on-dark.webp', 'images/logo-full.webp', 'favicon.png', 'apple-touch-icon.png'] as $file) {
            $this->assertFileExists(public_path($file));
        }
    }

    public function test_company_name_replaces_the_default_logo(): void
    {
        $this->admin();
        $this->post('/admin/website', ['site_name' => 'Acme Build'])->assertSessionHasNoErrors();

        $this->get('/')->assertSee('Acme Build')->assertDontSee('images/logo.webp', false);
    }

    public function test_second_email_shows_on_the_site(): void
    {
        $this->admin();

        $this->post('/admin/website', ['info_email' => 'not-an-email'])->assertSessionHasErrors('info_email');
        $this->post('/admin/website', ['contact_email' => 'contact@example.com', 'info_email' => 'info@example.com'])->assertSessionHasNoErrors();

        $this->get('/')->assertSee('contact@example.com')->assertSee('info@example.com');
        $this->get('/contact')->assertSee('info@example.com');
    }

    public function test_language_choice_is_a_dropdown_with_every_language(): void
    {
        $page = $this->get('/login')->assertOk()->assertSee('aria-haspopup="true"', false)->assertSee('Change language');

        foreach (array_keys(config('buildos.locales')) as $code) {
            $page->assertSee(route('locale', $code), false);
        }
    }

    public function test_colours_cannot_inject_css(): void
    {
        $this->admin();

        $this->post('/admin/website', ['color_primary' => 'red;}body{display:none'])->assertSessionHasErrors('color_primary');
    }

    public function test_logo_upload_accepts_images_only_and_is_served(): void
    {
        Storage::fake();
        $this->admin();

        $this->post('/admin/website', ['logo' => UploadedFile::fake()->create('logo.svg', 10, 'image/svg+xml')])->assertSessionHasErrors('logo');

        $this->post('/admin/website', ['logo' => UploadedFile::fake()->image('logo.png', 200, 60)])->assertSessionHasNoErrors();
        $this->get('/brand/logo')->assertOk();
    }

    public function test_legal_text_override_cannot_carry_html(): void
    {
        $this->admin();

        $this->post('/admin/website', ['privacy_text' => "## Our policy\n\nplain words\n\n<script>alert(1)</script>"])->assertSessionHasNoErrors();

        $this->get('/privacy')->assertSee('Our policy')->assertSee('plain words')->assertDontSee('<script>alert(1)</script>', false);
    }

    public function test_admins_can_be_added_only_from_existing_accounts(): void
    {
        $admin = $this->admin();
        $other = $this->member($this->tenant('Second'), 'owner');

        $this->post('/admin/admins', ['email' => 'nobody@example.com'])->assertSessionHasErrors('email');
        $this->post('/admin/admins', ['email' => $other->email])->assertSessionHasNoErrors();
        $this->assertTrue($other->fresh()->isPlatformAdmin());

        $this->delete("/admin/admins/{$admin->id}")->assertSessionHasErrors('email');
        $this->delete("/admin/admins/{$other->id}")->assertSessionHasNoErrors();
        $this->assertFalse($other->fresh()->isPlatformAdmin());
    }

    public function test_registration_cannot_grant_platform_admin(): void
    {
        $this->post('/register', ['company' => 'X', 'name' => 'A', 'email' => 'a@example.com', 'password' => 'a-long-password', 'password_confirmation' => 'a-long-password', 'is_platform_admin' => 1]);

        $this->assertFalse(User::where('email', 'a@example.com')->first()->isPlatformAdmin());
    }

    public function test_command_only_promotes_an_account_that_exists(): void
    {
        $this->artisan('platform:admin', ['email' => 'nobody@example.com'])->assertFailed();

        $user = $this->member($this->tenant(), 'owner');
        $this->artisan('platform:admin', ['email' => $user->email])->assertSuccessful();
        $this->assertTrue($user->fresh()->isPlatformAdmin());
    }
}
