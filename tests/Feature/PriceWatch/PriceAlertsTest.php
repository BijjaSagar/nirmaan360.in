<?php

namespace Tests\Feature\PriceWatch;

use App\Models\Alert;
use App\Models\PriceWatchMaterial;
use App\Models\PriceWatchProjectRate;
use App\Services\PriceWatch\Alerts\PriceAlertSource;
use App\Services\SiteAlerts\AlertEngine;
use App\Services\SiteAlerts\SiteDashboard;
use App\Support\PlatformSettings;
use Illuminate\Support\Carbon;

/** The price alerts: a material above the BOQ rate, a jump since the previous price, and the budget impact. */
class PriceAlertsTest extends PriceWatchTestCase
{
    private PriceWatchMaterial $cement;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-11-10 10:00:00');
        // 600 bags still to buy, quoted at ₹380 in the BOQ.
        $this->cement = $this->watch('Cement', 'bag', 'cement');
        $this->boqRate($this->cement, '380', '600');
    }

    private function scan(): array
    {
        return app(AlertEngine::class)->scanProject($this->project->fresh(), Carbon::parse('2026-11-10'));
    }

    private function alerts(string $type)
    {
        return Alert::withoutGlobalScopes()->where('project_id', $this->project->id)->where('type', $type);
    }

    public function test_a_price_5_percent_above_the_boq_rate_is_a_rate_alert_for_money_viewers(): void
    {
        // Only the rate alert is under test here, so the budget alert is kept out of the way.
        app(PlatformSettings::class)->set(['price_watch_impact_pct' => '50']);
        $this->price($this->cement, '399', '2026-11-09', 'Birla dealer');

        $this->scan();

        $alert = $this->alerts(PriceAlertSource::RISE)->sole();
        $this->assertSame('Material price above the BOQ rate', $alert->titleIn('en'));
        $this->assertSame('Cement is ₹399.00 per bag as on 09 Nov 2026, Birla dealer, 5% above the BOQ rate of ₹380.00.', $alert->messageIn('en'));
        $this->assertSame('warning', $alert->severity);
        $this->assertSame('price_watch:'.$this->cement->id, $alert->reference);
        $this->assertSame('money.view', $alert->needs_permission);
        $this->assertSame(['380.00', '5.00', '399.00'], [$alert->data['boq_rate'], $alert->data['above_boq_pct'], $alert->data['current']['rate']]);
        $this->assertSame(route('price-watch.project', $this->project), $alert->url);
        $this->assertStringContainsString('BOQ दर', $alert->messageIn('hi'));

        $this->assertTrue(Alert::visibleTo($this->pm)->whereKey($alert->id)->exists());
        $this->assertTrue(Alert::visibleTo($this->qs)->whereKey($alert->id)->exists());
        $this->assertFalse(Alert::visibleTo($this->engineer)->whereKey($alert->id)->exists());
        $this->assertSame(1, $this->pm->notifications()->count());
        $this->assertSame(0, $this->engineer->notifications()->count());
        $this->assertSame(0, $this->store->notifications()->count());

        // Twice the limit is critical.
        $this->price($this->cement, '418', '2026-11-10');
        $this->scan();
        $this->assertSame('critical', $alert->fresh()->severity);

        // The price comes back down and the alert closes.
        $this->price($this->cement, '390', '2026-11-10');
        $this->assertSame(1, $this->scan()['resolved']);
        $this->assertSame(0, $this->alerts(PriceAlertSource::RISE)->open()->count());
    }

    public function test_below_the_limit_nothing_opens_and_the_limit_is_adjustable(): void
    {
        $this->price($this->cement, '395', '2026-11-09');

        $this->scan();
        $this->assertSame(0, $this->alerts(PriceAlertSource::RISE)->count());

        app(PlatformSettings::class)->set(['price_watch_rise_pct' => '3']);
        $this->scan();
        $this->assertSame(1, $this->alerts(PriceAlertSource::RISE)->open()->count());
    }

    public function test_an_out_of_date_price_raises_nothing(): void
    {
        $this->price($this->cement, '460', '2026-10-01');

        $this->scan();

        $this->assertSame(0, Alert::withoutGlobalScopes()->whereIn('type', [PriceAlertSource::RISE, PriceAlertSource::IMPACT])->count());
    }

    public function test_a_jump_since_the_previous_price_is_an_alert_even_without_a_boq_rate(): void
    {
        $this->boqRate($this->cement, null, '600');
        $this->price($this->cement, '380', '2026-11-01');
        $this->price($this->cement, '400', '2026-11-09', 'Market enquiry');

        $this->scan();

        $alert = $this->alerts(PriceAlertSource::RISE)->sole();
        $this->assertSame('Material price has risen', $alert->titleIn('en'));
        $this->assertSame('Cement has risen 5.26% to ₹400.00 per bag as on 09 Nov 2026, Market enquiry, from ₹380.00.', $alert->messageIn('en'));
        $this->assertSame('money.view', $alert->needs_permission);

        // A material the project does not buy raises nothing here.
        $tiles = $this->watch('Tiles', 'sqm');
        $this->price($tiles, '50', '2026-11-01');
        $this->price($tiles, '60', '2026-11-09');
        $this->scan();
        $this->assertSame(1, $this->alerts(PriceAlertSource::RISE)->open()->count());
    }

    public function test_the_budget_impact_crossing_its_share_of_the_budget_is_an_alert_for_money_overview(): void
    {
        // (400 − 380) × 600 = ₹12,000, which is 1.2% of the ₹10,00,000 budget.
        $this->price($this->cement, '400', '2026-11-09');

        $this->scan();

        $alert = $this->alerts(PriceAlertSource::IMPACT)->sole();
        $this->assertSame('Material prices are eating into the budget', $alert->titleIn('en'));
        $this->assertSame('Buying the rest of 1 materials at today\'s prices costs ₹12,000.00 more than the BOQ allowed, 1.2% of the ₹10,00,000.00 budget.', $alert->messageIn('en'));
        $this->assertSame('warning', $alert->severity);
        $this->assertSame('money.overview', $alert->needs_permission);
        $this->assertSame('project', $alert->reference);
        $this->assertSame(['12000.00', '1.20', 'budget'], [$alert->data['fresh_impact'], $alert->data['fresh_impact_pct'], $alert->data['basis']]);

        $this->assertTrue(Alert::visibleTo($this->pm)->whereKey($alert->id)->exists());
        $this->assertFalse(Alert::visibleTo($this->qs)->whereKey($alert->id)->exists());
        $this->assertFalse(Alert::visibleTo($this->engineer)->whereKey($alert->id)->exists());
        $this->assertSame(0, $this->qs->notifications()->count());

        // Under the limit it closes; with a tiny budget the same impact goes beyond the margin and is critical.
        app(PlatformSettings::class)->set(['price_watch_impact_pct' => '2']);
        $this->assertSame(1, $this->scan()['resolved']);

        app(PlatformSettings::class)->set(['price_watch_impact_pct' => '1']);
        $this->project->update(['budget_amount' => '10000.00']);
        $this->scan();
        $this->assertSame('critical', $this->alerts(PriceAlertSource::IMPACT)->open()->sole()->severity);
    }

    public function test_without_a_budget_the_boq_value_is_the_yardstick(): void
    {
        $this->project->update(['budget_amount' => null]);
        $this->makeBoqItem($this->project, '100.000', '5000.00');
        $this->price($this->cement, '400', '2026-11-09');

        $this->scan();

        $alert = $this->alerts(PriceAlertSource::IMPACT)->sole();
        $this->assertSame('Buying the rest of 1 materials at today\'s prices costs ₹12,000.00 more than the BOQ allowed, 2.4% of the ₹5,00,000.00 BOQ value.', $alert->messageIn('en'));
    }

    public function test_the_dashboard_card_shows_the_impact_to_money_overview_only(): void
    {
        $this->price($this->cement, '400', '2026-11-09');

        $card = collect(app(SiteDashboard::class)->cards($this->project->fresh(), $this->pm))->firstWhere('key', 'price_watch');
        $this->assertNotNull($card);
        $this->assertSame('+₹12,000.00', $card->value);
        $this->assertSame('+1.2% of the approved budget', $card->note);
        $this->assertTrue($card->money);
        $this->assertSame(['Cement', '+₹12,000.00', '+5.26% against the BOQ rate'], [$card->rows[0]['label'], $card->rows[0]['value'], $card->rows[0]['sub']]);

        foreach ([$this->qs, $this->engineer, $this->store, $this->viewer] as $user) {
            $this->assertNull(collect(app(SiteDashboard::class)->cards($this->project->fresh(), $user))->firstWhere('key', 'price_watch'));
        }

        $this->actingAs($this->pm)->get(route('site-dashboard.show', $this->project))->assertOk()->assertSee('Material prices against the BOQ');
        $this->actingAs($this->engineer)->get(route('site-dashboard.show', $this->project))->assertOk()->assertDontSee('Material prices against the BOQ')->assertDontSee('12,000');
    }

    public function test_the_project_page_shows_the_impact_and_saves_boq_rates_for_money_overview_only(): void
    {
        $this->price($this->cement, '400', '2026-11-09', 'Birla dealer');

        $this->actingAs($this->pm)->get(route('price-watch.project', $this->project))->assertOk()
            ->assertSee('No live price feed.')->assertSee('₹400.00', false)->assertSee('Birla dealer')->assertSee('+12,000.00')->assertSee('+5.26%')->assertSee('+1.2% of the approved budget')->assertSee('Save BOQ rates');

        $this->actingAs($this->pm)->post(route('price-watch.project.rates', $this->project), ['rates' => [$this->cement->id => '390'], 'qtys' => [$this->cement->id => '500']])
            ->assertRedirect()->assertSessionHas('success', '1 BOQ rates saved.');
        $this->actingAs($this->pm)->get(route('price-watch.project', $this->project))->assertOk()->assertSee('+5,000.00');

        $this->actingAs($this->pm)->post(route('price-watch.project.rates', $this->project), ['rates' => [$this->cement->id => '-1']])->assertSessionHasErrors('rates.'.$this->cement->id);
        $this->actingAs($this->pm)->post(route('price-watch.project.rates', $this->project), ['rates' => [999999 => '1']])->assertSessionHasErrors('rates');

        foreach ([$this->qs, $this->accounts, $this->engineer, $this->store, $this->viewer] as $user) {
            $this->actingAs($user)->get(route('price-watch.project', $this->project))->assertForbidden();
            $this->actingAs($user)->post(route('price-watch.project.rates', $this->project), ['rates' => [$this->cement->id => '1']])->assertForbidden();
        }

        $analyst = $this->holding(['money.overview', 'money.view', 'project.view']);
        $this->actingAs($analyst)->get(route('price-watch.project', $this->project))->assertOk()->assertDontSee('Save BOQ rates');
        $this->actingAs($analyst)->post(route('price-watch.project.rates', $this->project), ['rates' => [$this->cement->id => '1']])->assertForbidden();

        [, $rivalOwner] = $this->rival();
        $this->actingAs($rivalOwner)->get(route('price-watch.project', $this->project))->assertNotFound();
        $this->actingAs($rivalOwner)->post(route('price-watch.project.rates', $this->project), ['rates' => [$this->cement->id => '1']])->assertNotFound();

        $elsewhere = $this->makeProject($this->org, ['code' => 'ELSE']);
        $this->actingAs($this->limitedPm($elsewhere))->get(route('price-watch.project', $this->project))->assertNotFound();

        $this->assertSame('390.00', PriceWatchProjectRate::withoutGlobalScopes()->where('price_watch_material_id', $this->cement->id)->sole()->boq_rate);
    }
}
