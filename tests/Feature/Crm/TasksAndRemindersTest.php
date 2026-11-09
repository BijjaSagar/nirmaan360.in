<?php

namespace Tests\Feature\Crm;

use App\Models\CrmActivity;
use App\Models\CrmTask;
use App\Notifications\CrmReminder;
use App\Services\Crm\ReminderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\BuildsTenant;
use Tests\TestCase;

class TasksAndRemindersTest extends TestCase
{
    use BuildsCrm, BuildsTenant, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function task(object $user, array $overrides = []): CrmTask
    {
        return CrmTask::create([
            'organization_id' => $user->organization_id,
            'title' => 'Call the executive engineer',
            'due_on' => today()->addDay(),
            'assigned_to' => $user->id,
            'created_by' => $user->id,
            'status' => 'open',
            ...$overrides,
        ]);
    }

    public function test_a_follow_up_is_added_to_a_tender_and_defaults_to_the_current_user(): void
    {
        $user = $this->crmUser();
        $tender = $this->makeTender($user->organization);

        $this->post('/crm/tasks', ['title' => 'Chase the EE', 'due_on' => today()->addDays(3)->toDateString(), 'due_time' => '11:30', 'subject_type' => 'tender', 'subject_id' => $tender->id, 'notes' => 'Ask about corrigendum'])
            ->assertSessionHasNoErrors();

        $task = CrmTask::firstOrFail();
        $this->assertSame($user->id, $task->assigned_to);
        $this->assertSame('open', $task->status);
        $this->assertSame('tender', $task->subject_type);
        $this->assertSame($tender->id, $task->subject_id);
        $this->assertSame('11:30', $task->due_time);
        $this->get(route('crm.tenders.show', $tender))->assertSee('Chase the EE');
    }

    public function test_the_reminder_defaults_to_nine_in_the_morning_on_the_due_date_unless_that_has_passed(): void
    {
        $user = $this->crmUser();

        $this->post('/crm/tasks', ['title' => 'Future', 'due_on' => today()->addDays(2)->toDateString()]);
        $this->post('/crm/tasks', ['title' => 'Timed', 'due_on' => today()->addDays(2)->toDateString(), 'due_time' => '16:45']);
        $this->post('/crm/tasks', ['title' => 'Past', 'due_on' => today()->subDay()->toDateString()]);
        $this->post('/crm/tasks', ['title' => 'Own time', 'due_on' => today()->addDays(5)->toDateString(), 'remind_at' => today()->addDays(4)->format('Y-m-d\T07:15')]);

        $byTitle = CrmTask::all()->keyBy('title');
        $this->assertSame(today()->addDays(2)->format('Y-m-d 09:00'), $byTitle['Future']->remind_at->format('Y-m-d H:i'));
        $this->assertSame(today()->addDays(2)->format('Y-m-d 16:45'), $byTitle['Timed']->remind_at->format('Y-m-d H:i'));
        $this->assertNull($byTitle['Past']->remind_at);
        $this->assertSame(today()->addDays(4)->format('Y-m-d 07:15'), $byTitle['Own time']->remind_at->format('Y-m-d H:i'));
    }

    public function test_a_follow_up_needs_a_title_a_date_and_a_valid_time(): void
    {
        $this->crmUser();

        $this->post('/crm/tasks', ['title' => '', 'due_on' => '2026-12-20'])->assertSessionHasErrors('title');
        $this->post('/crm/tasks', ['title' => 'x'])->assertSessionHasErrors('due_on');
        $this->post('/crm/tasks', ['title' => 'x', 'due_on' => '2026-12-20', 'due_time' => '25:99'])->assertSessionHasErrors('due_time');
        $this->post('/crm/tasks', ['title' => 'x', 'due_on' => '2026-12-20', 'subject_type' => 'tender'])->assertSessionHasErrors('subject_id');
        $this->post('/crm/tasks', ['title' => 'x', 'due_on' => '2026-12-20', 'subject_type' => 'invoice', 'subject_id' => 1])->assertSessionHasErrors('subject_type');
        $this->assertSame(0, CrmTask::count());
    }

    public function test_adding_the_same_follow_up_twice_saves_it_once(): void
    {
        $this->crmUser();
        $payload = ['title' => 'Once', 'due_on' => '2026-12-20', 'client_uuid' => (string) Str::uuid()];

        $this->post('/crm/tasks', $payload);
        $this->post('/crm/tasks', $payload);

        $this->assertSame(1, CrmTask::count());
    }

    public function test_finishing_a_follow_up_is_logged_on_its_record_and_can_be_undone(): void
    {
        $user = $this->crmUser();
        $lead = $this->makeLead($user->organization);
        $task = $this->task($user, ['subject_type' => 'lead', 'subject_id' => $lead->id]);

        $this->post(route('crm.tasks.done', $task));

        $task->refresh();
        $this->assertSame('done', $task->status);
        $this->assertSame($user->id, $task->completed_by);
        $this->assertNotNull($task->completed_at);
        $this->assertTrue(CrmActivity::where('subject_type', 'lead')->where('subject_id', $lead->id)->where('body', 'like', 'Follow-up done%')->exists());

        $this->post(route('crm.tasks.reopen', $task));
        $this->assertSame('open', $task->fresh()->status);
        $this->assertNull($task->fresh()->completed_at);
        $this->assertDatabaseHas('audit_logs', ['auditable_type' => $task->getMorphClass(), 'auditable_id' => $task->id, 'action' => 'updated']);
    }

    public function test_rescheduling_a_follow_up_lets_its_reminder_fire_again(): void
    {
        $user = $this->crmUser();
        $task = $this->task($user, ['remind_at' => now()->subHour(), 'reminded_at' => now()->subMinutes(30)]);

        $this->patch(route('crm.tasks.update', $task), ['title' => $task->title, 'due_on' => today()->addDays(4)->toDateString()]);

        $this->assertNull($task->fresh()->reminded_at);
        $this->assertSame(today()->addDays(4)->format('Y-m-d 09:00'), $task->fresh()->remind_at->format('Y-m-d H:i'));
    }

    public function test_the_list_shows_my_open_follow_ups_and_can_switch_to_everyone_and_done(): void
    {
        $user = $this->crmUser();
        $colleague = $this->member($user->organization, 'pm');
        $this->task($user, ['title' => 'My own task']);
        $this->task($colleague, ['title' => 'Their task', 'assigned_to' => $colleague->id]);
        $this->task($user, ['title' => 'Finished task', 'status' => 'done', 'completed_at' => now()]);
        $this->task($user, ['title' => 'Late task', 'due_on' => today()->subDays(2)]);

        $this->get(route('crm.tasks.index'))->assertSee('My own task')->assertSee('Late task')->assertDontSee('Their task')->assertDontSee('Finished task');
        $this->get(route('crm.tasks.index', ['scope' => 'all']))->assertSee('Their task');
        $this->get(route('crm.tasks.index', ['status' => 'done']))->assertSee('Finished task')->assertDontSee('My own task');
        $this->get(route('crm.tasks.index', ['when' => 'overdue']))->assertSee('Late task')->assertDontSee('My own task');
        $this->get(route('crm.tasks.index', ['when' => 'today']))->assertDontSee('My own task');
    }

    public function test_a_due_reminder_reaches_the_assignee_once(): void
    {
        $user = $this->crmUser();
        $colleague = $this->member($user->organization, 'pm');
        $task = $this->task($user, ['title' => 'Send corrigendum reply', 'assigned_to' => $colleague->id, 'remind_at' => now()->subMinutes(5)]);

        $result = app(ReminderService::class)->run();

        $this->assertSame(['tasks' => 1, 'tenders' => 0], $result);
        $this->assertCount(1, $colleague->notifications);
        $this->assertCount(0, $user->notifications);
        $this->assertSame('Follow-up: Send corrigendum reply', $colleague->notifications->first()->data['title']);
        $this->assertNotNull($task->fresh()->reminded_at);

        $this->assertSame(['tasks' => 0, 'tenders' => 0], app(ReminderService::class)->run());
        $this->assertCount(1, $colleague->fresh()->notifications);
    }

    public function test_the_reminder_shows_under_the_bell(): void
    {
        $user = $this->crmUser();
        $this->task($user, ['title' => 'Ring the collector', 'remind_at' => now()->subMinute()]);
        app(ReminderService::class)->run();

        $this->get('/dashboard')->assertOk()->assertSee('Follow-up: Ring the collector');
    }

    public function test_reminders_skip_done_future_and_unset_times(): void
    {
        $user = $this->crmUser();
        $this->task($user, ['title' => 'Done', 'status' => 'done', 'remind_at' => now()->subHour()]);
        $this->task($user, ['title' => 'Later', 'remind_at' => now()->addHour()]);
        $this->task($user, ['title' => 'No reminder', 'remind_at' => null]);

        $this->assertSame(['tasks' => 0, 'tenders' => 0], app(ReminderService::class)->run());
        $this->assertCount(0, $user->notifications);
    }

    public function test_a_reminder_goes_to_the_owner_when_the_assignee_is_switched_off(): void
    {
        $owner = $this->crmUser();
        $gone = $this->member($owner->organization, 'pm');
        $gone->update(['is_active' => false]);
        $this->task($owner, ['assigned_to' => $gone->id, 'remind_at' => now()->subMinute()]);

        app(ReminderService::class)->run();

        $this->assertCount(0, $gone->notifications);
        $this->assertCount(1, $owner->notifications);
    }

    public function test_a_company_without_the_crm_gets_no_reminders(): void
    {
        $free = $this->member($this->tenant('Small Co', 'free'), 'owner');
        $task = $this->task($free, ['remind_at' => now()->subMinute()]);

        $this->assertSame(['tasks' => 0, 'tenders' => 0], app(ReminderService::class)->run());
        $this->assertCount(0, $free->notifications);
        $this->assertNotNull($task->fresh()->reminded_at);
    }

    public function test_reminders_never_cross_companies(): void
    {
        $one = $this->member($this->tenant('One'), 'owner');
        $two = $this->member($this->tenant('Two'), 'owner');
        $this->task($one, ['remind_at' => now()->subMinute()]);

        app(ReminderService::class)->run();

        $this->assertCount(1, $one->notifications);
        $this->assertCount(0, $two->notifications);
    }

    public function test_a_tender_deadline_inside_two_days_is_warned_once(): void
    {
        $user = $this->crmUser();
        $soon = $this->makeTender($user->organization, ['title' => 'Closing soon', 'stage' => 'bidding', 'due_at' => now()->addHours(30), 'owner_id' => $user->id]);
        $far = $this->makeTender($user->organization, ['title' => 'Far away', 'due_at' => now()->addDays(10), 'owner_id' => $user->id]);
        $submitted = $this->makeTender($user->organization, ['title' => 'Sent already', 'stage' => 'submitted', 'due_at' => now()->addHours(5), 'owner_id' => $user->id]);
        $past = $this->makeTender($user->organization, ['title' => 'Missed', 'due_at' => now()->subHour(), 'owner_id' => $user->id]);

        $this->assertSame(['tasks' => 0, 'tenders' => 1], app(ReminderService::class)->run());
        $this->assertSame(['tasks' => 0, 'tenders' => 0], app(ReminderService::class)->run());

        $this->assertCount(1, $user->notifications);
        $this->assertSame('Tender due soon: Closing soon', $user->notifications->first()->data['title']);
        $this->assertNotNull($soon->fresh()->due_alerted_at);
        $this->assertNull($far->fresh()->due_alerted_at);
        $this->assertNull($submitted->fresh()->due_alerted_at);
        $this->assertNull($past->fresh()->due_alerted_at);
    }

    public function test_the_console_command_runs_the_reminders(): void
    {
        $user = $this->crmUser();
        $this->task($user, ['remind_at' => now()->subMinute()]);

        $this->artisan('crm:send-reminders')->expectsOutputToContain('Sent 1 task reminders and 0 tender deadline warnings.')->assertSuccessful();

        $this->assertSame(CrmReminder::class, DB::table('notifications')->value('type'));
    }
}
