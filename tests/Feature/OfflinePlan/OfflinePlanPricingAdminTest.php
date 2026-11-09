<?php

namespace Tests\Feature\OfflinePlan;

use App\Http\Controllers\AdminSettingsController;
use App\Models\User;
use App\Services\BillingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\BuildsTenant;
use Tests\TestCase;

/** The offline plan on the website, at registration, in billing and in the admin panel. */
class OfflinePlanPricingAdminTest extends TestCase
{
    use BuildsOfflineCompany, BuildsTenant, RefreshDatabase;

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

    /** The admin form as the browser sends it, with the offline plan's phone count set as given. */
    private function plansPayload(?int $maxDevices, bool $withDevicesField = true): array
    {
        $plans = [];

        foreach (config('buildos.plans') as $key => $plan) {
            $plans[$key] = collect($plan)->only(['label', 'tagline', 'monthly', 'yearly', 'max_users', 'max_projects'])->all();
            $plans[$key]['features'] = array_values(array_intersect($plan['features'], array_keys(AdminSettingsController::FEATURES)));

            if (! $plan['buyable']) {
                unset($plans[$key]['monthly'], $plans[$key]['yearly']);
            }
        }

        if ($withDevicesField) {
            $plans['offline']['max_devices'] = $maxDevices;
        }

        return ['plans' => $plans];
    }

    public function test_the_pricing_page_lists_the_offline_plan_honestly(): void
    {
        $this->get('/pricing')->assertOk()
            ->assertSee('Offline')
            ->assertSee('₹499')
            ->assertSee('₹4,990')
            ->assertSee('there is no server backup')
            ->assertSee('No team and no web dashboard: one person, one phone.')
            ->assertSee('A lost or broken phone means lost data, unless you exported a backup from the app.')
            ->assertSee('The app runs on 1 phone at a time.')
            ->assertSee('On the phone only')
            ->assertSee('Is the Offline plan backed up?')
            ->assertSee(route('register', ['plan' => 'offline']), false);
    }

    public function test_the_pricing_page_reads_in_hindi_and_marathi(): void
    {
        $this->get('/locale/hi');
        $this->get('/pricing')->assertOk()->assertSee('ऑफ़लाइन')->assertSee('सिर्फ़ फ़ोन में');

        $this->get('/locale/mr');
        $this->get('/pricing')->assertOk()->assertSee('ऑफलाइन')->assertSee('फक्त फोनमध्ये');
    }

    public function test_a_company_can_start_the_offline_plan_on_trial_and_lands_on_its_one_page(): void
    {
        $this->post('/register', ['plan' => 'offline', 'company' => 'Solo Builder', 'name' => 'Asha', 'email' => 'asha@example.com', 'password' => 'a-long-password', 'password_confirmation' => 'a-long-password'])
            ->assertRedirect('/dashboard');

        $user = User::where('email', 'asha@example.com')->first();
        $this->assertSame('offline', $user->organization->plan);
        $this->assertSame('trial', $user->organization->plan_status);
        $this->assertSame('offline', $user->organization->effectivePlan());

        // Her next visit, as a fresh request would load her.
        $this->app['auth']->forgetGuards();
        $this->actingAs($user);
        $this->get('/dashboard')->assertRedirect(route('offline.devices.index'));
        $this->get('/offline/devices')->assertOk()->assertSee('Trial ends on');

        // Enterprise is still priced by conversation.
        $this->post('/logout');
        $this->post('/register', ['plan' => 'enterprise', 'company' => 'X', 'name' => 'A', 'email' => 'b@example.com', 'password' => 'a-long-password', 'password_confirmation' => 'a-long-password'])->assertSessionHasErrors('plan');
    }

    public function test_billing_offers_the_offline_plan_through_the_usual_checkout_quote(): void
    {
        $this->actingAs($this->member($this->tenant('Big Co', 'business'), 'owner'));

        $this->get('/billing')->assertOk()->assertSee('For one person who keeps everything on one phone');
        $this->assertSame(['base' => '499.00', 'gst' => '89.82', 'total' => '588.82'], app(BillingService::class)->quote('offline', 'monthly'));
        $this->assertSame('4990.00', app(BillingService::class)->quote('offline', 'yearly')['base']);
    }

    public function test_the_admin_edits_the_offline_plan_including_how_many_phones_it_allows(): void
    {
        $this->admin();

        // The phone count is offered for the offline plan only.
        $page = $this->get('/admin/plans')->assertOk()->assertSee('Max phones');
        $this->assertSame(1, substr_count($page->getContent(), 'name="plans[offline][max_devices]"'));
        $this->assertStringNotContainsString('name="plans[contractor][max_devices]"', $page->getContent());

        $this->patch('/admin/plans', $this->plansPayload(3))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(3, config('buildos.plans.offline.max_devices'));
        $this->assertSame(1, config('buildos.plans.contractor.max_devices', 1));

        // The new limit applies to activations straight away.
        $organization = $this->offlineTenant();
        Sanctum::actingAs($this->member($organization, 'owner'));
        $this->activatePhone('phone-0001')->assertCreated()->assertJsonPath('max_devices', 3);
        $this->activatePhone('phone-0002')->assertCreated();
        $this->activatePhone('phone-0003')->assertCreated();
        $this->activatePhone('phone-0004')->assertStatus(409)->assertJsonPath('code', 'device_limit');

        // The pricing page follows the setting too.
        $this->app['auth']->forgetGuards();
        $this->get('/pricing')->assertOk()->assertSee('The app runs on up to 3 phones at a time.');
    }

    public function test_a_form_without_the_phone_field_keeps_the_default_and_a_blank_means_no_limit(): void
    {
        $this->admin();

        $this->patch('/admin/plans', $this->plansPayload(null, withDevicesField: false))->assertSessionHasNoErrors();
        $this->assertSame(1, config('buildos.plans.offline.max_devices'));

        $this->patch('/admin/plans', $this->plansPayload(null))->assertSessionHasNoErrors();
        $this->assertNull(config('buildos.plans.offline.max_devices'));
        $this->get('/pricing')->assertOk()->assertSee('No limit on phones.');

        $this->patch('/admin/plans', $this->plansPayload(0))->assertSessionHasErrors('plans.offline.max_devices');
    }
}
