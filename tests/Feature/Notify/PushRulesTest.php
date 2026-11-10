<?php

namespace Tests\Feature\Notify;

use App\Jobs\Notify\DeliverNotification;
use App\Models\Alert;
use App\Models\NotificationPreference;
use App\Models\Organization;
use App\Models\Project;
use App\Models\PushReceipt;
use App\Models\User;
use App\Services\ApprovalService;
use App\Services\MeasurementService;
use App\Services\Notify\NotificationMessage;
use App\Services\Notify\Notifier;
use App\Services\Notify\PermanentDeliveryFailure;
use App\Services\Notify\PushCategory;
use App\Services\Notify\PushDispatcher;
use App\Services\Notify\PushTarget;
use App\Services\Notify\QuietHours;
use App\Services\SiteAlerts\AlertNotifier;
use App\Support\PlatformSettings;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Mockery;
use ReflectionClass;
use ReflectionProperty;
use RuntimeException;

/**
 * The rules every push obeys whatever it is about: the person's switches, quiet hours, no
 * repeats, no money for anyone who may not see it, and the checks repeated just before sending.
 */
class PushRulesTest extends NotifyTestCase
{
    private Organization $organization;

    private Project $project;

    private User $pm;

    private User $engineer;

    private Alert $alert;

    protected function setUp(): void
    {
        parent::setUp();
        $this->organization = $this->tenant();
        $this->project = $this->makeProject($this->organization);
        $this->pm = $this->member($this->organization, 'pm');
        $this->engineer = $this->member($this->organization, 'engineer');
        $this->at('2026-11-10 11:00');

        // The open alert the sample push is about. A push about an alert that has closed is not sent.
        $this->alert = Alert::create([
            'organization_id' => $this->organization->id,
            'project_id' => $this->project->id,
            'type' => 'schedule.behind',
            'severity' => Alert::WARNING,
            'title' => 'Behind schedule',
            'message' => ':time% of the time is used but :work% of the work is done, :lag% behind.',
            'params' => ['time' => '50', 'work' => '35', 'lag' => '15'],
            'status' => Alert::OPEN,
            'open_key' => 'schedule.behind|project',
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ]);
    }

    private function message(array $target = [], array $overrides = []): NotificationMessage
    {
        return new NotificationMessage(...[
            'event' => 'alert.schedule.behind',
            'title' => 'Behind schedule · Riverside Tower',
            'body' => '50% of the time is used but 35% of the work is done.',
            'url' => 'https://example.test/alerts',
            'push' => new PushTarget(...[
                'category' => PushCategory::ALERTS,
                'type' => 'alert',
                'id' => $this->alert->id,
                'organizationId' => $this->organization->id,
                'projectId' => $this->project->id,
                'permissions' => ['alerts.view'],
                ...$target,
            ]),
            ...$overrides,
        ]);
    }

    private function quiet(string $indiaTime, array $preference = [], bool $critical = false): ?Carbon
    {
        return QuietHours::heldUntil(new NotificationPreference($preference), $critical, Carbon::parse($indiaTime, 'Asia/Kolkata'));
    }

    /** @return list<string> The phones Firebase was asked to push to, in order. */
    private function phonesReached(): array
    {
        return Http::recorded(fn (Request $request) => str_contains($request->url(), 'messages:send'))
            ->map(fn (array $pair) => $pair[0]['message']['token'])->values()->all();
    }

    /** @return list<string> The titles Firebase was asked to show, in order. */
    private function titlesSent(): array
    {
        return Http::recorded(fn (Request $request) => str_contains($request->url(), 'messages:send'))
            ->map(fn (array $pair) => $pair[0]['message']['notification']['title'])->values()->all();
    }

    /** Runs a job that Queue::fake() held back, as the worker would when its time comes. */
    private function work(DeliverNotification $job): void
    {
        $job->handle(app(Notifier::class), app(PlatformSettings::class));
    }

    public function test_quiet_hours_run_from_ten_at_night_to_seven_in_the_morning_india_time(): void
    {
        $this->assertNull($this->quiet('2026-11-10 21:59'));
        $this->assertSame('2026-11-11 07:00', $this->quiet('2026-11-10 22:00')->timezone('Asia/Kolkata')->format('Y-m-d H:i'));
        $this->assertSame('2026-11-11 07:00', $this->quiet('2026-11-10 23:45')->timezone('Asia/Kolkata')->format('Y-m-d H:i'));
        $this->assertSame('2026-11-11 07:00', $this->quiet('2026-11-11 00:00')->timezone('Asia/Kolkata')->format('Y-m-d H:i'));
        $this->assertSame('2026-11-11 07:00', $this->quiet('2026-11-11 06:59')->timezone('Asia/Kolkata')->format('Y-m-d H:i'));
        $this->assertNull($this->quiet('2026-11-11 07:00'));
        $this->assertNull($this->quiet('2026-11-11 13:30'));
    }

    public function test_a_critical_push_and_a_person_with_quiet_hours_off_are_never_held(): void
    {
        $this->assertNull($this->quiet('2026-11-10 23:45', critical: true));
        $this->assertNull($this->quiet('2026-11-10 23:45', ['quiet_hours' => false]));
    }

    public function test_a_person_can_set_their_own_window(): void
    {
        $afternoon = ['quiet_from' => '13:00', 'quiet_until' => '15:30'];

        $this->assertSame('2026-11-10 15:30', $this->quiet('2026-11-10 14:00', $afternoon)->timezone('Asia/Kolkata')->format('Y-m-d H:i'));
        $this->assertNull($this->quiet('2026-11-10 15:30', $afternoon));
        $this->assertNull($this->quiet('2026-11-10 23:45', $afternoon));

        // The same time twice, or a time that is not one, means no quiet hours rather than a guess.
        $this->assertNull($this->quiet('2026-11-10 23:45', ['quiet_from' => '08:00', 'quiet_until' => '08:00']));
        $this->assertNull($this->quiet('2026-11-10 23:45', ['quiet_from' => 'late', 'quiet_until' => '07:00']));
    }

    public function test_quiet_hours_follow_the_india_clock_whatever_timezone_the_server_runs_in(): void
    {
        config(['app.timezone' => 'UTC']);

        // 18:15 UTC is 23:45 in India.
        $until = QuietHours::heldUntil(new NotificationPreference, false, Carbon::parse('2026-11-10 18:15', 'UTC'));

        $this->assertSame('2026-11-11 01:30 UTC', $until->format('Y-m-d H:i T'));
    }

    public function test_at_night_a_push_is_held_until_morning_and_a_critical_one_goes_at_once(): void
    {
        Queue::fake();
        $this->connectFcm();
        $this->phone($this->pm);
        $this->at('2026-11-10 23:10');

        app(Notifier::class)->send($this->pm, $this->message());
        app(Notifier::class)->send($this->pm, $this->message(overrides: ['critical' => true]));

        [$held, $critical] = $this->pushesFor($this->pm)->all();
        $this->assertSame('2026-11-11 07:00', Carbon::instance($held->delay)->timezone('Asia/Kolkata')->format('Y-m-d H:i'));
        $this->assertNull($critical->delay);

        // By day nothing waits.
        $this->at('2026-11-11 08:00');
        app(Notifier::class)->send($this->pm, $this->message());
        $this->assertNull($this->pushesFor($this->pm)->last()->delay);
    }

    public function test_the_bell_and_email_are_not_held_by_quiet_hours(): void
    {
        Queue::fake();
        $this->connectFcm();
        $this->phone($this->pm);
        $this->at('2026-11-10 23:10');

        app(Notifier::class)->send($this->pm, $this->message());

        $this->assertSame(1, $this->pm->notifications()->count());
        $email = Queue::pushed(DeliverNotification::class, fn (DeliverNotification $job) => $job->channel === 'email')->sole();
        $this->assertNull($email->delay);
    }

    public function test_a_held_push_is_dropped_when_the_person_no_longer_qualifies_by_morning(): void
    {
        Queue::fake();
        $this->connectFcm();
        $this->fakeFcm();
        $other = $this->makeProject($this->organization, ['name' => 'Hilltop Villas']);
        $this->at('2026-11-10 23:10');

        $people = collect(['kept', 'category off', 'push off', 'lost the permission', 'moved to another project', 'deactivated', 'moved company'])
            ->mapWithKeys(fn (string $case) => [$case => tap($this->member($this->organization, 'pm'), fn (User $user) => $this->phone($user, 'phone of '.$case))]);
        $people->each(fn (User $user) => app(Notifier::class)->send($user, $this->message(['permissions' => ['alerts.manage']])));

        // Overnight, six of the seven change.
        $this->prefer($people['category off'], ['push_categories' => [PushCategory::ALERTS => false]]);
        $this->prefer($people['push off'], ['push' => false]);
        $people['lost the permission']->update(['role' => 'engineer']);
        $this->limitTo($people['moved to another project'], $other);
        $people['deactivated']->update(['is_active' => false]);
        $people['moved company']->forceFill(['organization_id' => $this->tenant('Elsewhere')->id])->save();

        $this->at('2026-11-11 07:00');
        $people->each(fn (User $user) => $this->work($this->pushesFor($user)->sole()));

        $this->assertSame(['phone of kept'], $this->phonesReached());
    }

    public function test_a_held_push_about_an_alert_that_closed_overnight_is_not_sent(): void
    {
        Queue::fake();
        $this->connectFcm();
        $this->fakeFcm();
        $this->phone($this->pm, 'manager-phone');
        $this->phone($this->engineer, 'engineer-phone');
        $this->at('2026-11-10 23:10');
        $still = Alert::create([...$this->alert->only(['organization_id', 'project_id', 'severity', 'title', 'message', 'status', 'first_seen_at', 'last_seen_at']), 'type' => 'schedule.deadline', 'open_key' => 'schedule.deadline|project']);

        app(Notifier::class)->send($this->pm, $this->message());
        app(Notifier::class)->send($this->engineer, $this->message(['id' => $still->id]));
        // A material gap names the material, and carries the alert it came from.
        app(Notifier::class)->pushOnly($this->engineer, $this->message(['category' => PushCategory::MATERIAL, 'type' => 'material_flag', 'id' => 9, 'permissions' => [], 'extra' => ['alert_id' => $this->alert->id]]));

        $this->alert->update(['status' => Alert::RESOLVED, 'open_key' => null, 'resolved_at' => now()]);
        $this->at('2026-11-11 07:00');
        $this->pushesFor($this->pm)->merge($this->pushesFor($this->engineer))->each(fn (DeliverNotification $job) => $this->work($job));

        $this->assertSame(['engineer-phone'], $this->phonesReached());
        Http::assertSent(fn (Request $request) => ($request['message']['data']['id'] ?? null) === (string) $still->id);
    }

    public function test_a_held_push_for_an_approval_that_moved_on_overnight_is_not_sent(): void
    {
        Queue::fake();
        $this->connectFcm();
        $this->fakeFcm();
        $owner = $this->member($this->organization, 'owner');
        $qs = $this->member($this->organization, 'qs');
        $this->phone($qs, 'estimator-phone');
        $this->phone($this->pm, 'manager-phone');
        $this->actingAs($this->engineer);
        $this->at('2026-11-10 22:30');

        // Sent late in the evening: the estimator's push waits for the morning.
        $approval = app(MeasurementService::class)->submit($this->draftEntry($this->project, $this->makeBoqItem($this->project), $this->engineer), $this->engineer);
        $forEstimator = $this->pushesFor($qs)->sole();
        $this->assertNotNull($forEstimator->delay);
        $this->assertSame(['approval', (string) $approval->id], [$this->dataOf($forEstimator)['type'], $this->dataOf($forEstimator)['id']]);

        // The owner approves that level at night, so it now waits on the project manager instead.
        app(ApprovalService::class)->act($approval, $owner, 'approve');
        $forManager = $this->pushesFor($this->pm)->sole();

        $this->at('2026-11-11 07:00');
        $this->work($forEstimator);
        $this->work($forManager);

        $this->assertSame(['manager-phone'], $this->phonesReached());
    }

    public function test_each_kind_of_push_obeys_its_own_switch_and_the_bell_still_rings(): void
    {
        Queue::fake();
        $this->connectFcm();
        $this->phone($this->pm);
        $this->prefer($this->pm, ['email' => false, 'push_categories' => [PushCategory::ALERTS => false]]);

        app(Notifier::class)->send($this->pm, $this->message());
        app(Notifier::class)->send($this->pm, $this->message(['category' => PushCategory::APPROVALS, 'type' => 'approval', 'permissions' => []], ['event' => 'approval.approved']));

        $this->assertSame(['approval.approved'], $this->pushesFor($this->pm)->map(fn (DeliverNotification $job) => $job->message->event)->all());
        $this->assertSame(2, $this->pm->notifications()->count());
    }

    public function test_an_update_with_no_kind_follows_only_the_main_push_switch(): void
    {
        Queue::fake();
        $this->connectFcm();
        $this->phone($this->pm);
        $this->prefer($this->pm, ['push_categories' => array_fill_keys(PushCategory::all(), false)]);

        app(Notifier::class)->send($this->pm, new NotificationMessage('company.export_ready', 'Your company data export is ready'));
        $this->assertCount(1, $this->pushesFor($this->pm));

        $this->prefer($this->pm, ['push' => false]);
        app(Notifier::class)->send($this->pm, new NotificationMessage('company.export_ready', 'Your company data export is ready'));
        app(Notifier::class)->pushOnly($this->pm, $this->message());
        $this->assertCount(1, $this->pushesFor($this->pm));
    }

    public function test_the_same_thing_is_pushed_to_a_person_once(): void
    {
        Queue::fake();
        $this->connectFcm();
        $this->phone($this->pm, 'phone-1');
        $this->phone($this->pm, 'phone-2');
        $this->phone($this->engineer);
        $this->prefer($this->engineer, ['email' => false]);
        $message = $this->message(['dedupeKey' => 'alert:7:schedule.behind|project:warning']);

        app(Notifier::class)->send($this->pm, $message);
        app(Notifier::class)->send($this->pm, $message);
        app(Notifier::class)->pushOnly($this->pm, $message);

        // Once, on each of the two phones. Someone else is told separately, and another severity is a new thing to say.
        $this->assertCount(2, $this->pushesFor($this->pm));
        app(Notifier::class)->pushOnly($this->engineer, $message);
        $this->assertCount(1, $this->pushesFor($this->engineer));
        app(Notifier::class)->send($this->pm, $this->message(['dedupeKey' => 'alert:7:schedule.behind|project:critical']));
        $this->assertCount(4, $this->pushesFor($this->pm));

        $this->assertSame(3, PushReceipt::withoutGlobalScopes()->count());
        $this->assertSame(3, $this->pm->notifications()->count());
    }

    public function test_the_same_cause_can_be_pushed_again_once_a_day_has_passed(): void
    {
        Queue::fake();
        $this->connectFcm();
        $this->phone($this->pm);
        $message = $this->message(['dedupeKey' => 'alert:7:schedule.behind|project:warning']);

        app(Notifier::class)->send($this->pm, $message);
        $this->travel(23)->hours();
        app(Notifier::class)->send($this->pm, $message);
        $this->assertCount(1, $this->pushesFor($this->pm));

        $this->travel(2)->hours();
        app(Notifier::class)->send($this->pm, $message);
        $this->assertCount(2, $this->pushesFor($this->pm));
        $this->assertSame(1, PushReceipt::withoutGlobalScopes()->count());
    }

    public function test_a_push_that_could_not_be_queued_may_be_tried_again(): void
    {
        $this->connectFcm();
        $this->phone($this->pm);
        $message = $this->message(['dedupeKey' => 'alert:7:schedule.behind|project:warning']);
        $preference = NotificationPreference::for($this->pm);

        Queue::shouldReceive('connection')->andThrow(new RuntimeException('Queue is down'));
        Queue::shouldReceive('push')->andThrow(new RuntimeException('Queue is down'));

        try {
            app(PushDispatcher::class)->queue($this->pm, fn () => $message, $preference);
        } catch (RuntimeException) {
            // The notifier logs this and carries on.
        }

        $this->assertSame(0, PushReceipt::withoutGlobalScopes()->count());
    }

    public function test_a_long_key_is_still_one_key(): void
    {
        $dispatcher = app(PushDispatcher::class);
        $long = 'alert:7:'.str_repeat('x', 300);

        $this->assertTrue($dispatcher->claim($this->pm, $long.':a', 'alert.x'));
        $this->assertFalse($dispatcher->claim($this->pm, $long.':a', 'alert.x'));
        $this->assertTrue($dispatcher->claim($this->pm, $long.':b', 'alert.x'));
    }

    public function test_someone_without_money_view_is_never_pushed_a_rupee_figure_whoever_wrote_it(): void
    {
        Queue::fake();
        Log::spy();
        $this->connectFcm();
        $viewer = $this->member($this->organization, 'viewer');
        $store = $this->member($this->organization, 'store');
        collect([$this->pm, $this->engineer, $viewer, $store])->each(fn (User $user) => $this->phone($user, 'phone-'.$user->id));

        foreach (['Paid ₹2,50,000.00 to the contractor', 'Rate Rs. 4100 per cum', 'Total INR 73105001', 'Balance ₹ -500'] as $body) {
            foreach ([$this->pm, $this->engineer, $viewer, $store] as $user) {
                app(Notifier::class)->pushOnly($user, $this->message(['permissions' => []], ['body' => $body]));
            }
        }

        $this->assertCount(4, $this->pushesFor($this->pm));
        $this->assertCount(0, $this->pushesFor($this->engineer));
        $this->assertCount(0, $this->pushesFor($viewer));
        $this->assertCount(0, $this->pushesFor($store));

        // The fact is logged for whoever maintains the writer. The words are not.
        Log::shouldHaveReceived('warning')->with('Push withheld: it carries a money figure for someone without money.view', Mockery::on(fn (array $context) => array_keys($context) === ['user_id', 'event']))->times(12);
    }

    public function test_quantities_and_percentages_are_not_mistaken_for_money(): void
    {
        Queue::fake();
        $this->connectFcm();
        $this->phone($this->engineer);

        app(Notifier::class)->pushOnly($this->engineer, $this->message(['permissions' => []], ['body' => 'Cement: 105 bag used against 100 bag, 5 bag over (5% wastage). Rs of the drawing, INR code.']));

        $this->assertCount(1, $this->pushesFor($this->engineer));
    }

    public function test_money_is_checked_again_when_a_held_push_is_sent(): void
    {
        Queue::fake();
        $this->connectFcm();
        $this->fakeFcm();
        $this->phone($this->pm);
        $this->at('2026-11-10 23:10');
        app(Notifier::class)->pushOnly($this->pm, $this->message(['permissions' => []], ['body' => 'Paid ₹2,50,000.00']));

        // Overnight the manager is moved to a role that does not see money.
        $this->pm->update(['role' => 'engineer']);
        $this->work($this->pushesFor($this->pm)->sole());

        Http::assertNothingSent();
    }

    public function test_each_phone_is_pushed_in_the_language_its_app_is_set_to(): void
    {
        Queue::fake();
        $this->connectFcm();
        $this->pm->update(['locale' => 'mr']);
        $hindi = $this->phone($this->pm, 'hindi-phone', ['locale' => 'hi']);
        $own = $this->phone($this->pm, 'no-language-phone');
        $tamil = $this->phone($this->pm, 'tamil-phone', ['locale' => 'ta']);

        app(Notifier::class)->send($this->pm, fn (string $locale) => new NotificationMessage('approval.approved', __('Approved: :document', ['document' => 'MB-1'], $locale)));

        $titles = $this->pushesFor($this->pm)->mapWithKeys(fn (DeliverNotification $job) => [$job->deviceId => $job->message->title]);
        $this->assertSame('मंज़ूर: MB-1', $titles[$hindi->id]);
        $this->assertSame('मंजूर: MB-1', $titles[$own->id]);
        $this->assertSame('मंजूर: MB-1', $titles[$tamil->id]);
        $this->assertSame('मंजूर: MB-1', $this->pm->notifications()->sole()->data['title']);
    }

    public function test_someone_with_no_phone_or_who_is_deactivated_is_not_queued_for(): void
    {
        Queue::fake();
        $this->connectFcm();
        $this->prefer($this->pm, ['email' => false]);

        app(Notifier::class)->send($this->pm, $this->message(['dedupeKey' => 'k']));
        Queue::assertNothingPushed();
        $this->assertSame(0, PushReceipt::withoutGlobalScopes()->count());

        $this->phone($this->engineer);
        $this->engineer->update(['is_active' => false]);
        app(Notifier::class)->pushOnly($this->engineer, $this->message());
        Queue::assertNothingPushed();
    }

    public function test_on_the_database_queue_a_held_push_waits_in_the_jobs_table_until_morning(): void
    {
        // Production runs "queue:work database", so this goes through that driver for real.
        config(['queue.default' => 'database']);
        $this->connectFcm();
        $this->fakeFcm();
        $this->phone($this->pm, 'manager-phone');
        $this->prefer($this->pm, ['email' => false]);
        $this->at('2026-11-10 23:10');

        app(Notifier::class)->send($this->pm, $this->message());
        app(Notifier::class)->send($this->pm, $this->message(overrides: ['critical' => true, 'title' => 'Deadline passed · Riverside Tower']));

        // Three jobs: the held push for the morning, and for the critical update a push and its email, both due now.
        $night = Carbon::parse('2026-11-10 23:10', 'Asia/Kolkata')->timestamp;
        $morning = Carbon::parse('2026-11-11 07:00', 'Asia/Kolkata')->timestamp;
        $this->assertEqualsCanonicalizing([$morning, $night, $night], DB::table('jobs')->pluck('available_at')->map(fn ($at) => (int) $at)->all());

        // One job per run. A worker started without --once is a daemon: it puts signal handlers on this whole process,
        // and they would go off later in the middle of another test (see Tests\TestCase::tearDown).
        $work = ['connection' => 'database', '--once' => true, '--sleep' => 0];

        // That night the worker sends the critical push and its email. A third run finds nothing due and leaves the held push where it is.
        for ($run = 1; $run <= 3; $run++) {
            $this->artisan('queue:work', $work);
        }
        $this->assertSame(['Deadline passed · Riverside Tower'], $this->titlesSent());
        $this->assertSame(1, DB::table('jobs')->count());

        $this->at('2026-11-11 07:00');
        $this->artisan('queue:work', $work);

        $this->assertSame(['Deadline passed · Riverside Tower', 'Behind schedule · Riverside Tower'], $this->titlesSent());
        $this->assertSame(0, DB::table('jobs')->count());
        $this->assertSame(0, DB::table('failed_jobs')->count());
    }

    public function test_a_push_job_comes_back_from_the_queue_as_it_went_in(): void
    {
        $job = new DeliverNotification($this->pm->id, 'push', $this->message(['dedupeKey' => 'k', 'extra' => ['alert_type' => 'schedule.behind']], ['critical' => true]), 7);

        // The database queue stores the job as text and the worker reads it back, possibly hours later.
        $restored = unserialize(serialize($job));

        $this->assertEquals($job->message, $restored->message);
        $this->assertSame(['alert', (string) $this->alert->id, 'alerts', '1'], array_values(array_intersect_key($this->dataOf($restored), array_flip(['type', 'id', 'category', 'critical']))));
    }

    public function test_a_job_queued_before_this_release_is_still_delivered(): void
    {
        $this->connectFcm();
        $this->fakeFcm();
        $device = $this->phone($this->pm);

        // A message stored by the old code has no push target at all: the field is simply absent.
        $old = (new ReflectionClass(NotificationMessage::class))->newInstanceWithoutConstructor();

        foreach (['event' => 'issue.assigned', 'title' => 'Issue assigned to you: Crack in C7', 'body' => 'Riverside Tower', 'url' => 'https://example.test/issues', 'critical' => false] as $name => $value) {
            (new ReflectionProperty(NotificationMessage::class, $name))->setValue($old, $value);
        }

        $this->work(unserialize(serialize(new DeliverNotification($this->pm->id, 'push', $old, $device->id))));

        Http::assertSent(fn (Request $request) => ($request['message']['notification']['title'] ?? null) === 'Issue assigned to you: Crack in C7'
            && $request['message']['data'] === ['type' => 'link', 'id' => '', 'category' => '', 'event' => 'issue.assigned', 'critical' => '0', 'url' => 'https://example.test/issues']);
    }

    public function test_a_busy_firebase_makes_the_job_throw_so_the_queue_retries_it_with_backoff(): void
    {
        $this->connectFcm();
        $this->fakeFcmAnswers([503, ['error' => ['status' => 'UNAVAILABLE']]], [200, ['name' => 'projects/demo-project/messages/1']]);
        $device = $this->phone($this->pm);
        $job = new DeliverNotification($this->pm->id, 'push', $this->message(), $device->id);

        $this->assertSame(3, $job->tries);
        $this->assertSame([30, 120], $job->backoff);

        $thrown = null;

        try {
            $this->work($job);
        } catch (RuntimeException $exception) {
            $thrown = $exception;
        }

        // A busy Firebase throws, so the queue runs the job again after the backoff. The second run gets through.
        $this->assertNotNull($thrown);
        $this->assertNotInstanceOf(PermanentDeliveryFailure::class, $thrown);
        $this->assertStringContainsString('HTTP 503', $thrown->getMessage());

        $this->work($job);
        $this->assertCount(2, Http::recorded(fn (Request $request) => str_contains($request->url(), 'messages:send')));
    }

    public function test_with_no_service_account_nothing_is_queued_or_sent_and_nothing_fails(): void
    {
        Queue::fake();
        Log::spy();
        $device = $this->phone($this->pm, 'manager-phone');
        $this->phone($this->engineer, 'engineer-phone');

        app(Notifier::class)->send($this->pm, $this->message(['dedupeKey' => 'k']));
        app(Notifier::class)->pushOnly($this->engineer, $this->message());
        app(Notifier::class)->deliver($this->pm, 'push', $this->message(), $device->id);

        $this->assertFalse(app(Notifier::class)->connected('push'));
        $this->assertCount(0, $this->pushesFor($this->pm));
        $this->assertCount(0, $this->pushesFor($this->engineer));
        $this->assertSame(0, PushReceipt::withoutGlobalScopes()->count());
        $this->assertSame(1, $this->pm->notifications()->count());
        Http::assertNothingSent();
        Log::shouldNotHaveReceived('warning');
        Log::shouldNotHaveReceived('error');
    }

    public function test_with_no_service_account_the_work_itself_goes_on_as_before(): void
    {
        // The queue runs in line here, so anything that tried to reach Firebase would be a stray request and fail the test.
        Log::spy();
        $owner = $this->member($this->organization, 'owner');
        $qs = $this->member($this->organization, 'qs');
        collect([$owner, $qs, $this->pm, $this->engineer])->each(fn (User $user) => $this->phone($user, 'phone-'.$user->id));
        $this->actingAs($this->engineer);

        $approval = app(MeasurementService::class)->submit($this->draftEntry($this->project, $this->makeBoqItem($this->project), $this->engineer), $this->engineer);
        $approval = app(ApprovalService::class)->act($approval, $qs, 'approve');
        $approval = app(ApprovalService::class)->act($approval, $this->pm, 'approve');
        app(AlertNotifier::class)->opened($this->project, [$this->alert]);

        $this->assertSame('APPROVED', $approval->status);
        $this->assertSame(1, $qs->notifications()->count());
        $this->assertSame(1, $this->engineer->notifications()->count());
        $this->assertNotNull($this->alert->refresh()->notified_at);
        $this->assertSame(0, PushReceipt::withoutGlobalScopes()->count());
        Http::assertNothingSent();
        Log::shouldNotHaveReceived('warning');
        Log::shouldNotHaveReceived('error');

        // The app is told the truth when it registers, so it can say push is not switched on yet.
        Sanctum::actingAs($this->engineer);
        $this->postJson(self::API.'/devices', ['token' => 'another-phone', 'platform' => 'android'])->assertCreated()->assertJsonPath('push_connected', false);
        $this->getJson(self::API.'/notification-preferences')->assertOk()->assertJsonPath('data.push.connected', false);
    }
}
