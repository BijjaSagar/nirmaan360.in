<?php

return [
    'feature' => 'site_control',
    'plans' => ['contractor', 'business', 'enterprise'],
    // Site staff enter quantities and never receive money figures.
    // money.view: rates, contract values and payments. money.overview: budget and total spend.
    'permissions' => [
        'pm' => ['money.view', 'money.overview', 'progress.*'],
        'accounts' => ['money.view', 'progress.view'],
        'qs' => ['money.view', 'progress.*'],
        'engineer' => ['progress.view', 'progress.create'],
        'store' => ['progress.view'],
        'viewer' => ['progress.view'],
    ],
];
