<?php

namespace App\Services\Access;

use App\Models\User;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/**
 * Every permission a custom role can be built from. It is worked out from the
 * built-in roles, from what each module declares and from the routes themselves,
 * so a module's permissions appear here without it having to register them.
 */
final class PermissionCatalog
{
    private const SHAPE = '/^[a-z][a-z0-9_]*\.([a-z][a-z0-9_]*|\*)$/';

    /** The names these areas already go by in the menu. Other modules fall back to a tidied key. */
    private const MODULE_LABELS = [
        'attendance' => 'Attendance',
        'audit' => 'Audit log',
        'billing' => 'Billing',
        'boq' => 'BOQ & ledger',
        'dashboard' => 'Dashboard',
        'dpr' => 'Daily reports',
        'issue' => 'Issues',
        'measurement' => 'Measurement book',
        'money' => 'Money figures',
        'party' => 'Contacts',
        'petty_cash' => 'Petty cash',
        'project' => 'Projects',
        'ra_bill' => 'RA bills',
        'settings' => 'Settings',
        'user' => 'Team',
        'work_order' => 'Work orders',
        'worker' => 'Workers',
    ];

    /** @return list<string> Sorted permission strings, including module.* wildcards. */
    public function all(): array
    {
        $found = [];
        $portalOnly = [];

        foreach (config('buildos.roles', []) as $role) {
            // What a portal role holds, such as the contractor login's view of the portal, is never built into a team role.
            if ($role['portal'] ?? false) {
                array_push($portalOnly, ...($role['permissions'] ?? []));
            } else {
                array_push($found, ...($role['permissions'] ?? []));
            }
        }

        foreach (config('modules', []) as $module) {
            foreach ($module['permissions'] ?? [] as $permissions) {
                array_push($found, ...$permissions);
            }
        }

        // ApprovalService asks for "<document>.approve", which no route names.
        foreach (array_keys(config('buildos.approval_defaults', [])) as $document) {
            $found[] = $document.'.approve';
        }

        foreach (Route::getRoutes() as $route) {
            foreach ($route->middleware() as $middleware) {
                if (is_string($middleware) && str_starts_with($middleware, 'can:')) {
                    $found[] = explode(',', substr($middleware, 4))[0];
                }
            }
        }

        $permissions = array_values(array_unique(array_filter(
            $found,
            fn ($permission) => preg_match(self::SHAPE, $permission) === 1 && ! in_array($permission, $portalOnly, true),
        )));
        sort($permissions);

        return $permissions;
    }

    /** @return array<string, list<string>> Module name => the permissions this person could hand out. */
    public function grantableBy(User $actor): array
    {
        $held = $actor->grantedPermissions();
        $groups = [];

        foreach ($this->all() as $permission) {
            if ($this->covers($held, $permission)) {
                $groups[Str::before($permission, '.')][] = $permission;
            }
        }

        return $groups;
    }

    /** Whether one held permission includes the wanted one. A wildcard is only covered by a wildcard. */
    public function covers(array $held, string $permission): bool
    {
        foreach ($held as $granted) {
            if ($granted === '*' || Str::is($granted, $permission)) {
                return true;
            }
        }

        return false;
    }

    public function coversAll(array $held, array $wanted): bool
    {
        foreach ($wanted as $permission) {
            if (! $this->covers($held, $permission)) {
                return false;
            }
        }

        return true;
    }

    public function moduleLabel(string $module): string
    {
        return __(self::MODULE_LABELS[$module] ?? Str::headline($module));
    }

    public function actionLabel(string $permission): string
    {
        $action = Str::after($permission, '.');

        return $action === '*' ? __('Everything') : __(Str::headline($action));
    }
}
