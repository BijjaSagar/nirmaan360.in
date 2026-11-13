<?php

namespace Tests\Feature\DocumentReading;

use App\Models\AiDocumentSettings;
use App\Models\ReaderTrial;
use App\Models\User;
use App\Services\DocumentReading\AiSpend;
use App\Services\DocumentReading\ReaderFixtures;
use Illuminate\Routing\Route as RouteDefinition;
use Illuminate\Support\Facades\Route;

/**
 * The platform admin's AI reader page and its dry runs are for platform staff alone. Everyone else,
 * the owner of a company included, gets a 404 on every route and changes nothing.
 */
class ReaderAdminAccessTest extends ReaderTrialTestCase
{
    /** @return list<RouteDefinition> */
    private function adminRoutes(): array
    {
        return collect(Route::getRoutes()->getRoutes())
            ->filter(fn (RouteDefinition $route) => str_starts_with((string) $route->getName(), 'ai-reader.admin.'))
            ->values()->all();
    }

    private function url(RouteDefinition $route, ReaderTrial $trial): string
    {
        $page = $this->pageOf($trial);

        return route($route->getName(), array_intersect_key([
            'trial' => $trial->id,
            'page' => $page->id,
            'organization' => $this->organization->id,
            'name' => ReaderFixtures::names()[0] ?? 'missing',
        ], array_flip($route->parameterNames())));
    }

    private function callEach(?User $as, ReaderTrial $trial, callable $assert): void
    {
        foreach ($this->adminRoutes() as $route) {
            $method = collect($route->methods())->reject(fn ($verb) => $verb === 'HEAD')->first();
            $request = $as === null ? $this : $this->actingAs($as);
            $data = ['action' => 'release', 'alerts_set' => '1', 'dry_run' => ['boq', 'tender', 'slip'], 'allowed' => '1', 'kind' => 'boq', 'pass' => 'rows', 'pages_total' => 1, 'monthly_cap' => '500', 'text' => 'Some notes', 'page_no' => 1, 'field' => 'rows.qty', 'row' => '0', 'wrong' => true];

            $assert($request->json($method, $this->url($route, $trial), $data), $route);
        }
    }

    public function test_the_page_lists_every_route_the_design_names(): void
    {
        $names = array_map(fn (RouteDefinition $route) => $route->getName(), $this->adminRoutes());

        foreach (['index', 'settings', 'fixtures', 'trials.store', 'trials.show', 'trials.status', 'trials.pages.store', 'trials.text', 'trials.start', 'trials.marks', 'trials.scores', 'trials.destroy'] as $name) {
            $this->assertContains("ai-reader.admin.{$name}", $names);
        }

        $this->assertSame('admin/ai-reader/trials/{trial}/scores.csv', Route::getRoutes()->getByName('ai-reader.admin.trials.scores')->uri());
    }

    public function test_the_owner_of_a_company_gets_a_404_everywhere_and_changes_nothing(): void
    {
        $trial = $this->trialWithPages(1);
        $this->fakeReader([$this->answer()]);

        foreach ([$this->owner, $this->member($this->organization, 'admin'), $this->member($this->tenant('Godavari Infra'), 'owner')] as $person) {
            $this->callEach($person, $trial, fn ($response, RouteDefinition $route) => $response->assertNotFound());
        }

        $spend = app(AiSpend::class);
        $this->assertFalse($spend->switchedOn());
        $this->assertNull($spend->monthlyCap());
        $this->assertFalse($spend->readersOn($this->organization));
        $this->assertSame(1, ReaderTrial::count());
        $this->assertSame(ReaderTrial::DRAFT, $trial->refresh()->status);
        $this->assertFalse((bool) AiDocumentSettings::for($this->organization)->platform_block);
        $this->assertSame([], $this->sent);
    }

    public function test_someone_signed_out_is_sent_to_sign_in(): void
    {
        $trial = $this->trialWithPages(1);

        $this->callEach(null, $trial, fn ($response) => $response->assertUnauthorized());

        $this->get('/admin/ai-reader')->assertRedirect();
        $this->assertFalse(app(AiSpend::class)->switchedOn());
    }

    public function test_platform_staff_see_the_page_with_the_readers_off_until_they_release_them(): void
    {
        $this->actingAs($this->admin)->get('/admin/ai-reader')
            ->assertOk()
            ->assertSee('Not offered to companies')
            ->assertSee('Offer the readers to companies')
            ->assertSee('The provider&#039;s monthly cap is not entered yet.', false);

        $this->assertFalse(app(AiSpend::class)->switchedOn());
        $this->assertFalse(app(AiSpend::class)->readersOn());
    }

    public function test_a_page_picture_is_served_only_under_its_own_dry_run(): void
    {
        $first = $this->trialWithPages(1);
        $second = $this->trialWithPages(1);

        $this->actingAs($this->admin)->get(route('ai-reader.admin.trials.pages.image', [$first, $this->pageOf($first)]))
            ->assertOk()->assertHeader('Content-Type', 'image/jpeg');

        $this->actingAs($this->admin)->get(route('ai-reader.admin.trials.pages.image', [$second, $this->pageOf($first)]))->assertNotFound();
    }

    public function test_a_company_that_does_not_exist_is_not_found(): void
    {
        $this->actingAs($this->admin)->patch('/admin/ai-reader/companies/999999', ['action' => 'block'])->assertNotFound();
    }
}
