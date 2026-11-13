<?php

namespace Tests\Feature\Access;

use App\Models\AgentInstall;
use App\Models\Announcement;
use App\Models\AnnouncementRecipient;
use App\Models\Approval;
use App\Models\AuditLog;
use App\Models\Dpr;
use App\Models\MeasurementEntry;
use App\Models\Organization;
use App\Models\Party;
use App\Models\Project;
use App\Models\TeamInvitation;
use App\Models\User;
use App\Models\Worker;
use App\Notifications\TeamInvite;
use App\Services\Access\InvitationService;
use App\Services\Access\ReviewDemoCompany;
use App\Services\Agents\AgentRegistry;
use App\Services\ApprovalService;
use App\Services\Notify\AnnouncementService;
use App\Services\Qhs\ChecklistService;
use App\Services\SampleDataService;
use App\Services\Workforce\WorkerCardService;
use App\Support\MailTransports;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Mockery\MockInterface;
use PDOException;
use Tests\BuildsTenant;
use Tests\TestCase;

/** The company app reviewers sign in to: made in one console run, never on a real company, with no secret printed. */
class ReviewDemoCompanyTest extends TestCase
{
    use BuildsTenant, RefreshDatabase;

    private const OWNER = 'review.owner@example.test';

    private const CONTRACTOR = 'site.partner@example.test';

    /** Shaped like a bcrypt hash, as a database error's bindings would carry the owner's. Invented. */
    private const HASH_IN_BINDINGS = '$2y$12$InventedHashForTheReviewDemoTestOnlyAbcdefghijklmnopq';

    /** @var list<MessageLogged> */
    private array $logged = [];

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        config(['mail.default' => 'smtp']);
        Event::listen(MessageLogged::class, fn (MessageLogged $message) => $this->logged[] = $message);
    }

    /** @return array{0: int, 1: string} Exit code and everything printed. */
    private function review(array $arguments): array
    {
        $code = Artisan::call('review:demo-company', $arguments);

        return [$code, Artisan::output()];
    }

    private function setUpDemo(): array
    {
        [$code, $output] = $this->review(['owner_email' => 'Review.Owner@Example.TEST', '--contractor' => 'Site.Partner@example.test']);
        $this->assertSame(0, $code, $output);

        return [User::findByEmail(self::OWNER), $output];
    }

    private function companyCount(): int
    {
        return Organization::count();
    }

    private function assertNothingMade(): void
    {
        $this->assertSame(0, $this->companyCount());
        $this->assertSame(0, User::count());
        Notification::assertNothingSent();
    }

    /** Everything logged during the test, as one string. */
    private function logText(): string
    {
        return collect($this->logged)->map(fn (MessageLogged $message) => $message->message.' '.json_encode($message->context))->implode("\n");
    }

    private function databaseError(): QueryException
    {
        return new QueryException('testing', 'update "users" set "password" = ? where "id" = ?', [self::HASH_IN_BINDINGS, 1], new PDOException('SQLSTATE[HY000]: General error'));
    }

    public function test_setup_makes_a_business_company_with_sample_data_agents_off_an_announcement_and_two_invitations(): void
    {
        [$owner, $output] = $this->setUpDemo();
        $organization = $owner->organization;

        $this->assertSame('owner', $owner->role);
        $this->assertSame('Review Owner', $owner->name);
        $this->assertSame(ReviewDemoCompany::DEFAULT_COMPANY, $organization->name);
        $this->assertSame(['business', 'active', null, null], [$organization->plan, $organization->plan_status, $organization->trial_ends_at, $organization->paid_until]);
        $this->assertSame('business', $organization->effectivePlan());
        $this->travel(2)->years();
        $this->assertSame('business', $organization->fresh()->effectivePlan());
        $this->travelBack();

        // Marked as this command's own, with an audit entry that holds no name, email or company.
        $this->assertTrue(app(ReviewDemoCompany::class)->madeHere($organization));
        $audit = AuditLog::where('action', ReviewDemoCompany::CREATED)->where('organization_id', $organization->id)->firstOrFail();
        $this->assertStringNotContainsString(self::OWNER, json_encode($audit->new_values));
        $this->assertStringNotContainsString('Demo Builders', json_encode($audit->new_values));
        $this->assertStringNotContainsString('Review Owner', json_encode($audit->new_values));

        // The sample data, as sample:fill adds it.
        $projects = Project::withoutGlobalScopes()->where('organization_id', $organization->id)->pluck('name')->all();
        $this->assertSame(['Kalyani Tower B (Sample)', 'Hinjewadi Commercial Block (Sample)'], $projects);
        $this->assertSame(9, Worker::withoutGlobalScopes()->where('organization_id', $organization->id)->count());
        $waiting = Approval::withoutGlobalScopes()->where('organization_id', $organization->id)->where('status', 'IN_REVIEW')->get()
            ->filter(fn (Approval $approval) => app(ApprovalService::class)->canAct($approval, $owner));
        $this->assertSame(2, $waiting->count());

        // Every agent off, and no daily brief, so nothing of this company goes to the AI provider.
        $installs = AgentInstall::withoutGlobalScopes()->where('organization_id', $organization->id)->get();
        $this->assertCount(count(AgentRegistry::KEYS), $installs);
        $this->assertTrue($installs->every(fn (AgentInstall $install) => $install->enabled === false && $install->daily_brief === false));

        // One announcement from the owner to the team, which the owner's app lists.
        $announcement = Announcement::where('organization_id', $organization->id)->sole();
        $this->assertSame([$owner->id, 'all', ReviewDemoCompany::ANNOUNCEMENT_TITLE], [$announcement->created_by, $announcement->audience, $announcement->title]);
        $this->assertTrue(AnnouncementRecipient::withoutGlobalScopes()->where('announcement_id', $announcement->id)->where('user_id', $owner->id)->exists());

        // A contractor-portal invitation for the sample subcontractor, emailed as the website does it.
        $party = Party::withoutGlobalScopes()->where('organization_id', $organization->id)->where('type', 'subcontractor')->sole();
        $this->assertSame('Shree Formwork Works (Sample)', $party->name);
        $invitation = TeamInvitation::withoutGlobalScopes()->where('organization_id', $organization->id)->sole();
        $this->assertSame([self::CONTRACTOR, User::CONTRACTOR_ROLE, $party->id], [$invitation->email, $invitation->role_key, (int) $invitation->contractor_party_id]);
        $this->assertTrue($invitation->isPending());

        $inviteLink = null;
        Notification::assertSentOnDemand(TeamInvite::class, function (TeamInvite $notification, array $channels, object $notifiable) use (&$inviteLink) {
            $inviteLink = $notification->link;

            return $notifiable->routes['mail'] === self::CONTRACTOR;
        });

        // The "Forgot password?" mail to the owner.
        $resetToken = null;
        Notification::assertSentTo($owner, ResetPassword::class, function (ResetPassword $notification) use (&$resetToken) {
            $resetToken = $notification->token;

            return true;
        });

        // What is printed: facts only, and no step failed.
        $this->assertStringNotContainsString('Failed while', $output);
        $this->assertStringContainsString("#{$organization->id} Demo Builders (Sample)", $output);
        $this->assertStringContainsString('Plan: Business, active; no trial end and no paid-until date', $output);
        $this->assertStringContainsString('Kalyani Tower B (Sample), Hinjewadi Commercial Block (Sample)', $output);
        $this->assertStringContainsString('Approvals waiting for the owner: 2 (daily report at Project Manager: 1, measurement at Estimator / QS: 1)', $output);
        $this->assertStringContainsString('AI agents: '.count(AgentRegistry::KEYS).' switched off, daily brief off.', $output);
        $this->assertSame(5, substr_count($output, 'Also added: '));
        $this->assertStringContainsString('for Shree Formwork Works (Sample) on WO-SMP-KT-001', $output);
        $this->assertStringContainsString('to '.self::OWNER.': sent', $output);
        $this->assertStringContainsString('contractor-portal invitation for Shree Formwork Works (Sample) to '.self::CONTRACTOR.': sent', $output);
        $this->assertStringContainsString('Worker QR cards print at /workers/cards ('.url('/workers/cards').')', $output);

        // The card is for someone not yet marked today, and reads as the app's "Scan card" expects.
        preg_match('/QR card text for (.+), not yet marked today: (\S+)/', $output, $card);
        $worker = Worker::withoutGlobalScopes()->where('organization_id', $organization->id)->where('name', $card[1])->sole();
        $this->assertSame(app(WorkerCardService::class)->code($worker), $card[2]);
        $this->assertMatchesRegularExpression('/^N360W-\d+-\d+-[a-f0-9]{20}$/', $card[2]);
        $this->assertSame($worker->id, app(WorkerCardService::class)->resolve($card[2], $organization->id)->id);

        // And never a secret: no reset token, no invitation link or its token, and no word of it is the password.
        $this->assertNotNull($resetToken);
        $this->assertNotNull($inviteLink);
        $inviteToken = basename((string) parse_url($inviteLink, PHP_URL_PATH));

        foreach ([$output, $this->logText()] as $text) {
            $this->assertStringNotContainsString($resetToken, $text);
            $this->assertStringNotContainsString($inviteLink, $text);
            $this->assertStringNotContainsString($inviteToken, $text);
            $this->assertStringNotContainsString($owner->password, $text);
        }

        foreach (preg_split('/\s+/', $output, -1, PREG_SPLIT_NO_EMPTY) as $word) {
            $this->assertFalse(Hash::check($word, $owner->password), 'The output holds the owner password.');
        }

        // The console run leaves nobody signed in.
        $this->assertNull(Auth::user());
    }

    public function test_the_apps_business_screens_and_the_contractor_portal_open_with_records(): void
    {
        [$owner] = $this->setUpDemo();
        $tower = Project::withoutGlobalScopes()->where('organization_id', $owner->organization_id)->where('code', SampleDataService::TOWER_CODE)->sole();

        // What the owner's app reads for Inspections, Machine log, Progress and Material.
        Sanctum::actingAs($owner);
        $this->assertCount(count(ChecklistService::STANDARD), $this->getJson('/api/v1/checklist-templates')->assertOk()->json('data'));
        $inspections = $this->getJson("/api/v1/projects/{$tower->id}/inspections")->assertOk()->json('data');
        $this->assertSame(['passed'], Arr::pluck($inspections, 'status'));
        $this->assertSame(['Backhoe loader'], Arr::pluck($this->getJson('/api/v1/assets')->assertOk()->json('data'), 'name'));
        $this->assertCount(1, $this->getJson("/api/v1/machine-logs?project_id={$tower->id}")->assertOk()->json('data'));
        $this->assertSame(['Level 1', 'Level 2', 'Level 3', 'Level 4', 'Level 5'], Arr::pluck($this->getJson("/api/v1/projects/{$tower->id}/floors")->assertOk()->json('data'), 'name'));
        $this->assertCount(1, $this->getJson("/api/v1/projects/{$tower->id}/progress/entries")->assertOk()->json('data'));
        $this->assertCount(1, $this->getJson("/api/v1/projects/{$tower->id}/material-control/challans")->assertOk()->json('data'));

        // The contractor accepts the emailed invitation and signs in to the portal: one bill and one payment.
        $link = null;
        Notification::assertSentOnDemand(TeamInvite::class, function (TeamInvite $notification) use (&$link) {
            $link = $notification->link;

            return true;
        });
        $contractor = app(InvitationService::class)->accept(basename((string) parse_url($link, PHP_URL_PATH)), ['name' => 'Site Partner', 'password' => 'Invented-portal-pass-42']);
        $this->assertTrue($contractor->isContractor());

        // As a sign-in loads it: the stored row, with the column defaults.
        Sanctum::actingAs($contractor->fresh());
        $bills = $this->getJson('/api/v1/contractor-portal/bills')->assertOk()->json('data');
        $this->assertCount(1, $bills);
        $this->assertSame('APPROVED', $bills[0]['status']);
        $this->assertSame('WO-SMP-KT-001', $bills[0]['work_order_number']);
        $this->assertCount(1, $this->getJson('/api/v1/contractor-portal/payments')->assertOk()->json('data'));
    }

    public function test_an_email_taken_in_another_letter_case_is_refused_and_nothing_changes(): void
    {
        $existing = $this->member($this->tenant('Real Builders'), 'engineer');
        // Stored with capitals, as older rows are, so only a lookup that ignores case in the database finds it.
        DB::table('users')->where('id', $existing->id)->update(['email' => 'Taken.Person@Example.test']);
        $before = $this->companyCount();

        foreach (['taken.person@example.test', 'TAKEN.person@EXAMPLE.test'] as $typed) {
            [$code, $output] = $this->review(['owner_email' => $typed]);

            $this->assertSame(1, $code, $typed);
            $this->assertStringContainsString('The owner email already has an account', $output);
            $this->assertStringContainsString('Nothing was changed.', $output);
        }

        $this->assertSame($before, $this->companyCount());
        $this->assertSame(1, User::count());
        Notification::assertNothingSent();
    }

    public function test_a_platform_admin_email_is_refused(): void
    {
        config(['buildos.platform_admins' => ['ops@example.test']]);

        [$code, $output] = $this->review(['owner_email' => 'OPS@example.test']);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('kept for platform admins', $output);
        $this->assertNothingMade();
    }

    public function test_an_invalid_domain_is_refused_for_the_owner_and_the_contractor(): void
    {
        [$code, $output] = $this->review(['owner_email' => 'review.owner@demo.invalid']);
        $this->assertSame(1, $code);
        $this->assertStringContainsString('The owner email ends in .invalid', $output);

        [$code, $output] = $this->review(['owner_email' => self::OWNER, '--contractor' => 'partner@site.INVALID']);
        $this->assertSame(1, $code);
        $this->assertStringContainsString('The contractor email ends in .invalid', $output);

        $this->assertNothingMade();
    }

    public function test_a_contractor_email_equal_to_the_owner_email_is_refused(): void
    {
        [$code, $output] = $this->review(['owner_email' => self::OWNER, '--contractor' => 'REVIEW.owner@example.test']);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('must differ from the owner email', $output);
        $this->assertNothingMade();
    }

    public function test_a_taken_contractor_email_is_refused(): void
    {
        $existing = $this->member($this->tenant('Real Builders'), 'pm');
        DB::table('users')->where('id', $existing->id)->update(['email' => 'Someone@Example.test']);

        [$code, $output] = $this->review(['owner_email' => self::OWNER, '--contractor' => 'someone@example.test']);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('The contractor email already has an account', $output);
        $this->assertSame(1, $this->companyCount());
        $this->assertNull(User::findByEmail(self::OWNER));
        Notification::assertNothingSent();
    }

    public function test_it_refuses_without_a_business_plan_or_without_its_contractor_portal(): void
    {
        $plans = config('buildos.plans');

        config(['buildos.plans' => Arr::except($plans, ReviewDemoCompany::PLAN)]);
        [$code, $output] = $this->review(['owner_email' => self::OWNER]);
        $this->assertSame(1, $code);
        $this->assertStringContainsString('The Business plan is not offered', $output);

        config(['buildos.plans' => $plans]);
        config(['buildos.plans.business.features' => array_values(array_diff($plans['business']['features'], ['contractor_portal']))]);
        [$code, $output] = $this->review(['owner_email' => self::OWNER, '--contractor' => self::CONTRACTOR]);
        $this->assertSame(1, $code);
        $this->assertStringContainsString('does not include the contractor portal', $output);

        $this->assertNothingMade();
    }

    public function test_it_refuses_while_any_mailer_it_can_reach_keeps_mail_instead_of_sending_it(): void
    {
        $cases = [
            'the log mailer' => ['mail.default' => 'log'],
            'the array mailer' => ['mail.default' => 'array'],
            'a failover that falls back to log' => ['mail.default' => 'failover', 'mail.mailers.failover.mailers' => ['smtp', 'log']],
            'a roundrobin that takes turns with array' => ['mail.default' => 'roundrobin', 'mail.mailers.roundrobin.mailers' => ['smtp', 'array']],
            'a mailer of another name on the log transport' => ['mail.default' => 'quiet', 'mail.mailers.quiet' => ['transport' => 'log']],
            'a MAIL_URL with the log scheme' => ['mail.default' => 'smtp', 'mail.mailers.smtp.url' => 'log://localhost'],
            'a mailer that is not defined' => ['mail.default' => 'nowhere'],
        ];

        $defaults = config('mail');

        foreach ($cases as $case => $settings) {
            config(['mail' => $defaults]);
            config($settings);

            [$code, $output] = $this->review(['owner_email' => self::OWNER]);

            $this->assertSame(1, $code, $case);
            $this->assertStringContainsString('No mail can go out safely', $output, $case);
            $this->assertStringContainsString('Nothing was changed.', $output, $case);
        }

        $this->assertNothingMade();

        // A failover to another sending mailer is fine.
        config(['mail' => $defaults]);
        config(['mail.mailers.failover.mailers' => ['smtp', 'ses']]);
        $this->assertNull(MailTransports::firstThatKeepsMail('failover'));
        $this->assertSame(['failover' => 'failover', 'smtp' => 'smtp', 'ses' => 'ses'], MailTransports::chain('failover'));
    }

    public function test_a_database_error_while_making_the_company_changes_nothing_and_shows_no_hash(): void
    {
        $this->partialMock(SampleDataService::class, fn (MockInterface $mock) => $mock->shouldReceive('fillCompany')->andThrow($this->databaseError()));

        [$code, $output] = $this->review(['owner_email' => self::OWNER, '--contractor' => self::CONTRACTOR]);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('The company could not be made (database error, SQLSTATE', $output);
        $this->assertStringContainsString('Nothing was changed.', $output);
        $this->assertStringNotContainsString(self::HASH_IN_BINDINGS, $output);
        $this->assertStringContainsString('review:demo-company step failed', $this->logText());
        $this->assertStringNotContainsString(self::HASH_IN_BINDINGS, $this->logText());
        $this->assertNothingMade();
        $this->assertSame(0, AuditLog::where('action', ReviewDemoCompany::CREATED)->count());
    }

    public function test_a_step_or_read_that_fails_after_the_company_is_made_is_reported_without_secrets_and_the_rest_still_prints(): void
    {
        $this->partialMock(AnnouncementService::class, fn (MockInterface $mock) => $mock->shouldReceive('create')->andThrow($this->databaseError()));
        $this->partialMock(WorkerCardService::class, fn (MockInterface $mock) => $mock->shouldReceive('code')->andThrow($this->databaseError()));

        [$code, $output] = $this->review(['owner_email' => self::OWNER, '--contractor' => self::CONTRACTOR]);

        $this->assertSame(0, $code, $output);
        $organization = User::findByEmail(self::OWNER)->organization;

        // The company, its plan and the emails sent are all there.
        $this->assertStringContainsString("Company: #{$organization->id} Demo Builders (Sample)", $output);
        $this->assertStringContainsString('Plan: Business, active', $output);
        $this->assertStringContainsString('Approvals waiting for the owner: 2', $output);
        $this->assertStringContainsString('Announcement: none', $output);
        $this->assertStringContainsString('to '.self::CONTRACTOR.': sent', $output);
        $this->assertStringContainsString('to '.self::OWNER.': sent', $output);

        // Each failure named, with the way to do it by hand, and never the database error's bindings.
        $this->assertStringContainsString('Failed while posting the announcement (database error, SQLSTATE', $output);
        $this->assertStringContainsString('Post one from Announcements on the website', $output);
        $this->assertStringContainsString('Failed while reading a worker card (database error, SQLSTATE', $output);
        $this->assertStringNotContainsString('QR card text', $output);

        foreach ([$output, $this->logText()] as $text) {
            $this->assertStringNotContainsString(self::HASH_IN_BINDINGS, $text);
            $this->assertStringNotContainsString('update "users"', $text);
        }

        $this->assertSame(2, substr_count($this->logText(), 'review:demo-company step failed'));
        $this->assertSame(0, Announcement::where('organization_id', $organization->id)->count());
    }

    public function test_an_unexpected_error_is_shown_without_its_message(): void
    {
        $this->partialMock(ReviewDemoCompany::class, fn (MockInterface $mock) => $mock->shouldReceive('setUp')->andThrow($this->databaseError()));

        [$code, $output] = $this->review(['owner_email' => self::OWNER]);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('Stopped by an unexpected error (database error, SQLSTATE', $output);
        $this->assertStringNotContainsString(self::HASH_IN_BINDINGS, $output);
        $this->assertStringNotContainsString(self::HASH_IN_BINDINGS, $this->logText());
    }

    public function test_top_up_adds_waiting_approvals_and_leaves_today_free(): void
    {
        [$owner] = $this->setUpDemo();
        $organization = $owner->organization;
        $tower = Project::withoutGlobalScopes()->where('organization_id', $organization->id)->where('code', SampleDataService::TOWER_CODE)->sole();

        // The reviewer clears the inbox.
        Auth::setUser($owner);
        foreach (Approval::where('status', 'IN_REVIEW')->get() as $approval) {
            while ($approval->status === 'IN_REVIEW') {
                $approval = app(ApprovalService::class)->act($approval, $owner, 'approve');
            }
        }
        Auth::forgetUser();
        $this->assertSame(0, Approval::withoutGlobalScopes()->where('organization_id', $organization->id)->where('status', 'IN_REVIEW')->count());
        $dprsBefore = Dpr::withoutGlobalScopes()->where('project_id', $tower->id)->count();

        $code = Artisan::call('review:demo-company', ['owner_email' => self::OWNER, '--top-up' => true]);
        $output = Artisan::output();

        $this->assertSame(0, $code, $output);
        $this->assertStringContainsString('Added: daily report for', $output);
        $this->assertStringContainsString('waiting at Project Manager', $output);
        $this->assertStringContainsString('Added: measurement of BOQ 2.1', $output);
        $this->assertStringContainsString('waiting at Estimator / QS', $output);
        $this->assertStringContainsString("Today's daily report on Kalyani Tower B (Sample) is free.", $output);
        $this->assertStringNotContainsString('Failed while', $output);

        $waiting = Approval::withoutGlobalScopes()->where('organization_id', $organization->id)->where('status', 'IN_REVIEW')->get();
        $this->assertSame(1, $waiting->where('doc_type', 'dpr')->filter(fn (Approval $approval) => $approval->currentRole() === 'pm')->count());
        $this->assertSame(1, $waiting->where('doc_type', 'measurement')->filter(fn (Approval $approval) => $approval->currentRole() === 'qs')->count());

        $dpr = Dpr::withoutGlobalScopes()->where('project_id', $tower->id)->latest('id')->firstOrFail();
        $this->assertSame('IN_REVIEW', $dpr->status);
        $this->assertNotSame(now()->toDateString(), $dpr->report_date->toDateString());
        $this->assertSame(1, Dpr::withoutGlobalScopes()->where('project_id', $tower->id)->whereDate('report_date', $dpr->report_date->toDateString())->count());
        $this->assertSame(0, Dpr::withoutGlobalScopes()->where('project_id', $tower->id)->whereDate('report_date', now()->toDateString())->count());
        $this->assertSame('engineer', User::find($dpr->created_by)->role);
        $this->assertSame('IN_REVIEW', MeasurementEntry::withoutGlobalScopes()->where('project_id', $tower->id)->latest('id')->value('status'));
        $this->assertTrue(AuditLog::where('action', ReviewDemoCompany::TOPPED_UP)->where('organization_id', $organization->id)->exists());

        // Run again with both still waiting: nothing more is added.
        [$code, $output] = $this->review(['owner_email' => self::OWNER, '--top-up' => true]);

        $this->assertSame(0, $code, $output);
        $this->assertStringContainsString('Nothing added', $output);
        $this->assertSame($dprsBefore + 1, Dpr::withoutGlobalScopes()->where('project_id', $tower->id)->count());
    }

    public function test_top_up_refuses_a_company_the_command_did_not_make(): void
    {
        $real = $this->tenant('Real Builders');
        $owner = $this->member($real, 'owner');
        $this->artisan('sample:fill', ['email' => $owner->email])->assertSuccessful();
        $approvals = Approval::withoutGlobalScopes()->where('organization_id', $real->id)->count();
        $dprs = Dpr::withoutGlobalScopes()->where('organization_id', $real->id)->count();

        [$code, $output] = $this->review(['owner_email' => strtoupper($owner->email), '--top-up' => true]);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('not made by review:demo-company', $output);
        $this->assertSame($approvals, Approval::withoutGlobalScopes()->where('organization_id', $real->id)->count());
        $this->assertSame($dprs, Dpr::withoutGlobalScopes()->where('organization_id', $real->id)->count());
        $this->assertFalse(AuditLog::where('action', ReviewDemoCompany::TOPPED_UP)->exists());

        // Copying only one of the two marks is not enough either.
        $real->forceFill(['settings' => [ReviewDemoCompany::MARKER => ['created_at' => now()->toIso8601String()]]])->save();
        [$code] = $this->review(['owner_email' => $owner->email, '--top-up' => true]);
        $this->assertSame(1, $code);
    }

    public function test_top_up_refuses_anyone_but_the_demo_owner(): void
    {
        [$owner] = $this->setUpDemo();
        $engineer = User::where('organization_id', $owner->organization_id)->where('role', 'engineer')->firstOrFail();

        [$code, $output] = $this->review(['owner_email' => $engineer->email, '--top-up' => true]);
        $this->assertSame(1, $code);
        $this->assertStringContainsString('No owner account uses that email', $output);

        [$code] = $this->review(['owner_email' => 'nobody@example.test', '--top-up' => true]);
        $this->assertSame(1, $code);

        [$code, $output] = $this->review(['owner_email' => self::OWNER, '--top-up' => true, '--contractor' => 'other@example.test']);
        $this->assertSame(1, $code);
        $this->assertStringContainsString('--top-up takes the owner email only', $output);
    }
}
