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
use App\Services\Access\ReviewDemoCompany;
use App\Services\Agents\AgentRegistry;
use App\Services\ApprovalService;
use App\Services\SampleDataService;
use App\Services\Workforce\WorkerCardService;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\BuildsTenant;
use Tests\TestCase;

/** The company app reviewers sign in to: made in one console run, never on a real company, with no secret printed. */
class ReviewDemoCompanyTest extends TestCase
{
    use BuildsTenant, RefreshDatabase;

    private const OWNER = 'review.owner@example.test';

    private const CONTRACTOR = 'site.partner@example.test';

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        config(['mail.default' => 'smtp']);
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
        $this->assertGreaterThanOrEqual(2, $waiting->count());

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

        // What is printed: facts only.
        $this->assertStringContainsString("#{$organization->id} Demo Builders (Sample)", $output);
        $this->assertStringContainsString('Plan: Business, active; no trial end and no paid-until date', $output);
        $this->assertStringContainsString('Kalyani Tower B (Sample), Hinjewadi Commercial Block (Sample)', $output);
        $this->assertStringContainsString('Approvals waiting for the owner: '.$waiting->count(), $output);
        $this->assertStringContainsString('daily report at Project Manager: 1', $output);
        $this->assertStringContainsString('measurement at Estimator / QS: 1', $output);
        $this->assertStringContainsString('AI agents: '.count(AgentRegistry::KEYS).' switched off, daily brief off.', $output);
        $this->assertStringContainsString('to '.self::OWNER.': sent', $output);
        $this->assertStringContainsString('contractor-portal invitation for Shree Formwork Works (Sample) to '.self::CONTRACTOR.': sent', $output);
        $this->assertStringContainsString('/workers/cards', $output);

        // The card is for someone not yet marked today, and reads as the app's "Scan card" expects.
        preg_match('/QR card text for (.+), not yet marked today: (\S+)/', $output, $card);
        $worker = Worker::withoutGlobalScopes()->where('organization_id', $organization->id)->where('name', $card[1])->sole();
        $this->assertSame(app(WorkerCardService::class)->code($worker), $card[2]);
        $this->assertMatchesRegularExpression('/^N360W-\d+-\d+-[a-f0-9]{20}$/', $card[2]);
        $this->assertSame($worker->id, app(WorkerCardService::class)->resolve($card[2], $organization->id)->id);

        // And never a secret: no reset token, no invitation link or its token, and no word of it is the password.
        $this->assertNotNull($resetToken);
        $this->assertNotNull($inviteLink);
        $this->assertStringNotContainsString($resetToken, $output);
        $this->assertStringNotContainsString($inviteLink, $output);
        $this->assertStringNotContainsString(basename((string) parse_url($inviteLink, PHP_URL_PATH)), $output);

        foreach (preg_split('/\s+/', $output, -1, PREG_SPLIT_NO_EMPTY) as $word) {
            $this->assertFalse(Hash::check($word, $owner->password), 'The output holds the owner password.');
        }

        // The console run leaves nobody signed in.
        $this->assertNull(Auth::user());
    }

    public function test_an_email_taken_in_another_letter_case_is_refused_and_nothing_changes(): void
    {
        $existing = $this->member($this->tenant('Real Builders'), 'engineer');
        $existing->forceFill(['email' => 'taken.person@example.test'])->save();
        $before = $this->companyCount();

        [$code, $output] = $this->review(['owner_email' => 'Taken.Person@EXAMPLE.test']);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('already has an account', $output);
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
        $this->assertSame(0, $this->companyCount());
        $this->assertSame(0, User::count());
        Notification::assertNothingSent();
    }

    public function test_a_contractor_email_equal_to_the_owner_email_is_refused(): void
    {
        [$code, $output] = $this->review(['owner_email' => self::OWNER, '--contractor' => 'REVIEW.owner@example.test']);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('must differ from the owner email', $output);
        $this->assertSame(0, $this->companyCount());
        Notification::assertNothingSent();
    }

    public function test_a_taken_contractor_email_is_refused(): void
    {
        $this->member($this->tenant('Real Builders'), 'pm')->forceFill(['email' => 'someone@example.test'])->save();

        [$code, $output] = $this->review(['owner_email' => self::OWNER, '--contractor' => 'Someone@example.test']);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('The contractor email already has an account', $output);
        $this->assertSame(1, $this->companyCount());
        $this->assertNull(User::findByEmail(self::OWNER));
    }

    public function test_it_refuses_while_mail_only_goes_to_the_log(): void
    {
        config(['mail.default' => 'log']);

        [$code, $output] = $this->review(['owner_email' => self::OWNER]);

        $this->assertSame(1, $code);
        $this->assertStringContainsString('No mail can go out', $output);
        $this->assertSame(0, $this->companyCount());
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
