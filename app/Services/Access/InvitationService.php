<?php

namespace App\Services\Access;

use App\Exceptions\InvitationClosedException;
use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\Project;
use App\Models\TeamInvitation;
use App\Models\User;
use App\Notifications\TeamInvite;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Invitation links are signed, expire, and work once. Only a hash of the token is
 * stored, so the database alone cannot produce a working link.
 */
class InvitationService
{
    public const VALID_DAYS = 7;

    public function __construct(private TeamAccessService $access) {}

    /** Pending invitations hold a seat, so a company cannot invite past its plan limit. */
    public function hasSeat(Organization $organization, ?TeamInvitation $except = null): bool
    {
        $max = $organization->planConfig()['max_users'];

        if ($max === null) {
            return true;
        }

        $users = User::where('organization_id', $organization->id)->count();
        $pending = TeamInvitation::withoutGlobalScopes()
            ->where('organization_id', $organization->id)
            ->pending()
            ->when($except, fn ($query) => $query->whereKeyNot($except->id))
            ->count();

        return $users + $pending < $max;
    }

    /** @return array{invitation: TeamInvitation, link: string, emailed: bool} */
    public function invite(User $actor, array $data): array
    {
        abort_unless($this->access->canAssign($actor, $data['role']), 403, __('You cannot give a role with more access than you have.'));

        $email = Str::lower(trim($data['email']));

        if ($this->emailTaken($email)) {
            throw ValidationException::withMessages(['email' => __('This email already has an account.')]);
        }

        $token = Str::random(64);

        $invitation = DB::transaction(function () use ($actor, $data, $email, $token) {
            $organization = Organization::lockForUpdate()->findOrFail($actor->organization_id);

            // A second invitation to the same person replaces the first, so it cannot hold two seats.
            TeamInvitation::where('organization_id', $organization->id)->where('email', $email)->pending()->update(['revoked_at' => now()]);

            abort_unless($this->hasSeat($organization), 403, __('Your plan user limit has been reached. Upgrade to add more.'));

            $invitation = TeamInvitation::create([
                'organization_id' => $organization->id,
                'email' => $email,
                'phone' => $data['phone'] ?? null,
                'role_key' => $data['role'],
                'limited_to_projects' => $data['limited'],
                'project_ids' => $data['limited'] ? array_map('intval', $data['project_ids']) : null,
                'token_hash' => $this->hash($token),
                'invited_by' => $actor->id,
                'expires_at' => now()->addDays(self::VALID_DAYS),
            ]);

            AuditLog::record('invitation.created', $invitation, null, ['email' => $email, 'role' => $data['role']]);

            return $invitation;
        });

        return $this->issued($invitation, $token);
    }

    /** Replace the link. The old one stops working at once. */
    public function reissue(User $actor, TeamInvitation $invitation): array
    {
        abort_unless($this->access->canAssign($actor, $invitation->role_key), 403, __('You cannot give a role with more access than you have.'));

        $token = Str::random(64);

        $invitation = DB::transaction(function () use ($actor, $invitation, $token) {
            $organization = Organization::lockForUpdate()->findOrFail($actor->organization_id);
            $invitation = TeamInvitation::lockForUpdate()->findOrFail($invitation->id);

            if ($invitation->accepted_at !== null || $invitation->revoked_at !== null) {
                throw ValidationException::withMessages(['invitation' => __('This invitation has already been used or cancelled.')]);
            }

            abort_unless($this->hasSeat($organization, $invitation), 403, __('Your plan user limit has been reached. Upgrade to add more.'));

            $invitation->forceFill(['token_hash' => $this->hash($token), 'expires_at' => now()->addDays(self::VALID_DAYS)])->save();
            AuditLog::record('invitation.reissued', $invitation, null, ['email' => $invitation->email]);

            return $invitation;
        });

        return $this->issued($invitation, $token);
    }

    public function revoke(User $actor, TeamInvitation $invitation): void
    {
        abort_unless($this->access->canAssign($actor, $invitation->role_key), 403, __('You cannot give a role with more access than you have.'));

        DB::transaction(function () use ($invitation) {
            $invitation = TeamInvitation::lockForUpdate()->findOrFail($invitation->id);

            if ($invitation->accepted_at === null && $invitation->revoked_at === null) {
                $invitation->forceFill(['revoked_at' => now()])->save();
                AuditLog::record('invitation.revoked', $invitation, null, ['email' => $invitation->email]);
            }
        });
    }

    /** The invitation behind a link, if it can still be used. Public code: filters itself. */
    public function findPending(string $token): ?TeamInvitation
    {
        $invitation = TeamInvitation::withoutGlobalScopes()->where('token_hash', $this->hash($token))->first();

        return $invitation?->isPending() ? $invitation : null;
    }

    /** @return ?string The role's name, for showing on the invitation. */
    public function roleLabel(TeamInvitation $invitation): ?string
    {
        return $this->access->describeRole($invitation->role_key, $invitation->organization_id)['label'] ?? null;
    }

    /**
     * Create the account. The row lock makes the link single-use, and everything
     * the invitation grants is checked again because time has passed since it was sent.
     *
     * @throws InvitationClosedException
     */
    public function accept(string $token, array $data): User
    {
        return DB::transaction(function () use ($token, $data) {
            $invitation = TeamInvitation::withoutGlobalScopes()->where('token_hash', $this->hash($token))->lockForUpdate()->first();

            if (! $invitation?->isPending()) {
                throw new InvitationClosedException(__('This invitation has expired or has already been used.'));
            }

            $organization = Organization::lockForUpdate()->findOrFail($invitation->organization_id);

            if (! $organization->canAddUser()) {
                throw new InvitationClosedException(__('This company has reached its plan user limit. Ask them to upgrade and send a new invitation.'));
            }

            if ($this->emailTaken($invitation->email)) {
                throw new InvitationClosedException(__('This email already has an account. Please sign in.'));
            }

            $role = $this->access->describeRole($invitation->role_key, $organization->id);
            $inviter = User::find($invitation->invited_by);

            if ($role === null || ! $inviter?->is_active || ! $inviter->hasPermission('user.manage') || ! $this->access->canAssign($inviter, $role['key'])) {
                throw new InvitationClosedException(__('This invitation is no longer valid. Please ask for a new one.'));
            }

            $user = new User;
            $user->forceFill([
                'organization_id' => $organization->id,
                'name' => $data['name'],
                'email' => $invitation->email,
                'phone' => $this->freePhone($invitation->phone),
                'role' => $role['role'],
                'custom_role_id' => $role['custom_role_id'],
                'password' => $data['password'],
            ])->save();

            $this->access->applyProjects($user, $invitation->limited_to_projects, $this->liveProjectIds($invitation, $organization->id));

            $invitation->forceFill(['accepted_at' => now(), 'accepted_user_id' => $user->id])->save();
            AuditLog::record('invitation.accepted', $invitation, null, ['email' => $invitation->email, 'user_id' => $user->id]);

            return $user;
        });
    }

    private function issued(TeamInvitation $invitation, string $token): array
    {
        $link = URL::temporarySignedRoute('invitations.show', $invitation->expires_at, ['token' => $token]);

        return ['invitation' => $invitation, 'link' => $link, 'emailed' => $this->email($invitation, $link)];
    }

    /** Only reports true when a real mailer took the message. */
    private function email(TeamInvitation $invitation, string $link): bool
    {
        if (in_array(config('mail.default'), ['log', 'array'], true)) {
            return false;
        }

        try {
            Notification::route('mail', $invitation->email)->notify(new TeamInvite(
                Organization::findOrFail($invitation->organization_id)->name,
                (string) $this->roleLabel($invitation),
                $link,
                self::VALID_DAYS,
            ));

            return true;
        } catch (Throwable $exception) {
            report($exception);

            return false;
        }
    }

    private function emailTaken(string $email): bool
    {
        return User::whereRaw('lower(email) = ?', [$email])->exists();
    }

    /** Someone else may have taken this number since the invitation was sent. */
    private function freePhone(?string $phone): ?string
    {
        return $phone !== null && ! User::where('phone', $phone)->exists() ? $phone : null;
    }

    /** @return list<int> Projects chosen on the invitation that still exist in this company. */
    private function liveProjectIds(TeamInvitation $invitation, int $organizationId): array
    {
        return Project::withoutGlobalScopes()
            ->where('organization_id', $organizationId)
            ->whereNull('deleted_at')
            ->whereIn('id', $invitation->project_ids ?? [])
            ->pluck('id')
            ->all();
    }

    private function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}
