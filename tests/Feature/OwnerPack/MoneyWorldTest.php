<?php

namespace Tests\Feature\OwnerPack;

use App\Models\ClientQuotation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\SiteControl\BuildsMoneyWorld;
use Tests\TestCase;

/**
 * The control for the two route walks. They prove that site roles receive no money on any signed-in
 * route, with the quotation this module files in SiteControl/MoneyWorld/owner_pack.php. Here the
 * same record is shown to carry the sentinel figure for those who may see it, so the walks cannot
 * pass by the quotation screens showing nothing to anyone.
 */
class MoneyWorldTest extends TestCase
{
    use BuildsMoneyWorld, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->buildMoneyWorld();
    }

    public function test_the_walks_get_an_issued_and_shared_quotation_that_carries_the_boq_figure(): void
    {
        $quotation = $this->rows[ClientQuotation::class];

        $this->assertSame([$this->organization->id, $this->project->id, ClientQuotation::ISSUED], [$quotation->organization_id, $quotation->project_id, $quotation->status]);
        $this->assertTrue($quotation->isShared());
        $this->assertSame(['id' => $quotation->id, 'token' => $quotation->share_token], $this->routeParams['quotations.shared']);

        foreach (['owner', 'pm', 'accounts', 'qs'] as $role) {
            foreach ([route('quotations.show', $quotation), route('quotations.index', $this->project)] as $page) {
                $response = $this->actingAs($this->users[$role])->get($page)->assertOk();

                $this->assertStringContainsString($this->needle('boq_rate'), $this->digits($response->getContent()), "{$role} sees the figure at {$page}");
            }
        }
    }

    public function test_site_roles_are_refused_and_the_shared_address_without_its_signature_opens_for_nobody(): void
    {
        $quotation = $this->rows[ClientQuotation::class];
        $unsigned = route('quotations.shared', $this->routeParams['quotations.shared']);

        foreach (['engineer', 'store', 'viewer'] as $role) {
            $this->actingAs($this->users[$role]);

            $this->get(route('quotations.show', $quotation))->assertForbidden();
            $this->get(route('quotations.index', $this->project))->assertForbidden();
            $this->get(route('quotations.create', $this->project))->assertForbidden();
        }

        foreach (['owner', 'engineer'] as $role) {
            $response = $this->actingAs($this->users[$role])->get($unsigned)->assertForbidden();

            $this->assertStringNotContainsString($this->needle('boq_rate'), $this->digits($response->getContent()));
        }
    }
}
