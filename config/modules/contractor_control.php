<?php

return [
    'feature' => 'site_control',
    'plans' => ['contractor', 'business', 'enterprise'],

    // Contract values, payments and quotes also need money.view, from config/modules/site_control.php.
    // Without it, contractor.view shows work %, and on a BOQ line which tenders it is in and who quoted, with no prices.
    'permissions' => [
        'pm' => ['contractor.*'],
        'qs' => ['contractor.view', 'contractor.tender'],
        'accounts' => ['contractor.view', 'contractor.request'],
        'engineer' => ['contractor.view', 'contractor.request'],
        'viewer' => ['contractor.view'],
    ],

    // Used until the company has its own approval rule for doc_type "contractor".
    'payment_approval_levels' => ['pm'],

    'payment_modes' => ['cash' => 'Cash', 'bank' => 'Bank', 'upi' => 'UPI'],

    'nav' => [
        ['place' => 'project', 'group' => 'Contractors', 'label' => 'Work vs payment', 'route' => 'contractor-control.board', 'active' => 'contractor-control.board', 'can' => 'contractor.view', 'order' => 10],
        ['place' => 'project', 'group' => 'Contractors', 'label' => 'Contractor payments', 'route' => 'contractor-control.payments.index', 'active' => 'contractor-control.payments.*', 'can' => 'contractor.view', 'order' => 20],
        ['place' => 'project', 'group' => 'Contractors', 'label' => 'Contractor tenders', 'route' => 'contractor-control.tenders.index', 'active' => 'contractor-control.tenders.*', 'can' => 'money.view', 'order' => 30],
        ['place' => 'project', 'group' => 'Contractors', 'label' => 'Deadlines', 'route' => 'contractor-control.deadlines', 'active' => 'contractor-control.deadlines', 'can' => 'contractor.view', 'order' => 40],
        ['place' => 'project', 'group' => 'Contractors', 'label' => 'Cost variance', 'route' => 'contractor-control.variance', 'active' => 'contractor-control.variance', 'can' => ['contractor.view', 'money.view', 'money.overview'], 'order' => 50],
        ['place' => 'company', 'group' => 'Contractors', 'label' => 'Contractor ledger', 'route' => 'contractor-control.contractors.index', 'active' => 'contractor-control.contractors.*', 'can' => 'money.view', 'order' => 10],
    ],
];
