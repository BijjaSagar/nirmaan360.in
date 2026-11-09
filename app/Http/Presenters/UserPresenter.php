<?php

namespace App\Http\Presenters;

use App\Models\User;
use App\Services\ContractorPortal\ContractorPortalService;

final class UserPresenter
{
    /** The exact permissions the field app asks about. */
    private const PERMISSIONS = [
        'project.view', 'project.manage', 'boq.view', 'boq.manage',
        'dpr.view', 'dpr.create', 'dpr.approve',
        'issue.view', 'issue.manage',
        'attendance.view', 'attendance.manage',
        'worker.view', 'worker.manage',
        'petty_cash.view', 'petty_cash.manage',
        'measurement.view', 'measurement.create', 'measurement.approve',
        'ra_bill.view', 'ra_bill.approve',
        'money.view', 'money.overview',
        'progress.view', 'progress.create', 'progress.manage',
        'material_control.view', 'material_control.challan', 'material_control.consume', 'material_control.count',
        'material_control.reason', 'material_control.extra', 'material_control.bills', 'material_control.suppliers', 'material_control.adjust',
        'contractor.view', 'contractor.request', 'contractor.approve', 'contractor.manage', 'contractor.tender', 'contractor.award',
        'asset.log', 'checklist.fill', 'procurement.stock',
        'contractor_portal.view',
    ];

    public static function present(User $user): array
    {
        // A contractor login uses the /contractor-portal endpoints and none of the site screens.
        $party = $user->isContractor() ? app(ContractorPortalService::class)->partyFor($user) : null;

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role,
            'role_label' => $user->roleLabel(),
            'kind' => $user->isContractor() ? 'contractor' : 'staff',
            'contractor' => $party ? ['party_id' => $party->id, 'name' => $party->name] : null,
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
