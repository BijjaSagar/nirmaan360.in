<?php

return [
    'feature' => 'crm',
    'plans' => ['contractor', 'business', 'enterprise'],
    'permissions' => [
        // Owner and admin already have everything.
        'pm' => ['crm.*'],
        'qs' => ['crm.view'],
        // Accounts also records EMD and fees paid and refunded.
        'accounts' => ['crm.view', 'crm.emd'],
    ],
    'nav' => [
        ['place' => 'company', 'group' => 'Sales', 'label' => 'Sales overview', 'route' => 'crm.dashboard', 'active' => 'crm.dashboard', 'can' => 'crm.view', 'order' => 10],
        ['place' => 'company', 'group' => 'Sales', 'label' => 'Tenders', 'route' => 'crm.tenders.index', 'active' => 'crm.tenders.*', 'can' => 'crm.view', 'order' => 20],
        ['place' => 'company', 'group' => 'Sales', 'label' => 'Leads', 'route' => 'crm.leads.index', 'active' => 'crm.leads.*', 'can' => 'crm.view', 'order' => 30],
        ['place' => 'company', 'group' => 'Sales', 'label' => 'Companies', 'route' => 'crm.companies.index', 'active' => 'crm.companies.*', 'can' => 'crm.view', 'order' => 40],
        ['place' => 'company', 'group' => 'Sales', 'label' => 'Follow-ups', 'route' => 'crm.tasks.index', 'active' => 'crm.tasks.*', 'can' => 'crm.view', 'order' => 50],
        ['place' => 'company', 'group' => 'Sales', 'label' => 'EMD & fees', 'route' => 'crm.payments.index', 'active' => 'crm.payments.*', 'can' => 'crm.view', 'order' => 60],
        ['place' => 'company', 'group' => 'Sales', 'label' => 'Win/loss report', 'route' => 'crm.reports.win-loss', 'active' => 'crm.reports.*', 'can' => 'crm.view', 'order' => 70],
        ['place' => 'company', 'group' => 'Sales', 'label' => 'Sales setup', 'route' => 'crm.setup', 'active' => 'crm.setup*', 'can' => 'crm.manage', 'order' => 80],
        ['place' => 'project', 'label' => 'Tender file', 'route' => 'crm.project-file', 'active' => 'crm.project-file', 'can' => 'crm.view', 'order' => 10],
    ],
    'icons' => [],
];
