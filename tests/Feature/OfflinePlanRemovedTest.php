<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\User;
use App\Services\BillingService;
use App\Support\PlatformSettings;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Tests\BuildsTenant;
use Tests\TestCase;

/**
 * There is no offline plan any more: every company syncs online. Covers the migration that moves any company left
 * on it, the website, billing and the admin panel without it, and the API replies field app build 18 still reads.
 */
class OfflinePlanRemovedTest extends TestCase
{
    use BuildsTenant, RefreshDatabase;

    private const MIGRATION = '2027_01_07_100001_remove_offline_plan.php';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function migration(string $file = self::MIGRATION): Migration
    {
        return require database_path('migrations/'.$file);
    }

    private function licence(Organization $organization, User $user, string $device, string $status, ?int $slot): void
    {
        DB::table('device_licences')->insert([
            'organization_id' => $organization->id, 'user_id' => $user->id, 'device_id' => $device, 'device_name' => 'Pixel 8 on Android',
            'platform' => 'android', 'app_version' => '1.0.18', 'status' => $status, 'slot' => $slot,
            'activated_at' => now(), 'last_seen_at' => now(), 'expires_at' => now()->addDays(90),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** @return list<array<string, mixed>> */
    private function organizations(): array
    {
        return DB::table('organizations')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
    }

    private function planChanges(Organization $organization)
    {
        return AuditLog::withoutGlobalScopes()->where('organization_id', $organization->id)->where('action', 'platform.plan_changed')->get();
    }

    public function test_the_migration_moves_an_offline_company_to_free_and_drops_its_licences(): void
    {
        $migration = $this->migration();
        // The database as it stood before: the licence table, and a company on the plan with two phones.
        $migration->down();

        $phoneCo = $this->tenant('Phone Co', 'offline');
        $phoneCo->forceFill(['plan_status' => 'trial', 'trial_ends_at' => now()->addDays(10), 'paid_until' => now()->addMonth(), 'billing_cycle' => 'monthly'])->save();
        $owner = $this->member($phoneCo, 'owner');
        $this->licence($phoneCo, $owner, 'n360-phone-a', 'active', 1);
        $this->licence($phoneCo, $owner, 'n360-phone-b', 'revoked', null);

        $online = $this->tenant('Deccan Builders', 'contractor');
        $online->forceFill(['plan_status' => 'trial', 'trial_ends_at' => now()->addDays(20)])->save();
        $this->member($online, 'owner');
        $onlineBefore = DB::table('organizations')->where('id', $online->id)->first();

        $migration->up();

        $phoneCo->refresh();
        $this->assertSame(['free', 'active', null, null, null], [$phoneCo->plan, $phoneCo->plan_status, $phoneCo->trial_ends_at, $phoneCo->paid_until, $phoneCo->billing_cycle]);
        $this->assertSame('free', $phoneCo->effectivePlan());
        $this->assertSame(config('buildos.plans.free.features'), $phoneCo->planConfig()['features']);
        $this->assertFalse(Schema::hasTable('device_licences'));

        // One audit row, filed under the company, by nobody, with what changed and nothing that names anyone.
        $log = $this->planChanges($phoneCo)->sole();
        $this->assertSame([null, null, $phoneCo->getMorphClass(), $phoneCo->id], [$log->user_id, $log->ip_address, $log->auditable_type, (int) $log->auditable_id]);
        $this->assertSame(['offline', 'trial', 'monthly'], [$log->old_values['plan'], $log->old_values['plan_status'], $log->old_values['billing_cycle']]);
        $this->assertNotNull($log->old_values['trial_ends_at']);
        $this->assertNotNull($log->old_values['paid_until']);
        $this->assertSame([
            'plan' => 'free', 'plan_status' => 'active', 'trial_ends_at' => null, 'paid_until' => null, 'billing_cycle' => null,
            'reason' => 'offline_plan_removed', 'device_licences_removed' => 2,
        ], $log->new_values);
        $written = json_encode([$log->old_values, $log->new_values]);
        foreach ([$owner->name, $owner->email, 'Phone Co'] as $personal) {
            $this->assertStringNotContainsString($personal, $written);
        }

        // The company on an online plan is exactly as it was.
        $this->assertEquals($onlineBefore, DB::table('organizations')->where('id', $online->id)->first());
        $this->assertCount(0, $this->planChanges($online));
    }

    public function test_the_migration_changes_nothing_when_no_company_is_on_the_plan(): void
    {
        $migration = $this->migration();
        $migration->down();

        // What production held when the plan was removed: contractor trials and one enterprise company.
        foreach (['Deccan Builders', 'Konkan Infra', 'Sahyadri Homes'] as $name) {
            $this->tenant($name, 'contractor')->forceFill(['plan_status' => 'trial', 'trial_ends_at' => now()->addDays(12)])->save();
        }
        $this->tenant('Big Infra', 'enterprise');
        $this->tenant('Solo Site', 'free');
        $organizations = $this->organizations();
        $audits = DB::table('audit_logs')->count();

        $migration->up();

        $this->assertSame($organizations, $this->organizations());
        $this->assertSame($audits, DB::table('audit_logs')->count());
        $this->assertFalse(Schema::hasTable('device_licences'));

        // Again on a database where the table is already gone: just as quiet.
        $migration->up();
        $this->assertSame($organizations, $this->organizations());
        $this->assertSame($audits, DB::table('audit_logs')->count());
    }

    public function test_down_puts_back_the_licence_table_as_it_was_and_empty(): void
    {
        $original = $this->migration('2026_12_30_100001_create_device_licences_table.php');
        $original->up();
        $columns = collect(Schema::getColumns('device_licences'))->map(fn (array $c) => [$c['name'], $c['type_name'], $c['nullable']])->sortBy(0)->values()->all();
        $indexes = collect(Schema::getIndexes('device_licences'))->map(fn (array $i) => [$i['columns'], $i['unique']])->sortBy(fn ($i) => implode(',', $i[0]))->values()->all();
        $foreignKeys = collect(Schema::getForeignKeys('device_licences'))->map(fn (array $f) => [$f['columns'], $f['foreign_table'], $f['on_delete']])->sortBy(fn ($f) => implode(',', $f[0]))->values()->all();
        $original->down();

        $migration = $this->migration();
        $migration->down();
        $migration->down();

        $this->assertSame($columns, collect(Schema::getColumns('device_licences'))->map(fn (array $c) => [$c['name'], $c['type_name'], $c['nullable']])->sortBy(0)->values()->all());
        $this->assertSame($indexes, collect(Schema::getIndexes('device_licences'))->map(fn (array $i) => [$i['columns'], $i['unique']])->sortBy(fn ($i) => implode(',', $i[0]))->values()->all());
        $this->assertSame($foreignKeys, collect(Schema::getForeignKeys('device_licences'))->map(fn (array $f) => [$f['columns'], $f['foreign_table'], $f['on_delete']])->sortBy(fn ($f) => implode(',', $f[0]))->values()->all());
        $this->assertSame(0, DB::table('device_licences')->count());

        $migration->up();
        $this->assertFalse(Schema::hasTable('device_licences'));
    }

    public function test_the_migration_takes_the_plan_out_of_the_saved_admin_settings(): void
    {
        app(PlatformSettings::class)->set(['plans' => json_encode([
            'offline' => ['label' => 'Offline', 'tagline' => 'One phone', 'monthly' => 599, 'yearly' => 5990, 'max_devices' => 2, 'features' => ['site']],
            'contractor' => ['label' => 'Contractor', 'tagline' => 'Sites', 'max_users' => 25, 'max_projects' => 5, 'features' => ['site', 'billing', 'offline_plan'], 'monthly' => 3499, 'yearly' => 34990],
        ])]);

        $migration = $this->migration();
        $migration->down();
        $migration->up();

        $saved = json_decode((string) DB::table('platform_settings')->where('key', 'plans')->value('value'), true);
        $this->assertSame(['contractor'], array_keys($saved));
        $this->assertSame(['site', 'billing'], $saved['contractor']['features']);
        $this->assertSame([3499, 34990], [$saved['contractor']['monthly'], $saved['contractor']['yearly']]);

        // What a fresh process reads: the cached copy went too.
        config(['buildos.plans.contractor.monthly' => 2999]);
        $fresh = new PlatformSettings;
        $this->assertSame(['contractor'], array_keys(json_decode((string) $fresh->get('plans'), true)));
        $fresh->applyToConfig();
        $this->assertSame(3499, config('buildos.plans.contractor.monthly'));
        $this->assertNull(config('buildos.plans.offline'));
    }

    public function test_the_public_pages_show_no_offline_plan(): void
    {
        $this->get('/pricing')->assertOk()
            ->assertDontSee('Offline')
            ->assertDontSee('₹499')
            ->assertDontSee('one phone')
            ->assertDontSee('On the phone only')
            ->assertDontSee('plan=offline', false)
            ->assertSee('Contractor')
            ->assertSee(route('register', ['plan' => 'business']), false);

        foreach (['/', '/register', '/terms', '/privacy', '/delete-account', '/sitemap.xml'] as $page) {
            $this->get($page)->assertOk()
                ->assertDontSee('Offline plan')
                ->assertDontSee('offline plan')
                ->assertDontSee('offline-plan')
                ->assertDontSee('licence')
                ->assertDontSee('plan=offline', false);
        }

        // The field app's normal offline working is still on the home page.
        $this->get('/')->assertSee('Works offline');
    }

    public function test_register_with_the_old_plan_starts_the_default_plan_like_any_unknown_plan(): void
    {
        foreach (['offline', 'no-such-plan'] as $plan) {
            $this->get("/register?plan={$plan}")->assertOk()
                ->assertSee('Plan: Contractor. No card needed.')
                ->assertSee('name="plan" value="contractor"', false);
        }

        // Sent as it is, the old plan is refused like any other plan that is not sold, and nothing is started.
        foreach (['offline', 'no-such-plan'] as $plan) {
            $this->post('/register', ['plan' => $plan, 'company' => 'Solo Builder', 'name' => 'Asha', 'email' => "asha-{$plan}@example.com", 'password' => 'a-long-password', 'password_confirmation' => 'a-long-password'])
                ->assertSessionHasErrors('plan');
        }

        $this->assertSame(0, Organization::count());

        // The form as it comes, with the default plan, starts a trial of it.
        $this->flushSession();
        $this->post('/register', ['plan' => 'contractor', 'company' => 'Solo Builder', 'name' => 'Asha', 'email' => 'asha@example.com', 'password' => 'a-long-password', 'password_confirmation' => 'a-long-password'])
            ->assertRedirect('/dashboard');
        $this->assertSame(['contractor', 'trial'], [Organization::sole()->plan, Organization::sole()->plan_status]);
    }

    public function test_billing_and_the_admin_panel_offer_no_offline_plan(): void
    {
        $owner = $this->member($this->tenant('Deccan Builders', 'contractor'), 'owner');
        $owner->forceFill(['is_platform_admin' => true])->save();
        $this->actingAs($owner);

        $this->get('/billing')->assertOk()->assertDontSee('Offline')->assertDontSee('one phone');
        $this->post('/billing/checkout', ['plan' => 'offline', 'cycle' => 'monthly'])->assertSessionHasErrors('plan');
        $this->get('/admin/plans')->assertOk()->assertDontSee('Max phones')->assertDontSee('plans[offline]', false)->assertDontSee('offline_plan', false);
        $this->patch(route('admin.organizations.update', $owner->organization), ['plan' => 'offline', 'plan_status' => 'active'])->assertSessionHasErrors('plan');
        $this->get('/offline/devices')->assertNotFound();
        $this->get('/offline/devices/phones')->assertNotFound();

        $this->expectException(ValidationException::class);
        app(BillingService::class)->quote('offline', 'monthly');
    }

    public function test_the_billing_page_keeps_its_hindi_and_marathi(): void
    {
        foreach (['hi' => 'मौजूदा प्लान', 'mr' => 'सध्याचा प्लॅन'] as $locale => $currentPlan) {
            $owner = $this->member($this->tenant("Co {$locale}", 'contractor'), 'owner');
            $owner->forceFill(['locale' => $locale])->save();

            $this->actingAs($owner)->get('/billing')->assertOk()->assertSee($currentPlan)->assertSee('बिलिंग');
        }
    }

    public function test_no_route_name_or_address_of_the_plan_is_left_but_the_status_older_apps_ask(): void
    {
        $routes = collect(Route::getRoutes()->getRoutes());

        $this->assertSame([], $routes->map->getName()->filter(fn (?string $name) => $name !== null && str_contains($name, 'offline'))->values()->all());
        $this->assertSame(
            ['GET|HEAD api/v1/offline/status'],
            $routes->filter(fn ($route) => str_contains($route->uri(), 'offline'))->map(fn ($route) => implode('|', $route->methods()).' '.$route->uri())->values()->all(),
        );
        $this->assertSame([], $routes->map(fn ($route) => $route->getActionName())->filter(fn (string $action) => str_contains($action, 'OfflinePlan'))->values()->all());

        $this->assertNull(config('buildos.plans.offline'));
        $this->assertNull(config('modules.offline_plan'));
        foreach (config('buildos.plans') as $key => $plan) {
            $this->assertNotContains('offline_plan', $plan['features'], $key);
            $this->assertArrayNotHasKey('max_devices', $plan, $key);
        }
    }

    public function test_the_status_build_18_asks_gives_every_company_the_online_answer(): void
    {
        $this->getJson('/api/v1/offline/status')->assertUnauthorized();

        foreach (['free', 'contractor', 'business', 'enterprise'] as $plan) {
            Sanctum::actingAs($this->member($this->tenant("{$plan} co", $plan), 'engineer'));
            $this->getJson('/api/v1/offline/status')->assertOk()->assertExactJson(['plan' => $plan, 'offline' => false, 'syncs' => true]);
        }

        // An ended trial is on the free plan, as before.
        $ended = $this->tenant('Ended Co', 'contractor');
        $ended->forceFill(['plan_status' => 'trial', 'trial_ends_at' => now()->subDay()])->save();
        Sanctum::actingAs($this->member($ended, 'owner'));
        $this->getJson('/api/v1/offline/status')->assertOk()->assertExactJson(['plan' => 'free', 'offline' => false, 'syncs' => true]);

        // The licence endpoints only the offline plan called are gone.
        $this->getJson('/api/v1/offline/public-key')->assertNotFound();
        foreach (['activate', 'refresh', 'release'] as $step) {
            $this->postJson("/api/v1/offline/devices/{$step}", ['device_id' => 'n360-phone-a', 'device_name' => 'Pixel 8'])->assertNotFound();
        }
    }

    public function test_sign_in_and_me_keep_the_shape_the_app_reads(): void
    {
        $organization = $this->tenant('Deccan Builders', 'contractor');
        $user = $this->member($organization, 'owner');

        $login = $this->postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'password', 'device_name' => 'Pixel 8'])->assertOk();
        $this->assertSame(['token', 'user'], array_keys($login->json()));

        $me = $this->withToken($login->json('token'))->getJson('/api/v1/me')->assertOk();

        foreach ([$login->json('user'), $me->json('user')] as $profile) {
            $this->assertSame(['id', 'name', 'email', 'role', 'role_label', 'kind', 'contractor', 'locale', 'organization', 'can'], array_keys($profile));
            $this->assertSame(['id', 'name', 'features'], array_keys($profile['organization']));
            $this->assertSame([$user->id, 'staff', null, $organization->id], [$profile['id'], $profile['kind'], $profile['contractor'], $profile['organization']['id']]);
            $this->assertSame(config('buildos.plans.contractor.features'), $profile['organization']['features']);
            $this->assertNotContains('offline_plan', $profile['organization']['features']);
        }
    }

    public function test_a_company_still_marked_offline_is_treated_as_free_and_syncs(): void
    {
        // Only if a payment for the old plan lands after the migration ran: nothing is refused, and it gets the free plan's limits.
        $organization = $this->tenant('Late Co', 'offline');
        Sanctum::actingAs($this->member($organization, 'owner'));

        $this->getJson('/api/v1/me')->assertOk()->assertJsonPath('user.organization.features', config('buildos.plans.free.features'));
        $this->getJson('/api/v1/sync/pull')->assertOk();
        $this->getJson('/api/v1/offline/status')->assertOk()->assertJsonPath('offline', false)->assertJsonPath('syncs', true);
        $this->assertSame(config('buildos.plans.free'), $organization->planConfig());
    }
}
