<?php

namespace Tests\Feature\PriceWatch;

use App\Models\PriceWatchMaterial;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;

class PriceWatchApiTest extends PriceWatchTestCase
{
    private PriceWatchMaterial $cement;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-10 10:00:00');
        $this->cement = $this->watch('Cement', 'bag', 'cement');
        $this->price($this->cement, '83614700', '2026-11-01');
        $this->price($this->cement, '83614750', '2026-11-09', 'Market enquiry');
        $this->boqRate($this->cement, '83614600', '600');
    }

    public function test_owners_and_project_managers_read_the_board_a_material_and_the_project_impact(): void
    {
        foreach ([$this->owner, $this->pm] as $user) {
            Sanctum::actingAs($user);

            $board = $this->getJson(self::API.'/price-watch')->assertOk()
                ->assertJsonPath('meta.live_feed', false)->assertJsonPath('meta.stale_after_days', 30)
                ->assertJsonPath('data.0.name', 'Cement')->assertJsonPath('data.0.current.rate', '83614750.00')->assertJsonPath('data.0.current.source', 'Market enquiry')
                ->assertJsonPath('data.0.previous.rate', '83614700.00')->assertJsonPath('data.0.change_pct', '0.00')->assertJsonPath('data.0.current.stale', false)
                ->json('data');
            // The default list joined the board on the first read.
            $this->assertCount(8, $board);
            $this->assertArrayNotHasKey('material', $board[0]);

            $this->getJson(self::API.'/price-watch/materials/'.$this->cement->id)->assertOk()
                ->assertJsonPath('data.id', $this->cement->id)->assertJsonCount(2, 'data.prices')->assertJsonPath('data.prices.0.rate', '83614750.00')->assertJsonPath('data.trend.0.rate', '83614700.00');

            $this->getJson(self::API.'/projects/'.$this->project->id.'/price-watch')->assertOk()
                ->assertJsonPath('data.0.boq_rate', '83614600.00')->assertJsonPath('data.0.boq_qty', '600.000')->assertJsonPath('data.0.remaining', '600.000')
                ->assertJsonPath('data.0.impact', '90000.00')->assertJsonPath('data.0.why', null)
                ->assertJsonPath('summary.impact', '90000.00')->assertJsonPath('summary.basis', 'budget')->assertJsonPath('summary.impact_pct', '9.00');
        }
    }

    public function test_site_roles_get_no_figures_at_all(): void
    {
        foreach (['engineer', 'store', 'viewer'] as $role) {
            Sanctum::actingAs($this->{$role});

            foreach ([self::API.'/price-watch', self::API.'/price-watch/materials/'.$this->cement->id, self::API.'/projects/'.$this->project->id.'/price-watch'] as $url) {
                $response = $this->getJson($url);
                $response->assertForbidden();
                $this->assertStringNotContainsString('836147', (string) $response->getContent(), "{$role} at {$url}");
                $this->assertStringNotContainsString('836146', (string) $response->getContent(), "{$role} at {$url}");
            }
        }

        // Rates without the budget: the estimator and the accountant read the board but not the project impact.
        foreach ([$this->qs, $this->accounts] as $user) {
            Sanctum::actingAs($user);
            $this->getJson(self::API.'/price-watch')->assertOk();
            $this->getJson(self::API.'/projects/'.$this->project->id.'/price-watch')->assertForbidden();
        }
    }

    public function test_another_company_and_a_limited_manager_cannot_reach_what_is_not_theirs(): void
    {
        [, $rivalOwner, $rivalProject] = $this->rival();
        Sanctum::actingAs($rivalOwner);
        $this->getJson(self::API.'/price-watch/materials/'.$this->cement->id)->assertNotFound();
        $this->getJson(self::API.'/projects/'.$this->project->id.'/price-watch')->assertNotFound();
        $this->assertStringNotContainsString('836147', (string) $this->getJson(self::API.'/price-watch')->assertOk()->getContent());
        $this->getJson(self::API.'/projects/'.$rivalProject->id.'/price-watch')->assertOk()->assertJsonPath('summary.with_boq_rate', 0);

        $elsewhere = $this->makeProject($this->org, ['code' => 'ELSE']);
        Sanctum::actingAs($this->limitedPm($elsewhere));
        $this->getJson(self::API.'/projects/'.$this->project->id.'/price-watch')->assertNotFound();
        $this->getJson(self::API.'/projects/'.$elsewhere->id.'/price-watch')->assertOk();
        // The board is company-wide, so a limited manager still sees it.
        $this->getJson(self::API.'/price-watch')->assertOk()->assertJsonPath('data.0.current.rate', '83614750.00');
    }

    public function test_the_api_is_read_only(): void
    {
        Sanctum::actingAs($this->owner);

        $this->postJson(self::API.'/price-watch', ['rate' => '1'])->assertStatus(405);
        $this->postJson(self::API.'/projects/'.$this->project->id.'/price-watch', ['rates' => []])->assertStatus(405);
    }
}
