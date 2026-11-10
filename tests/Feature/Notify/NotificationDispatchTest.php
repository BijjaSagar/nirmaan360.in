<?php

namespace Tests\Feature\Notify;

use App\Jobs\Notify\DeliverNotification;
use App\Mail\Notify\WorkflowNotificationMail;
use App\Models\DeviceToken;
use App\Models\Issue;
use App\Models\NotificationFailure;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Services\ApprovalService;
use App\Services\IssueService;
use App\Services\MeasurementService;
use App\Services\Notify\NotificationMessage;
use App\Services\Notify\Notifier;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use RuntimeException;

class NotificationDispatchTest extends NotifyTestCase
{
    private Organization $organization;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->organization = $this->tenant();
        $this->project = $this->makeProject($this->organization);
    }

    private function submitEntry(User $engineer)
    {
        $entry = $this->draftEntry($this->project, $this->makeBoqItem($this->project), $engineer);

        return app(MeasurementService::class)->submit($entry, $engineer);
    }

    private function issue(User $raiser, array $overrides = []): Issue
    {
        return app(IssueService::class)->create($this->project, ['title' => 'Crack in C7', 'priority' => 'low', ...$overrides], $raiser);
    }

    private function queuedFor(User $user, string $channel): int
    {
        return Queue::pushed(DeliverNotification::class, fn ($job) => $job->userId === $user->id && $job->channel === $channel)->count();
    }

    public function test_submitting_tells_the_people_who_can_approve_next(): void
    {
        Queue::fake();
        $engineer = $this->member($this->organization);
        $qs = $this->member($this->organization, 'qs');
        $pm = $this->member($this->organization, 'pm');
        $owner = $this->member($this->organization, 'owner');
        $this->actingAs($engineer);

        $this->submitEntry($engineer);

        $this->assertSame(1, $qs->notifications()->count());
        $this->assertStringStartsWith('Approval needed:', $qs->notifications()->first()->data['title']);
        $this->assertSame(0, $pm->notifications()->count());
        $this->assertSame(0, $owner->notifications()->count());
        $this->assertSame(0, $engineer->notifications()->count());
    }

    public function test_each_approval_level_tells_the_next_approver_and_the_end_tells_the_submitter(): void
    {
        Queue::fake();
        $engineer = $this->member($this->organization);
        $qs = $this->member($this->organization, 'qs');
        $pm = $this->member($this->organization, 'pm');
        $this->actingAs($engineer);
        $approval = $this->submitEntry($engineer);

        $approval = app(ApprovalService::class)->act($approval, $qs, 'approve');

        $this->assertSame(1, $pm->notifications()->count());
        $this->assertSame(0, $engineer->notifications()->count());

        app(ApprovalService::class)->act($approval, $pm, 'approve');

        $this->assertStringStartsWith('Approved:', $engineer->notifications()->first()->data['title']);
    }

    public function test_when_nobody_holds_the_role_an_owner_is_told_instead(): void
    {
        Queue::fake();
        $engineer = $this->member($this->organization);
        $owner = $this->member($this->organization, 'owner');
        $this->actingAs($engineer);

        $this->submitEntry($engineer);

        $this->assertSame(1, $owner->notifications()->count());
    }

    public function test_a_return_reaches_the_submitter_with_the_remark(): void
    {
        Queue::fake();
        $engineer = $this->member($this->organization);
        $owner = $this->member($this->organization, 'owner');
        $this->actingAs($engineer);
        $approval = $this->submitEntry($engineer);

        app(ApprovalService::class)->act($approval, $owner, 'return', 'Check the depth');

        $data = $engineer->notifications()->first()->data;
        $this->assertStringStartsWith('Returned:', $data['title']);
        $this->assertSame('Check the depth', $data['body']);
        $this->assertStringContainsString('/measurements/', $data['url']);
    }

    public function test_a_notice_is_written_in_the_language_of_the_person_receiving_it(): void
    {
        Queue::fake();
        $engineer = $this->member($this->organization);
        $qs = $this->member($this->organization, 'qs');
        $qs->update(['locale' => 'hi']);
        $this->actingAs($engineer);

        $this->submitEntry($engineer);

        $this->assertStringStartsWith('मंज़ूरी चाहिए:', $qs->notifications()->first()->data['title']);
    }

    public function test_channels_follow_each_persons_choices(): void
    {
        Queue::fake();
        $this->connectWhatsApp();
        $this->connectFcm();
        $engineer = $this->member($this->organization);
        $qs = $this->member($this->organization, 'qs');
        $qs->update(['phone' => '9876543210']);
        $this->phone($qs, 'one');
        $this->phone($qs, 'two');
        $this->prefer($qs, ['email' => false, 'push' => true, 'whatsapp' => true]);
        $this->actingAs($engineer);

        $this->submitEntry($engineer);

        $this->assertSame(0, $this->queuedFor($qs, 'email'));
        $this->assertSame(2, $this->queuedFor($qs, 'push'));
        $this->assertSame(1, $this->queuedFor($qs, 'whatsapp'));
    }

    public function test_whatsapp_is_off_until_someone_turns_it_on(): void
    {
        Queue::fake();
        $this->connectWhatsApp();
        $engineer = $this->member($this->organization);
        $qs = $this->member($this->organization, 'qs');
        $qs->update(['phone' => '9876543210']);
        $this->actingAs($engineer);

        $this->submitEntry($engineer);

        $this->assertSame(1, $this->queuedFor($qs, 'email'));
        $this->assertSame(0, $this->queuedFor($qs, 'whatsapp'));
    }

    public function test_nothing_is_queued_for_a_channel_that_is_not_connected(): void
    {
        Queue::fake();
        config(['mail.default' => 'log']);
        $engineer = $this->member($this->organization);
        $qs = $this->member($this->organization, 'qs');
        $qs->update(['phone' => '9876543210']);
        $this->phone($qs);
        $this->prefer($qs, ['email' => true, 'push' => true, 'whatsapp' => true]);
        $this->actingAs($engineer);

        $this->submitEntry($engineer);

        Queue::assertNothingPushed();
        $this->assertSame(1, $qs->notifications()->count());
    }

    public function test_someone_without_a_valid_number_is_skipped_on_whatsapp(): void
    {
        Queue::fake();
        $this->connectWhatsApp();
        $engineer = $this->member($this->organization);
        $qs = $this->member($this->organization, 'qs');
        $this->prefer($qs, ['whatsapp' => true]);
        $this->actingAs($engineer);

        $this->submitEntry($engineer);

        $this->assertSame(0, $this->queuedFor($qs, 'whatsapp'));
    }

    public function test_a_failing_provider_is_logged_and_never_breaks_the_action(): void
    {
        Log::spy();
        $this->connectWhatsApp();
        Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['code' => 132001, 'message' => 'Template missing']], 400)]);
        $engineer = $this->member($this->organization);
        $qs = $this->member($this->organization, 'qs');
        $qs->update(['phone' => '9876543210']);
        $this->prefer($qs, ['email' => false, 'push' => false, 'whatsapp' => true]);
        $this->actingAs($engineer);

        $approval = $this->submitEntry($engineer);

        $this->assertSame('IN_REVIEW', $approval->status);
        $failure = NotificationFailure::withoutGlobalScopes()->firstOrFail();
        $this->assertSame('whatsapp', $failure->channel);
        $this->assertSame('approval.waiting', $failure->event);
        $this->assertStringContainsString('132001', $failure->error);
        Log::shouldHaveReceived('warning')->withArgs(fn ($message) => $message === 'Notification not delivered');
    }

    public function test_a_queue_outage_never_breaks_the_action(): void
    {
        Queue::shouldReceive('connection')->andThrow(new RuntimeException('Queue is down'));
        Queue::shouldReceive('push')->andThrow(new RuntimeException('Queue is down'));
        $engineer = $this->member($this->organization);
        $qs = $this->member($this->organization, 'qs');
        $this->actingAs($engineer);

        $approval = $this->submitEntry($engineer);

        $this->assertSame('IN_REVIEW', $approval->status);
        $this->assertSame(1, $qs->notifications()->count());
    }

    public function test_nothing_goes_out_when_the_action_rolls_back(): void
    {
        Queue::fake();
        $engineer = $this->member($this->organization);
        $qs = $this->member($this->organization, 'qs');
        $this->actingAs($engineer);

        try {
            DB::transaction(function () use ($engineer) {
                $this->submitEntry($engineer);

                throw new RuntimeException('Something later failed.');
            });
        } catch (RuntimeException) {
            $this->addToAssertionCount(1);
        }

        $this->assertSame(0, $qs->notifications()->count());
        Queue::assertNothingPushed();
    }

    public function test_failure_log_hides_addresses_and_phone_numbers(): void
    {
        $user = $this->member($this->organization);

        app(Notifier::class)->recordFailure($user->id, 'email', 'issue.assigned', new RuntimeException('550 rejected a.person@example.com and 919876543210'));

        $error = NotificationFailure::withoutGlobalScopes()->firstOrFail()->error;
        $this->assertStringNotContainsString('a.person@example.com', $error);
        $this->assertStringNotContainsString('919876543210', $error);
    }

    public function test_email_goes_through_the_queue_job(): void
    {
        Mail::fake();
        $user = $this->member($this->organization);

        app(Notifier::class)->send($user, new NotificationMessage('issue.assigned', 'Issue assigned', 'Riverside', 'https://example.test/i'));

        Mail::assertSent(WorkflowNotificationMail::class, fn ($mail) => $mail->hasTo($user->email) && $mail->notice->title === 'Issue assigned');
    }

    public function test_the_email_renders_the_message(): void
    {
        $html = (new WorkflowNotificationMail(new NotificationMessage('issue.assigned', 'Issue assigned', 'Riverside', 'https://example.test/i')))->render();

        $this->assertStringContainsString('Issue assigned', $html);
        $this->assertStringContainsString('https://example.test/i', $html);
    }

    public function test_a_critical_notice_is_emailed_even_with_email_switched_off(): void
    {
        Queue::fake();
        $user = $this->member($this->organization);
        $this->prefer($user, ['email' => false]);

        app(Notifier::class)->send($user, new NotificationMessage('company.deletion_requested', 'Deletion', critical: true));

        $this->assertSame(1, $this->queuedFor($user, 'email'));
    }

    public function test_a_deactivated_person_gets_nothing_on_outside_channels(): void
    {
        Queue::fake();
        $user = $this->member($this->organization);
        $user->update(['is_active' => false]);

        app(Notifier::class)->send($user, new NotificationMessage('issue.assigned', 'Title'));

        Queue::assertNothingPushed();
    }

    public function test_a_push_to_an_uninstalled_app_forgets_the_device(): void
    {
        $this->connectFcm();
        $this->fakeFcm(404, ['error' => ['details' => [['errorCode' => 'UNREGISTERED']]]]);
        $user = $this->member($this->organization);
        $this->phone($user);
        $this->prefer($user, ['email' => false]);

        app(Notifier::class)->send($user, new NotificationMessage('issue.assigned', 'Title'));

        $this->assertSame(0, DeviceToken::withoutGlobalScopes()->count());
        $this->assertSame(0, NotificationFailure::withoutGlobalScopes()->count());
    }

    public function test_assigning_a_new_issue_tells_the_assignee_but_not_the_raiser(): void
    {
        Queue::fake();
        $raiser = $this->member($this->organization);
        $assignee = $this->member($this->organization);
        $this->actingAs($raiser);

        $this->issue($raiser, ['assigned_to' => $assignee->id]);

        $this->assertStringStartsWith('Issue assigned to you:', $assignee->notifications()->first()->data['title']);
        $this->assertSame(0, $raiser->notifications()->count());
    }

    public function test_an_urgent_issue_tells_owners_and_project_managers(): void
    {
        Queue::fake();
        $raiser = $this->member($this->organization);
        $pm = $this->member($this->organization, 'pm');
        $owner = $this->member($this->organization, 'owner');
        $qs = $this->member($this->organization, 'qs');
        $this->actingAs($raiser);

        $this->issue($raiser, ['priority' => 'critical']);

        $this->assertStringStartsWith('New Critical issue:', $pm->notifications()->first()->data['title']);
        $this->assertSame(1, $owner->notifications()->count());
        $this->assertSame(0, $qs->notifications()->count());
    }

    public function test_a_routine_issue_tells_nobody(): void
    {
        Queue::fake();
        $raiser = $this->member($this->organization);
        $pm = $this->member($this->organization, 'pm');
        $this->actingAs($raiser);

        $this->issue($raiser);

        $this->assertSame(0, $pm->notifications()->count());
    }

    public function test_a_repeated_issue_is_not_notified_twice(): void
    {
        Queue::fake();
        $raiser = $this->member($this->organization);
        $assignee = $this->member($this->organization);
        $this->actingAs($raiser);
        $uuid = (string) Str::uuid();

        $this->issue($raiser, ['assigned_to' => $assignee->id, 'client_uuid' => $uuid]);
        $this->issue($raiser, ['assigned_to' => $assignee->id, 'client_uuid' => $uuid]);

        $this->assertSame(1, $assignee->notifications()->count());
    }

    public function test_a_status_change_tells_the_raiser_and_reassigning_tells_the_new_assignee(): void
    {
        Queue::fake();
        $raiser = $this->member($this->organization);
        $fixer = $this->member($this->organization);
        $this->actingAs($raiser);
        $issue = $this->issue($raiser);

        Auth::login($fixer);
        app(IssueService::class)->update($issue, ['status' => 'in_progress']);

        $this->assertSame('Issue In Progress: Crack in C7', $raiser->notifications()->first()->data['title']);

        Auth::login($raiser);
        app(IssueService::class)->update($issue, ['assigned_to' => $fixer->id]);

        $this->assertStringStartsWith('Issue assigned to you:', $fixer->notifications()->first()->data['title']);
    }

    public function test_changing_nothing_tells_nobody(): void
    {
        Queue::fake();
        $raiser = $this->member($this->organization);
        $fixer = $this->member($this->organization);
        $this->actingAs($raiser);
        $issue = $this->issue($raiser);

        Auth::login($fixer);
        app(IssueService::class)->update($issue, ['status' => 'open']);

        $this->assertSame(0, $raiser->notifications()->count());
    }
}
