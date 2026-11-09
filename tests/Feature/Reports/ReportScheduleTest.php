<?php

namespace Tests\Feature\Reports;

use App\Mail\ScheduledReportMail;
use App\Models\Project;
use App\Models\ReportSchedule;
use App\Models\User;
use App\Services\Reports\ReportMailGateway;
use App\Services\Reports\ScheduleService;
use Carbon\Carbon;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\IOFactory;

class ReportScheduleTest extends ReportsTestCase
{
    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->project = $this->makeProject($this->org, ['code' => 'RT1', 'name' => 'Riverside Tower']);
        $this->makeBoqItem($this->project);
        // Monday 9 November 2026, 06:00 in India.
        $this->travelTo(Carbon::parse('2026-11-09 06:00:00'));
    }

    private function emailConfigured(bool $configured = true): void
    {
        config(['mail.default' => $configured ? 'smtp' : 'log']);
    }

    private function payload(array $overrides = []): array
    {
        return [
            'name' => 'Monday review',
            'report' => 'boq_ledger',
            'project_id' => $this->project->id,
            'period' => 'last_30_days',
            'format' => 'xlsx',
            'frequency' => 'weekly',
            'day_of_week' => 1,
            'hour' => 7,
            'recipients' => 'owner@acme.test, Site.Lead@acme.test',
            ...$overrides,
        ];
    }

    private function schedule(array $overrides = [], ?User $by = null): ReportSchedule
    {
        $by ??= $this->pm;
        $data = $this->payload($overrides);
        $data['recipients'] = app(ScheduleService::class)->recipients($data['recipients']);

        return app(ScheduleService::class)->create($by, $data);
    }

    /** @return list<ScheduledReportMail> */
    private function sent(): array
    {
        return Mail::sent(ScheduledReportMail::class)->all();
    }

    // Saving schedules

    public function test_a_schedule_is_saved_with_its_choices(): void
    {
        $this->actingAs($this->pm)->post(route('reports.schedules.store'), $this->payload())
            ->assertRedirect(route('reports.schedules.index'))->assertSessionHas('success');

        $schedule = ReportSchedule::first();
        $this->assertSame(['owner@acme.test', 'site.lead@acme.test'], $schedule->recipients);
        $this->assertSame([$this->org->id, $this->pm->id, 'boq_ledger', $this->project->id, 'xlsx', 'weekly', 1, null, 7, true], [
            $schedule->organization_id, $schedule->created_by, $schedule->report, $schedule->project_id, $schedule->format, $schedule->frequency,
            $schedule->day_of_week, $schedule->day_of_month, $schedule->hour, $schedule->active,
        ]);
        $this->assertDatabaseHas('audit_logs', ['auditable_type' => $schedule->getMorphClass(), 'auditable_id' => $schedule->id, 'action' => 'created']);

        $this->actingAs($this->pm)->post(route('reports.schedules.store'), $this->payload(['report' => 'petty_cash', 'project_id' => '', 'frequency' => 'monthly', 'day_of_month' => 5, 'day_of_week' => 3, 'format' => 'pdf']))
            ->assertSessionHas('success');
        $monthly = ReportSchedule::latest('id')->first();
        $this->assertSame([null, 5, null, 'pdf'], [$monthly->day_of_week, $monthly->day_of_month, $monthly->project_id, $monthly->format]);
    }

    public function test_saving_twice_with_the_same_client_uuid_gives_one_schedule(): void
    {
        $uuid = (string) Str::uuid();

        $this->actingAs($this->pm)->post(route('reports.schedules.store'), $this->payload(['client_uuid' => $uuid]))->assertSessionHas('success');
        $this->actingAs($this->pm)->post(route('reports.schedules.store'), $this->payload(['client_uuid' => $uuid]))->assertSessionHas('success');

        $this->assertSame(1, ReportSchedule::count());

        // The form carries a fresh one each time it is opened.
        $this->actingAs($this->pm)->get(route('reports.schedules.index'))->assertSee('name="client_uuid"', false);
        $this->actingAs($this->pm)->post(route('reports.schedules.store'), $this->payload(['client_uuid' => 'not-a-uuid']))->assertSessionHasErrors('client_uuid');
    }

    public function test_the_choices_are_checked(): void
    {
        $bad = [
            'no recipients' => [['recipients' => ''], 'recipients'],
            'bad address' => [['recipients' => 'not-an-email'], 'recipients.0'],
            'too many' => [['recipients' => 'a@x.test, b@x.test, c@x.test, d@x.test, e@x.test, f@x.test'], 'recipients'],
            'unknown report' => [['report' => 'secrets'], 'report'],
            'unknown frequency' => [['frequency' => 'hourly'], 'frequency'],
            'weekly needs a day' => [['day_of_week' => ''], 'day_of_week'],
            'monthly day 29' => [['frequency' => 'monthly', 'day_of_month' => 29], 'day_of_month'],
            'hour 24' => [['hour' => 24], 'hour'],
            'unknown format' => [['format' => 'docx'], 'format'],
            'unknown period' => [['period' => 'custom'], 'period'],
            'ledger needs a project' => [['project_id' => ''], 'project_id'],
        ];

        foreach ($bad as [$overrides, $field]) {
            $this->actingAs($this->pm)->post(route('reports.schedules.store'), $this->payload($overrides))->assertSessionHasErrors($field);
        }

        $this->assertSame(0, ReportSchedule::count());
    }

    public function test_another_companys_project_cannot_be_scheduled(): void
    {
        $theirs = $this->makeProject($this->tenant('Other Co', 'business'));

        $this->actingAs($this->pm)->post(route('reports.schedules.store'), $this->payload(['project_id' => $theirs->id]))->assertSessionHasErrors('project_id');
        $this->assertSame(0, ReportSchedule::count());
    }

    public function test_a_person_cannot_schedule_a_report_they_cannot_open(): void
    {
        $qs = $this->member($this->org, 'qs');

        $this->expectException(ValidationException::class);
        app(ScheduleService::class)->create($qs, [...$this->payload(['report' => 'attendance_summary', 'project_id' => null, 'recipients' => ['a@x.test']])]);
    }

    public function test_each_company_can_keep_a_limited_number_of_schedules(): void
    {
        foreach (range(1, ScheduleService::MAX_PER_ORGANIZATION) as $n) {
            $this->schedule(['name' => "S{$n}"]);
        }

        $this->actingAs($this->pm)->post(route('reports.schedules.store'), $this->payload())->assertSessionHasErrors('report');
        $this->assertSame(ScheduleService::MAX_PER_ORGANIZATION, ReportSchedule::count());

        // Another company has its own allowance.
        $other = $this->tenant('Other Co', 'business');
        $theirs = $this->makeProject($other);
        $this->actingAs($this->member($other, 'pm'))->post(route('reports.schedules.store'), $this->payload(['project_id' => $theirs->id]))->assertSessionHas('success');
    }

    public function test_a_schedule_can_be_paused_resumed_and_deleted(): void
    {
        $schedule = $this->schedule();

        $this->actingAs($this->pm)->patch(route('reports.schedules.toggle', $schedule))->assertSessionHas('success');
        $this->assertFalse($schedule->fresh()->active);
        $this->actingAs($this->pm)->patch(route('reports.schedules.toggle', $schedule))->assertSessionHas('success');
        $this->assertTrue($schedule->fresh()->active);

        $this->actingAs($this->pm)->delete(route('reports.schedules.destroy', $schedule))->assertSessionHas('success');
        $this->assertSame(0, ReportSchedule::count());
        $this->assertDatabaseHas('audit_logs', ['auditable_type' => $schedule->getMorphClass(), 'auditable_id' => $schedule->id, 'action' => 'deleted']);
    }

    public function test_access_by_role_plan_and_company(): void
    {
        $schedule = $this->schedule();

        // They may look at the schedules but not change them.
        foreach (['qs', 'accounts'] as $role) {
            $user = $this->member($this->org, $role);
            $this->actingAs($user)->get(route('reports.schedules.index'))->assertOk()->assertSee('Monday review');
            $this->actingAs($user)->post(route('reports.schedules.store'), $this->payload())->assertForbidden();
            $this->actingAs($user)->patch(route('reports.schedules.toggle', $schedule))->assertForbidden();
            $this->actingAs($user)->delete(route('reports.schedules.destroy', $schedule))->assertForbidden();
        }

        foreach (['engineer', 'store', 'viewer'] as $role) {
            $this->actingAs($this->member($this->org, $role))->get(route('reports.schedules.index'))->assertForbidden();
        }

        $free = $this->tenant('Small Co', 'free');
        $this->actingAs($this->member($free, 'owner'))->post(route('reports.schedules.store'), $this->payload())->assertForbidden();

        $other = $this->member($this->tenant('Other Co', 'business'), 'owner');
        $this->actingAs($other)->get(route('reports.schedules.index'))->assertOk()->assertDontSee('Monday review');
        $this->actingAs($other)->patch(route('reports.schedules.toggle', $schedule))->assertNotFound();
        $this->actingAs($other)->delete(route('reports.schedules.destroy', $schedule))->assertNotFound();
        $this->assertSame(1, ReportSchedule::withoutGlobalScopes()->count());
        $this->assertTrue($schedule->fresh()->active);
    }

    public function test_the_page_warns_when_email_is_not_set_up(): void
    {
        $this->schedule();

        $this->emailConfigured(false);
        $this->actingAs($this->pm)->get(route('reports.schedules.index'))->assertOk()
            ->assertSee('Email is not set up yet.')->assertSee('Every Monday at 07:00')->assertSee('Not sent yet')->assertSee('owner@acme.test');

        $this->emailConfigured(true);
        $this->actingAs($this->pm)->get(route('reports.schedules.index'))->assertOk()->assertDontSee('Email is not set up yet.');
    }

    public function test_the_form_starts_with_the_report_being_looked_at(): void
    {
        $this->actingAs($this->pm)->get(route('reports.schedules.index', ['report' => 'petty_cash', 'period' => 'this_month']))->assertOk()
            ->assertSee("report: 'petty_cash'", false);

        $this->actingAs($this->pm)->get(route('reports.index', ['report' => 'petty_cash', 'period' => 'this_month']))->assertOk()
            ->assertSee(e(route('reports.schedules.index', ['report' => 'petty_cash', 'period' => 'this_month'])), false);
    }

    // When a schedule is due

    public function test_the_latest_slot_for_each_frequency(): void
    {
        $service = app(ScheduleService::class);
        $make = fn (array $attributes) => new ReportSchedule($attributes);
        $slot = fn (ReportSchedule $schedule, string $now) => $service->latestSlot($schedule, Carbon::parse($now))->format('Y-m-d H:i');

        $daily = $make(['frequency' => 'daily', 'hour' => 7]);
        $this->assertSame('2026-11-09 07:00', $slot($daily, '2026-11-09 07:00:00'));
        $this->assertSame('2026-11-09 07:00', $slot($daily, '2026-11-09 23:59:00'));
        $this->assertSame('2026-11-08 07:00', $slot($daily, '2026-11-09 06:59:00'));

        $weekly = $make(['frequency' => 'weekly', 'day_of_week' => 3, 'hour' => 9]);   // Wednesday
        $this->assertSame('2026-11-04 09:00', $slot($weekly, '2026-11-09 06:00:00'));
        $this->assertSame('2026-11-11 09:00', $slot($weekly, '2026-11-11 09:00:00'));
        $this->assertSame('2026-11-04 09:00', $slot($weekly, '2026-11-11 08:59:00'));
        $this->assertSame('2026-11-08 08:00', $slot($make(['frequency' => 'weekly', 'day_of_week' => 7, 'hour' => 8]), '2026-11-09 06:00:00'));

        $monthly = $make(['frequency' => 'monthly', 'day_of_month' => 5, 'hour' => 7]);
        $this->assertSame('2026-11-05 07:00', $slot($monthly, '2026-11-09 06:00:00'));
        $this->assertSame('2026-10-05 07:00', $slot($monthly, '2026-11-05 06:59:00'));
        $this->assertSame('2026-12-05 07:00', $slot($monthly, '2026-12-05 07:00:00'));
        $this->assertSame('2025-12-28 07:00', $slot($make(['frequency' => 'monthly', 'day_of_month' => 28, 'hour' => 7]), '2026-01-10 00:00:00'));
    }

    public function test_a_new_schedule_does_not_fire_for_a_slot_that_passed_before_it_was_saved(): void
    {
        $schedule = $this->schedule(['frequency' => 'daily', 'hour' => 5]);   // saved at 06:00, after today's 05:00
        $service = app(ScheduleService::class);

        $this->assertFalse($service->isDue($schedule, now()));
        $this->assertFalse($service->isDue($schedule, now()->addHours(22)));
        $this->assertTrue($service->isDue($schedule, now()->addHours(23)));   // tomorrow 05:00
    }

    public function test_describing_a_schedule(): void
    {
        $service = app(ScheduleService::class);

        $this->assertSame('Every Monday at 07:00', $service->describe(new ReportSchedule(['frequency' => 'weekly', 'day_of_week' => 1, 'hour' => 7])));
        $this->assertSame('Every day at 18:00', $service->describe(new ReportSchedule(['frequency' => 'daily', 'hour' => 18])));
        $this->assertSame('Day 5 of every month at 07:00', $service->describe(new ReportSchedule(['frequency' => 'monthly', 'day_of_month' => 5, 'hour' => 7])));
    }

    // Sending

    public function test_a_due_schedule_is_emailed_once_with_the_file_attached(): void
    {
        Mail::fake();
        $this->emailConfigured();
        $schedule = $this->schedule();

        $service = app(ScheduleService::class);
        $this->travelTo(Carbon::parse('2026-11-09 06:59:00'));
        $this->assertSame(['sent' => 0, 'waiting' => 0, 'skipped' => 0, 'failed' => 0], $service->runDue());

        $this->travelTo(Carbon::parse('2026-11-09 07:05:00'));
        $this->assertSame(['sent' => 1, 'waiting' => 0, 'skipped' => 0, 'failed' => 0], $service->runDue());

        $mail = $this->sent()[0];
        $this->assertTrue($mail->hasTo('owner@acme.test'));
        $this->assertTrue($mail->hasTo('site.lead@acme.test'));
        $this->assertSame('BOQ ledger · Acme Infra', $mail->envelope()->subject);
        $this->assertSame('RT1 · Riverside Tower', $mail->subtitle);
        $this->assertStringEndsWith('.xlsx', $mail->filename);
        $this->assertStringContainsString('spreadsheetml', $mail->mime);
        $this->assertSame(1, $mail->rowCount);
        $this->assertCount(1, $mail->attachments());

        $path = tempnam(sys_get_temp_dir(), 'm').'.xlsx';
        file_put_contents($path, $mail->bytes);
        $sheet = IOFactory::load($path)->getSheet(0);
        $this->assertSame('Acme Infra', $sheet->getCell('A1')->getValue());
        $this->assertSame('BOQ ledger', $sheet->getCell('A2')->getValue());

        $schedule->refresh();
        $this->assertSame('sent', $schedule->last_status);
        $this->assertTrue($schedule->last_run_at->equalTo(now()));
        $this->assertDatabaseHas('audit_logs', ['auditable_type' => $schedule->getMorphClass(), 'auditable_id' => $schedule->id, 'action' => 'report.emailed']);
        // The status bookkeeping itself is not a user change, so it is not audited as one.
        $this->assertDatabaseMissing('audit_logs', ['auditable_type' => $schedule->getMorphClass(), 'auditable_id' => $schedule->id, 'action' => 'updated']);

        // Not again in the same slot, and not at the next tick. Again next Monday.
        $this->travelTo(Carbon::parse('2026-11-09 07:20:00'));
        $service->runDue();
        $this->travelTo(Carbon::parse('2026-11-14 12:00:00'));
        $service->runDue();
        $this->assertCount(1, $this->sent());

        $this->travelTo(Carbon::parse('2026-11-16 07:01:00'));
        $this->assertSame(1, $service->runDue()['sent']);
        $this->assertCount(2, $this->sent());
    }

    public function test_a_pdf_schedule_attaches_a_pdf(): void
    {
        Mail::fake();
        $this->emailConfigured();
        $this->schedule(['format' => 'pdf', 'frequency' => 'daily', 'hour' => 5]);

        $this->travelTo(Carbon::parse('2026-11-10 05:10:00'));
        app(ScheduleService::class)->runDue();

        $mail = $this->sent()[0];
        $this->assertSame('application/pdf', $mail->mime);
        $this->assertStringEndsWith('.pdf', $mail->filename);
        $this->assertStringStartsWith('%PDF', $mail->bytes);
    }

    public function test_nothing_is_sent_while_email_is_not_set_up_and_it_catches_up_afterwards(): void
    {
        Mail::fake();
        $this->emailConfigured(false);
        $schedule = $this->schedule();

        $this->travelTo(Carbon::parse('2026-11-09 07:05:00'));
        $this->assertSame(1, app(ScheduleService::class)->runDue()['waiting']);

        Mail::assertNothingSent();
        $schedule->refresh();
        $this->assertSame('no_mail', $schedule->last_status);
        $this->assertNull($schedule->last_run_at);

        $this->emailConfigured();
        $this->travelTo(Carbon::parse('2026-11-09 07:20:00'));
        $this->assertSame(1, app(ScheduleService::class)->runDue()['sent']);
        $this->assertSame('sent', $schedule->fresh()->last_status);
    }

    public function test_the_gateway_only_counts_a_real_mailer_as_set_up(): void
    {
        $gateway = app(ReportMailGateway::class);

        foreach (['log' => false, 'array' => false, 'smtp' => true, 'ses' => true] as $mailer => $expected) {
            config(['mail.default' => $mailer]);
            $this->assertSame($expected, $gateway->configured(), $mailer);
        }
    }

    public function test_a_schedule_is_skipped_when_the_person_who_saved_it_can_no_longer_run_it(): void
    {
        Mail::fake();
        $this->emailConfigured();
        $service = app(ScheduleService::class);

        $gone = $this->member($this->org, 'pm');
        $demoted = $this->member($this->org, 'pm');
        $one = $this->schedule(['name' => 'gone'], $gone);
        $two = $this->schedule(['name' => 'demoted'], $demoted);
        $three = $this->schedule(['name' => 'plan']);
        $this->travelTo(Carbon::parse('2026-11-09 07:05:00'));

        $gone->update(['is_active' => false]);
        $demoted->update(['role' => 'engineer']);
        $this->org->update(['plan' => 'free']);
        $counts = $service->runDue();

        Mail::assertNothingSent();
        $this->assertSame(3, $counts['skipped']);
        $this->assertSame('no_owner', $one->fresh()->last_status);
        $this->assertSame('no_access', $two->fresh()->last_status);
        $this->assertSame('no_access', $three->fresh()->last_status);

        // A skipped slot is handled. It is not tried again until the next one.
        $this->org->update(['plan' => 'business']);
        $this->travelTo(Carbon::parse('2026-11-09 08:00:00'));
        $this->assertSame(0, $service->runDue()['sent']);
    }

    public function test_a_paused_schedule_is_left_alone(): void
    {
        Mail::fake();
        $this->emailConfigured();
        $schedule = $this->schedule();
        $schedule->update(['active' => false]);

        $this->travelTo(Carbon::parse('2026-11-09 07:05:00'));
        app(ScheduleService::class)->runDue();

        Mail::assertNothingSent();
    }

    public function test_a_failed_send_is_recorded_and_does_not_stop_the_others(): void
    {
        $this->emailConfigured();
        $this->schedule(['name' => 'first']);
        $this->schedule(['name' => 'second']);

        $gateway = new class extends ReportMailGateway
        {
            public int $calls = 0;

            public array $delivered = [];

            public function configured(): bool
            {
                return true;
            }

            public function send(array $recipients, Mailable $mail): void
            {
                $this->calls++;

                if ($this->calls === 1) {
                    throw new \RuntimeException('SMTP server said no');
                }

                $this->delivered[] = $mail;
            }
        };
        $this->app->instance(ReportMailGateway::class, $gateway);

        $this->travelTo(Carbon::parse('2026-11-09 07:05:00'));
        $counts = app(ScheduleService::class)->runDue();

        $this->assertSame(['sent' => 1, 'waiting' => 0, 'skipped' => 0, 'failed' => 1], $counts);
        $this->assertCount(1, $gateway->delivered);
        $failed = ReportSchedule::where('last_status', 'failed')->first();
        $this->assertNotNull($failed->last_run_at);

        // The error text stays in the log, not in the row that people see.
        $this->assertStringNotContainsString('SMTP', (string) $failed->last_status);
    }

    public function test_each_schedule_emails_only_its_own_companys_data(): void
    {
        Mail::fake();
        $this->emailConfigured();

        $other = $this->tenant('Other Co', 'business');
        $theirs = $this->makeProject($other, ['code' => 'THEIRS', 'name' => 'Their Tower']);
        $this->makeBoqItem($theirs);

        $mine = $this->schedule();
        $their = app(ScheduleService::class)->create($this->member($other, 'pm'), [...$this->payload(['project_id' => $theirs->id]), 'recipients' => ['boss@other.test']]);

        $this->travelTo(Carbon::parse('2026-11-09 07:05:00'));
        $this->assertSame(2, app(ScheduleService::class)->runDue()['sent']);

        foreach ($this->sent() as $mail) {
            $path = tempnam(sys_get_temp_dir(), 'm').'.xlsx';
            file_put_contents($path, $mail->bytes);
            $text = json_encode(IOFactory::load($path)->getSheet(0)->toArray());

            if ($mail->hasTo('boss@other.test')) {
                $this->assertStringContainsString('THEIRS', $text);
                $this->assertStringNotContainsString('Riverside', $text);
                $this->assertSame('Other Co', $mail->organizationName);
            } else {
                $this->assertStringContainsString('Riverside', $text);
                $this->assertStringNotContainsString('THEIRS', $text);
            }
        }

        $this->assertNotSame($mine->organization_id, $their->organization_id);
    }

    public function test_the_report_is_worked_out_again_for_each_send(): void
    {
        Mail::fake();
        $this->emailConfigured();
        $this->schedule(['report' => 'measurement_register', 'project_id' => null, 'period' => 'last_7_days', 'frequency' => 'daily', 'hour' => 5]);
        $item = $this->makeBoqItem($this->project);

        $this->draftEntry($this->project, $item, $this->pm, ['measured_on' => '2026-11-09']);
        $this->travelTo(Carbon::parse('2026-11-10 05:10:00'));
        app(ScheduleService::class)->runDue();

        // A week later the same entry is outside "last 7 days", so the next email has no rows.
        $this->travelTo(Carbon::parse('2026-11-20 05:10:00'));
        app(ScheduleService::class)->runDue();

        $this->assertSame([1, 0], array_map(fn ($mail) => $mail->rowCount, $this->sent()));
    }

    public function test_the_command_runs_due_schedules_and_reports_what_it_did(): void
    {
        Mail::fake();
        $this->emailConfigured();
        $this->schedule();
        $this->travelTo(Carbon::parse('2026-11-09 07:05:00'));

        $this->artisan('reports:send-scheduled')->expectsOutputToContain('Sent 1, waiting for email 0, skipped 0, failed 0.')->assertSuccessful();
        $this->assertCount(1, $this->sent());
    }

    public function test_the_command_says_when_email_is_not_set_up(): void
    {
        $this->emailConfigured(false);

        $this->artisan('reports:send-scheduled')->expectsOutputToContain('Email is not set up')->assertSuccessful();
    }

    public function test_the_command_is_on_the_laravel_scheduler_every_fifteen_minutes(): void
    {
        $events = collect(app(Schedule::class)->events())->filter(fn ($event) => str_contains($event->command, 'reports:send-scheduled'));

        $this->assertCount(1, $events);
        $this->assertSame('*/15 * * * *', $events->first()->expression);
        $this->assertTrue($events->first()->withoutOverlapping);
    }

    public function test_the_email_is_sent_in_the_language_of_the_person_who_saved_the_schedule(): void
    {
        Mail::fake();
        $this->emailConfigured();
        $hindi = $this->member($this->org, 'pm');
        $hindi->update(['locale' => 'hi']);
        $this->schedule(['frequency' => 'daily', 'hour' => 5], $hindi);

        $this->travelTo(Carbon::parse('2026-11-10 05:10:00'));
        app(ScheduleService::class)->runDue();

        $this->assertNotSame('BOQ ledger', $this->sent()[0]->title);
        $this->assertSame('en', app()->getLocale());
    }
}
