<?php

return [
    'nav' => [
        ['place' => 'project', 'label' => 'Selfie attendance', 'route' => 'notify.selfie.index', 'active' => 'notify.selfie.*', 'can' => 'attendance.manage'],
        ['place' => 'company', 'group' => 'Account', 'label' => 'Notification settings', 'route' => 'notify.preferences', 'active' => 'notify.preferences*', 'order' => 10],
        ['place' => 'company', 'group' => 'Account', 'label' => 'Company data', 'route' => 'notify.data.index', 'active' => 'notify.data.*', 'can' => 'settings.manage', 'order' => 20],
    ],
];
