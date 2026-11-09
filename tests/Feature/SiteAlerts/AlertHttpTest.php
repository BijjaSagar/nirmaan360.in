<?php

namespace Tests\Feature\SiteAlerts;

use App\Models\Alert;
use App\Models\BoqItem;
use Tests\Feature\SiteAlerts\Support\MoneyTestSource;

class AlertHttpTest extends SiteAlertsTestCase
{
    private function url(string $path = ''): string
    {
        return '/projects/'.$this->project->id.'/alerts'.$path;
    }

    private function behind(): void
    {
        $this->done($this->item(), '35');
        $this->scan('2026-04-01');
    }

    public function test_open_and_resolved_alerts_are_listed(): void
    {
        $this->behind();
        $this->actingAs($this->owner);

        $this->get($this->url())->assertOk()->assertSee('Behind schedule')->assertSee('15% behind')->assertSee('Site dashboard');
        $this->get($this->url('?status=resolved'))->assertOk()->assertSee('Nothing resolved yet');

        $this->done(BoqItem::first(), '30');
        $this->scan('2026-04-01');

        $this->get($this->url())->assertOk()->assertSee('No open alerts');
        $this->get($this->url('?status=resolved'))->assertOk()->assertSee('Behind schedule')->assertSee('Resolved');
    }

    public function test_only_alert_managers_can_run_a_check(): void
    {
        $this->done($this->item(), '35');
        $this->travelTo('2026-04-01 09:00');

        $this->actingAs($this->member($this->organization, 'engineer'))->post($this->url('/scan'))->assertForbidden();
        $this->assertSame(0, Alert::count());

        $this->actingAs($this->member($this->organization, 'pm'))->from($this->url())->post($this->url('/scan'))
            ->assertRedirect($this->url())
            ->assertSessionHas('success', 'Alert check finished. New: 1. Resolved: 0. Open: 1.');
        $this->assertSame(1, Alert::count());
    }

    public function test_a_money_alert_is_not_on_the_site_engineers_page(): void
    {
        config(['modules.alert_test.alert_sources' => [MoneyTestSource::class]]);
        $this->project->update(['planned_days' => null]);
        $this->scan('2026-04-01');

        $this->actingAs($this->member($this->organization, 'engineer'))->get($this->url())->assertOk()
            ->assertDontSee('Test money alert')->assertDontSee('₹2,08,00,000.00')->assertSee('No open alerts');

        $this->actingAs($this->member($this->organization, 'pm'))->get($this->url())->assertOk()
            ->assertSee('Test money alert')->assertSee('₹2,08,00,000.00');
    }

    public function test_the_company_page_groups_open_alerts_by_the_projects_a_person_can_see(): void
    {
        $this->behind();
        $second = $this->makeProject($this->organization, ['name' => 'Hilltop Villas', 'start_date' => '2026-01-01', 'planned_days' => 180]);
        $this->done($this->item('100.000', '500.00', [], $second), '35');
        $this->scan('2026-04-01', $second);

        $limited = $this->member($this->organization, 'pm');
        $this->limitTo($limited, $this->project);

        $this->actingAs($limited)->get('/alerts')->assertOk()->assertSee('Riverside Tower')->assertDontSee('Hilltop Villas');
        $this->actingAs($this->owner)->get('/alerts')->assertOk()->assertSee('Riverside Tower')->assertSee('Hilltop Villas');
    }

    public function test_another_companys_alerts_are_out_of_reach(): void
    {
        $other = $this->makeProject($this->tenant('Other Co'), ['start_date' => '2026-01-01', 'planned_days' => 180]);
        $this->actingAs($this->owner);

        $this->get("/projects/{$other->id}/alerts")->assertNotFound();
        $this->post("/projects/{$other->id}/alerts/scan")->assertNotFound();
    }
}
