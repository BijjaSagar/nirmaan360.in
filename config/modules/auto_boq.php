<?php

// Auto BOQ: quantities worked out by formula from building dimensions typed in or taken from the
// drawing takeoff. It rides on the site_control plan feature, so no plan gains anything here, and
// it uses the BOQ permission that already exists: boq.manage to run and apply. Rates follow money.view.
return [
    'feature' => 'site_control',
    'plans' => [],
    'permissions' => [],

    'nav' => [
        ['place' => 'project', 'group' => 'Project setup', 'label' => 'Auto BOQ', 'route' => 'auto-boq.show', 'active' => 'auto-boq.show', 'can' => 'boq.manage', 'order' => 25],
        ['place' => 'company', 'group' => 'Reports & rates', 'label' => 'BOQ assumptions', 'route' => 'auto-boq.assumptions', 'active' => 'auto-boq.assumptions*', 'can' => 'boq.manage', 'order' => 30],
    ],

    'icons' => [],
];
