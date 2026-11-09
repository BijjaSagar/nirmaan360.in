<?php

// Reports module: Excel BOQ, rate library, DPR and measurement PDFs, the Reports screen and its scheduled emails.
return [
    'feature' => 'reports',
    'plans' => ['contractor', 'business', 'enterprise'],
    'permissions' => [
        'pm' => ['report.*', 'rate.*'],
        'qs' => ['report.view', 'rate.*'],
        'accounts' => ['report.view'],
    ],
    'nav' => [
        ['place' => 'company', 'group' => 'Reports & rates', 'label' => 'Reports', 'route' => 'reports.index', 'active' => 'reports.*', 'can' => 'report.view', 'order' => 10],
        ['place' => 'company', 'group' => 'Reports & rates', 'label' => 'Rate library', 'route' => 'ratelib.index', 'active' => 'ratelib.*', 'can' => 'rate.view', 'order' => 20],
    ],
    'icons' => [],
];
