<?php

namespace App\Services\Access;

use App\Exceptions\AccountDeletionChanged;
use App\Jobs\ScrubDeletedAccount;
use App\Models\AuditLog;
use App\Models\CompanyDeletionRequest;
use App\Models\DeviceLicence;
use App\Models\Organization;
use App\Models\ReportSchedule;
use App\Models\TeamInvitation;
use App\Models\User;
use App\Services\Notify\CompanyDeletionService;
use App\Services\OfflinePlan\DeviceLicenceService;
use Carbon\CarbonInterface;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * A person deletes their own account.
 *
 * - Anyone the company can do without is anonymised at once: the row stays so every record they made keeps
 *   its author, shown as "Deleted user", and everything that identifies or reaches them is removed.
 * - The only active owner cannot leave a company with no owner, so their request deletes the company,
 *   after the usual grace period, through CompanyDeletionService.
 *
 * The server decides which of the two applies, under a lock on the company, and refuses when it is not
 * the one the person was shown.
 */
class AccountDeletionService
{
    public const MODE_ACCOUNT = 'account';

    public const MODE_COMPANY = 'company';

    public const MODES = [self::MODE_ACCOUNT, self::MODE_COMPANY];

    public function __construct(
        private CompanyDeletionService $companies,
        private TeamAccessService $access,
        private DeviceLicenceService $licences,
    ) {}

    /** 'company' only for the company's last active owner; everyone else is deleted on their own. */
    public function modeFor(User $user): string
    {
        $soleOwner = $user->organization_id !== null
            && $this->isOwner($user)
            && $user->is_active
            && ! $this->access->hasOtherActiveOwner($user);

        return $soleOwner ? self::MODE_COMPANY : self::MODE_ACCOUNT;
    }

    /** An owner by built-in role, as the Company data page requires. */
    public function isOwner(User $user): bool
    {
        return $user->role === 'owner' && $user->custom_role_id === null;
    }

    /**
     * What deleting this account would do, for the person to read before they confirm.
     *
     * @return array{mode: string, company_name: string, other_people: int, requires_confirm_name: bool, grace_days: int, erase_after: ?CarbonInterface, pending: ?array{erase_after: CarbonInterface, can_cancel: bool}}
     */
    public function preview(User $user): array
    {
        $mode = $this->modeFor($user);
        $pending = $user->organization_id === null ? null : $this->companies->pending($user->organization_id);
        $company = $user->organization_id === null ? null : Organization::find($user->organization_id);

        return [
            'mode' => $mode,
            'company_name' => (string) $company?->name,
            // Contractor logins lose access with the company too, so they are counted.
            'other_people' => $company === null ? 0 : User::where('organization_id', $company->id)->where('is_active', true)->whereKeyNot($user->id)->count(),
            'requires_confirm_name' => $mode === self::MODE_COMPANY,
            'grace_days' => CompanyDeletionService::GRACE_DAYS,
            // Asking again while a deletion is pending keeps its date, so that is the date shown.
            'erase_after' => $mode === self::MODE_COMPANY ? ($pending?->purge_after ?? now()->addDays(CompanyDeletionService::GRACE_DAYS)) : null,
            'pending' => $pending === null ? null : ['erase_after' => $pending->purge_after, 'can_cancel' => $this->isOwner($user)],
        ];
    }

    /**
     * The person's own request, from the app or the website, confirmed with their password.
     *
     * @param  string  $via  'app' or 'web'
     * @return array{result: 'deleted'}|array{result: 'scheduled', erase_after: CarbonInterface}
     *
     * @throws AccountDeletionChanged when the mode sent is no longer the one that applies
     * @throws ValidationException on a wrong password or company name
     */
    public function deleteAccount(User $user, string $mode, string $password, ?string $confirmName, string $via): array
    {
        return DB::transaction(function () use ($user, $mode, $password, $confirmName, $via) {
            $organization = $this->lockCompany($user);
            $user->refresh();

            if ($user->anonymised_at !== null) {
                return ['result' => 'deleted'];
            }

            if ($this->modeFor($user) !== $mode) {
                throw new AccountDeletionChanged;
            }

            if (! Hash::check($password, $user->password)) {
                throw ValidationException::withMessages(['password' => __('That password is not right.')]);
            }

            if ($mode === self::MODE_ACCOUNT) {
                $this->anonymise($user, $via);

                return ['result' => 'deleted'];
            }

            return ['result' => 'scheduled', 'erase_after' => $this->scheduleCompany($organization, $user, (string) $confirmName, $via)->purge_after];
        });
    }

    /**
     * Support deletes an account for someone who cannot sign in, after confirming the request by email.
     * No password; for the only owner the company's own name is the confirmation.
     *
     * @return array{result: 'deleted'}|array{result: 'scheduled', erase_after: CarbonInterface}
     */
    public function deleteBySupport(User $user): array
    {
        return DB::transaction(function () use ($user) {
            $organization = $this->lockCompany($user);
            $user->refresh();

            if ($user->anonymised_at !== null) {
                return ['result' => 'deleted'];
            }

            if ($this->modeFor($user) === self::MODE_ACCOUNT) {
                $this->anonymise($user, 'support');

                return ['result' => 'deleted'];
            }

            return ['result' => 'scheduled', 'erase_after' => $this->scheduleCompany($organization, $user, $organization->name, 'support')->purge_after];
        });
    }

    /** @return 'cancelled'|'none' */
    public function cancelCompanyDeletion(User $user): string
    {
        abort_unless($this->isOwner($user), 403, __('Only a company owner can do this.'));

        return $this->companies->cancel($user->organization, $user) ? 'cancelled' : 'none';
    }

    /**
     * Called by CompanyDeletionService::cancel inside its transaction. The only owner asked for their own
     * account to go; another owner kept the company, so the company no longer needs the requester and
     * their wish is carried out now.
     */
    public function afterCompanyDeletionCancelled(CompanyDeletionRequest $request, User $by): void
    {
        if ($request->requested_via !== 'account' || $request->requested_by === null || (int) $request->requested_by === (int) $by->id) {
            return;
        }

        Organization::whereKey($request->organization_id)->lockForUpdate()->first();

        $requester = User::where('organization_id', $request->organization_id)->find($request->requested_by);

        // A requester another owner has since deactivated still asked to go, and can no longer sign in to ask again.
        if ($requester === null || $requester->anonymised_at !== null || $this->modeFor($requester) !== self::MODE_ACCOUNT) {
            return;
        }

        $this->anonymise($requester, 'support');
    }

    private function lockCompany(User $user): ?Organization
    {
        return $user->organization_id === null ? null : Organization::lockForUpdate()->findOrFail($user->organization_id);
    }

    /**
     * The only owner: the company is erased after the grace period, and they are signed out everywhere now.
     * Their row stays as it is, because the company still needs an owner who can sign in and cancel.
     */
    private function scheduleCompany(Organization $organization, User $user, string $confirmName, string $via): CompanyDeletionRequest
    {
        $request = $this->companies->request($organization, $user, $confirmName, 'account');

        // A request already pending, from the Company data page or by an owner who has since gone, now
        // stands for this person's account: a later cancel by another owner must delete them, and the
        // purge must email them. Whoever asked first stays on record in the company.deletion_requested audit row.
        if (! $request->wasRecentlyCreated && ((int) $request->requested_by !== (int) $user->id || $request->requested_via !== 'account')) {
            $request->update(['requested_by' => $user->id, 'requested_via' => 'account']);
        }

        $this->signOutEverywhere($user);
        $this->freeLicences($user);

        AuditLog::record('account.deleted', $user, null, ['mode' => self::MODE_COMPANY, 'via' => $via], actor: $via === 'support' ? null : $user);

        return $request;
    }

    /** Recipe B.2 of the deletion spec. Only the users row and rows about this person change; no business record is touched. */
    private function anonymise(User $user, string $via): void
    {
        $oldEmail = (string) $user->email;
        $oldPhone = $user->phone;
        $placeholder = $this->placeholderEmail($user);

        $user->forceFill([
            'name' => 'Deleted user',
            'email' => $placeholder,
            'phone' => null,
            'password' => Str::random(64),
            'remember_token' => Str::random(60),
            'is_active' => false,
            'email_verified_at' => null,
            'last_login_at' => null,
            'is_platform_admin' => false,
            // A built-in role with no reach, so a custom role they held can be deleted.
            'role' => 'viewer',
            'custom_role_id' => null,
            'limited_to_projects' => false,
            'contractor_party_id' => null,
            'access_changed_at' => now(),
            'anonymised_at' => now(),
        ])->save();
        $user->unsetRelation('customRole');

        $this->removeSideRows($user, $oldEmail, $oldPhone);

        if ($user->organization_id !== null) {
            $this->scrubCopies($user, $oldEmail, $placeholder);

            // An agent step already waiting on the model writes its question and answer back when it ends.
            ScrubDeletedAccount::dispatch($user->organization_id, $user->id)->delay(ScrubDeletedAccount::DELAY_SECONDS)->afterCommit();
        }

        AuditLog::record('account.deleted', $user, null, ['mode' => self::MODE_ACCOUNT, 'via' => $via], actor: $via === 'support' ? null : $user);
    }

    /**
     * Run by ScrubDeletedAccount once every agent step that was running at the deletion has ended. Such a
     * step saves the question and the model's answer back onto the run, and a scheduled brief that finishes
     * then rings the bell again; both are cleared once more. Only a person this company has deleted is
     * touched, so a second run, or one after the company itself was erased, changes nothing.
     */
    public function scrubLateWrites(int $organizationId, int $userId): void
    {
        $user = User::where('organization_id', $organizationId)->whereKey($userId)->whereNotNull('anonymised_at')->first();

        if ($user === null) {
            return;
        }

        $this->scrubAgentRuns($organizationId, $userId);
        DB::table('notifications')->where('notifiable_type', $user->getMorphClass())->where('notifiable_id', $userId)->delete();
    }

    /** What they asked the company's agents and what came back. The runs stay for the credits spent. */
    private function scrubAgentRuns(int $organizationId, int $userId): void
    {
        $runs = DB::table('agent_runs')->where('organization_id', $organizationId)->where('user_id', $userId);
        DB::table('agent_steps')->where('organization_id', $organizationId)->whereIn('agent_run_id', (clone $runs)->select('id'))->update(['text' => null]);
        (clone $runs)->update(['input' => '', 'messages' => '[]', 'answer' => null, 'output' => null]);
    }

    /** Ways in, ways to reach them, and what was only theirs. Plain queries, so nothing writes audit rows full of the old values. */
    private function removeSideRows(User $user, string $oldEmail, ?string $oldPhone): void
    {
        $user->tokens()->delete();
        DB::table('device_tokens')->where('user_id', $user->id)->delete();
        DB::table('sessions')->where('user_id', $user->id)->delete();
        DB::table('password_reset_tokens')->whereRaw('lower(email) = ?', [User::normaliseEmail($oldEmail)])->delete();

        if ($oldPhone !== null && $oldPhone !== '') {
            DB::table('otp_codes')->where('phone', $oldPhone)->delete();
        }

        DB::table('notifications')->where('notifiable_type', $user->getMorphClass())->where('notifiable_id', $user->id)->delete();
        DB::table('sso_identities')->where('user_id', $user->id)->delete();
        DB::table('notification_preferences')->where('user_id', $user->id)->delete();
        DB::table('notification_failures')->where('user_id', $user->id)->delete();
        DB::table('push_receipts')->where('user_id', $user->id)->delete();
        $user->assignedProjects()->detach();
        // Their assistant chats. The runs stay, unlinked, because they carry the credits the company spent.
        DB::table('agent_conversations')->where('user_id', $user->id)->delete();
    }

    /** Copies of their name, email or words that live in rows the company keeps. */
    private function scrubCopies(User $user, string $oldEmail, string $placeholder): void
    {
        $organizationId = $user->organization_id;
        $email = User::normaliseEmail($oldEmail);

        $this->scrubAgentRuns($organizationId, $user->id);

        // Their own invitation, and any earlier one sent to the same address in this company.
        $invitations = DB::table('team_invitations')
            ->where('organization_id', $organizationId)
            ->where(fn ($query) => $query->where('accepted_user_id', $user->id)->orWhereRaw('lower(email) = ?', [$email]))
            ->pluck('id');
        DB::table('team_invitations')->whereIn('id', $invitations)->update(['email' => $placeholder, 'phone' => null, 'updated_at' => now()]);

        // Invitations they sent would be refused at acceptance anyway, because the inviter is inactive.
        DB::table('team_invitations')
            ->where('organization_id', $organizationId)
            ->where('invited_by', $user->id)
            ->whereNull('accepted_at')
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now())
            ->update(['revoked_at' => now(), 'updated_at' => now()]);

        $this->dropReportRecipient($organizationId, $email);
        $this->freeLicences($user);

        // The approval trail keeps who acted by id, which now reads "Deleted user"; the copied name goes.
        AuditLog::withoutGlobalScopes()
            ->where('organization_id', $organizationId)
            ->where('user_id', $user->id)
            ->where('action', 'like', 'approval.%')
            ->chunkById(200, function ($logs) {
                foreach ($logs as $log) {
                    if (is_array($log->new_values) && array_key_exists('actor', $log->new_values)) {
                        $log->new_values = Arr::except($log->new_values, ['actor']);
                        $log->saveQuietly();
                    }
                }
            });

        AuditLog::withoutGlobalScopes()
            ->where('organization_id', $organizationId)
            ->where('auditable_type', (new TeamInvitation)->getMorphClass())
            ->whereIn('auditable_id', $invitations)
            ->chunkById(200, function ($logs) use ($placeholder) {
                foreach ($logs as $log) {
                    $changed = false;

                    foreach (['old_values', 'new_values'] as $column) {
                        $values = $log->{$column};

                        if (is_array($values) && array_key_exists('email', $values)) {
                            $values['email'] = $placeholder;
                            $log->{$column} = $values;
                            $changed = true;
                        }
                    }

                    if ($changed) {
                        $log->saveQuietly();
                    }
                }
            });
    }

    /**
     * Report schedules mail whatever addresses were typed in, so theirs is taken out of every list in
     * the company, and out of the audit copies of those lists. Saved quietly: the Auditable trait would
     * otherwise write the old list, address and all, into a new audit row. A schedule that mailed only
     * them is paused, and says why, since there is nobody left to send it to.
     */
    private function dropReportRecipient(int $organizationId, string $email): void
    {
        $keep = fn (mixed $list) => is_array($list)
            ? array_values(array_filter($list, fn ($address) => ! is_string($address) || User::normaliseEmail($address) !== $email))
            : $list;

        ReportSchedule::withoutGlobalScopes()->where('organization_id', $organizationId)->chunkById(200, function ($schedules) use ($keep) {
            foreach ($schedules as $schedule) {
                $recipients = $keep($schedule->recipients);

                if ($recipients !== $schedule->recipients) {
                    $schedule->recipients = $recipients;

                    if ($recipients === []) {
                        $schedule->active = false;
                        $schedule->last_status = 'no_recipients';
                    }

                    $schedule->saveQuietly();
                }
            }
        });

        AuditLog::withoutGlobalScopes()
            ->where('organization_id', $organizationId)
            ->where('auditable_type', (new ReportSchedule)->getMorphClass())
            ->chunkById(200, function ($logs) use ($keep) {
                foreach ($logs as $log) {
                    $changed = false;

                    foreach (['old_values', 'new_values'] as $column) {
                        $values = $log->{$column};

                        if (! is_array($values) || ! array_key_exists('recipients', $values)) {
                            continue;
                        }

                        // Auditable stores the column as it is held, which for a JSON column is the encoded text.
                        $encoded = is_string($values['recipients']);
                        $list = $encoded ? json_decode($values['recipients'], true) : $values['recipients'];
                        $kept = $keep($list);

                        if ($kept !== $list) {
                            $values['recipients'] = $encoded ? json_encode($kept) : $kept;
                            $log->{$column} = $values;
                            $changed = true;
                        }
                    }

                    if ($changed) {
                        $log->saveQuietly();
                    }
                }
            });
    }

    /** Their phones stop holding the company's offline-plan slots. */
    private function freeLicences(User $user): void
    {
        $licences = DeviceLicence::withoutGlobalScopes()
            ->where('organization_id', $user->organization_id)
            ->where('user_id', $user->id)
            ->where('status', DeviceLicence::ACTIVE)
            ->get();

        foreach ($licences as $licence) {
            $this->licences->free($licence, $user);
        }
    }

    /** Signed out of the website and the app on every device, without changing who they are. */
    private function signOutEverywhere(User $user): void
    {
        $user->tokens()->delete();
        DB::table('device_tokens')->where('user_id', $user->id)->delete();
        DB::table('sessions')->where('user_id', $user->id)->delete();
        $user->forceFill(['remember_token' => Str::random(60)])->save();
    }

    /**
     * Unique to this deletion and impossible to guess, so nobody can hold the address first and make the
     * deletion fail on the unique email index. Lower case, as every stored email is. Sign-up and
     * invitations refuse every .invalid address as well.
     */
    private function placeholderEmail(User $user): string
    {
        return 'deleted-'.$user->id.'-'.Str::lower(Str::random(24)).'@deleted.invalid';
    }
}
