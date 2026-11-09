<?php

namespace App\Services\Access;

use App\Models\CustomRole;
use App\Models\TeamInvitation;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CustomRoleService
{
    /** Approval levels a custom role may act at. Owner and client levels are never offered. */
    private const NOT_OFFERED = ['owner', 'admin', 'client'];

    public function __construct(private PermissionCatalog $catalog) {}

    /** @return array<string, string> */
    public function approvalRoles(): array
    {
        return Arr::except(config('buildos.approval_level_roles', []), self::NOT_OFFERED);
    }

    public function rules(User $actor, ?CustomRole $role = null): array
    {
        return [
            'name' => [
                'required', 'string', 'max:80',
                Rule::unique('custom_roles', 'name')->where('organization_id', $actor->organization_id)->ignore($role?->id),
                function (string $attribute, mixed $value, \Closure $fail) {
                    if ($this->isBuiltInName((string) $value)) {
                        $fail(__('This name is used by a built-in role.'));
                    }
                },
            ],
            'approval_role' => ['nullable', Rule::in(array_keys($this->approvalRoles()))],
            'permissions' => ['required', 'array', 'min:1'],
            'permissions.*' => ['string', Rule::in(Arr::flatten($this->catalog->grantableBy($actor)))],
        ];
    }

    public function create(User $actor, array $data): CustomRole
    {
        $this->assertWithinCeiling($actor, $data['permissions']);

        return CustomRole::create([
            'organization_id' => $actor->organization_id,
            'name' => $data['name'],
            'approval_role' => $data['approval_role'] ?? null,
            'permissions' => array_values(array_unique($data['permissions'])),
            'created_by' => $actor->id,
        ]);
    }

    public function update(User $actor, CustomRole $role, array $data): CustomRole
    {
        $this->assertCanEdit($actor, $role);
        $this->assertWithinCeiling($actor, $data['permissions']);

        DB::transaction(function () use ($role, $data) {
            $role->update([
                'name' => $data['name'],
                'approval_role' => $data['approval_role'] ?? null,
                'permissions' => array_values(array_unique($data['permissions'])),
            ]);

            User::where('custom_role_id', $role->id)
                ->where('organization_id', $role->organization_id)
                ->update(['role' => $role->baseRole()]);
        });

        return $role;
    }

    public function delete(User $actor, CustomRole $role): void
    {
        $this->assertCanEdit($actor, $role);

        DB::transaction(function () use ($role) {
            $role = CustomRole::lockForUpdate()->findOrFail($role->id);

            $inUse = User::where('custom_role_id', $role->id)->exists()
                || TeamInvitation::pending()->where('role_key', $role->key())->exists();

            if ($inUse) {
                throw ValidationException::withMessages(['role' => __('This role is still in use. Move people to another role first.')]);
            }

            $role->delete();
        });
    }

    /** A person may only touch a role that is entirely within what they hold. */
    public function assertCanEdit(User $actor, CustomRole $role): void
    {
        abort_unless($this->catalog->coversAll($actor->grantedPermissions(), $role->permissions), 403, __('This role has more access than you do.'));
    }

    private function assertWithinCeiling(User $actor, array $permissions): void
    {
        if (! $this->catalog->coversAll($actor->grantedPermissions(), $permissions)) {
            throw ValidationException::withMessages(['permissions' => __('You cannot give a role with more access than you have.')]);
        }
    }

    private function isBuiltInName(string $name): bool
    {
        $labels = array_column(config('buildos.roles'), 'label');

        return in_array(Str::lower(trim($name)), array_map(Str::lower(...), $labels), true);
    }
}
