<?php

namespace Tests\Feature\Access;

use App\Jobs\ResolveAgentAction;
use App\Jobs\RunAgentStep;
use App\Jobs\ScrubDeletedAccount;
use App\Mail\Notify\WorkflowNotificationMail;
use App\Models\Agent;
use App\Models\AgentConversation;
use App\Models\AgentRun;
use App\Models\AuditLog;
use App\Models\BoqItem;
use App\Models\CompanyDeletionRequest;
use App\Models\CustomRole;
use App\Models\DeviceLicence;
use App\Models\Dpr;
use App\Models\ExpenseClaim;
use App\Models\MeasurementEntry;
use App\Models\Organization;
use App\Models\Party;
use App\Models\Project;
use App\Models\ReportSchedule;
use App\Models\StaffLeave;
use App\Models\TeamInvitation;
use App\Models\User;
use App\Notifications\ApprovalUpdate;
use App\Services\Access\InvitationService;
use App\Services\Access\TeamAccessService;
use App\Services\Agents\AgentRegistry;
use App\Services\ApprovalService;
use App\Services\ContractorPortal\ContractorAccessService;
use App\Services\MeasurementService;
use App\Services\Notify\CompanyDeletionService;
use App\Services\Notify\NotificationMessage;
use App\Services\Notify\Notifier;
use App\Services\Reports\ReportMailGateway;
use App\Services\Reports\ScheduleService;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Api\ApiTestCase;
use Tests\Feature\OfflinePlan\BuildsOfflineCompany;
use Tests\Feature\SiteControl\BuildsMoneyWorld;

/**
 * Deleting one's own account from the app (spec B and C). A member, or an owner the company can do
 * without, is anonymised at once. The only active owner schedules the company's deletion instead.
 */
class AccountDeletionTest extends ApiTestCase
{
    use BuildsMoneyWorld, BuildsOfflineCompany;

    private const PATH = '/api/v1/auth/account/deletion';

    private Organization $company;

    private User $owner;

    /** @var array<int, array{email: string, phone: string}> The email and phone each person's traces were written under. */
    private array $traceKeys = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        // Pushes and emails are queued; the bell is written straight away, which is what these tests count.
        Queue::fake();
        $this->company = $this->tenant('Deccan Builders');
        $this->owner = $this->member($this->company, 'owner');
    }

    private function person(string $role = 'engineer', array $attributes = []): User
    {
        $user = $this->member($this->company, $role);
        $user->forceFill($attributes)->save();

        return $user;
    }

    private function token(User $user): string
    {
        return $user->createToken('Pixel 8')->plainTextToken;
    }

    /** A fresh request with a phone's bearer token, as the app sends it: nothing signed in is kept between calls. */
    private function onPhone(string $method, string $token, array $data = []): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->json($method, self::PATH, $data, ['Authorization' => 'Bearer '.$token]);
    }

    private function preview(string $token): TestResponse
    {
        return $this->onPhone('GET', $token);
    }

    private function requestDeletion(string $token, array $data): TestResponse
    {
        return $this->onPhone('POST', $token, $data);
    }

    private function cancel(string $token): TestResponse
    {
        return $this->onPhone('DELETE', $token);
    }

    private function deleteMember(User $user, string $password = 'password'): TestResponse
    {
        return $this->requestDeletion($this->token($user), ['mode' => 'account', 'password' => $password]);
    }

    private function deleteCompany(User $owner, ?string $name = 'Deccan Builders'): TestResponse
    {
        return $this->requestDeletion($this->token($owner), ['mode' => 'company', 'password' => 'password', 'confirm_name' => $name]);
    }

    /** The address a deleted account is given: theirs alone, and impossible to guess. */
    private function assertPlaceholder(User $user, ?string $email = null): string
    {
        $email ??= (string) $user->fresh()->email;
        $this->assertMatchesRegularExpression('/^deleted-'.$user->id.'-[a-z0-9]{24}@deleted\.invalid$/', $email);

        return $email;
    }

    private function iso(\DateTimeInterface $at): string
    {
        return Carbon::instance($at)->setTimezone('Asia/Kolkata')->toIso8601String();
    }

    // Mode 1: a member

    public function test_a_member_is_shown_that_only_their_account_goes(): void
    {
        $member = $this->person();
        $this->member($this->company, 'contractor');
        $this->person('pm', ['is_active' => false]);

        $response = $this->preview($this->token($member))->assertOk();

        $this->assertSame(['mode', 'company_name', 'other_people', 'requires_confirm_name', 'grace_days', 'erase_after', 'pending'], array_keys($response->json('data')));
        $response->assertExactJson(['data' => [
            'mode' => 'account',
            'company_name' => 'Deccan Builders',
            // The owner and the contractor login; the inactive member does not count.
            'other_people' => 2,
            'requires_confirm_name' => false,
            'grace_days' => 30,
            'erase_after' => null,
            'pending' => null,
        ]]);
    }

    public function test_a_wrong_password_changes_nothing(): void
    {
        $member = $this->person('engineer', ['name' => 'Ravi Patil', 'email' => 'ravi@example.com', 'phone' => '9800000001']);
        $token = $this->token($member);

        $this->requestDeletion($token, ['mode' => 'account', 'password' => 'not-it'])
            ->assertUnprocessable()
            ->assertJsonPath('errors.password', ['That password is not right.']);

        $member->refresh();
        $this->assertSame(['Ravi Patil', 'ravi@example.com', '9800000001', true], [$member->name, $member->email, $member->phone, $member->is_active]);
        $this->assertNull($member->anonymised_at);
        $this->preview($token)->assertOk();
        $this->assertSame(0, AuditLog::where('action', 'account.deleted')->count());
    }

    public function test_the_body_is_checked(): void
    {
        $token = $this->token($this->person());

        $this->requestDeletion($token, ['password' => 'password'])->assertUnprocessable()->assertJsonValidationErrors('mode');
        $this->requestDeletion($token, ['mode' => 'everything', 'password' => 'password'])->assertUnprocessable()->assertJsonValidationErrors('mode');
        $this->requestDeletion($token, ['mode' => 'account'])->assertUnprocessable()->assertJsonValidationErrors('password');
    }

    public function test_a_member_is_anonymised_and_everything_that_reaches_them_is_removed(): void
    {
        $project = $this->makeProject($this->company);
        $customRole = CustomRole::create(['organization_id' => $this->company->id, 'name' => 'Site lead', 'permissions' => ['dpr.view'], 'approval_role' => 'qs']);
        $member = $this->person('engineer', [
            'name' => 'Ravi Patil', 'email' => 'ravi@example.com', 'phone' => '9800000001', 'locale' => 'mr',
            'custom_role_id' => $customRole->id, 'limited_to_projects' => true, 'last_login_at' => now(), 'is_platform_admin' => true,
        ]);
        $other = $this->person('pm', ['name' => 'Sunita Kale']);
        $this->traces($member, $project);
        $this->traces($other, $project);
        $token = $this->token($member);

        $this->requestDeletion($token, ['mode' => 'account', 'password' => 'password'])->assertOk()->assertExactJson(['data' => ['result' => 'deleted']]);

        $row = DB::table('users')->where('id', $member->id)->first();
        $this->assertSame('Deleted user', $row->name);
        $this->assertPlaceholder($member, $row->email);
        $this->assertNull($row->phone);
        $this->assertFalse((bool) $row->is_active);
        $this->assertNull($row->email_verified_at);
        $this->assertNull($row->last_login_at);
        $this->assertFalse((bool) $row->is_platform_admin);
        $this->assertSame('viewer', $row->role);
        $this->assertNull($row->custom_role_id);
        $this->assertFalse((bool) $row->limited_to_projects);
        $this->assertNull($row->contractor_party_id);
        $this->assertNotNull($row->access_changed_at);
        $this->assertNotNull($row->anonymised_at);
        $this->assertSame([$this->company->id, 'mr'], [(int) $row->organization_id, $row->locale]);
        $this->assertNotSame($member->getAttributes()['password'], $row->password);
        $this->assertNotSame($member->getAttributes()['remember_token'], $row->remember_token);

        // Every side table is empty for them, and untouched for the colleague who stays.
        foreach ($this->sideTables($member) as $table => $count) {
            $this->assertSame(0, $count, "{$table} still holds the deleted person");
        }

        foreach ($this->sideTables($other) as $table => $count) {
            $this->assertGreaterThan(0, $count, "{$table} lost the colleague's rows");
        }

        // The custom role they held can now be removed.
        $this->assertFalse(User::where('custom_role_id', $customRole->id)->exists());

        // Their assistant runs stay for the credits spent, without their words.
        $run = AgentRun::withoutGlobalScopes()->where('user_id', $member->id)->sole();
        $this->assertSame(['', [], null, null, null], [$run->input, $run->messages, $run->answer, $run->output, $run->agent_conversation_id]);
        $this->assertNull(DB::table('agent_steps')->where('agent_run_id', $run->id)->value('text'));
        $this->assertSame('Half the slab is cast.', AgentRun::withoutGlobalScopes()->where('user_id', $other->id)->value('answer'));

        // The licence their phone held is freed, and kept as a record.
        $licence = DeviceLicence::withoutGlobalScopes()->where('user_id', $member->id)->sole();
        $this->assertSame([DeviceLicence::REVOKED, null, $member->id], [$licence->status, $licence->slot, $licence->revoked_by]);
        $this->assertSame(DeviceLicence::ACTIVE, DeviceLicence::withoutGlobalScopes()->where('user_id', $other->id)->value('status'));
    }

    public function test_copies_of_their_email_and_name_are_scrubbed(): void
    {
        $member = $this->person('qs', ['name' => 'Ravi Patil', 'email' => 'ravi@example.com', 'phone' => '9800000001']);

        // An older invitation to the same address, the one they joined with, one they sent that is still open,
        // and a colleague's. Each is audited the way InvitationService does it.
        $invite = function (array $attributes, User $by, array $audit) {
            $invitation = TeamInvitation::create([
                'organization_id' => $this->company->id, 'role_key' => 'engineer', 'token_hash' => Str::random(64), 'invited_by' => $by->id,
                'expires_at' => now()->addDays(7), ...$attributes,
            ]);

            foreach ($audit as $action => $values) {
                AuditLog::record($action, $invitation, null, $values, actor: $by);
            }

            return $invitation;
        };
        $old = $invite(['email' => 'Ravi@Example.com', 'phone' => '9800000001', 'expires_at' => now()->subDays(20), 'revoked_at' => now()->subDays(25)], $this->owner, [
            'invitation.created' => ['email' => 'Ravi@Example.com', 'role' => 'engineer'], 'invitation.revoked' => ['email' => 'Ravi@Example.com'],
        ]);
        $joined = $invite(['email' => 'ravi@example.com', 'phone' => '9800000001', 'role_key' => 'qs', 'accepted_at' => now(), 'accepted_user_id' => $member->id], $this->owner, [
            'invitation.created' => ['email' => 'ravi@example.com', 'role' => 'qs'], 'invitation.accepted' => ['email' => 'ravi@example.com', 'user_id' => $member->id],
        ]);
        $sent = $invite(['email' => 'friend@example.com'], $member, ['invitation.created' => ['email' => 'friend@example.com', 'role' => 'engineer']]);
        $colleagueInvite = $invite(['email' => 'colleague@example.com'], $this->owner, ['invitation.created' => ['email' => 'colleague@example.com', 'role' => 'engineer']]);

        // An approval they gave, and one a colleague gave on the same entry.
        $project = $this->makeProject($this->company);
        $engineer = $this->person();
        $pm = $this->person('pm', ['name' => 'Sunita Kale']);
        $approval = app(MeasurementService::class)->submit($this->draftEntry($project, $this->makeBoqItem($project), $engineer), $engineer);
        $this->actingAs($member);
        app(ApprovalService::class)->act($approval, $member, 'approve');
        $this->actingAs($pm);
        app(ApprovalService::class)->act($approval->refresh(), $pm, 'approve');

        $this->deleteMember($member)->assertOk();
        $placeholder = $this->assertPlaceholder($member);

        $this->assertSame([$placeholder, null], [$joined->fresh()->email, $joined->fresh()->phone]);
        $this->assertSame($placeholder, $old->fresh()->email);
        $this->assertNotNull($sent->fresh()->revoked_at);
        $this->assertSame('friend@example.com', $sent->fresh()->email);
        $this->assertNull($colleagueInvite->fresh()->revoked_at);

        $invitationAudit = AuditLog::withoutGlobalScopes()->where('auditable_type', (new TeamInvitation)->getMorphClass())->whereIn('auditable_id', [$old->id, $joined->id])->get();
        $this->assertCount(4, $invitationAudit);
        $this->assertSame([$placeholder], $invitationAudit->pluck('new_values.email')->unique()->values()->all());
        $this->assertSame('friend@example.com', AuditLog::withoutGlobalScopes()->where('auditable_type', (new TeamInvitation)->getMorphClass())->where('auditable_id', $sent->id)->sole()->new_values['email']);
        $this->assertSame('colleague@example.com', AuditLog::withoutGlobalScopes()->where('auditable_type', (new TeamInvitation)->getMorphClass())->where('auditable_id', $colleagueInvite->id)->sole()->new_values['email']);

        $theirs = AuditLog::withoutGlobalScopes()->where('action', 'approval.approve')->where('user_id', $member->id)->sole();
        $this->assertArrayNotHasKey('actor', $theirs->new_values);
        $this->assertSame(1, $theirs->new_values['level']);
        $this->assertSame('Sunita Kale', AuditLog::withoutGlobalScopes()->where('action', 'approval.approve')->where('user_id', $pm->id)->sole()->new_values['actor']);

        $everything = json_encode(AuditLog::withoutGlobalScopes()->get(['old_values', 'new_values'])->toArray());
        $this->assertStringNotContainsStringIgnoringCase('ravi@example.com', $everything);
        $this->assertStringNotContainsString('Ravi Patil', $everything);
    }

    public function test_report_schedules_stop_mailing_them_and_another_companys_lists_are_untouched(): void
    {
        $member = $this->person('engineer', ['email' => 'ravi@example.com']);
        $rival = $this->tenant('Rival Constructions');
        $schedule = fn (Organization $organization, array $recipients) => ReportSchedule::withoutGlobalScopes()->create([
            'organization_id' => $organization->id, 'report' => 'boq_ledger', 'frequency' => 'weekly', 'day_of_week' => 1, 'recipients' => $recipients,
        ]);
        $ours = $schedule($this->company, ['office@example.com', 'RAVI@example.com']);
        $onlyTheirs = $schedule($this->company, ['ravi@example.com']);
        $theirs = $schedule($rival, ['ravi@example.com', 'office@rival.example']);
        $this->actingAs($this->owner);
        $ours->update(['recipients' => ['office@example.com', 'RAVI@example.com', 'site@example.com']]);
        $audited = AuditLog::withoutGlobalScopes()->where('auditable_type', (new ReportSchedule)->getMorphClass())->count();

        $this->deleteMember($member)->assertOk();

        $this->assertSame(['office@example.com', 'site@example.com'], $ours->fresh()->recipients);
        $this->assertSame([], $onlyTheirs->fresh()->recipients);
        $this->assertSame(['ravi@example.com', 'office@rival.example'], $theirs->fresh()->recipients);
        // Nobody is left on that one list, so it is paused and says why. The others keep going.
        $this->assertSame([false, 'no_recipients'], [$onlyTheirs->fresh()->active, $onlyTheirs->fresh()->last_status]);
        $this->assertSame([true, true], [$ours->fresh()->active, $theirs->fresh()->active]);

        $ourAudit = json_encode(AuditLog::withoutGlobalScopes()->where('organization_id', $this->company->id)->get(['old_values', 'new_values'])->toArray());
        $this->assertStringNotContainsStringIgnoringCase('ravi@example.com', $ourAudit);
        $this->assertStringContainsString('site@example.com', $ourAudit);
        $this->assertStringContainsString('ravi@example.com', json_encode(AuditLog::withoutGlobalScopes()->where('organization_id', $rival->id)->get(['new_values'])->toArray()));
        $this->assertSame($audited, AuditLog::withoutGlobalScopes()->where('auditable_type', (new ReportSchedule)->getMorphClass())->count(), 'Scrubbing writes no new copy of the list.');
    }

    public function test_a_schedule_that_mailed_only_them_never_fails_at_its_next_time(): void
    {
        $member = $this->person('engineer', ['email' => 'ravi@example.com']);
        $admin = $this->person('admin');
        $schedule = ReportSchedule::withoutGlobalScopes()->create([
            'organization_id' => $this->company->id, 'created_by' => $admin->id, 'report' => 'boq_ledger', 'format' => 'xlsx',
            'period' => 'last_30_days', 'frequency' => 'daily', 'hour' => 7, 'recipients' => ['ravi@example.com'], 'active' => true,
        ]);
        $gateway = new class extends ReportMailGateway
        {
            public int $calls = 0;

            public function configured(): bool
            {
                return true;
            }

            public function send(array $recipients, Mailable $mail): void
            {
                $this->calls++;
            }
        };
        $this->app->instance(ReportMailGateway::class, $gateway);

        $this->deleteMember($member)->assertOk();

        $this->travel(2)->days();
        $this->assertSame(['sent' => 0, 'waiting' => 0, 'skipped' => 0, 'failed' => 0], app(ScheduleService::class)->runDue(), 'Paused, so not even tried.');

        // Resumed by an admin with the list still empty: skipped at its time instead of failing there.
        $this->actingAs($admin)->patch(route('reports.schedules.toggle', $schedule))->assertRedirect();
        $this->assertTrue($schedule->fresh()->active);
        $this->travel(1)->days();
        $this->assertSame(['sent' => 0, 'waiting' => 0, 'skipped' => 1, 'failed' => 0], app(ScheduleService::class)->runDue());
        $this->assertSame(0, $gateway->calls);
        $this->assertSame('no_recipients', $schedule->fresh()->last_status);
    }

    public function test_their_work_stays_with_the_company_and_shows_as_deleted_user(): void
    {
        $this->buildMoneyWorld();
        $world = $this->organization;
        $engineer = $this->users['engineer'];
        $name = $engineer->name;
        $entry = $this->approveEntry($this->draftEntry($this->project, $this->rows[BoqItem::class], $engineer), $engineer, $this->users['owner']);
        $this->assertSame('APPROVED', $entry->status);
        $ledger = DB::table('stock_ledger')->where('organization_id', $world->id)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
        $this->assertNotEmpty($ledger);
        $claims = ExpenseClaim::withoutGlobalScopes()->where('user_id', $engineer->id)->count();
        $leaves = StaffLeave::withoutGlobalScopes()->where('user_id', $engineer->id)->count();
        $this->assertGreaterThan(0, $claims + $leaves);

        $this->deleteMember($engineer)->assertOk();

        $entry = MeasurementEntry::withoutGlobalScopes()->findOrFail($entry->id);
        $this->assertSame([$engineer->id, 'APPROVED'], [$entry->created_by, $entry->status]);
        $this->assertSame($engineer->id, Dpr::withoutGlobalScopes()->findOrFail($this->rows[Dpr::class]->id)->created_by);
        $this->assertSame($ledger, DB::table('stock_ledger')->where('organization_id', $world->id)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all());
        $this->assertSame($claims, ExpenseClaim::withoutGlobalScopes()->where('user_id', $engineer->id)->count());
        $this->assertSame($leaves, StaffLeave::withoutGlobalScopes()->where('user_id', $engineer->id)->count());
        $this->assertSame(0, AgentConversation::withoutGlobalScopes()->where('user_id', $engineer->id)->count());

        $this->app['auth']->forgetGuards();
        $this->actingAs($this->users['owner'])->get(route('measurements.show', $entry))->assertOk()->assertSee('Deleted user')->assertDontSee($name);
        $this->assertSame('Deleted user', $engineer->fresh()->name);
        $this->app->setLocale('hi');
        $this->assertSame(__('Deleted user', [], 'hi'), $engineer->fresh()->name);
    }

    public function test_the_seat_is_freed_and_the_team_screen_forgets_them(): void
    {
        $this->company->update(['plan' => 'free']);
        $member = $this->person('engineer', ['name' => 'Ravi Patil']);
        $this->person('qs');
        $this->assertFalse($this->company->fresh()->canAddUser());
        $this->assertFalse(app(InvitationService::class)->hasSeat($this->company->fresh()));

        $this->deleteMember($member)->assertOk();

        $this->assertTrue($this->company->fresh()->canAddUser());
        $this->assertTrue(app(InvitationService::class)->hasSeat($this->company->fresh()));

        $this->app['auth']->forgetGuards();
        $this->actingAs($this->owner);
        $this->get('/team')->assertOk()->assertDontSee('Deleted user')->assertDontSee('Ravi Patil');
        $this->get("/team/{$member->id}/access")->assertNotFound();
        $this->patch("/team/{$member->id}", ['role' => 'engineer', 'is_active' => '1'])->assertNotFound();
        $this->assertFalse((bool) $member->fresh()->is_active);
    }

    public function test_nothing_is_sent_to_a_deleted_account(): void
    {
        $member = $this->person();
        $this->deleteMember($member)->assertOk();

        app(Notifier::class)->send($member->fresh(), new NotificationMessage(event: 'approval.decided', title: 'Approved', body: 'Your entry was approved.', critical: true));

        $this->assertSame(0, DB::table('notifications')->where('notifiable_id', $member->id)->count());
        // Only the deletion's own second scrub is queued; nothing goes to them.
        $this->assertSame([ScrubDeletedAccount::class], array_keys(Queue::pushedJobs()));
    }

    public function test_the_old_token_stops_working_and_the_email_can_start_again(): void
    {
        $member = $this->person('engineer', ['email' => 'ravi@example.com', 'phone' => '9800000001']);
        $token = $this->token($member);
        $spare = $this->token($member);

        $this->requestDeletion($token, ['mode' => 'account', 'password' => 'password'])->assertOk();

        $this->app['auth']->forgetGuards();
        $this->getJson(self::API.'/me', ['Authorization' => 'Bearer '.$token])->assertUnauthorized();
        $this->app['auth']->forgetGuards();
        $this->getJson(self::API.'/me', ['Authorization' => 'Bearer '.$spare])->assertUnauthorized();
        $this->app['auth']->forgetGuards();
        $this->postJson(self::API.'/auth/login', ['email' => 'ravi@example.com', 'password' => 'password', 'device_name' => 'Pixel 8'])->assertUnprocessable();

        $this->app['auth']->forgetGuards();
        $this->postJson(self::API.'/auth/register', [
            'company' => 'Ravi Works', 'name' => 'Ravi Patil', 'email' => 'Ravi@Example.com', 'phone' => '9800000001',
            'password' => 'a-long-password', 'password_confirmation' => 'a-long-password', 'device_name' => 'Pixel 8',
        ])->assertCreated();
        $this->assertSame(2, User::whereIn('email', ['ravi@example.com', $this->assertPlaceholder($member)])->count());
    }

    public function test_the_deletion_is_audited_without_personal_values(): void
    {
        $member = $this->person('engineer', ['name' => 'Ravi Patil', 'email' => 'ravi@example.com']);

        $this->deleteMember($member)->assertOk();

        $log = AuditLog::withoutGlobalScopes()->where('action', 'account.deleted')->sole();
        $this->assertSame(['mode' => 'account', 'via' => 'app'], $log->new_values);
        $this->assertNull($log->old_values);
        $this->assertSame([$member->id, $this->company->id, $member->getMorphClass(), $member->id], [$log->user_id, $log->organization_id, $log->auditable_type, $log->auditable_id]);
    }

    public function test_the_migration_rolls_back(): void
    {
        $migration = require database_path('migrations/2027_01_05_100001_add_anonymised_at_to_users.php');

        $migration->down();
        $this->assertFalse(Schema::hasColumn('users', 'anonymised_at'));

        $migration->up();
        $this->assertTrue(Schema::hasColumn('users', 'anonymised_at'));
    }

    // Mode 2: an owner the company can do without

    public function test_an_owner_with_another_active_owner_is_deleted_on_their_own(): void
    {
        $second = $this->person('owner');
        $token = $this->token($second);

        $this->preview($token)->assertOk()->assertJsonPath('data.mode', 'account')->assertJsonPath('data.requires_confirm_name', false);
        $this->requestDeletion($token, ['mode' => 'account', 'password' => 'password'])->assertOk()->assertJsonPath('data.result', 'deleted');

        $this->assertNotNull($second->fresh()->anonymised_at);
        $this->assertTrue(User::where('organization_id', $this->company->id)->where('role', 'owner')->where('is_active', true)->exists());
        $this->assertSame(0, CompanyDeletionRequest::count());
    }

    public function test_an_owner_whose_only_other_owner_is_inactive_deletes_the_company(): void
    {
        $this->person('owner', ['is_active' => false]);

        $this->preview($this->token($this->owner))->assertOk()
            ->assertJsonPath('data.mode', 'company')
            ->assertJsonPath('data.requires_confirm_name', true);
    }

    // Mode 3: the only owner

    public function test_the_only_owner_is_shown_the_company_and_its_date(): void
    {
        $this->travelTo(now()->setDate(2026, 11, 12)->setTime(15, 4, 5));
        $this->person();
        $this->member($this->company, 'contractor');

        $this->preview($this->token($this->owner))->assertOk()->assertExactJson(['data' => [
            'mode' => 'company',
            'company_name' => 'Deccan Builders',
            'other_people' => 2,
            'requires_confirm_name' => true,
            'grace_days' => 30,
            'erase_after' => '2026-12-12T15:04:05+05:30',
            'pending' => null,
        ]]);
    }

    public function test_the_only_owner_asking_for_account_mode_is_told_it_changed(): void
    {
        $this->deleteMember($this->owner)
            ->assertStatus(409)
            ->assertExactJson(['message' => 'Something changed in your company. Check what will happen, then try again.', 'code' => 'deletion_mode_changed']);

        $this->assertNull($this->owner->fresh()->anonymised_at);
        $this->assertSame(0, CompanyDeletionRequest::count());
    }

    public function test_a_member_asking_for_company_mode_is_told_it_changed(): void
    {
        $this->requestDeletion($this->token($this->person()), ['mode' => 'company', 'password' => 'password', 'confirm_name' => 'Deccan Builders'])
            ->assertStatus(409)->assertJsonPath('code', 'deletion_mode_changed');

        $this->assertSame(0, CompanyDeletionRequest::count());
    }

    public function test_the_company_name_must_be_typed_exactly(): void
    {
        $this->deleteCompany($this->owner, null)->assertUnprocessable()->assertJsonValidationErrors('confirm_name');
        $this->deleteCompany($this->owner, 'deccan builders')->assertUnprocessable()
            ->assertJsonPath('errors.confirm_name', ['Type the company name exactly as shown.']);

        $this->assertSame(0, CompanyDeletionRequest::count());
        $this->assertSame(2, $this->owner->tokens()->count());
    }

    /** A token on its own must not be a way to try passwords: only the right company name reaches the password check. */
    public function test_the_company_name_is_checked_before_the_password(): void
    {
        $token = $this->token($this->owner);
        $errors = fn (array $body) => array_keys($this->requestDeletion($token, ['mode' => 'company', ...$body])->assertUnprocessable()->json('errors'));

        $this->assertSame(['confirm_name'], $errors(['password' => 'not-it', 'confirm_name' => 'Deccan']));
        $this->assertSame(['confirm_name'], $errors(['password' => 'password', 'confirm_name' => 'Deccan']));
        $this->assertSame(['confirm_name'], $errors(['password' => 'not-it']));
        $this->assertSame(['password'], $errors(['password' => 'not-it', 'confirm_name' => 'Deccan Builders']));

        $this->requestDeletion($token, ['mode' => 'company', 'password' => 'password', 'confirm_name' => 'deccan builders'])
            ->assertJsonPath('errors.confirm_name', ['Type the company name exactly as shown.']);

        // Every try counts towards the limit, whichever check refused it.
        $this->requestDeletion($token, ['mode' => 'company', 'password' => 'password', 'confirm_name' => 'Deccan Builders'])->assertTooManyRequests();

        $this->assertSame(0, CompanyDeletionRequest::count());
        $this->assertSame(0, AuditLog::where('action', 'account.deleted')->count());
        $this->assertSame(1, $this->owner->tokens()->count());
    }

    public function test_the_only_owner_schedules_the_company_and_is_signed_out_everywhere(): void
    {
        $this->owner->forceFill(['name' => 'Asha Deshmukh', 'email' => 'asha@example.com'])->save();
        $engineer = $this->person();
        $contractor = $this->member($this->company, 'contractor');
        $this->traces($this->owner, $this->makeProject($this->company));
        $remember = $this->owner->fresh()->remember_token;

        $response = $this->deleteCompany($this->owner)->assertOk();

        $request = CompanyDeletionRequest::sole();
        $response->assertExactJson(['data' => ['result' => 'scheduled', 'erase_after' => $this->iso($request->purge_after)]]);
        $this->assertSame(['pending', 'account', $this->owner->id], [$request->status, $request->requested_via, (int) $request->requested_by]);

        $this->assertSame(0, $this->owner->tokens()->count());
        $this->assertSame(0, DB::table('sessions')->where('user_id', $this->owner->id)->count());
        $this->assertSame(0, DB::table('device_tokens')->where('user_id', $this->owner->id)->count());
        $this->assertSame(DeviceLicence::REVOKED, DeviceLicence::withoutGlobalScopes()->where('user_id', $this->owner->id)->value('status'));

        // The row itself stays as it was: the company still needs an owner who can sign in and cancel.
        $owner = $this->owner->fresh();
        $this->assertSame(['Asha Deshmukh', 'asha@example.com', true, 'owner'], [$owner->name, $owner->email, $owner->is_active, $owner->role]);
        $this->assertNull($owner->anonymised_at);
        $this->assertNotSame($remember, $owner->remember_token);

        foreach ([$this->owner, $engineer, $contractor] as $person) {
            $this->assertCount(1, $person->notifications()->get()->where('data.title', 'Company deletion requested for Deccan Builders'), "{$person->role} was not told");
        }

        $log = AuditLog::withoutGlobalScopes()->where('action', 'account.deleted')->sole();
        $this->assertSame(['mode' => 'company', 'via' => 'app'], $log->new_values);
        $this->assertSame($this->owner->id, $log->user_id);
    }

    public function test_asking_again_keeps_the_same_date(): void
    {
        $first = $this->deleteCompany($this->owner)->assertOk()->json('data.erase_after');
        $this->travel(3)->days();

        // They sign in again, which they may do during the grace period.
        $token = $this->token($this->owner);
        $this->preview($token)->assertOk()
            ->assertJsonPath('data.erase_after', $first)
            ->assertJsonPath('data.pending', ['erase_after' => $first, 'can_cancel' => true]);
        $this->requestDeletion($token, ['mode' => 'company', 'password' => 'password', 'confirm_name' => 'Deccan Builders'])->assertOk()->assertJsonPath('data.erase_after', $first);

        $this->assertSame(1, CompanyDeletionRequest::count());
    }

    public function test_an_earlier_request_from_the_company_data_page_becomes_an_account_request(): void
    {
        app(CompanyDeletionService::class)->request($this->company, $this->owner, 'Deccan Builders');
        $this->assertSame('company', CompanyDeletionRequest::sole()->requested_via);

        $this->deleteCompany($this->owner)->assertOk();

        $this->assertSame('account', CompanyDeletionRequest::sole()->requested_via);
    }

    public function test_members_see_the_pending_deletion_and_cannot_cancel_it(): void
    {
        $member = $this->person();
        $this->deleteCompany($this->owner)->assertOk();
        $date = $this->iso(CompanyDeletionRequest::sole()->purge_after);

        $this->preview($this->token($member))->assertOk()
            ->assertJsonPath('data.mode', 'account')
            ->assertJsonPath('data.pending', ['erase_after' => $date, 'can_cancel' => false]);
    }

    // Cancelling

    public function test_an_owner_cancels_and_a_member_cannot(): void
    {
        $member = $this->person();
        $this->deleteCompany($this->owner)->assertOk();

        $this->cancel($this->token($member))->assertForbidden()->assertJsonPath('message', 'Only a company owner can do this.');
        $this->assertSame('pending', CompanyDeletionRequest::sole()->status);

        $token = $this->token($this->owner);
        $this->cancel($token)->assertOk()->assertExactJson(['data' => ['result' => 'cancelled']]);
        $this->cancel($token)->assertOk()->assertExactJson(['data' => ['result' => 'none']]);

        $this->assertSame('cancelled', CompanyDeletionRequest::sole()->status);
        // The requester cancelled it themselves, so they stay as they are.
        $this->assertNull($this->owner->fresh()->anonymised_at);
        $this->assertTrue($this->owner->fresh()->is_active);
    }

    public function test_another_owner_cancelling_carries_out_the_requesters_own_deletion(): void
    {
        $this->owner->forceFill(['name' => 'Asha Deshmukh', 'email' => 'asha@example.com'])->save();
        $colleague = $this->person('pm');
        $this->deleteCompany($this->owner)->assertOk();

        // The company finds another owner and keeps going.
        $colleague->forceFill(['role' => 'owner'])->save();
        $this->cancel($this->token($colleague))->assertOk()->assertJsonPath('data.result', 'cancelled');

        $requester = $this->owner->fresh();
        $this->assertNotNull($requester->anonymised_at);
        $this->assertPlaceholder($requester);
        $this->assertFalse($requester->is_active);
        $this->assertSame('cancelled', CompanyDeletionRequest::sole()->status);
        $this->assertNull($colleague->fresh()->anonymised_at);

        // Another owner's cancel did it, not support.
        $log = AuditLog::withoutGlobalScopes()->where('action', 'account.deleted')->latest('id')->first();
        $this->assertSame(['mode' => 'account', 'via' => 'owner'], $log->new_values);
        $this->assertSame($colleague->id, $log->user_id, 'The person whose cancel carried it out is on record.');
        $this->assertSame($requester->id, $log->auditable_id);

        // Nobody tells the deleted person that the company stays.
        $this->assertSame(0, DB::table('notifications')->where('notifiable_id', $requester->id)->count());
        $this->assertCount(1, $colleague->notifications()->get()->where('data.title', 'Company deletion cancelled for Deccan Builders'));
    }

    public function test_a_cancel_on_the_company_data_page_does_the_same(): void
    {
        $colleague = $this->person('owner', ['is_active' => false]);
        $this->deleteCompany($this->owner)->assertOk();
        $colleague->forceFill(['is_active' => true])->save();

        $this->app['auth']->forgetGuards();
        $this->actingAs($colleague)->delete('/company-data/deletion')->assertRedirect()->assertSessionHas('success');

        $this->assertNotNull($this->owner->fresh()->anonymised_at);
    }

    public function test_a_company_data_request_cancelled_by_another_owner_deletes_nobody(): void
    {
        $colleague = $this->person('owner');
        app(CompanyDeletionService::class)->request($this->company, $this->owner, 'Deccan Builders');

        $this->cancel($this->token($colleague))->assertOk()->assertJsonPath('data.result', 'cancelled');

        $this->assertNull($this->owner->fresh()->anonymised_at);
    }

    // Gates and limits

    public function test_a_contractor_login_can_look_and_delete(): void
    {
        $party = Party::create(['organization_id' => $this->company->id, 'type' => 'subcontractor', 'name' => 'Shree Formwork']);
        $contractor = $this->person(User::CONTRACTOR_ROLE, ['contractor_party_id' => $party->id]);
        $token = $this->token($contractor);

        $this->preview($token)->assertOk()->assertJsonPath('data.mode', 'account');
        $this->requestDeletion($token, ['mode' => 'account', 'password' => 'password'])->assertOk()->assertJsonPath('data.result', 'deleted');

        $this->assertNull($contractor->fresh()->contractor_party_id);
        $this->assertCount(0, app(ContractorAccessService::class)->state($party)['logins']);
    }

    public function test_an_offline_plan_company_can_look_and_delete(): void
    {
        $offline = $this->offlineTenant('Phone Co');
        $owner = $this->member($offline, 'owner');
        $member = $this->member($offline, 'engineer');

        $this->preview($this->token($member))->assertOk()->assertJsonPath('data.mode', 'account');
        $this->deleteMember($member)->assertOk()->assertJsonPath('data.result', 'deleted');

        $this->preview($this->token($owner))->assertOk()->assertJsonPath('data.mode', 'company');
        $this->requestDeletion($this->token($owner), ['mode' => 'company', 'password' => 'password', 'confirm_name' => 'Phone Co'])->assertOk()->assertJsonPath('data.result', 'scheduled');
        $this->cancel($this->token($owner))->assertOk()->assertJsonPath('data.result', 'cancelled');
    }

    public function test_the_sixth_try_in_a_minute_is_refused(): void
    {
        $token = $this->token($this->person());

        for ($i = 0; $i < 5; $i++) {
            $this->requestDeletion($token, ['mode' => 'account', 'password' => 'not-it'])->assertUnprocessable();
        }

        $this->requestDeletion($token, ['mode' => 'account', 'password' => 'password'])->assertTooManyRequests()->assertHeader('Retry-After');
        $this->assertNull(User::where('role', 'engineer')->sole()->anonymised_at);

        // Its own counter: reading the preview and signing in still work.
        $this->preview($token)->assertOk();
    }

    public function test_without_a_token_nothing_is_reachable(): void
    {
        $this->app['auth']->forgetGuards();

        $this->getJson(self::PATH)->assertUnauthorized();
        $this->postJson(self::PATH, ['mode' => 'account', 'password' => 'password'])->assertUnauthorized();
        $this->deleteJson(self::PATH)->assertUnauthorized();
    }

    public function test_another_company_is_untouched(): void
    {
        $rival = $this->tenant('Rival Constructions');
        $rivalOwner = $this->member($rival, 'owner');
        $rivalMember = $this->member($rival, 'engineer');
        $project = $this->makeProject($rival);
        $this->traces($rivalMember, $project);
        $ourOwnerToken = $this->token($this->owner);
        $rivalInvite = TeamInvitation::create(['organization_id' => $rival->id, 'email' => 'ravi@example.com', 'role_key' => 'engineer', 'token_hash' => Str::random(64), 'invited_by' => $rivalOwner->id, 'expires_at' => now()->addDays(3)]);
        $member = $this->person('engineer', ['email' => 'ravi@example.com']);
        $before = $rivalMember->fresh()->getAttributes();

        $this->preview($this->token($member))->assertOk()->assertJsonPath('data.other_people', 1);
        $this->deleteMember($member)->assertOk();

        $this->assertSame($before, $rivalMember->fresh()->getAttributes());

        foreach ($this->sideTables($rivalMember) as $table => $count) {
            $this->assertGreaterThan(0, $count, "{$table} lost another company's rows");
        }

        $this->assertSame('ravi@example.com', $rivalInvite->fresh()->email);
        $this->assertNull($rivalInvite->fresh()->revoked_at);

        // The rival's only owner deleting the rival company touches nothing here.
        $this->requestDeletion($this->token($rivalOwner), ['mode' => 'company', 'password' => 'password', 'confirm_name' => 'Rival Constructions'])->assertOk();
        $this->assertNull(app(CompanyDeletionService::class)->pending($this->company->id));
        $this->assertSame(0, $this->owner->notifications()->count());
        $this->preview($ourOwnerToken)->assertOk()->assertJsonPath('data.pending', null)->assertJsonPath('data.company_name', 'Deccan Builders');
        // And a rival's token cannot cancel anything here: the cancel only ever reaches its own company.
        $this->cancel($this->token($rivalOwner))->assertOk()->assertJsonPath('data.result', 'cancelled');
        $this->assertSame('cancelled', CompanyDeletionRequest::withoutGlobalScopes()->where('organization_id', $rival->id)->sole()->status);
    }

    // Console

    public function test_support_deletes_a_member_from_the_console(): void
    {
        $member = $this->person('engineer', ['email' => 'ravi@example.com']);
        $this->app['auth']->forgetGuards();

        $this->artisan('account:delete', ['email' => 'RAVI@example.com', '--force' => true])
            ->expectsOutputToContain('Mode: account')
            ->expectsOutputToContain('Deleted.')
            ->assertSuccessful();

        $this->assertNotNull($member->fresh()->anonymised_at);
        $log = AuditLog::withoutGlobalScopes()->where('action', 'account.deleted')->sole();
        $this->assertSame(['mode' => 'account', 'via' => 'support'], $log->new_values);
        $this->assertNull($log->user_id);
    }

    public function test_support_schedules_the_only_owners_company_from_the_console(): void
    {
        $this->owner->forceFill(['email' => 'asha@example.com'])->save();
        $this->token($this->owner);
        $this->app['auth']->forgetGuards();

        $this->artisan('account:delete', ['email' => 'asha@example.com'])
            ->expectsOutputToContain('Mode: company')
            ->expectsConfirmation("Schedule this company's deletion?", 'yes')
            ->expectsOutputToContain('Scheduled.')
            ->assertSuccessful();

        $request = CompanyDeletionRequest::sole();
        $this->assertSame(['pending', 'account'], [$request->status, $request->requested_via]);
        $this->assertSame(0, $this->owner->tokens()->count());
        $this->assertSame(['mode' => 'company', 'via' => 'support'], AuditLog::withoutGlobalScopes()->where('action', 'account.deleted')->sole()->new_values);
    }

    public function test_the_console_asks_first_and_knows_no_stranger(): void
    {
        $member = $this->person('engineer', ['email' => 'ravi@example.com']);
        $this->app['auth']->forgetGuards();

        $this->artisan('account:delete', ['email' => 'ravi@example.com'])
            ->expectsConfirmation('Delete this account now? This cannot be undone.', 'no')
            ->expectsOutput('Nothing was changed.')
            ->assertSuccessful();
        $this->assertNull($member->fresh()->anonymised_at);

        $this->artisan('account:delete', ['email' => 'nobody@example.com', '--force' => true])->assertFailed();
    }

    // The placeholder address

    public function test_a_member_is_deleted_even_when_someone_holds_the_old_placeholder_address(): void
    {
        $member = $this->person('engineer', ['email' => 'ravi@example.com']);
        // Before sign-up refused .invalid addresses, anyone could take the address a deletion would give.
        $squatter = $this->member($this->tenant('Squatters'), 'owner');
        $squatter->forceFill(['email' => "deleted-{$member->id}@deleted.invalid"])->save();

        $this->deleteMember($member)->assertOk()->assertExactJson(['data' => ['result' => 'deleted']]);

        $this->assertNotNull($member->fresh()->anonymised_at);
        $this->assertPlaceholder($member);
        $this->assertSame("deleted-{$member->id}@deleted.invalid", $squatter->fresh()->email);
    }

    public function test_another_owner_can_cancel_even_when_someone_holds_the_requesters_old_placeholder_address(): void
    {
        $colleague = $this->person('pm');
        $this->deleteCompany($this->owner)->assertOk();
        $this->member($this->tenant('Squatters'), 'owner')->forceFill(['email' => "deleted-{$this->owner->id}@deleted.invalid"])->save();
        $colleague->forceFill(['role' => 'owner'])->save();

        $this->cancel($this->token($colleague))->assertOk()->assertJsonPath('data.result', 'cancelled');

        $this->assertSame('cancelled', CompanyDeletionRequest::sole()->status);
        $this->assertNotNull($this->owner->fresh()->anonymised_at);
        $this->assertPlaceholder($this->owner);
    }

    public function test_an_invalid_domain_address_can_neither_sign_up_nor_be_invited(): void
    {
        $taken = 'This email already has an account.';

        foreach (['deleted-7@deleted.invalid', 'Someone@Site.INVALID', 'someone@invalid'] as $email) {
            $this->app['auth']->forgetGuards();
            $this->postJson(self::API.'/auth/register', [
                'company' => 'Squat Works', 'name' => 'Stranger', 'email' => $email,
                'password' => 'a-long-password', 'password_confirmation' => 'a-long-password', 'device_name' => 'Pixel 8',
            ])->assertUnprocessable()->assertJsonPath('errors.email', [$taken]);

            $this->app['auth']->forgetGuards();
            $this->post('/register', ['company' => 'Squat Works', 'name' => 'Stranger', 'email' => $email, 'password' => 'a-long-password', 'password_confirmation' => 'a-long-password'])
                ->assertSessionHasErrors(['email' => $taken]);

            $this->actingAs($this->owner)->post('/team', ['email' => $email, 'role' => 'engineer', 'project_access' => 'all'])->assertSessionHasErrors(['email' => $taken]);
        }

        $this->assertSame(0, User::whereRaw('lower(email) like ?', ['%invalid'])->count());
        $this->assertSame(0, TeamInvitation::withoutGlobalScopes()->count());

        // An open invitation a deletion has rewritten to its placeholder does not open either.
        $this->actingAs($this->owner)->post('/team', ['email' => 'ravi@example.com', 'role' => 'engineer', 'project_access' => 'all'])->assertSessionHasNoErrors();
        $link = session('invite_link');
        TeamInvitation::withoutGlobalScopes()->update(['email' => 'deleted-7-abcdefghijklmnopqrstuvwx@deleted.invalid']);
        $this->flushSession();
        $this->app['auth']->forgetGuards();
        $this->post($link, ['name' => 'Someone', 'password' => 'a-long-password', 'password_confirmation' => 'a-long-password']);

        $this->assertSame(0, User::where('email', 'deleted-7-abcdefghijklmnopqrstuvwx@deleted.invalid')->count());
        $this->assertNull(TeamInvitation::withoutGlobalScopes()->sole()->accepted_at);
        $this->assertGuest();
    }

    // When the request is not the one this person made

    public function test_a_requester_another_owner_deactivated_is_still_deleted_when_that_owner_cancels(): void
    {
        $this->owner->forceFill(['name' => 'Asha Deshmukh', 'email' => 'asha@example.com'])->save();
        $colleague = $this->person('pm');
        $this->deleteCompany($this->owner)->assertOk();

        // Asha makes a colleague an owner, who then switches Asha off on the Team page.
        $colleague->forceFill(['role' => 'owner'])->save();
        app(TeamAccessService::class)->update($colleague->fresh(), $this->owner->fresh(), 'owner', false);
        $this->assertFalse($this->owner->fresh()->is_active);

        $this->cancel($this->token($colleague))->assertOk()->assertJsonPath('data.result', 'cancelled');

        $requester = $this->owner->fresh();
        $this->assertNotNull($requester->anonymised_at);
        $this->assertSame('Deleted user', $requester->name);
        $this->assertPlaceholder($requester);
        $this->assertNull($colleague->fresh()->anonymised_at);
    }

    public function test_the_only_owner_takes_over_a_request_an_owner_who_left_made(): void
    {
        $this->owner->forceFill(['email' => 'asha@example.com'])->save();
        $second = $this->person('owner');
        app(CompanyDeletionService::class)->request($this->company, $second, 'Deccan Builders');

        // The owner who asked deletes their own account, so Asha is the only owner left, and asks too.
        $this->deleteMember($second)->assertOk()->assertJsonPath('data.result', 'deleted');
        $first = $this->iso(CompanyDeletionRequest::sole()->purge_after);
        $this->deleteCompany($this->owner)->assertOk()->assertJsonPath('data.result', 'scheduled')->assertJsonPath('data.erase_after', $first);

        $request = CompanyDeletionRequest::sole();
        $this->assertSame([$this->owner->id, 'account', 'pending'], [(int) $request->requested_by, $request->requested_via, $request->status]);
        $this->assertSame($second->id, AuditLog::withoutGlobalScopes()->where('action', 'company.deletion_requested')->sole()->new_values['requested_by'], 'Whoever asked first stays on record.');

        // A new owner keeps the company, so Asha's own account goes as she asked.
        $newOwner = $this->person('pm');
        $newOwner->forceFill(['role' => 'owner'])->save();
        $this->cancel($this->token($newOwner))->assertOk()->assertJsonPath('data.result', 'cancelled');

        $this->assertNotNull($this->owner->fresh()->anonymised_at);
        $this->assertNull($newOwner->fresh()->anonymised_at);
    }

    public function test_an_earlier_account_requester_is_deleted_when_the_only_owner_takes_the_request_over(): void
    {
        // Asha, the only owner, asks through her account, so the company is scheduled and her row waits.
        $this->deleteCompany($this->owner)->assertOk()->assertJsonPath('data.result', 'scheduled');

        // Kiran is made an owner and Asha is switched off; Kiran is now the only owner and asks too.
        $kiran = $this->person('owner');
        $this->owner->forceFill(['is_active' => false])->save();
        $this->deleteCompany($kiran)->assertOk()->assertJsonPath('data.result', 'scheduled');

        $this->assertNotNull($this->owner->fresh()->anonymised_at, 'Once the request names Kiran, nothing else would ever delete Asha.');
        $this->assertPlaceholder($this->owner->fresh());
        $this->assertSame([$kiran->id, 'account'], [(int) CompanyDeletionRequest::sole()->requested_by, CompanyDeletionRequest::sole()->requested_via]);
        $this->assertNull($kiran->fresh()->anonymised_at, 'The only owner stays until the purge, so the company can still be cancelled.');

        // Kiran's takeover deleted Asha: on record as an owner's doing, by Kiran.
        $asha = $this->anonymisedLog($this->owner);
        $this->assertSame([['mode' => 'account', 'via' => 'owner'], $kiran->id], [$asha->new_values, $asha->user_id]);

        // A new owner keeps the company, and Kiran goes as he asked.
        $newOwner = $this->person('pm');
        $newOwner->forceFill(['role' => 'owner'])->save();
        $this->cancel($this->token($newOwner))->assertOk()->assertJsonPath('data.result', 'cancelled');
        $this->assertNotNull($kiran->fresh()->anonymised_at);
        $this->assertNull($newOwner->fresh()->anonymised_at);

        $kiranLog = $this->anonymisedLog($kiran);
        $this->assertSame([['mode' => 'account', 'via' => 'owner'], $newOwner->id], [$kiranLog->new_values, $kiranLog->user_id]);
    }

    public function test_a_takeover_support_runs_from_the_console_is_on_record_as_support(): void
    {
        // Asha, the only owner, asks through her account; then Kiran is the only active owner and writes to support.
        $this->deleteCompany($this->owner)->assertOk()->assertJsonPath('data.result', 'scheduled');
        $kiran = $this->person('owner', ['email' => 'kiran@example.com']);
        $this->owner->forceFill(['is_active' => false])->save();
        $this->app['auth']->forgetGuards();

        $this->artisan('account:delete', ['email' => 'kiran@example.com', '--force' => true])->expectsOutputToContain('Scheduled.')->assertSuccessful();

        $this->assertNotNull($this->owner->fresh()->anonymised_at);
        $asha = $this->anonymisedLog($this->owner);
        $this->assertSame([['mode' => 'account', 'via' => 'support'], null], [$asha->new_values, $asha->user_id]);
    }

    public function test_an_offline_plan_owner_is_pointed_at_delete_my_account_to_cancel(): void
    {
        $offline = $this->offlineTenant('Phone Co');
        $owner = $this->member($offline, 'owner');
        $member = $this->member($offline, 'engineer');

        $this->requestDeletion($this->token($owner), ['mode' => 'company', 'password' => 'password', 'confirm_name' => 'Phone Co'])->assertOk();

        $this->assertSame(route('account.delete'), $owner->notifications()->sole()->data['url'], 'Company data is closed on the offline plan.');
        $this->assertNull($member->notifications()->sole()->data['url']);
    }

    public function test_support_scheduling_for_the_only_owner_takes_over_the_request_and_the_purge_emails_them(): void
    {
        Storage::fake();
        $this->owner->forceFill(['email' => 'asha@example.com'])->save();
        $second = $this->person('owner', ['email' => 'kiran@example.com']);
        app(CompanyDeletionService::class)->request($this->company, $second, 'Deccan Builders');
        // The owner who asked is made a project manager again.
        $second->forceFill(['role' => 'pm'])->save();
        $this->app['auth']->forgetGuards();

        $this->artisan('account:delete', ['email' => 'asha@example.com', '--force' => true])->expectsOutputToContain('Scheduled.')->assertSuccessful();

        $request = CompanyDeletionRequest::sole();
        $this->assertSame([$this->owner->id, 'account'], [(int) $request->requested_by, $request->requested_via]);

        Mail::fake();
        $this->travel(CompanyDeletionService::GRACE_DAYS + 1)->days();
        $this->artisan('company-data:housekeeping')->assertSuccessful();

        $this->assertDatabaseMissing('organizations', ['id' => $this->company->id]);
        $sent = Mail::sent(WorkflowNotificationMail::class, fn (WorkflowNotificationMail $mail) => $mail->notice->event === 'company.erased');
        $this->assertCount(1, $sent);
        $this->assertTrue($sent->first()->hasTo('asha@example.com'));
        $this->assertFalse($sent->first()->hasTo('kiran@example.com'));
    }

    // Agent work that ends after the deletion

    public function test_an_agent_step_that_ends_after_the_deletion_is_scrubbed_again(): void
    {
        $project = $this->makeProject($this->company);
        $member = $this->person('engineer', ['name' => 'Ravi Patil']);
        $colleague = $this->person('pm');
        $this->traces($member, $project);
        $this->traces($colleague, $project);
        $run = AgentRun::withoutGlobalScopes()->where('user_id', $member->id)->sole();
        // The worker loaded the run before the deletion and is still waiting on the model.
        $held = AgentRun::withoutGlobalScopes()->findOrFail($run->id);

        $this->deleteMember($member)->assertOk();

        $job = Queue::pushed(ScrubDeletedAccount::class)->sole();
        $this->assertSame([$this->company->id, $member->id], [$job->organizationId, $job->userId]);
        $this->assertGreaterThan((new RunAgentStep(0))->timeout, $job->delay, 'It must wait out the longest agent step.');
        $this->assertGreaterThan((new ResolveAgentAction(0))->timeout, $job->delay);

        // The model answers after the scrub and the worker saves what it held, as AgentOrchestrator::record
        // and finish() do: a new step, the whole message list, the answer, and the daily brief's bell.
        DB::table('agent_steps')->insert(['organization_id' => $this->company->id, 'agent_run_id' => $run->id, 'number' => 2, 'model' => 'claude-sonnet-5-5', 'text' => 'Ravi asked about the slab.', 'created_at' => now(), 'updated_at' => now()]);
        $held->forceFill([
            'messages' => [...$held->messages, ['role' => 'assistant', 'content' => 'Ravi asked about the slab.']],
            'answer' => 'Ravi asked about the slab.',
            'output' => ['answer' => 'Ravi asked about the slab.'],
        ])->save();
        $member->notify(new ApprovalUpdate('Your daily brief is ready.', ''));
        $this->assertSame('Ravi asked about the slab.', $run->fresh()->answer);

        app()->call([$job, 'handle']);

        $run->refresh();
        $this->assertSame(['', [], null, null], [$run->input, $run->messages, $run->answer, $run->output]);
        $this->assertSame([null, null], DB::table('agent_steps')->where('agent_run_id', $run->id)->orderBy('number')->pluck('text')->all(), 'The steps stay for the credits, without their words.');
        $this->assertSame(0, DB::table('notifications')->where('notifiable_type', $member->getMorphClass())->where('notifiable_id', $member->id)->count());

        // Running it again changes nothing, and the colleague's run and bell are untouched throughout.
        app()->call([$job, 'handle']);
        $this->assertSame([], $run->fresh()->messages);
        $this->assertSame('Half the slab is cast.', AgentRun::withoutGlobalScopes()->where('user_id', $colleague->id)->value('answer'));
        $this->assertSame('Half the slab is cast.', DB::table('agent_steps')->whereIn('agent_run_id', AgentRun::withoutGlobalScopes()->where('user_id', $colleague->id)->select('id'))->value('text'));
        $this->assertSame(1, DB::table('notifications')->where('notifiable_id', $colleague->id)->count());
    }

    public function test_the_second_scrub_touches_only_a_person_that_company_deleted(): void
    {
        $project = $this->makeProject($this->company);
        $colleague = $this->person('pm');
        $member = $this->person();
        $this->traces($colleague, $project);
        $this->traces($member, $project);
        $this->deleteMember($member)->assertOk();
        // Written back after the deletion, as an agent step still running would.
        DB::table('agent_runs')->where('user_id', $member->id)->update(['answer' => 'Late answer.']);
        $rival = $this->tenant('Rival Constructions');

        // Someone not deleted, a deleted person named under the wrong company, and a person whose row has gone with their company.
        foreach ([[$this->company->id, $colleague->id], [$rival->id, $member->id], [$this->company->id, $member->id + 1000]] as [$organizationId, $userId]) {
            app()->call([new ScrubDeletedAccount($organizationId, $userId), 'handle']);
        }

        $this->assertSame('Half the slab is cast.', AgentRun::withoutGlobalScopes()->where('user_id', $colleague->id)->value('answer'));
        $this->assertSame(1, DB::table('notifications')->where('notifiable_id', $colleague->id)->count());
        $this->assertSame('Late answer.', AgentRun::withoutGlobalScopes()->where('user_id', $member->id)->value('answer'));

        app()->call([new ScrubDeletedAccount($this->company->id, $member->id), 'handle']);
        $this->assertNull(AgentRun::withoutGlobalScopes()->where('user_id', $member->id)->value('answer'));
    }

    // Holding the company lock

    public function test_members_are_told_only_once_the_callers_transaction_commits(): void
    {
        $engineer = $this->person();
        $companies = app(CompanyDeletionService::class);

        // As an account deletion asks, from inside its own transaction holding the company row.
        DB::transaction(function () use ($companies, $engineer) {
            $companies->request($this->company, $this->owner, 'Deccan Builders', 'account');
            $this->assertSame(0, $engineer->notifications()->count(), 'Nobody is told while the company row is still locked.');
        });
        $this->assertSame(1, $engineer->notifications()->count());

        // A cancel that is rolled back tells nobody.
        try {
            DB::transaction(function () use ($companies) {
                $companies->cancel($this->company, $this->owner);

                throw new \RuntimeException('Rolled back.');
            });
        } catch (\RuntimeException) {
        }

        $this->assertSame('pending', CompanyDeletionRequest::sole()->status);
        $this->assertSame(1, $engineer->notifications()->count());

        $this->assertTrue($companies->cancel($this->company, $this->owner));
        $this->assertSame(2, $engineer->notifications()->count());
    }

    public function test_the_lookup_indexes_roll_back_and_serve_the_lookups(): void
    {
        $indexes = [
            '2027_01_06_100001_add_organization_user_index_to_audit_logs.php' => 'audit_logs_organization_id_user_id_id_index',
            '2027_01_06_100002_add_lower_email_index_to_users.php' => 'users_email_lower_index',
        ];

        foreach ($indexes as $file => $index) {
            $this->assertTrue($this->indexExists($index), $index);

            $migration = require database_path('migrations/'.$file);
            $migration->down();
            $this->assertFalse($this->indexExists($index), $index);

            $migration->up();
            $this->assertTrue($this->indexExists($index), $index);
        }

        // Sign-in's lower(email) lookup is served by the index, so its expression matches the query.
        $lookup = User::whereRaw('lower(email) = ?', ['ravi@example.com'])->toRawSql();
        $plan = match (DB::getDriverName()) {
            'pgsql' => (function () use ($lookup) {
                DB::statement('SET LOCAL enable_seqscan = off');

                return json_encode(DB::select('EXPLAIN '.$lookup));
            })(),
            default => json_encode(DB::select('EXPLAIN QUERY PLAN '.$lookup)),
        };
        $this->assertStringContainsString('users_email_lower_index', $plan);
    }

    // Console audit

    public function test_a_deletion_support_schedules_is_on_the_companys_own_audit_trail(): void
    {
        $this->owner->forceFill(['email' => 'asha@example.com'])->save();
        $admin = $this->person('admin');
        $this->app['auth']->forgetGuards();

        $this->artisan('account:delete', ['email' => 'asha@example.com', '--force' => true])->expectsOutputToContain('Scheduled.')->assertSuccessful();

        $requested = AuditLog::withoutGlobalScopes()->where('action', 'company.deletion_requested')->sole();
        $this->assertSame([$this->company->id, null], [(int) $requested->organization_id, $requested->user_id]);

        // A cancel run with nobody signed in is filed under the company as well.
        app(CompanyDeletionService::class)->cancel($this->company, $admin);
        $this->assertSame($this->company->id, (int) AuditLog::withoutGlobalScopes()->where('action', 'company.deletion_cancelled')->sole()->organization_id);

        $this->actingAs($admin)->get('/audit')->assertOk()->assertSee('company.deletion_requested')->assertSee('company.deletion_cancelled');
    }

    /** The audit row written when this person's account itself was anonymised, not when their company was scheduled. */
    private function anonymisedLog(User $user): AuditLog
    {
        return AuditLog::withoutGlobalScopes()->where('action', 'account.deleted')->where('auditable_id', $user->id)->get()
            ->filter(fn (AuditLog $log) => $log->new_values['mode'] === 'account')->sole();
    }

    /** Whether an index of this name exists, read from the database's own catalogue. */
    private function indexExists(string $name): bool
    {
        return match (DB::getDriverName()) {
            'pgsql' => DB::table('pg_indexes')->where('indexname', $name)->exists(),
            default => DB::table('sqlite_master')->where('type', 'index')->where('name', $name)->exists(),
        };
    }

    /** Rows of every kind the deletion removes, so a test can show they go for one person only. */
    private function traces(User $user, Project $project): void
    {
        $org = $user->organization_id;
        $morph = $user->getMorphClass();
        $email = $user->email;
        $phone = $user->phone ?? '98'.random_int(10000000, 99999999);
        $this->traceKeys[$user->id] = ['email' => $email, 'phone' => $phone];
        $user->createToken('Old phone');

        DB::table('device_tokens')->insert(['organization_id' => $org, 'user_id' => $user->id, 'token_hash' => hash('sha256', Str::random()), 'token' => 'x', 'platform' => 'android', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('sessions')->insert(['id' => Str::random(40), 'user_id' => $user->id, 'ip_address' => '10.0.0.1', 'user_agent' => 'Chrome', 'payload' => 'x', 'last_activity' => time()]);
        DB::table('password_reset_tokens')->insert(['email' => $email, 'token' => 'x', 'created_at' => now()]);
        DB::table('otp_codes')->insert(['phone' => $phone, 'code_hash' => 'x', 'expires_at' => now()->addMinutes(5), 'created_at' => now(), 'updated_at' => now()]);
        $user->forceFill(['phone' => $phone])->save();
        $user->notify(new ApprovalUpdate('Approved', 'Your entry was approved.'));
        DB::table('sso_identities')->insert(['organization_id' => $org, 'user_id' => $user->id, 'provider' => 'google', 'subject' => Str::random(12), 'email' => $email, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('notification_preferences')->insert(['organization_id' => $org, 'user_id' => $user->id, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('notification_failures')->insert(['organization_id' => $org, 'user_id' => $user->id, 'channel' => 'email', 'event' => 'approval.decided', 'error' => "Mailbox {$email} full", 'created_at' => now()]);
        DB::table('push_receipts')->insert(['organization_id' => $org, 'user_id' => $user->id, 'dedupe_key' => 'k'.$user->id, 'event' => 'alert', 'claimed_at' => now()]);
        $user->assignedProjects()->attach($project->id, ['organization_id' => $org]);

        app(AgentRegistry::class)->sync();
        $agent = Agent::where('key', 'project_brain')->firstOrFail();
        $conversation = AgentConversation::withoutGlobalScopes()->create(['organization_id' => $org, 'agent_id' => $agent->id, 'user_id' => $user->id, 'project_id' => $project->id, 'title' => 'Slab progress']);
        $run = AgentRun::withoutGlobalScopes()->create([
            'organization_id' => $org, 'agent_id' => $agent->id, 'agent_version_id' => $agent->current_version_id, 'user_id' => $user->id,
            'project_id' => $project->id, 'agent_conversation_id' => $conversation->id, 'status' => AgentRun::COMPLETED, 'autonomy' => 'L0',
            'model' => 'claude-sonnet-5-5', 'input' => 'How much slab is done? Ask Ravi.', 'messages' => [['role' => 'user', 'content' => 'How much slab is done?']],
            'answer' => 'Half the slab is cast.', 'output' => ['answer' => 'Half the slab is cast.'], 'sources' => [],
            'max_steps' => 5, 'max_seconds' => 60, 'max_credits' => '1.0000', 'credits_used' => '0.2500', 'started_at' => now(), 'finished_at' => now(),
        ]);
        DB::table('agent_steps')->insert(['organization_id' => $org, 'agent_run_id' => $run->id, 'number' => 1, 'model' => 'claude-sonnet-5-5', 'text' => 'Half the slab is cast.', 'created_at' => now(), 'updated_at' => now()]);

        DeviceLicence::withoutGlobalScopes()->create([
            'organization_id' => $org, 'user_id' => $user->id, 'device_id' => 'phone-'.$user->id, 'device_name' => 'Pixel 8', 'status' => DeviceLicence::ACTIVE,
            'slot' => $user->id, 'activated_at' => now(), 'expires_at' => now()->addDays(30),
        ]);
    }

    /** @return array<string, int> */
    private function sideTables(User $user): array
    {
        $user = $user->fresh();
        $keys = $this->traceKeys[$user->id];

        return [
            'personal_access_tokens' => DB::table('personal_access_tokens')->where('tokenable_type', $user->getMorphClass())->where('tokenable_id', $user->id)->count(),
            'device_tokens' => DB::table('device_tokens')->where('user_id', $user->id)->count(),
            'sessions' => DB::table('sessions')->where('user_id', $user->id)->count(),
            'notifications' => DB::table('notifications')->where('notifiable_type', $user->getMorphClass())->where('notifiable_id', $user->id)->count(),
            'sso_identities' => DB::table('sso_identities')->where('user_id', $user->id)->count(),
            'notification_preferences' => DB::table('notification_preferences')->where('user_id', $user->id)->count(),
            'notification_failures' => DB::table('notification_failures')->where('user_id', $user->id)->count(),
            'push_receipts' => DB::table('push_receipts')->where('user_id', $user->id)->count(),
            'user_project_access' => DB::table('user_project_access')->where('user_id', $user->id)->count(),
            'agent_conversations' => DB::table('agent_conversations')->where('user_id', $user->id)->count(),
            'password_reset_tokens' => DB::table('password_reset_tokens')->where('email', $keys['email'])->count(),
            'otp_codes' => DB::table('otp_codes')->where('phone', $keys['phone'])->count(),
        ];
    }
}
