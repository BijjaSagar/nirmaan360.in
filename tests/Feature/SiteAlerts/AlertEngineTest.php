<?php

namespace Tests\Feature\SiteAlerts;

use App\Models\Alert;
use App\Models\AuditLog;
use App\Services\SiteAlerts\AlertEngine;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Mockery;
use Tests\Feature\SiteAlerts\Support\FailingTestSource;
use Tests\Feature\SiteAlerts\Support\MoneyTestSource;

class AlertEngineTest extends SiteAlertsTestCase
{
    public function test_a_project_behind_schedule_raises_the_engineers_example(): void
    {
        // 90 of 180 days have gone, so 50% of the time, but only 35% of the work is done: 15% behind.
        $this->done($this->item(), '35');

        $result = $this->scan('2026-04-01');

        $this->assertSame(['new' => 1, 'open' => 1, 'resolved' => 0], $result);
        $alert = Alert::sole();
        $this->assertSame('schedule.behind', $alert->type);
        $this->assertSame('project', $alert->reference);
        $this->assertSame('warning', $alert->severity);
        $this->assertSame('open', $alert->status);
        $this->assertSame('schedule.behind|project', $alert->open_key);
        $this->assertNull($alert->needs_permission);
        $this->assertSame('Behind schedule', $alert->titleIn('en'));
        $this->assertSame('50% of the time is used but 35% of the work is done, 15% behind.', $alert->messageIn('en'));
        $this->assertSame(['time_used_pct' => '50.00', 'work_pct' => '35.00', 'lag_pct' => '15.00', 'days_elapsed' => 90, 'planned_days' => 180], $alert->data);
        $this->assertStringContainsString('/site-dashboard', $alert->url);
        $this->assertSame(1, AuditLog::where('action', 'alert.opened')->count());
    }

    public function test_a_small_lag_stays_quiet(): void
    {
        // 50% of the time and 45% of the work: 5% behind, below the 10% limit.
        $this->done($this->item(), '45');

        $this->assertSame(['new' => 0, 'open' => 0, 'resolved' => 0], $this->scan('2026-04-01'));
        $this->assertSame(0, Alert::count());
    }

    public function test_scanning_again_refreshes_the_open_alert_instead_of_opening_another(): void
    {
        $this->done($this->item(), '35');
        $this->scan('2026-04-01');
        $first = Alert::sole();

        // Ten days on, 100 of 180 days are gone: the lag is 20.56%, so the same alert turns critical.
        $this->assertSame(['new' => 0, 'open' => 1, 'resolved' => 0], $this->scan('2026-04-11'));

        $alert = Alert::sole();
        $this->assertSame($first->id, $alert->id);
        $this->assertSame('critical', $alert->severity);
        $this->assertSame('55.56', $alert->data['time_used_pct']);
        $this->assertSame('55.56% of the time is used but 35% of the work is done, 20.56% behind.', $alert->messageIn('en'));
        $this->assertSame(1, AuditLog::where('action', 'alert.escalated')->count());
    }

    public function test_the_alert_resolves_when_work_catches_up_and_a_new_slip_opens_a_new_one(): void
    {
        $item = $this->item();
        $this->done($item, '35');
        $this->scan('2026-04-01');

        // 20 more units take the work to 55%, ahead of the 50% of time used.
        $this->done($item, '20');
        $this->assertSame(['new' => 0, 'open' => 0, 'resolved' => 1], $this->scan('2026-04-01'));

        $resolved = Alert::sole();
        $this->assertSame('resolved', $resolved->status);
        $this->assertNotNull($resolved->resolved_at);
        $this->assertNull($resolved->open_key);
        $this->assertSame(1, AuditLog::where('action', 'alert.resolved')->count());

        // A correction takes the work back to 35%: the cause is back, and history keeps the resolved row.
        $this->done($item, '-20');
        $this->assertSame(1, $this->scan('2026-04-02')['new']);
        $this->assertSame(2, Alert::count());
        $this->assertSame(1, Alert::open()->count());
    }

    public function test_deadline_approaching_names_the_days_left_and_the_work_remaining(): void
    {
        $this->done($this->item(), '35');

        // 1 January plus 180 days is 30 June. On 10 June, 20 days and 65% of the work remain.
        $this->scan('2026-06-10');

        $deadline = Alert::where('type', 'schedule.deadline')->sole();
        $this->assertSame('warning', $deadline->severity);
        $this->assertSame('Deadline approaching', $deadline->titleIn('en'));
        $this->assertSame('20 days to the deadline on 30 Jun 2026 with 65% of the work remaining.', $deadline->messageIn('en'));
        $this->assertSame(['deadline' => '2026-06-30', 'days_left' => 20, 'work_pct' => '35.00', 'remaining_pct' => '65.00'], $deadline->data);

        // The project is also far behind schedule by then, so both alerts are open.
        $this->assertSame(2, Alert::open()->count());
    }

    public function test_a_passed_deadline_is_critical_and_a_fixed_deadline_wins_over_the_planned_days(): void
    {
        $this->project->update(['deadline_date' => '2026-05-15']);
        $this->done($this->item(), '35');

        $this->scan('2026-05-20');

        $alert = Alert::where('type', 'schedule.deadline')->sole();
        $this->assertSame('critical', $alert->severity);
        $this->assertSame('Deadline passed', $alert->titleIn('en'));
        $this->assertSame('The deadline on 15 May 2026 passed 5 days ago with 65% of the work remaining.', $alert->messageIn('en'));
    }

    public function test_a_deadline_inside_a_week_is_critical_and_a_far_one_is_quiet(): void
    {
        $this->project->update(['planned_days' => null, 'deadline_date' => '2026-06-30']);
        $this->done($this->item(), '35');

        $this->assertSame(0, $this->scan('2026-05-01')['new']);
        $this->scan('2026-06-25');

        $this->assertSame('critical', Alert::where('type', 'schedule.deadline')->sole()->severity);
    }

    public function test_finished_work_raises_no_alert(): void
    {
        $this->done($this->item(), '100');

        $this->assertSame(0, $this->scan('2026-06-25')['new']);
        $this->assertSame(0, Alert::count());
    }

    public function test_a_project_without_dates_raises_nothing(): void
    {
        $this->project->update(['planned_days' => null]);
        $this->done($this->item(), '10');

        $this->assertSame(0, $this->scan('2026-04-01')['new']);
    }

    public function test_people_who_manage_alerts_and_see_the_project_are_told_once_in_their_language(): void
    {
        $pm = $this->member($this->organization, 'pm');
        $pm->update(['locale' => 'hi']);
        $engineer = $this->member($this->organization, 'engineer');
        $outsider = $this->member($this->organization, 'pm');
        $this->limitTo($outsider);
        $insider = $this->member($this->organization, 'pm');
        $this->limitTo($insider, $this->project);
        $inactive = $this->member($this->organization, 'pm');
        $inactive->update(['is_active' => false]);
        $this->done($this->item(), '35');

        $this->scan('2026-04-01');
        $this->scan('2026-04-02');

        $this->assertSame(1, $this->owner->notifications()->count());
        $this->assertSame(1, $pm->notifications()->count());
        $this->assertSame(1, $insider->notifications()->count());
        $this->assertSame(0, $engineer->notifications()->count());
        $this->assertSame(0, $outsider->notifications()->count());
        $this->assertSame(0, $inactive->notifications()->count());

        $notice = $this->owner->notifications()->sole()->data;
        $this->assertSame('Behind schedule · Riverside Tower', $notice['title']);
        $this->assertSame('50% of the time is used but 35% of the work is done, 15% behind.', $notice['body']);
        $this->assertStringContainsString('/site-dashboard', $notice['url']);

        $hindi = $pm->notifications()->sole()->data;
        $this->assertSame(__('Behind schedule', [], 'hi').' · Riverside Tower', $hindi['title']);
        $this->assertNotSame($notice['body'], $hindi['body']);
        $this->assertNotNull(Alert::sole()->notified_at);
    }

    public function test_an_escalation_to_critical_tells_people_again_but_only_once(): void
    {
        $this->done($this->item(), '35');

        $this->scan('2026-04-01');
        $this->scan('2026-04-11');
        $this->assertSame(2, $this->owner->notifications()->count());

        $this->scan('2026-04-12');
        $this->assertSame(2, $this->owner->notifications()->count());
    }

    public function test_a_money_alert_is_kept_from_site_staff(): void
    {
        config(['modules.alert_test.alert_sources' => [MoneyTestSource::class]]);
        $this->project->update(['planned_days' => null]);
        $pm = $this->member($this->organization, 'pm');
        $accounts = $this->member($this->organization, 'accounts');
        $engineer = $this->member($this->organization, 'engineer');

        $this->scan('2026-04-01');

        $alert = Alert::where('type', 'test.money')->sole();
        $this->assertSame('money.overview', $alert->needs_permission);
        $this->assertSame(1, $this->owner->notifications()->count());
        $this->assertSame(1, $pm->notifications()->count());
        $this->assertSame(0, $accounts->notifications()->count());
        $this->assertSame(0, $engineer->notifications()->count());

        $this->assertTrue(Alert::visibleTo($pm)->whereKey($alert->id)->exists());
        $this->assertFalse(Alert::visibleTo($accounts)->whereKey($alert->id)->exists());
        $this->assertFalse(Alert::visibleTo($engineer)->whereKey($alert->id)->exists());
    }

    public function test_a_failing_source_is_logged_and_the_others_still_run(): void
    {
        config(['modules.alert_test.alert_sources' => [FailingTestSource::class]]);
        Log::spy();
        $this->done($this->item(), '35');

        $this->assertSame(1, $this->scan('2026-04-01')['new']);

        Log::shouldHaveReceived('warning')->with('Alert source failed', Mockery::on(fn (array $context) => $context['source'] === FailingTestSource::class))->once();
    }

    public function test_an_alert_whose_source_is_gone_is_left_as_it_is(): void
    {
        config(['modules.alert_test.alert_sources' => [MoneyTestSource::class]]);
        $this->project->update(['planned_days' => null]);
        $this->scan('2026-04-01');

        config(['modules.alert_test.alert_sources' => []]);

        $this->assertSame(0, $this->scan('2026-04-02')['resolved']);
        $this->assertSame('open', Alert::sole()->status);
    }

    public function test_scan_all_covers_live_projects_of_companies_with_site_control(): void
    {
        $this->done($this->item(), '35');
        $onHold = $this->makeProject($this->organization, ['status' => 'on_hold', 'start_date' => '2026-01-01', 'planned_days' => 180]);
        $free = $this->tenant('Free Co', 'free');
        $this->makeProject($free, ['start_date' => '2026-01-01', 'planned_days' => 180]);

        $result = app(AlertEngine::class)->scanAll(Carbon::parse('2026-04-01'));

        $this->assertSame(['projects' => 1, 'new' => 1, 'resolved' => 0], $result);
        $this->assertSame(0, Alert::where('project_id', $onHold->id)->count());
        $this->assertSame(1, Alert::count());
    }

    public function test_the_console_command_runs_a_scan(): void
    {
        $this->done($this->item(), '35');
        $this->travelTo('2026-04-01 08:00');

        $this->artisan('site-alerts:scan')->expectsOutputToContain('Projects checked: 1. New alerts: 1. Resolved: 0.')->assertSuccessful();
        $this->artisan('site-alerts:scan', ['--project' => $this->project->id])->expectsOutputToContain('1 open, 0 new, 0 resolved')->assertSuccessful();
        $this->artisan('site-alerts:scan', ['--project' => 999999])->assertFailed();
    }
}
