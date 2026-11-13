<?php

namespace Tests\Feature\DocumentReading;

use App\Http\Middleware\RequireAiReader;
use App\Models\DocumentRead;
use App\Models\DocumentReadPage;
use App\Models\User;
use App\Support\PlatformSettings;
use Illuminate\Cache\RateLimiter;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;

/**
 * Who reaches the shared read routes: the read's own company, people who see its project and hold
 * what its kind asks for, on a plan with the AI features. And what guards the routes that lead to a
 * page being sent, while the routes that only show something stay open.
 */
class ReadRoutesAccessTest extends ReadRoutesTestCase
{
    /** Every shared route with a request that would succeed for the owner. The read has two uploaded pages; the second failed as cut off. */
    private function everyRoute(DocumentRead $read, DocumentReadPage $failed): array
    {
        return [
            'status' => ['GET', '/status', []],
            'image' => ['GET', "/pages/{$failed->id}/image", []],
            'cancel' => ['POST', '/cancel', []],
            'upload' => ['POST', '/pages', ['page_no' => 3, 'image' => $this->picture()]],
            'source' => ['PUT', '/source', ['file' => $this->file('estimate.pdf', "%PDF-1.4\n%%EOF\n")]],
            'quote' => ['POST', '/quote', []],
            'start' => ['POST', '/start', []],
            'resume' => ['POST', '/resume', []],
            'retry' => ['POST', "/pages/{$failed->id}/retry", []],
            'split' => ['POST', "/pages/{$failed->id}/split", []],
        ];
    }

    /** The routes that lead to a page being sent, and so carry the reader's guard and need "agents.use". */
    private const SENDING = ['upload', 'source', 'quote', 'start', 'resume', 'retry', 'split'];

    /** A read with an uploaded page and a failed one, so every route has something to act on. */
    private function fixture(array $overrides = [], ?User $by = null): array
    {
        $read = $this->draft($overrides, $by);
        $pass = $read->kind === 'boq' ? 'rows' : 'main';
        $this->storedPage($read, 1, ['status' => DocumentReadPage::WAITING, 'pass' => $pass]);
        $failed = $this->storedPage($read, 2, ['status' => DocumentReadPage::FAILED, 'fail_reason' => 'cut_off', 'attempts' => 1, 'pass' => $pass]);

        return [$read, $failed];
    }

    private function hit(User $user, bool $api, DocumentRead $read, array $route): TestResponse
    {
        [$method, $path, $data] = $route;

        if ($api) {
            // The phone's status route is the read's own address.
            return $this->api($user, $method, $read, $path === '/status' ? '' : $path, $data);
        }

        return $this->actingAs($user)->call($method, "/document-reads/{$read->id}{$path}", array_filter($data, fn ($value) => ! is_object($value)), [], array_filter($data, is_object(...)), ['HTTP_ACCEPT' => 'application/json']);
    }

    public function test_another_companys_read_does_not_exist_on_any_route(): void
    {
        [$read, $failed] = $this->fixture();
        $outsider = $this->member($this->tenant('Godavari Infra'), 'owner');
        $this->consent($outsider->organization);
        $before = $read->refresh()->toArray();

        foreach ([false, true] as $api) {
            foreach ($this->everyRoute($read, $failed) as $name => $route) {
                $this->hit($outsider, $api, $read, $route)->assertNotFound();
            }
        }

        $this->assertSame($before, $read->refresh()->toArray());
        $this->assertCount(2, $this->rows($read));
        $this->assertSame([], $this->queuedPages());

        // A page of another company is not found under one's own read either.
        $own = $this->makeRead($outsider->organization, $outsider, ['status' => DocumentRead::DRAFT]);
        $this->actingAs($outsider)->getJson("/document-reads/{$own->id}/pages/{$failed->id}/image")->assertNotFound();
        $this->actingAs($outsider)->postJson("/document-reads/{$own->id}/pages/{$failed->id}/retry")->assertNotFound();
    }

    public function test_someone_limited_to_other_projects_cannot_reach_a_projects_read(): void
    {
        [$read, $failed] = $this->fixture();
        $limited = $this->member($this->organization, 'pm');
        $limited->forceFill(['limited_to_projects' => true])->save();
        $limited->assignedProjects()->attach($this->makeProject($this->organization, ['name' => 'Canal Road Block']), ['organization_id' => $this->organization->id]);

        foreach ([false, true] as $api) {
            foreach ($this->everyRoute($read, $failed) as $route) {
                $this->hit($limited, $api, $read, $route)->assertNotFound();
            }
        }

        // Once the project is theirs, so is the read.
        $limited->assignedProjects()->attach($this->project, ['organization_id' => $this->organization->id]);
        $this->web($limited, 'GET', $read, '/status')->assertOk();

        // A read that belongs to no project, such as a tender document, is a company-level record and stays visible.
        $company = $this->draft(['kind' => 'tender', 'project_id' => null]);
        $this->web($limited, 'GET', $company, '/status')->assertOk();
    }

    public function test_a_read_is_open_only_to_people_who_hold_what_its_kind_asks_for(): void
    {
        [$read, $failed] = $this->fixture();
        Support\FakeKind::$abilities = ['all' => ['boq.view'], 'any' => [['boq.manage', 'measurement.approve']]];

        // An engineer sees the BOQ but holds neither of the second group.
        $engineer = $this->member($this->organization, 'engineer');
        $this->assertTrue($engineer->can('boq.view') && $engineer->can('agents.use'));

        foreach ([false, true] as $api) {
            foreach ($this->everyRoute($read, $failed) as $route) {
                $this->hit($engineer, $api, $read, $route)->assertForbidden();
            }
        }

        // A project manager holds both.
        $pm = $this->member($this->organization, 'pm');
        $this->web($pm, 'GET', $read, '/status')->assertOk();
        $this->api($pm, 'POST', $read, '/quote')->assertOk();
    }

    public function test_sending_needs_the_right_to_use_ai_and_watching_does_not(): void
    {
        [$read, $failed] = $this->fixture();
        // A viewer sees the BOQ, but may not use the AI features.
        $viewer = $this->member($this->organization, 'viewer');
        $this->assertTrue($viewer->can('boq.view'));
        $this->assertFalse($viewer->can('agents.use'));

        foreach ([false, true] as $api) {
            foreach ($this->everyRoute($read, $failed) as $name => $route) {
                $response = $this->hit($viewer, $api, $read, $route);

                in_array($name, self::SENDING, true) ? $response->assertForbidden() : $this->assertLessThan(300, $response->getStatusCode(), $name);
            }

            $read->update(['status' => DocumentRead::DRAFT]);
        }

        $this->assertCount(2, $this->rows($read));
        $this->assertSame([], $this->queuedPages());
    }

    public function test_a_plan_without_the_ai_features_or_without_the_kinds_own_module_is_refused(): void
    {
        [$read, $failed] = $this->fixture();
        $routes = $this->everyRoute($read, $failed);

        // The plan no longer includes the module the reader belongs to.
        config(['modules.document_reading.kinds.boq.feature' => 'a_module_this_plan_lacks']);

        foreach ([false, true] as $api) {
            foreach ($routes as $route) {
                // Both ask for JSON, so neither is told to upgrade (App\Support\PlanWording).
                $this->hit($this->owner, $api, $read, $route)->assertForbidden()->assertJson(['message' => "Your company's plan does not include this module. Your company's owner can check the plan."]);
            }
        }

        config(['modules.document_reading.kinds.boq.feature' => 'reports']);
        $this->web($this->owner, 'GET', $read, '/status')->assertOk();

        // The plan has no AI features at all.
        $this->organization->update(['plan' => 'contractor']);

        foreach ([false, true] as $api) {
            foreach ($routes as $route) {
                $this->hit($this->owner->refresh(), $api, $read, $route)->assertForbidden();
            }
        }

        $this->assertSame([], $this->queuedPages());
    }

    public function test_a_slip_read_is_its_own_persons_and_an_owners_or_admins(): void
    {
        $storeKeeper = $this->member($this->organization, 'store');
        [$read, $failed] = $this->fixture(['kind' => 'slip'], $storeKeeper);
        $routes = $this->everyRoute($read, $failed);
        unset($routes['source']);

        // Another store keeper holds every permission the kind asks for, and still may not open it.
        $colleague = $this->member($this->organization, 'store');
        $pm = $this->member($this->organization, 'pm');

        foreach ([$colleague, $pm] as $other) {
            foreach ([false, true] as $api) {
                foreach ($routes as $route) {
                    $this->hit($other, $api, $read, $route)->assertForbidden();
                }
            }
        }

        $this->web($storeKeeper, 'GET', $read, '/status')->assertOk();
        $this->actingAs($storeKeeper)->get("/document-reads/{$read->id}/pages/{$failed->id}/image")->assertOk();
        $this->web($this->owner, 'GET', $read, '/status')->assertOk();
        $this->web($this->member($this->organization, 'admin'), 'GET', $read, '/status')->assertOk();

        // An owner who starts it for them does not take it away from them.
        $this->web($this->owner, 'POST', $read, '/start', ['page_from' => 1, 'page_to' => 1])->assertStatus(202);
        $this->assertSame($storeKeeper->id, $read->refresh()->user_id);
        $this->web($storeKeeper, 'GET', $read, '/status')->assertOk()->assertJson(['status' => 'running']);

        // A read nobody is on any more is for owners and admins alone.
        $read->update(['user_id' => null]);
        $this->web($storeKeeper, 'GET', $read, '/status')->assertForbidden();
        $this->web($this->owner, 'GET', $read, '/status')->assertOk();
    }

    public function test_a_read_whose_reader_is_not_deployed_is_not_found(): void
    {
        [$read, $failed] = $this->fixture(['kind' => 'bill']);

        foreach ([false, true] as $api) {
            foreach ($this->everyRoute($read, $failed) as $route) {
                $this->hit($this->owner, $api, $read, $route)->assertNotFound();
            }
        }
    }

    public function test_nobody_reaches_a_read_without_signing_in(): void
    {
        [$read, $failed] = $this->fixture();

        foreach ($this->everyRoute($read, $failed) as [$method, $path, $data]) {
            $this->json($method, "/document-reads/{$read->id}{$path}")->assertUnauthorized();
            $this->json($method, "/api/v1/document-reads/{$read->id}".($path === '/status' ? '' : $path))->assertUnauthorized();
        }

        $this->getJson('/api/v1/document-reading/status')->assertUnauthorized();
    }

    public function test_routes_that_send_are_guarded_and_routes_that_show_never_are(): void
    {
        // Each route is called many times here; their counters have a test of their own.
        $this->withoutMiddleware(ThrottleRequests::class);
        [$read, $failed] = $this->fixture();
        $routes = $this->everyRoute($read, $failed);

        $refusals = [
            'not_released' => fn () => app(PlatformSettings::class)->set(['ai_readers_released' => '0']),
            'not_connected' => fn () => app(PlatformSettings::class)->set(['ai_readers_released' => '1', 'anthropic_api_key' => null]),
            'consent_required' => function () {
                $this->connect();
                $this->consent($this->organization, false);
            },
        ];

        foreach ($refusals as $code => $arrange) {
            $arrange();

            foreach ([false, true] as $api) {
                foreach ($routes as $name => $route) {
                    $response = $this->hit($this->owner, $api, $read, $route);

                    if (in_array($name, self::SENDING, true)) {
                        $response->assertStatus(409)->assertJson(['code' => $code]);
                    } elseif ($name !== 'cancel') {
                        // Watching a read and looking at its pages work whatever the provider, the release or the consent say.
                        $response->assertOk();
                    }
                }
            }
        }

        // And so does cancelling.
        $this->web($this->owner, 'POST', $read, '/cancel')->assertOk()->assertJson(['status' => 'cancelled']);

        $this->assertCount(2, $this->rows($read));
        $this->assertSame([], $this->queuedPages());
        Http::assertNothingSent();
    }

    public function test_every_shared_route_is_registered_with_its_guards(): void
    {
        $routes = collect(Route::getRoutes()->getRoutes())->filter(fn ($route) => str_contains($route->getActionName(), 'DocumentReadController'));

        $this->assertCount(20, $routes);

        foreach ($routes as $route) {
            $middleware = $route->gatherMiddleware();
            $name = (string) $route->getName();
            $sends = ! in_array('GET', $route->methods(), true) && ! str_ends_with($name, '.cancel');

            $this->assertContains('feature:ai_agents', $middleware, $name);
            $this->assertSame($sends, in_array('can:agents.use', $middleware, true), $name);
            $this->assertSame($sends, in_array(RequireAiReader::class.':consent', $middleware, true), $name);

            // No throttle without a name of its own: an unnamed one would share its counter with every other route of the person.
            foreach ($middleware as $entry) {
                if (is_string($entry) && str_starts_with($entry, 'throttle:') && $entry !== 'throttle:120,1') {
                    $this->assertCount(3, explode(',', $entry), "{$name} has an unnamed throttle");
                }
            }

            if ($sends) {
                $this->assertNotEmpty(array_filter($middleware, fn ($entry) => is_string($entry) && preg_match('/^throttle:\d+,1,read-/', $entry) === 1), "{$name} has no throttle of its own");
            }
        }

        $byName = $routes->keyBy(fn ($route) => $route->getName());
        $this->assertContains('throttle:240,1,read-page', $byName['document-reads.pages.store']->gatherMiddleware());
        $this->assertContains('throttle:10,1,read-start', $byName['document-reads.start']->gatherMiddleware());
        $this->assertContains('throttle:10,1,read-retry', $byName['document-reads.pages.retry']->gatherMiddleware());

        // On the phone, uploading sixty pages and watching a read do not use up the budget the app's sync shares.
        foreach (['api.document-reads.pages.store', 'api.document-reads.status'] as $outside) {
            $this->assertContains('throttle:120,1', $byName[$outside]->excludedMiddleware(), $outside);
        }

        $this->assertContains('throttle:120,1', $byName['api.document-reads.start']->gatherMiddleware());
        $this->assertSame([], $byName['api.document-reads.start']->excludedMiddleware());
    }

    public function test_a_persons_other_requests_do_not_use_up_the_counters_of_the_read_routes(): void
    {
        [$read, $failed] = $this->fixture();

        // The counter an unnamed throttle keeps for this person is far beyond any limit.
        $limiter = app(RateLimiter::class);

        foreach (range(1, 300) as $ignored) {
            $limiter->hit(sha1((string) $this->owner->id), 60);
        }

        Sanctum::actingAs($this->owner);
        $this->getJson('/api/v1/me')->assertStatus(429);

        // The phone still uploads and still watches.
        $this->api($this->owner, 'POST', $read, '/pages', ['page_no' => 3, 'image' => $this->picture()])->assertStatus(201);
        $this->api($this->owner, 'GET', $read)->assertOk();

        // On the web every throttled read route has a counter of its own.
        $this->upload($read, 3)->assertStatus(201);
        $this->web($this->owner, 'POST', $read, '/quote')->assertOk();
        $this->actingAs($this->owner)->put("/document-reads/{$read->id}/source", ['file' => $this->file('estimate.pdf', "%PDF-1.4\n%%EOF\n")], ['Accept' => 'application/json'])->assertNoContent();
        $this->web($this->owner, 'POST', $read, '/start', ['page_from' => 1, 'page_to' => 1])->assertStatus(202);
        $this->web($this->owner, 'POST', $read, "/pages/{$failed->id}/retry")->assertStatus(409)->assertJson(['code' => 'cut_off']);
        $this->web($this->owner, 'POST', $read, "/pages/{$failed->id}/split")->assertStatus(409)->assertJson(['code' => 'pages_missing']);
        $this->web($this->owner, 'POST', $read, '/resume')->assertStatus(409)->assertJson(['code' => 'wrong_state']);
    }

    public function test_starting_is_limited_to_ten_a_minute_for_each_person(): void
    {
        $read = $this->draft();

        foreach (range(1, 10) as $ignored) {
            $this->web($this->owner, 'POST', $read, '/start')->assertStatus(409);
        }

        $this->web($this->owner, 'POST', $read, '/start')->assertStatus(429);

        // Another person has a counter of their own, and so has another route.
        $this->web($this->member($this->organization, 'admin'), 'POST', $read, '/start')->assertStatus(409);
        $this->web($this->owner, 'POST', $read, '/quote')->assertOk();
    }
}
