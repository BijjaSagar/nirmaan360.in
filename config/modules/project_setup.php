<?php

// Project setup: the permission checklist, the starter BOQ and its categories, the BOQ history,
// concrete by ingredients, and the budget against the BOQ. It rides on the site_control plan
// feature, so no plan gains anything here.
return [
    'feature' => 'site_control',
    'plans' => [],

    // The budget screens need money.overview and the history shows rates only to money.view. Both
    // are granted by the site_control module, not here.
    'permissions' => [
        'pm' => ['permit.*'],
        'qs' => ['permit.view'],
        'accounts' => ['permit.view'],
        'engineer' => ['permit.view'],
        'viewer' => ['permit.view'],
    ],

    'nav' => [
        ['place' => 'project', 'group' => 'Project setup', 'label' => 'Permissions', 'route' => 'project-setup.permits.index', 'active' => 'project-setup.permits.*', 'can' => 'permit.view', 'order' => 10],
        ['place' => 'project', 'group' => 'Project setup', 'label' => 'BOQ setup', 'route' => 'project-setup.boq-setup', 'active' => 'project-setup.boq-setup*', 'can' => 'boq.view', 'order' => 20],
        ['place' => 'project', 'group' => 'Project setup', 'label' => 'BOQ history', 'route' => 'project-setup.history', 'active' => 'project-setup.history', 'can' => 'boq.view', 'order' => 30],
        ['place' => 'project', 'group' => 'Project setup', 'label' => 'BOQ budget', 'route' => 'project-setup.budget', 'active' => 'project-setup.budget*', 'can' => 'money.overview', 'order' => 40],
    ],

    'icons' => [],
];
