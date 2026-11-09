<?php

namespace App\Services\Access;

use App\Models\AuditLog;
use App\Models\CustomRole;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Who may give whom which role and which projects. */
class TeamAccessService
{
    public function __construct(private PermissionCatalog $catalog) {}

    /** @return array<string, string> Role key => label, for the roles this person may hand out. */
    public function roleOptions(User $actor): array
    {
        $held = $actor->grantedPermissions();
        $options = [];

        foreach (config('buildos.roles') as $key => $role) {
            // A portal role, such as a contractor login, is handed out from the record it belongs to, never from the team screen.
            if (($role['portal'] ?? false) || ! $this->catalog->coversAll($held, $role['permissions'])) {
                continue;
            }

            $options[$key] = __($role['label'] ?? $key);
        }

        $customRoles = CustomRole::withoutGlobalScopes()->where('organization_id', $actor->organization_id)->orderBy('name')->get();

        foreach ($customRoles as $role) {
            if ($this->catalog->coversAll($held, $role->permissions)) {
                $options[$role->key()] = $role->name;
            }
        }

        return $options;
    }

    /**
     * What a role key means inside one company. Null when it means nothing there,
     * which is how another company's custom role is refused.
     *
     * @return array{key: string, role: string, custom_role_id: ?int, permissions: list<string>, label: string, portal: bool, managed_by: ?string}|null
     */
    public function describeRole(string $key, int $organizationId): ?array
    {
        if (str_starts_with($key, CustomRole::KEY_PREFIX)) {
            $id = substr($key, strlen(CustomRole::KEY_PREFIX));
            $role = ctype_digit($id)
                ? CustomRole::withoutGlobalScopes()->where('organization_id', $organizationId)->find((int) $id)
                : null;

            return $role ? [
                'key' => $key,
                'role' => $role->baseRole(),
                'custom_role_id' => $role->id,
                'permissions' => $role->permissions,
                'label' => $role->name,
                'portal' => false,
                'managed_by' => null,
            ] : null;
        }

        $role = config('buildos.roles')[$key] ?? null;

        return $role ? [
            'key' => $key,
            'role' => $key,
            'custom_role_id' => null,
            'permissions' => $role['permissions'],
            'label' => $role['label'] ?? $key,
            'portal' => (bool) ($role['portal'] ?? false),
            'managed_by' => $role['managed_by'] ?? null,
        ] : null;
    }

    /** A team role needs every permission it grants; a portal role needs the one permission that hands it out. */
    public function canAssign(User $actor, string $key): bool
    {
        $role = $this->describeRole($key, $actor->organization_id);

        if ($role === null) {
            return false;
        }

        if ($role['portal']) {
            return $role['managed_by'] !== null && $actor->hasPermission($role['managed_by']);
        }

        return $this->catalog->coversAll($actor->grantedPermissions(), $role['permissions']);
    }

    /** Whether this person may send, and still stands behind, an invitation for this role. */
    public function canInvite(User $actor, string $key): bool
    {
        $role = $this->describeRole($key, $actor->organization_id);

        if ($role === null) {
            return false;
        }

        return $role['portal'] ? $this->canAssign($actor, $key) : ($actor->hasPermission('user.manage') && $this->canAssign($actor, $key));
    }

    /** Nobody may act on a person who holds more than they do. Contractor logins are not team members and are managed from the contractor's page. */
    public function canManage(User $actor, User $target): bool
    {
        return $actor->organization_id === $target->organization_id
            && ! $target->isContractor()
            && $this->catalog->coversAll($actor->grantedPermissions(), $target->grantedPermissions());
    }

    public function assertCanManage(User $actor, User $target): void
    {
        abort_unless($actor->organization_id === $target->organization_id && ! $target->isContractor(), 404);
        abort_unless($this->canManage($actor, $target), 403, __('This person has more access than you do.'));
    }

    /**
     * Change a member's role and active state, and their projects when $projects is given.
     *
     * @param  array{limited: bool, ids: list<int>}|null  $projects  Null leaves their projects as they are.
     */
    public function update(User $actor, User $target, string $roleKey, bool $active, ?array $projects = null): void
    {
        $this->assertCanManage($actor, $target);

        $role = $this->describeRole($roleKey, $target->organization_id);

        if ($role === null) {
            throw ValidationException::withMessages(['role' => __('Choose a valid role.')]);
        }

        if ($role['portal']) {
            throw ValidationException::withMessages(['role' => __('A contractor login is given from the contractor\'s page, not from the team screen.')]);
        }

        if ($actor->is($target)) {
            if (! $active || $role['key'] !== $target->roleKey()) {
                throw ValidationException::withMessages(['role' => __('You cannot change your own role or deactivate yourself.')]);
            }

            $projects = null;
        }

        if (! $this->catalog->coversAll($actor->grantedPermissions(), $role['permissions'])) {
            throw ValidationException::withMessages(['role' => __('You cannot give a role with more access than you have.')]);
        }

        DB::transaction(function () use ($target, $role, $active, $projects) {
            // Serialises team changes, so two people cannot remove the last owner together.
            Organization::lockForUpdate()->findOrFail($target->organization_id);

            $this->assertNotLastOwner($target, $role, $active);

            $old = $this->snapshot($target);
            $wasActive = $target->is_active;

            $target->forceFill([
                'role' => $role['role'],
                'custom_role_id' => $role['custom_role_id'],
                'is_active' => $active,
            ])->save();
            $target->unsetRelation('customRole');

            if ($projects !== null || $this->seesAllProjects($target)) {
                $this->applyProjects($target, $projects['limited'] ?? false, $projects['ids'] ?? []);
            }

            if ($wasActive && ! $active) {
                $target->forceFill(['remember_token' => Str::random(60)])->save();
                $target->tokens()->delete();
            }

            $new = $this->snapshot($target);

            if ($old != $new) {
                $target->forceFill(['access_changed_at' => now()])->save();
                AuditLog::record('team.access_changed', $target, $old, $new);
            }
        });
    }

    /** Owners and admins always see everything, so a limit never sticks to them. */
    public function applyProjects(User $user, bool $limited, array $projectIds): void
    {
        if ($this->seesAllProjects($user)) {
            $limited = false;
        }

        $user->forceFill(['limited_to_projects' => $limited])->save();

        $user->assignedProjects()->sync(
            collect($limited ? $projectIds : [])
                ->mapWithKeys(fn ($id) => [(int) $id => ['organization_id' => $user->organization_id]])
                ->all()
        );
    }

    private function seesAllProjects(User $user): bool
    {
        return $user->custom_role_id === null && in_array($user->role, User::SEES_ALL_PROJECTS, true);
    }

    /** A company always keeps at least one active owner. */
    private function assertNotLastOwner(User $target, array $role, bool $active): void
    {
        $stopsBeingOwner = $target->role === 'owner' && $target->is_active && ($role['key'] !== 'owner' || ! $active);

        if (! $stopsBeingOwner) {
            return;
        }

        $otherOwners = User::where('organization_id', $target->organization_id)
            ->where('role', 'owner')
            ->where('is_active', true)
            ->whereKeyNot($target->id)
            ->exists();

        if (! $otherOwners) {
            throw ValidationException::withMessages(['role' => __('A company must keep at least one active owner.')]);
        }
    }

    /** @return array<string, mixed> */
    private function snapshot(User $user): array
    {
        return [
            'role' => $user->role,
            'custom_role_id' => $user->custom_role_id,
            'is_active' => (bool) $user->is_active,
            'limited_to_projects' => (bool) $user->limited_to_projects,
            'project_ids' => $user->assignedProjectIds()->orderBy('project_id')->pluck('project_id')->map(fn ($id) => (int) $id)->all(),
        ];
    }
}
