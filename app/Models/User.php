<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['organization_id', 'name', 'email', 'phone', 'role', 'locale', 'is_active', 'password', 'last_login_at'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /** These built-in roles see every project, whatever else is set on the person. */
    public const SEES_ALL_PROJECTS = ['owner', 'admin'];

    /** These reach across the whole company, so someone limited to chosen projects never holds them. */
    public const COMPANY_WIDE_PERMISSIONS = ['user.*', 'settings.*', 'billing.*', 'audit.*'];

    /** A subcontractor's own login: it reaches the contractor portal and nothing else, and never counts as a team seat. */
    public const CONTRACTOR_ROLE = 'contractor';

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'is_active' => 'boolean',
            'is_platform_admin' => 'boolean',
            'limited_to_projects' => 'boolean',
            'access_changed_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** Read without the tenant scope, then checked against this person's company in grantedPermissions(). */
    public function customRole(): BelongsTo
    {
        return $this->belongsTo(CustomRole::class)->withoutGlobalScopes();
    }

    public function assignedProjects(): BelongsToMany
    {
        return $this->belongsToMany(Project::class, 'user_project_access')->withTimestamps();
    }

    /** Whether this is a contractor login. ContractorPortalService::partyFor() says whether its link still leads to a subcontractor. */
    public function isContractor(): bool
    {
        return $this->custom_role_id === null && $this->role === self::CONTRACTOR_ROLE;
    }

    /** The subcontractor a contractor login belongs to, within the signed-in company. */
    public function contractorParty(): BelongsTo
    {
        return $this->belongsTo(Party::class, 'contractor_party_id');
    }

    public function hasPermission(string $permission): bool
    {
        if ($this->isProjectLimited() && Str::is(self::COMPANY_WIDE_PERMISSIONS, $permission)) {
            return false;
        }

        foreach ($this->grantedPermissions() as $granted) {
            if ($granted === '*' || Str::is($granted, $permission)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The permission list behind this person's role.
     * A custom role that has gone, or that belongs to another company, grants nothing:
     * it never falls back to the built-in role kept in the role column.
     *
     * @return list<string>
     */
    public function grantedPermissions(): array
    {
        if ($this->custom_role_id === null) {
            return config("buildos.roles.{$this->role}.permissions", []);
        }

        $customRole = $this->customRole;

        return $customRole && $customRole->organization_id === $this->organization_id ? $customRole->permissions : [];
    }

    /** The value used for this person's role on forms and invitations. */
    public function roleKey(): string
    {
        return $this->custom_role_id !== null ? CustomRole::keyFor($this->custom_role_id) : $this->role;
    }

    public function roleLabel(): string
    {
        if ($this->custom_role_id !== null) {
            return $this->customRole->name ?? __('Removed role');
        }

        return config("buildos.roles.{$this->role}.label", $this->role);
    }

    public function isProjectLimited(): bool
    {
        return $this->limited_to_projects
            && ($this->custom_role_id !== null || ! in_array($this->role, self::SEES_ALL_PROJECTS, true));
    }

    /** A subquery, so a scope adds no round trip and can never be out of date. */
    public function assignedProjectIds(): QueryBuilder
    {
        return DB::table('user_project_access')
            ->select('project_id')
            ->where('user_id', $this->id)
            ->where('organization_id', $this->organization_id);
    }

    /**
     * Staff who run the platform. Separate from company roles, which all grant '*' to owners.
     *
     * A listed address counts only for the first account that holds it, whatever its letter case,
     * so a later sign-up as OPS@ or Ops@ for ops@ never reaches the admin screens.
     */
    public function isPlatformAdmin(): bool
    {
        if ($this->is_platform_admin) {
            return true;
        }

        $email = Str::lower(trim((string) $this->email));

        if (! self::emailReservedForPlatform($email)) {
            return false;
        }

        return ! static::query()->whereRaw('lower(email) = ?', [$email])->where('id', '<', $this->id)->exists();
    }

    /** An address on the platform admin list. Nobody can sign up or be invited with one. */
    public static function emailReservedForPlatform(string $email): bool
    {
        return in_array(Str::lower(trim($email)), config('buildos.platform_admins'), true);
    }

    /** Someone already signs in with this address, in any letter case. */
    public static function emailTaken(string $email): bool
    {
        return static::query()->whereRaw('lower(email) = ?', [Str::lower(trim($email))])->exists();
    }

    public function isApprovalOverride(): bool
    {
        return $this->custom_role_id === null && in_array($this->role, config('buildos.approver_override_roles'), true);
    }
}
