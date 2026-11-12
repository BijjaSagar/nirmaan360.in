<?php

namespace App\Services\ContractorPortal;

use App\Models\AuditLog;
use App\Models\Party;
use App\Models\TeamInvitation;
use App\Models\User;
use App\Services\Access\InvitationService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Who may sign in as a contractor. Invitations reuse the team invitation flow with the contractor
 * role and the subcontractor's id; logins are taken away by deactivating the person, which also
 * signs out every device. Nothing here touches the plan's user limit.
 */
class ContractorAccessService
{
    public function __construct(private InvitationService $invitations) {}

    public function rules(): array
    {
        return [
            'email' => ['required', 'email', 'max:190'],
            'phone' => ['nullable', 'string', 'max:20', 'unique:users,phone'],
        ];
    }

    /**
     * The logins and pending invitations of one subcontractor.
     *
     * @return array{logins: Collection<int, User>, invitations: Collection<int, TeamInvitation>}
     */
    public function state(Party $party): array
    {
        return [
            'logins' => User::where('organization_id', $party->organization_id)
                ->where('role', User::CONTRACTOR_ROLE)
                ->where('contractor_party_id', $party->id)
                ->whereNull('anonymised_at')
                ->orderBy('name')
                ->get(),
            'invitations' => TeamInvitation::where('organization_id', $party->organization_id)
                ->where('role_key', User::CONTRACTOR_ROLE)
                ->where('contractor_party_id', $party->id)
                ->pending()
                ->latest('id')
                ->get(),
        ];
    }

    /**
     * Invite someone to sign in for this subcontractor. The link works once and expires, as for the team.
     *
     * @param  array{email: string, phone?: ?string}  $data
     * @return array{invitation: TeamInvitation, link: string, emailed: bool}
     */
    public function invite(User $actor, Party $party, array $data): array
    {
        $this->assertOwnSubcontractor($actor, $party);

        return $this->invitations->invite($actor, [
            'email' => $data['email'],
            'phone' => $data['phone'] ?? null,
            'role' => User::CONTRACTOR_ROLE,
            'limited' => false,
            'project_ids' => [],
            'contractor_party_id' => $party->id,
        ]);
    }

    /** @return array{invitation: TeamInvitation, link: string, emailed: bool} */
    public function reissue(User $actor, Party $party, TeamInvitation $invitation): array
    {
        $this->assertOwnSubcontractor($actor, $party);
        abort_unless($this->belongs($invitation, $party), 404);

        return $this->invitations->reissue($actor, $invitation);
    }

    public function cancel(User $actor, Party $party, TeamInvitation $invitation): void
    {
        $this->assertOwnSubcontractor($actor, $party);
        abort_unless($this->belongs($invitation, $party), 404);

        $this->invitations->revoke($actor, $invitation);
    }

    /** Take the login away. The person stays on record, signed out everywhere, and can be restored. */
    public function revoke(User $actor, Party $party, User $login): void
    {
        $this->setActive($actor, $party, $login, false);
    }

    public function restore(User $actor, Party $party, User $login): void
    {
        $this->setActive($actor, $party, $login, true);
    }

    private function setActive(User $actor, Party $party, User $login, bool $active): void
    {
        $this->assertOwnSubcontractor($actor, $party);
        abort_unless($login->organization_id === $party->organization_id && $login->isContractor() && (int) $login->contractor_party_id === (int) $party->id, 404);

        DB::transaction(function () use ($party, $login, $active) {
            $login = User::lockForUpdate()->findOrFail($login->id);

            if ((bool) $login->is_active === $active) {
                return;
            }

            $login->forceFill(['is_active' => $active, 'remember_token' => Str::random(60), 'access_changed_at' => now()])->save();
            $login->tokens()->delete();

            AuditLog::record(
                $active ? 'contractor_portal.access_restored' : 'contractor_portal.access_revoked',
                $login,
                ['is_active' => ! $active],
                ['is_active' => $active, 'party_id' => $party->id, 'party' => $party->name],
            );
        });
    }

    /** Only a live subcontractor of the actor's own company can be given a login. */
    private function assertOwnSubcontractor(User $actor, Party $party): void
    {
        $own = Party::withoutGlobalScopes()
            ->where('organization_id', $actor->organization_id)
            ->where('type', 'subcontractor')
            ->whereNull('deleted_at')
            ->whereKey($party->id)
            ->exists();

        if (! $own) {
            throw ValidationException::withMessages(['party' => __('Choose a subcontractor from your own contacts.')]);
        }
    }

    private function belongs(TeamInvitation $invitation, Party $party): bool
    {
        return $invitation->organization_id === $party->organization_id
            && $invitation->role_key === User::CONTRACTOR_ROLE
            && (int) $invitation->contractor_party_id === (int) $party->id;
    }
}
