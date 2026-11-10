<?php

namespace App\Services\Notify;

use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

/**
 * Who may be told about something that happened on a project. Runs from the queue and the
 * scheduler, where no tenant scope applies, so the company is always named in the query and
 * the project limit is checked for each person rather than for whoever is signed in.
 */
final class Audience
{
    /**
     * Active team members of one company who hold every permission named and can see the project.
     * A contractor login is never part of the team.
     *
     * @param  list<string>  $permissions
     * @return Collection<int, User>
     */
    public static function staff(int $organizationId, ?int $projectId, array $permissions): Collection
    {
        return User::where('organization_id', $organizationId)->where('is_active', true)->get()
            ->filter(fn (User $user) => ! $user->isContractor() && self::holds($user, $permissions) && self::seesProject($user, $projectId))
            ->values();
    }

    /** @param  list<string>  $permissions */
    public static function holds(User $user, array $permissions): bool
    {
        foreach ($permissions as $permission) {
            if (! $user->hasPermission($permission)) {
                return false;
            }
        }

        return true;
    }

    /** Someone limited to chosen projects sees only those. A null project is a company-level record. */
    public static function seesProject(User $user, ?int $projectId): bool
    {
        return $projectId === null
            || ! $user->isProjectLimited()
            || $user->assignedProjectIds()->where('project_id', $projectId)->exists();
    }
}
