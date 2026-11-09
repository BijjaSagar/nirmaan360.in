<?php

namespace Tests\Feature\SiteAlerts;

use App\Models\Issue;
use App\Models\PettyCashEntry;
use App\Models\PettyCashWallet;
use App\Models\Project;

class SiteDashboardTest extends SiteAlertsTestCase
{
    private function url(?Project $project = null): string
    {
        return '/projects/'.($project ?? $this->project)->id.'/site-dashboard';
    }

    /** The engineer's example on 1 April: 35% of the work, 50% of the time, and ₹12,000 of petty cash spent against a ₹50,000 BOQ. */
    private function example(): void
    {
        $this->done($this->item('100.000', '500.00'), '35');
        $this->travelTo('2026-04-01 09:00');
        $this->scan('2026-04-01');

        $wallet = PettyCashWallet::create(['organization_id' => $this->organization->id, 'project_id' => $this->project->id, 'name' => 'Site cash']);
        PettyCashEntry::create(['organization_id' => $this->organization->id, 'petty_cash_wallet_id' => $wallet->id, 'type' => 'expense', 'amount' => '12000.00', 'category' => 'Materials', 'entry_date' => '2026-03-20']);
    }

    public function test_the_manager_sees_work_time_money_pending_items_and_alerts(): void
    {
        $this->example();
        $this->actingAs($this->member($this->organization, 'pm'));

        $this->get($this->url())->assertOk()
            ->assertSee('Work done')->assertSee('35%')
            ->assertSee('90 of 180 days')->assertSee('15% behind schedule')->assertSee('30 Jun 2026')
            ->assertSee('Money spent against budget')->assertSee('₹12,000.00')->assertSee('24% of the ₹50,000.00 budget, margin 5%')->assertSee('Budget taken from the BOQ value')
            ->assertSee('Projected at finish')->assertSee('₹34,285.71')
            ->assertSee('Pending items')
            ->assertSee('Open alerts')->assertSee('Behind schedule')
            ->assertSee('RCC')->assertSee('Brick work')
            ->assertSee('Check alerts now');
    }

    public function test_a_site_engineer_gets_quantities_time_and_pending_items_but_no_money(): void
    {
        $this->example();
        $this->actingAs($this->member($this->organization, 'engineer'));

        $this->get($this->url())->assertOk()
            ->assertSee('35%')->assertSee('90 of 180 days')->assertSee('15% behind schedule')
            ->assertSee('Pending items')->assertSee('Behind schedule')
            ->assertDontSee('Money spent against budget')->assertDontSee('₹')->assertDontSee('12,000')->assertDontSee('Budget')
            ->assertDontSee('Check alerts now');
    }

    public function test_accounts_see_no_total_spend_either(): void
    {
        $this->example();
        $this->actingAs($this->member($this->organization, 'accounts'));

        $this->get($this->url())->assertOk()->assertDontSee('Money spent against budget')->assertDontSee('₹');
    }

    public function test_pending_remarks_and_open_issues_show_on_the_pending_card(): void
    {
        $this->done($this->item(), '90', ['is_pending' => true, 'remark' => 'Outside passage wiring not done']);
        Issue::create(['organization_id' => $this->organization->id, 'project_id' => $this->project->id, 'title' => 'Crack in C7', 'priority' => 'high', 'status' => 'open']);
        $this->actingAs($this->owner);

        $this->get($this->url())->assertOk()
            ->assertSee('Outside passage wiring not done')
            ->assertSee('Open issues')
            ->assertSee('1 pending work remarks, 1 open issues');
    }

    public function test_rcc_and_brick_work_have_their_own_cards_with_done_against_boq(): void
    {
        $rcc = $this->item('185.000', '9000.00', ['description' => 'RCC M25 in footing', 'unit' => 'cum']);
        $brick = $this->item('100.000', '6500.00', ['description' => 'Brick work in CM 1:6', 'unit' => 'cum']);
        $this->item('640.000', '350.00', ['description' => 'Excavation for isolated footings', 'unit' => 'cum']);
        $this->done($rcc, '92.5');
        $this->done($brick, '25');
        $this->actingAs($this->owner);

        $this->get($this->url())->assertOk()
            ->assertSeeInOrder(['data-card="trade:rcc"', '50%', '92.5 / 185 cum', 'data-card="trade:brick"', '25%', '25 / 100 cum'], false)
            ->assertDontSee('Excavation for isolated footings');
    }

    public function test_the_trade_cards_keep_their_place_before_the_boq_has_such_lines(): void
    {
        $this->actingAs($this->owner);

        $this->get($this->url())->assertOk()
            ->assertSee('No RCC lines in the BOQ yet.')
            ->assertSee('No Brick work lines in the BOQ yet.');
    }

    public function test_without_dates_the_time_card_asks_for_them(): void
    {
        $this->project->update(['planned_days' => null]);
        $this->actingAs($this->owner);

        $this->get($this->url())->assertOk()->assertSee('Set the start date and planned days on the project to track time.');
    }

    public function test_other_companies_and_unassigned_projects_are_hidden(): void
    {
        $other = $this->makeProject($this->tenant('Other Co'));
        $this->actingAs($this->owner)->get($this->url($other))->assertNotFound();

        $outsider = $this->member($this->organization, 'engineer');
        $this->limitTo($outsider);
        $this->actingAs($outsider)->get($this->url())->assertNotFound();

        $insider = $this->member($this->organization, 'engineer');
        $this->limitTo($insider, $this->project);
        $this->actingAs($insider)->get($this->url())->assertOk();
    }

    public function test_the_dashboard_needs_the_site_control_feature(): void
    {
        $free = $this->tenant('Free Co', 'free');
        $project = $this->makeProject($free);

        $this->actingAs($this->member($free, 'owner'))->get($this->url($project))->assertForbidden();
    }

    public function test_the_project_menu_links_to_the_dashboard_and_alerts(): void
    {
        $this->actingAs($this->owner);

        $this->get('/projects/'.$this->project->id)->assertOk()
            ->assertSee(route('site-dashboard.show', $this->project))
            ->assertSee(route('alerts.index', $this->project));
    }
}
