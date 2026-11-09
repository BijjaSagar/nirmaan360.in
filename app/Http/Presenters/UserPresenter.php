<?php

namespace App\Http\Presenters;

use App\Models\User;

final class UserPresenter
{
    /** The exact permissions the field app asks about. */
    private const PERMISSIONS = [
        'project.view', 'boq.view',
        'dpr.view', 'dpr.create', 'dpr.approve',
        'issue.view', 'issue.manage',
        'attendance.view', 'attendance.manage',
        'worker.view', 'worker.manage',
        'petty_cash.view', 'petty_cash.manage',
        'measurement.view', 'measurement.create', 'measurement.approve',
        'ra_bill.view', 'ra_bill.approve',
    ];

    public static function present(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role,
            'role_label' => $user->roleLabel(),
            'locale' => $user->locale,
            'organization' => [
                'id' => $user->organization_id,
                'name' => $user->organization->name,
                'features' => $user->organization->planConfig()['features'],
            ],
            'can' => array_combine(self::PERMISSIONS, array_map($user->hasPermission(...), self::PERMISSIONS)),
        ];
    }
}
