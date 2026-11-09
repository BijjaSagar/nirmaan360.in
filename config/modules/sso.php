<?php

// Google and Microsoft sign-in for people who already have an account. Business and Enterprise.
return [
    'feature' => 'sso',
    'plans' => ['business', 'enterprise'],
    'permissions' => [],
    'nav' => [
        ['place' => 'company', 'group' => 'Security', 'label' => 'Single sign-on', 'route' => 'sso.settings', 'active' => 'sso.*', 'can' => 'settings.manage', 'order' => 10],
    ],
    'icons' => [],
];
