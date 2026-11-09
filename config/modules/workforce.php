<?php

// QR attendance and productivity. These belong to the labour feature every plan has, so no plans are added here.
return [
    'feature' => 'labour',
    'permissions' => [],
    'nav' => [
        ['place' => 'company', 'group' => 'Workforce', 'label' => 'Worker QR cards', 'route' => 'workforce.cards', 'active' => 'workforce.cards*', 'can' => 'worker.view', 'order' => 10],
        ['place' => 'project', 'label' => 'Scan attendance', 'route' => 'workforce.scan', 'active' => 'workforce.scan*', 'can' => 'attendance.manage', 'order' => 10],
        ['place' => 'project', 'label' => 'Productivity', 'route' => 'workforce.productivity', 'active' => 'workforce.productivity*', 'can' => 'attendance.view', 'order' => 20],
    ],
    'icons' => [],
];
