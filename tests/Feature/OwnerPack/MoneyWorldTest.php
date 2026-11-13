<?php

namespace Tests\Feature\OwnerPack;

use App\Models\ClientQuotation;
use App\Models\OwnerUpdate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\SiteControl\BuildsMoneyWorld;
use Tests\TestCase;

/**
 * The control for the two route walks. They prove that site roles receive no money on any signed-in
 * route, with the quotation and the weekly update this module files in
 * SiteControl/MoneyWorld/owner_pack.php. Here the same records are shown to carry the sentinel
 * figure for those who may see it, so the walks cannot pass by the screens showing nothing to anyone.
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

    public function test_the_walks_get_a_shared_weekly_update_that_states_the_same_figure_to_those_who_may_see_it(): void
    {
        $update = $this->rows[OwnerUpdate::class];
        $quotation = $this->rows[ClientQuotation::class];

        $this->assertSame([$this->organization->id, $this->project->id, OwnerUpdate::SHARED, true, $quotation->id], [$update->organization_id, $update->project_id, $update->status, $update->show_money, $update->quotation_id]);
        $this->assertTrue($update->isShared());
        $this->assertSame(['id' => $update->id, 'token' => $update->share_token], $this->routeParams['owner-updates.shared']);
        $this->assertSame(['id' => $update->id, 'token' => $update->share_token, 'photo' => $update->photoIds()[0]], $this->routeParams['owner-updates.shared.photo']);

        // Those who manage the pack and may see money read the quotation total in the update.
        foreach (['owner', 'pm'] as $role) {
            $response = $this->actingAs($this->users[$role])->get(route('owner-updates.show', $update))->assertOk();

            $this->assertStringContainsString($this->needle('boq_rate'), $this->digits($response->getContent()), "{$role} sees the figure in the update");
            $this->get(route('owner-updates.index', $this->project))->assertOk()->assertSee('States the payment position');
        }
    }

    public function test_site_roles_get_no_figure_from_the_weekly_update_and_the_shared_addresses_need_their_signatures(): void
    {
        $update = $this->rows[OwnerUpdate::class];

        // The engineer prepares updates, so the list opens. It carries no figure, and the update that states money stays closed.
        $list = $this->actingAs($this->users['engineer'])->get(route('owner-updates.index', $this->project))->assertOk()->assertSee('States the payment position, which your role does not see');
        $this->assertStringNotContainsString($this->needle('boq_rate'), $this->digits($list->getContent()));
        $this->assertStringNotContainsString('₹', $list->getContent());
        $this->get(route('owner-updates.show', $update))->assertForbidden();
        $this->get(route('owner-updates.create', $this->project))->assertOk()->assertDontSee('show_money', false);

        foreach (['store', 'viewer', 'accounts', 'qs'] as $role) {
            $this->actingAs($this->users[$role]);

            $this->get(route('owner-updates.index', $this->project))->assertForbidden();
            $this->get(route('owner-updates.show', $update))->assertForbidden();
            $this->get(route('owner-updates.create', $this->project))->assertForbidden();
        }

        foreach (['owner-updates.shared', 'owner-updates.shared.photo'] as $name) {
            foreach (['owner', 'engineer'] as $role) {
                $response = $this->actingAs($this->users[$role])->get(route($name, $this->routeParams[$name]))->assertForbidden();

                $this->assertStringNotContainsString($this->needle('boq_rate'), $this->digits($response->getContent()));
            }
        }
    }
}
