<?php

namespace Tests\Feature\RateHelper;

use App\Models\RateSuggestionLine;
use App\Models\RateSuggestionRun;
use App\Services\RateHelper\Sources\RateLibrarySource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\SiteControl\BuildsMoneyWorld;
use Tests\TestCase;

/**
 * The control for the module's file in SiteControl/MoneyWorld: the run it adds to the walked company
 * really does put a rupee figure on the review screen for the owner, so the walks that find no money
 * there for site roles are looking at a page that has some.
 */
class RouteWalkFixtureTest extends TestCase
{
    use BuildsMoneyWorld, RefreshDatabase;

    public function test_the_walked_run_shows_the_owner_a_rate_and_refuses_every_site_role(): void
    {
        $this->withoutVite();
        $this->buildMoneyWorld();

        $run = $this->rows[RateSuggestionRun::class];
        $line = $this->rows[RateSuggestionLine::class];

        $this->assertInstanceOf(RateSuggestionRun::class, $run);
        $this->assertSame($this->project->id, $run->project_id);
        $this->assertSame($run->id, $line->run_id);
        $this->assertSame(RateLibrarySource::KEY, $line->candidates[0]['source']);

        $page = $this->actingAs($this->users['owner'])->get(route('rate-suggestions.show', $run))->assertOk();
        $this->assertStringContainsString($this->needle('library_rate'), $this->digits($page->getContent()));

        foreach (['engineer', 'store', 'viewer'] as $role) {
            $this->actingAs($this->users[$role])->get(route('rate-suggestions.show', $run))->assertForbidden();
        }
    }
}
