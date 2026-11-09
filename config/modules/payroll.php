<?php

// Wage runs, advances, payslips, and staff leave and expense claims. Part of the workforce module.
return [
    'feature' => 'payroll',
    'plans' => ['contractor', 'business', 'enterprise'],
    'permissions' => [
        'pm' => ['payroll.view', 'payroll.manage', 'claim.submit', 'claim.approve'],
        'qs' => ['claim.submit'],
        'engineer' => ['claim.submit'],
        'store' => ['claim.submit'],
        'accounts' => ['payroll.*', 'claim.submit', 'claim.approve'],
    ],
    'nav' => [
        ['place' => 'company', 'group' => 'Payroll', 'label' => 'Wage runs', 'route' => 'payroll.runs.index', 'active' => 'payroll.runs.*', 'can' => 'payroll.view', 'order' => 10],
        ['place' => 'company', 'group' => 'Payroll', 'label' => 'Advances', 'route' => 'payroll.advances.index', 'active' => 'payroll.advances.*', 'can' => 'payroll.view', 'order' => 20],
        ['place' => 'company', 'group' => 'Payroll', 'label' => 'Staff leave and claims', 'route' => 'payroll.staff.index', 'active' => 'payroll.staff.*', 'can' => 'claim.submit', 'order' => 30],
    ],
    'icons' => [],
];
