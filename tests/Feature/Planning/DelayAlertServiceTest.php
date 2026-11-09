<?php

namespace Tests\Feature\Planning;

use App\Models\PlanDelayAlert;
use App\Models\User;
use App\Notifications\PlanningDelayAlert;
use App\Services\MeasurementService;
use App\Services\Planning\DelayAlertService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class DelayAlertServiceTest extends TestCase
{
    use BuildsPlan, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpPlan();
    }

    private function scan(string $today): array
    {
        return app(DelayAlertService::class)->scanProject($this->project, Carbon::parse($today));
    }

    private function overdueActivity(): array
    {
        $item = $this->makeBoqItem($this->project, '100.000', '100.00');
        $activity = $this->activity('2026-11-01', '2026-11-10', ['code' => 'EXC', 'name' => 'Excavation']);
        $this->link($activity, $item, '100');

        return [$activity, $item];
    }

    public function test_a_delay_notifies_planners_once_through_the_bell(): void
    {
        $this->overdueActivity();
        $pm = $this->member($this->organization, 'pm');

        $this->assertSame(1, $this->scan('2026-11-15')['new']);
        $this->assertSame(0, $this->scan('2026-11-16')['new']);

        $notice = $pm->notifications()->sole();
        $this->assertSame('Delay alert: Riverside Tower', $notice->data['title']);
        $this->assertSame('EXC Excavation (overdue by 5 days)', $notice->data['body']);
        $this->assertSame(1, $this->owner->notifications()->count());
        $this->assertSame(0, $this->engineer->notifications()->count());
        $this->assertSame(6, PlanDelayAlert::sole()->days_late);
    }

    public function test_a_delay_inside_the_grace_period_stays_quiet(): void
    {
        $this->overdueActivity();

        $this->assertSame(0, $this->scan('2026-11-12')['new']);
        $this->assertSame(1, $this->scan('2026-11-13')['new']);
    }

    public function test_finished_work_clears_the_alert_and_a_new_slip_alerts_again(): void
    {
        [$activity, $item] = $this->overdueActivity();

        $this->scan('2026-11-15');

        $entry = $this->approved($item, '100', '2026-11-16');
        $this->assertSame(1, $this->scan('2026-11-17')['resolved']);
        $this->assertNotNull(PlanDelayAlert::sole()->resolved_on);

        // Reverse the approved work: the delay is back and the planners hear about it again.
        Auth::login($this->owner);
        $reversal = app(MeasurementService::class)->reverse($entry, $this->engineer);
        $this->approveEntry($reversal, $this->engineer, $this->owner);

        $this->assertSame(1, $this->scan('2026-11-20')['new']);
        $this->assertNull(PlanDelayAlert::sole()->resolved_on);
        $this->assertSame(2, $this->owner->notifications()->where('type', PlanningDelayAlert::class)->count());
    }

    public function test_a_late_start_that_runs_past_its_finish_alerts_as_overdue(): void
    {
        $item = $this->makeBoqItem($this->project, '100.000', '100.00');
        $activity = $this->activity('2026-11-01', '2026-11-10');
        $this->link($activity, $item, '100');

        $this->scan('2026-11-05');
        $this->scan('2026-11-15');

        $this->assertSame(1, PlanDelayAlert::where('kind', 'late_start')->whereNotNull('resolved_on')->count());
        $this->assertSame(1, PlanDelayAlert::where('kind', 'overdue')->whereNull('resolved_on')->count());
        $this->assertSame(2, $this->owner->notifications()->count());
    }

    public function test_many_delays_become_one_notice_naming_the_worst(): void
    {
        $item = $this->makeBoqItem($this->project, '100.000', '100.00');

        foreach ([['A', 10], ['B', 9], ['C', 8], ['D', 7], ['E', 6]] as [$code, $day]) {
            $activity = $this->activity('2026-11-01', sprintf('2026-11-%02d', $day), ['code' => $code]);
            $this->link($activity, $item, '10');
        }

        $this->scan('2026-11-20');

        $notice = $this->owner->notifications()->sole();
        $this->assertStringStartsWith('E Work package (overdue by 14 days), D Work package', $notice->data['body']);
        $this->assertStringEndsWith('and 2 more', $notice->data['body']);
    }

    public function test_notices_use_each_planners_own_language(): void
    {
        $this->overdueActivity();
        $this->owner->update(['locale' => 'hi']);

        $this->scan('2026-11-15');

        $this->assertStringContainsString('देरी की सूचना', $this->owner->notifications()->sole()->data['title']);
    }

    public function test_the_daily_run_covers_each_company_and_skips_plans_without_planning(): void
    {
        Notification::fake();

        $this->overdueActivity();

        $other = $this->tenant('Other Co', 'contractor');
        $otherOwner = $this->member($other, 'owner');
        $this->actingAs($otherOwner);
        $otherProject = $this->makeProject($other);
        $otherItem = $this->makeBoqItem($otherProject, '10.000', '10.00');
        $otherActivity = $this->activity('2026-11-01', '2026-11-05', [], $otherProject);
        $this->link($otherActivity, $otherItem, '10');

        $free = $this->tenant('Free Co', 'free');
        $freeOwner = $this->member($free, 'owner');
        $this->actingAs($freeOwner);
        $freeProject = $this->makeProject($free);
        $freeItem = $this->makeBoqItem($freeProject, '10.000', '10.00');
        $freeActivity = $this->activity('2026-11-01', '2026-11-05', [], $freeProject);
        $this->link($freeActivity, $freeItem, '10');

        Auth::logout();

        $result = app(DelayAlertService::class)->scanAll(Carbon::parse('2026-11-20'));

        $this->assertSame(2, $result['new']);
        Notification::assertSentTo($this->owner, PlanningDelayAlert::class, fn ($notice) => str_contains($notice->body, 'Excavation'));
        Notification::assertSentTo($otherOwner, PlanningDelayAlert::class, fn ($notice) => ! str_contains($notice->body, 'Excavation'));
        Notification::assertNotSentTo($freeOwner, PlanningDelayAlert::class);
        Notification::assertNotSentTo(User::where('role', 'engineer')->first(), PlanningDelayAlert::class);
    }

    public function test_projects_on_hold_are_not_scanned(): void
    {
        Notification::fake();
        $this->overdueActivity();
        $this->project->update(['status' => 'on_hold']);
        Auth::logout();

        $result = app(DelayAlertService::class)->scanAll(Carbon::parse('2026-11-20'));

        $this->assertSame(0, $result['projects']);
        Notification::assertNothingSent();
    }

    public function test_the_command_runs_without_a_signed_in_user(): void
    {
        $this->overdueActivity();
        Auth::logout();
        Carbon::setTestNow('2026-11-20 08:00:00');

        $this->artisan('planning:delay-alerts')->assertSuccessful()->expectsOutputToContain('New delays: 1');

        Carbon::setTestNow();
        $this->assertSame(1, $this->owner->notifications()->count());
    }
}
