<?php

namespace Tests\Feature\SiteAlerts;

use Laravel\Sanctum\Sanctum;
use Tests\Feature\SiteAlerts\Support\MoneyTestSource;

class SiteAlertsApiTest extends SiteAlertsTestCase
{
    private function dashboard(): string
    {
        return self::API."/projects/{$this->project->id}/site-dashboard";
    }

    private function alerts(string $path = ''): string
    {
        return self::API."/projects/{$this->project->id}/alerts".$path;
    }

    public function test_the_dashboard_returns_the_same_cards_as_the_page(): void
    {
        $this->done($this->item(), '35');
        $this->travelTo('2026-04-01 09:00');
        Sanctum::actingAs($this->member($this->organization, 'pm'));

        $response = $this->getJson($this->dashboard())->assertOk()
            ->assertJsonPath('data.project.id', $this->project->id)
            ->assertJsonPath('data.today', '2026-04-01')
            ->assertJsonPath('data.cards.0.key', 'work')
            ->assertJsonPath('data.cards.0.value', '35%')
            ->assertJsonPath('data.cards.1.value', '90 of 180 days')
            ->assertJsonPath('data.cards.1.note', '15% behind schedule')
            ->assertJsonPath('data.cards.1.meters.0.percent', '50.00')
            ->assertJsonPath('data.cards.1.meters.1.percent', '35.00')
            ->assertJsonPath('data.cards.2.key', 'money')
            ->assertJsonPath('data.cards.2.money', true);

        $this->assertSame(['work', 'time', 'money', 'pending', 'alerts', 'trade:rcc', 'trade:brick'], collect($response->json('data.cards'))->pluck('key')->all());
    }

    public function test_site_staff_get_no_money_card_and_no_rupee_anywhere(): void
    {
        $this->done($this->item(), '35');
        Sanctum::actingAs($this->member($this->organization, 'engineer'));

        $response = $this->getJson($this->dashboard())->assertOk();

        $this->assertNotContains('money', collect($response->json('data.cards'))->pluck('key')->all());
        $this->assertStringNotContainsString('₹', $response->getContent());
    }

    public function test_alerts_are_listed_and_limited_to_what_the_person_may_see(): void
    {
        config(['modules.alert_test.alert_sources' => [MoneyTestSource::class]]);
        $this->done($this->item(), '35');
        $this->scan('2026-04-01');

        Sanctum::actingAs($this->member($this->organization, 'pm'));
        $this->getJson($this->alerts())->assertOk()->assertJsonCount(2, 'data');
        $this->getJson($this->alerts('?status=resolved'))->assertOk()->assertJsonCount(0, 'data');
        $this->getJson(self::API.'/alerts')->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('data.0.project_name', 'Riverside Tower');

        Sanctum::actingAs($this->member($this->organization, 'engineer'));
        $response = $this->getJson($this->alerts())->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.type', 'schedule.behind')
            ->assertJsonPath('data.0.title', 'Behind schedule')
            ->assertJsonPath('data.0.message', '50% of the time is used but 35% of the work is done, 15% behind.')
            ->assertJsonPath('data.0.data.lag_pct', '15.00')
            ->assertJsonPath('data.0.status', 'open');

        $this->assertStringNotContainsString('₹', $response->getContent());
    }

    public function test_alerts_come_in_the_readers_language(): void
    {
        $this->done($this->item(), '35');
        $this->scan('2026-04-01');
        $pm = $this->member($this->organization, 'pm');
        $pm->update(['locale' => 'mr']);
        Sanctum::actingAs($pm);

        $this->getJson($this->alerts())->assertOk()->assertJsonPath('data.0.title', __('Behind schedule', [], 'mr'));
    }

    public function test_a_check_can_be_run_from_the_app_by_alert_managers(): void
    {
        $this->done($this->item(), '35');
        $this->travelTo('2026-04-01 09:00');

        Sanctum::actingAs($this->member($this->organization, 'engineer'));
        $this->postJson($this->alerts('/scan'))->assertForbidden();

        Sanctum::actingAs($this->member($this->organization, 'pm'));
        $this->postJson($this->alerts('/scan'))->assertOk()->assertJsonPath('data.new', 1)->assertJsonPath('data.open', 1);
        $this->postJson($this->alerts('/scan'))->assertOk()->assertJsonPath('data.new', 0);
    }

    public function test_the_api_honours_company_and_project_limits(): void
    {
        $other = $this->makeProject($this->tenant('Other Co'));
        Sanctum::actingAs($this->member($this->organization, 'pm'));
        $this->getJson(self::API."/projects/{$other->id}/site-dashboard")->assertNotFound();
        $this->getJson(self::API."/projects/{$other->id}/alerts")->assertNotFound();

        $limited = $this->member($this->organization, 'engineer');
        $this->limitTo($limited);
        Sanctum::actingAs($limited);
        $this->getJson($this->dashboard())->assertNotFound();
        $this->getJson($this->alerts())->assertNotFound();
    }
}
