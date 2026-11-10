<?php

namespace Tests\Feature\Notify;

use App\Jobs\Notify\DeliverNotification;
use App\Models\Alert;
use App\Models\BoqItem;
use App\Models\Organization;
use App\Models\ProgressEntry;
use App\Models\Project;
use App\Models\User;
use App\Services\Notify\FcmGateway;
use App\Services\Notify\Notifier;
use App\Services\SiteAlerts\AlertEngine;
use App\Services\SiteAlerts\AlertNotifier;
use App\Support\PlatformSettings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Feature\SiteAlerts\Support\MoneyTestSource;

/**
 * New site alerts as pushes: who is pushed, in what order, and that the same alert is never
 * pushed twice. The project is the engineer's example from the alert tests: 180 planned days from
 * 1 January 2026 with 35% of the work done, so it is 15% behind on 1 April.
 */
class PushAlertsTest extends NotifyTestCase
{
    private Organization $organization;

    private Project $project;

    private User $owner;

    private User $pm;

    private User $engineer;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->connectFcm();
        $this->at('2026-11-10 11:00');

        $this->organization = $this->tenant();
        $this->project = $this->behindSchedule($this->organization);
        $this->owner = $this->withPhone($this->member($this->organization, 'owner'));
        $this->pm = $this->withPhone($this->member($this->organization, 'pm'));
        $this->engineer = $this->withPhone($this->member($this->organization, 'engineer'));
    }

    private function withPhone(User $user): User
    {
        $this->phone($user, 'phone-'.$user->id);

        return $user;
    }

    /** A project with 35 of 100 units done, which is 15% behind by 1 April and critical by 11 April. */
    private function behindSchedule(Organization $organization, array $overrides = []): Project
    {
        $project = $this->makeProject($organization, ['start_date' => '2026-01-01', 'planned_days' => 180, ...$overrides]);
        $item = BoqItem::create(['organization_id' => $organization->id, 'project_id' => $project->id, 'code' => 'I'.Str::upper(Str::random(4)), 'description' => 'Flooring', 'unit' => 'sqm', 'qty' => '100.000', 'rate' => '500.00']);
        ProgressEntry::create(['organization_id' => $organization->id, 'project_id' => $project->id, 'boq_item_id' => $item->id, 'work_date' => '2026-03-30', 'qty_done' => '35']);

        return $project;
    }

    private function progress(Project $project, string $qty): void
    {
        ProgressEntry::create(['organization_id' => $project->organization_id, 'project_id' => $project->id, 'boq_item_id' => BoqItem::where('project_id', $project->id)->value('id'), 'work_date' => '2026-03-31', 'qty_done' => $qty]);
    }

    private function scan(string $today, ?Project $project = null): array
    {
        return app(AlertEngine::class)->scanProject(($project ?? $this->project)->fresh(), Carbon::parse($today));
    }

    public function test_a_new_alert_is_pushed_to_everyone_who_can_see_it(): void
    {
        $store = $this->withPhone($this->member($this->organization, 'store'));
        $viewer = $this->withPhone($this->member($this->organization, 'viewer'));

        $this->scan('2026-04-01');

        $alert = Alert::sole();

        // The people who manage alerts are told in full: the bell and email as well as the push.
        foreach ([$this->owner, $this->pm] as $manager) {
            $this->assertCount(1, $this->pushesFor($manager));
            $this->assertSame(1, $manager->notifications()->count());
        }

        // Site staff can see a schedule alert, so the app pushes it to them too. Nothing else about them changes.
        foreach ([$this->engineer, $store, $viewer] as $staff) {
            $this->assertSame('Behind schedule · Riverside Tower', $this->pushesFor($staff)->sole()->message->title);
            $this->assertSame(0, $staff->notifications()->count());
            $this->assertCount(0, Queue::pushed(DeliverNotification::class, fn (DeliverNotification $job) => $job->userId === $staff->id && $job->channel !== 'push'));
        }

        $push = $this->pushesFor($this->pm)->sole();
        $this->assertSame('Behind schedule · Riverside Tower', $push->message->title);
        $this->assertSame('50% of the time is used but 35% of the work is done, 15% behind.', $push->message->body);
        $this->assertFalse($push->message->critical);
        $this->assertSame([
            'alert_type' => 'schedule.behind',
            'severity' => 'warning',
            'type' => 'alert',
            'id' => (string) $alert->id,
            'category' => 'alerts',
            'project_id' => (string) $this->project->id,
            'event' => 'alert.schedule.behind',
            'critical' => '0',
            'url' => $alert->url,
        ], $this->dataOf($push));
    }

    public function test_the_push_is_written_in_each_persons_language(): void
    {
        $this->pm->update(['locale' => 'hi']);
        $marathi = $this->member($this->organization, 'pm');
        $marathi->update(['locale' => 'mr']);
        $this->phone($marathi, 'marathi-phone');

        $this->scan('2026-04-01');

        $this->assertSame('Behind schedule · Riverside Tower', $this->pushesFor($this->owner)->sole()->message->title);
        $this->assertSame(__('Behind schedule', [], 'hi').' · Riverside Tower', $this->pushesFor($this->pm)->sole()->message->title);
        $this->assertSame(__('Behind schedule', [], 'mr').' · Riverside Tower', $this->pushesFor($marathi)->sole()->message->title);
        $this->assertNotSame($this->pushesFor($this->pm)->sole()->message->body, $this->pushesFor($marathi)->sole()->message->body);
    }

    public function test_someone_limited_to_other_projects_or_in_another_company_is_never_pushed(): void
    {
        $elsewhere = $this->makeProject($this->organization, ['name' => 'Hilltop Villas']);
        $insider = $this->withPhone($this->member($this->organization, 'pm'));
        $this->limitTo($insider, $this->project);
        $outsider = $this->withPhone($this->member($this->organization, 'pm'));
        $this->limitTo($outsider, $elsewhere);
        $nowhere = $this->withPhone($this->member($this->organization, 'pm'));
        $this->limitTo($nowhere);

        $otherCompany = $this->tenant('Other Infra');
        $theirOwner = $this->withPhone($this->member($otherCompany, 'owner'));
        $theirProject = $this->behindSchedule($otherCompany, ['name' => 'Their Tower']);

        $this->scan('2026-04-01');

        $this->assertCount(1, $this->pushesFor($insider));
        $this->assertCount(0, $this->pushesFor($outsider));
        $this->assertCount(0, $this->pushesFor($nowhere));
        $this->assertCount(0, $this->pushesFor($theirOwner));

        // The other company's own alert reaches its own people and nobody here.
        $this->scan('2026-04-01', $theirProject);

        $this->assertStringContainsString('Their Tower', $this->pushesFor($theirOwner)->sole()->message->title);
        $this->assertSame((string) $theirProject->id, $this->dataOf($this->pushesFor($theirOwner)->sole())['project_id']);

        foreach ([$this->owner, $this->pm, $insider] as $ours) {
            $this->assertCount(1, $this->pushesFor($ours));
            $this->assertStringNotContainsString('Their Tower', $this->wordsOf($this->pushesFor($ours)->sole()));
        }
    }

    public function test_critical_alerts_are_pushed_first_across_every_project_in_a_scan(): void
    {
        // The first project scanned is only behind. The second has passed its deadline, which is critical.
        $late = $this->behindSchedule($this->organization, ['name' => 'Late Tower', 'deadline_date' => '2026-03-15']);

        $result = app(AlertEngine::class)->scanAll(Carbon::parse('2026-04-01'));

        $this->assertSame(2, $result['projects']);
        $pushes = $this->pushesFor($this->owner);
        $this->assertSame(
            ['alert.schedule.deadline', 'alert.schedule.behind', 'alert.schedule.behind'],
            $pushes->map(fn (DeliverNotification $job) => $job->message->event)->all(),
        );
        $this->assertSame([true, false, false], $pushes->map(fn (DeliverNotification $job) => $job->message->critical)->all());
        $this->assertStringContainsString('Late Tower', $pushes->first()->message->title);
        $this->assertSame((string) $late->id, $this->dataOf($pushes->first())['project_id']);
        $this->assertSame('critical', app(FcmGateway::class)->payload('t', $pushes->first()->message)['message']['android']['notification']['channel_id']);
    }

    public function test_at_night_only_the_critical_alert_is_pushed_and_the_rest_wait_for_morning(): void
    {
        $this->behindSchedule($this->organization, ['name' => 'Late Tower', 'deadline_date' => '2026-03-15']);
        $this->at('2026-11-10 23:30');

        app(AlertEngine::class)->scanAll(Carbon::parse('2026-04-01'));

        $pushes = $this->pushesFor($this->owner);
        $this->assertNull($pushes->first()->delay);
        $this->assertTrue($pushes->first()->message->critical);

        foreach ($pushes->skip(1) as $held) {
            $this->assertSame('2026-11-11 07:00', Carbon::instance($held->delay)->timezone('Asia/Kolkata')->format('Y-m-d H:i'));
        }
    }

    public function test_a_scan_that_runs_again_does_not_push_the_same_alert_twice(): void
    {
        $this->scan('2026-04-01');
        $this->scan('2026-04-01');
        $this->scan('2026-04-02');

        $this->assertCount(1, $this->pushesFor($this->owner));
        $this->assertCount(1, $this->pushesFor($this->pm));
    }

    public function test_an_alert_that_closes_and_reopens_the_same_day_is_not_pushed_again(): void
    {
        $this->scan('2026-04-01');

        // The work catches up and the alert resolves. A correction brings the cause back within the hour.
        $this->progress($this->project, '20');
        $this->assertSame(1, $this->scan('2026-04-01')['resolved']);
        $this->progress($this->project, '-20');
        $this->assertSame(1, $this->scan('2026-04-01')['new']);

        $this->assertSame(2, Alert::count());
        $this->assertCount(1, $this->pushesFor($this->owner));
        // The bell keeps the full history: only the push is held back.
        $this->assertSame(2, $this->owner->notifications()->count());

        // The same thing a day later is news again.
        $this->progress($this->project, '20');
        $this->scan('2026-04-01');
        $this->travel(25)->hours();
        $this->progress($this->project, '-20');
        $this->scan('2026-04-02');

        $this->assertCount(2, $this->pushesFor($this->owner));
    }

    public function test_an_alert_that_turns_critical_is_pushed_once_more(): void
    {
        $this->scan('2026-04-01');
        $this->scan('2026-04-11');
        $this->scan('2026-04-12');

        $pushes = $this->pushesFor($this->owner);
        $this->assertSame([false, true], $pushes->map(fn (DeliverNotification $job) => $job->message->critical)->all());
        $this->assertSame(['warning', 'critical'], $pushes->map(fn (DeliverNotification $job) => $this->dataOf($job)['severity'])->all());
        // Both are about the one alert, so on the phone the second replaces the first.
        $this->assertSame(1, $pushes->map(fn (DeliverNotification $job) => $this->dataOf($job)['id'])->unique()->count());
    }

    public function test_a_warning_held_overnight_is_dropped_once_the_alert_has_turned_critical(): void
    {
        $this->fakeFcm();
        $this->at('2026-11-10 23:30');
        $this->scan('2026-04-01');
        $this->scan('2026-04-11');

        [$warning, $critical] = $this->pushesFor($this->owner)->all();
        $this->assertNotNull($warning->delay);
        $this->assertNull($critical->delay);

        // The critical push went out in the night. In the morning the older warning would only replace it on the phone.
        $this->at('2026-11-11 07:00');
        $warning->handle(app(Notifier::class), app(PlatformSettings::class));
        Http::assertNothingSent();

        $critical->handle(app(Notifier::class), app(PlatformSettings::class));
        Http::assertSent(fn ($request) => ($request['message']['data']['severity'] ?? null) === 'critical');
    }

    public function test_a_money_alert_is_pushed_only_to_people_who_may_see_that_money(): void
    {
        config(['modules.alert_test.alert_sources' => [MoneyTestSource::class]]);
        $this->project->update(['planned_days' => null]);
        $accounts = $this->withPhone($this->member($this->organization, 'accounts'));
        $store = $this->withPhone($this->member($this->organization, 'store'));
        $viewer = $this->withPhone($this->member($this->organization, 'viewer'));

        $this->scan('2026-04-01');

        // Budget figures need money.overview: the owner and the manager hold it, accounts and site staff do not.
        foreach ([$this->owner, $this->pm] as $allowed) {
            $this->assertStringContainsString('₹2,08,00,000.00', $this->pushesFor($allowed)->sole()->message->body);
        }

        foreach ([$accounts, $this->engineer, $store, $viewer] as $kept) {
            $this->assertCount(0, $this->pushesFor($kept));
        }
    }

    public function test_a_payment_alert_reaches_everyone_with_money_view_and_no_site_role(): void
    {
        $accounts = $this->withPhone($this->member($this->organization, 'accounts'));
        $qs = $this->withPhone($this->member($this->organization, 'qs'));
        $store = $this->withPhone($this->member($this->organization, 'store'));
        $viewer = $this->withPhone($this->member($this->organization, 'viewer'));
        $alert = Alert::create([
            'organization_id' => $this->organization->id, 'project_id' => $this->project->id, 'type' => 'payment.ahead', 'reference' => 'work_order:1',
            'severity' => Alert::WARNING, 'title' => 'Payment ahead of work', 'message' => ':contract: paid :paid against :value.',
            'params' => ['contract' => 'WO-1', 'paid' => inr('73105001.00'), 'value' => inr('90000000.00')],
            'needs_permission' => 'money.view', 'notify_permission' => 'alerts.manage',
            'status' => Alert::OPEN, 'open_key' => 'payment.ahead|work_order:1', 'first_seen_at' => now(), 'last_seen_at' => now(),
        ]);

        app(AlertNotifier::class)->opened($this->project, [$alert]);

        // The manager and the owner are told. Accounts and the estimator may see payments, so the app pushes it to them.
        foreach ([$this->owner, $this->pm, $accounts, $qs] as $allowed) {
            $this->assertStringContainsString(inr('73105001.00'), $this->pushesFor($allowed)->sole()->message->body);
        }

        foreach ([$this->engineer, $store, $viewer] as $siteRole) {
            $this->assertCount(0, $this->pushesFor($siteRole), $siteRole->role.' must never be pushed a payment alert');
        }

        $this->assertSame([0, 0], [$accounts->notifications()->count(), $qs->notifications()->count()]);
    }

    public function test_someone_who_switched_site_alerts_off_is_not_pushed_but_still_sees_the_bell(): void
    {
        $this->prefer($this->pm, ['push_categories' => ['alerts' => false]]);

        $this->scan('2026-04-01');

        $this->assertCount(0, $this->pushesFor($this->pm));
        $this->assertSame(1, $this->pm->notifications()->count());
        $this->assertCount(1, $this->pushesFor($this->owner));
    }

    public function test_an_alert_for_the_dashboard_only_is_pushed_to_nobody(): void
    {
        Alert::query()->delete();
        $alert = Alert::create([
            'organization_id' => $this->organization->id, 'project_id' => $this->project->id, 'type' => 'supplier.repeat_short', 'reference' => 'party:1',
            'severity' => Alert::WARNING, 'title' => 'Repeat short delivery', 'message' => 'Short again.', 'notify_permission' => null,
            'status' => Alert::OPEN, 'open_key' => 'supplier.repeat_short|party:1', 'first_seen_at' => now(), 'last_seen_at' => now(),
        ]);

        app(AlertNotifier::class)->opened($this->project, [$alert]);

        Queue::assertNothingPushed();
    }
}
