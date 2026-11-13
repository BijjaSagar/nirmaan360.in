<?php

namespace Tests\Feature\SlipReading;

use App\Http\Middleware\RequireAiReader;
use App\Models\Project;
use App\Models\SlipRead;
use App\Models\User;
use Illuminate\Cache\RateLimiter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;

/** Who may read a photo, open a reading, read it again and discard it, in which company, project and plan. */
class SlipReadAccessTest extends SlipReadingTestCase
{
    public function test_every_route_needs_both_plan_features_and_only_the_routes_that_send_a_photo_carry_the_reader_guard(): void
    {
        $routes = collect(Route::getRoutes()->getRoutes())->filter(fn ($route) => str_contains($route->getActionName(), '\\Controllers\\SlipReading\\'));

        $this->assertCount(8, $routes);

        foreach ($routes as $route) {
            $middleware = collect($route->gatherMiddleware());
            $name = (string) $route->getName();

            $this->assertTrue($middleware->contains('feature:site_control'), $name);
            $this->assertTrue($middleware->contains('feature:ai_agents'), $name);

            $sends = str_ends_with($name, '.store') || str_ends_with($name, '.recheck');
            $this->assertSame($sends, $middleware->contains(RequireAiReader::class.':consent'), $name);
            $this->assertSame($sends, $middleware->contains('can:agents.use'), $name);

            foreach ($middleware->filter(fn ($entry) => is_string($entry) && str_starts_with($entry, 'throttle:') && $entry !== 'throttle:120,1') as $throttle) {
                $this->assertCount(3, explode(',', $throttle), "{$name} has an unnamed throttle");
            }
        }

        // The website's tests hold "slip-reads.store" as a reader not built; the card follows the reader kinds instead.
        $this->assertFalse(Route::has('slip-reads.store'));
    }

    public function test_a_challan_photo_is_read_by_whoever_may_enter_a_challan(): void
    {
        foreach (['store', 'engineer', 'pm', 'owner'] as $role) {
            $this->readPhoto($this->member($this->organization, $role))->assertStatus(202);
        }

        foreach (['accounts', 'viewer', 'qs'] as $role) {
            $this->readPhoto($this->member($this->organization, $role))->assertForbidden();
        }

        $this->readPhotoFromPhone($this->member($this->organization, 'viewer'))->assertForbidden();
    }

    public function test_a_supplier_bill_photo_needs_money_view_and_the_bill_permission(): void
    {
        // The bill reader is not deployed in this slice, so a permitted person is told the readers are not on.
        $this->readPhoto($this->member($this->organization, 'store'), null, ['kind' => 'bill'])->assertForbidden();
        $this->readPhoto($this->member($this->organization, 'engineer'), null, ['kind' => 'bill'])->assertForbidden();
        $this->readPhoto($this->member($this->organization, 'accounts'), null, ['kind' => 'bill'])->assertStatus(409)->assertJsonPath('code', 'not_released');
    }

    public function test_another_companys_project_and_a_project_outside_a_persons_limit_are_not_found(): void
    {
        $rival = $this->tenant('Narmada Infra');
        $this->readPhoto($this->member($this->organization, 'store'), $this->makeProject($rival))->assertNotFound();

        $limited = $this->member($this->organization, 'engineer');
        $this->limitTo($limited, $this->project);
        $this->assertTrue($limited->refresh()->isProjectLimited());

        $this->readPhoto($limited, $this->otherProject)->assertNotFound();
        $this->readPhoto($limited, $this->project)->assertStatus(202);
    }

    public function test_a_plan_without_the_ai_features_cannot_read_and_says_so_without_upgrade_words(): void
    {
        $this->organization->update(['plan' => 'contractor']);
        $store = $this->member($this->organization, 'store');

        $this->readPhoto($store)->assertForbidden();
        $answer = $this->readPhotoFromPhone($store)->assertForbidden();
        $this->assertDoesNotMatchRegularExpression('/upgrade|buy/i', (string) $answer->json('message'));
    }

    public function test_a_reading_is_opened_by_its_person_an_owner_or_an_admin_only(): void
    {
        $store = $this->member($this->organization, 'store');
        $slip = $this->storedReading($store);

        foreach ([$store, $this->owner, $this->member($this->organization, 'admin')] as $user) {
            $this->actingAs($user)->getJson("/slip-reads/{$slip->id}")->assertOk();
        }

        foreach (['store', 'engineer', 'pm', 'viewer'] as $role) {
            $other = $this->member($this->organization, $role);
            $this->actingAs($other)->getJson("/slip-reads/{$slip->id}")->assertForbidden();
            $this->actingAs($other)->postJson("/slip-reads/{$slip->id}/discard")->assertForbidden();
        }

        $this->actingAs($this->member($this->organization, 'engineer'))->postJson("/slip-reads/{$slip->id}/recheck")->assertForbidden();

        Sanctum::actingAs($this->member($this->organization, 'store'));
        $this->getJson("/api/v1/slip-reads/{$slip->id}")->assertForbidden();
        Sanctum::actingAs($store);
        $this->getJson("/api/v1/slip-reads/{$slip->id}")->assertOk()->assertJsonPath('id', $slip->id);

        $this->assertSame(SlipRead::READY, $slip->refresh()->status);
    }

    public function test_another_companys_reading_and_a_reading_outside_a_persons_projects_are_not_found(): void
    {
        $rival = $this->tenant('Narmada Infra');
        $rivalOwner = $this->member($rival, 'owner');
        $slip = $this->storedReading($this->owner, [], $this->otherProject);

        $this->actingAs($rivalOwner)->getJson("/slip-reads/{$slip->id}")->assertNotFound();
        $this->actingAs($rivalOwner)->postJson("/slip-reads/{$slip->id}/discard")->assertNotFound();
        Sanctum::actingAs($rivalOwner);
        $this->getJson("/api/v1/slip-reads/{$slip->id}")->assertNotFound();

        // Their own reading, made in a project they no longer see, is out of reach too.
        $limited = $this->member($this->organization, 'store');
        $mine = $this->storedReading($limited, [], $this->otherProject);
        $this->limitTo($limited, $this->project);
        $this->actingAs($limited->refresh())->getJson("/slip-reads/{$mine->id}")->assertNotFound();

        $this->assertSame(SlipRead::READY, $slip->refresh()->status);
    }

    public function test_a_bill_reading_is_opened_only_by_someone_who_sees_money(): void
    {
        $accounts = $this->member($this->organization, 'accounts');
        $bill = $this->storedReading($accounts, [], null, SlipRead::BILL);

        $this->actingAs($accounts)->getJson("/slip-reads/{$bill->id}")->assertOk();

        // The same person moved to the store keeper's role, which has no money.view, is refused.
        $accounts->forceFill(['role' => 'store'])->save();
        $this->actingAs($accounts->refresh())->getJson("/slip-reads/{$bill->id}")->assertForbidden();
    }

    public function test_a_persons_other_requests_do_not_use_up_the_counters_of_the_reading_routes(): void
    {
        $store = $this->member($this->organization, 'store');
        $slip = $this->storedReading($store);
        $limiter = app(RateLimiter::class);

        foreach (range(1, 300) as $ignored) {
            $limiter->hit(sha1((string) $store->id), 60);
        }

        Sanctum::actingAs($store);
        $this->getJson('/api/v1/me')->assertStatus(429);
        $this->getJson("/api/v1/slip-reads/{$slip->id}")->assertOk();

        $this->readPhoto($store)->assertStatus(202);
        $this->actingAs($store)->postJson("/slip-reads/{$slip->id}/recheck")->assertStatus(409);
        $this->actingAs($store)->postJson("/slip-reads/{$slip->id}/discard")->assertOk();
    }

    public function test_reading_photos_is_limited_to_twenty_a_minute_for_each_person(): void
    {
        $store = $this->member($this->organization, 'store');
        $slip = $this->storedReading($store);

        foreach (range(1, 20) as $ignored) {
            $this->actingAs($store)->postJson("/slip-reads/{$slip->id}/recheck")->assertStatus(409);
        }

        $this->actingAs($store)->postJson("/slip-reads/{$slip->id}/recheck")->assertStatus(429);
        $this->readPhoto($store)->assertStatus(429);

        // Another person has a counter of their own, and looking at a reading has none of this.
        $this->readPhoto($this->member($this->organization, 'store'))->assertStatus(202);
        $this->actingAs($store)->getJson("/slip-reads/{$slip->id}")->assertOk();
    }

    /** Limits a person to the given projects. */
    private function limitTo(User $user, Project ...$projects): void
    {
        $user->forceFill(['limited_to_projects' => true])->save();

        foreach ($projects as $project) {
            DB::table('user_project_access')->insert(['organization_id' => $user->organization_id, 'user_id' => $user->id, 'project_id' => $project->id, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    public function test_the_field_app_is_told_who_may_read_a_challan_photo(): void
    {
        $ask = function (User $user) {
            Sanctum::actingAs($user);

            return $this->getJson('/api/v1/document-reading/status')->assertOk()->json('can.slip_challan');
        };

        $this->assertTrue($ask($this->member($this->organization, 'store')));
        $this->assertFalse($ask($this->member($this->organization, 'accounts')));
        $this->assertFalse($ask($this->member($this->organization, 'viewer')));

        // Until the module registers its offer, nobody is.
        config(['modules.slip_reading.reader_offers' => []]);
        $this->assertFalse($ask($this->member($this->organization, 'store')));
    }
}
