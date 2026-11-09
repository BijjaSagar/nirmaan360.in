<?php

// The offline plan: one phone, no server copy, no sync. The plan itself is in config/buildos.php.
// The server's part is small: sign device licences the app checks offline, refuse sync and writes
// for companies on the plan, and lift that the moment they move to an online plan.
return [
    'feature' => 'offline_plan',
    'plans' => ['offline'],
    'permissions' => [],
    'nav' => [
        ['place' => 'company', 'group' => 'Account', 'label' => 'Offline devices', 'route' => 'offline.devices.index', 'active' => 'offline.*', 'can' => 'billing.manage', 'order' => 20],
    ],
    'icons' => [],

    // The plan key that never syncs.
    'plan' => 'offline',

    // Longest a licence token stays valid without the phone refreshing it. The token also ends with the
    // paid period or the trial. Shorter means a freed phone stops sooner; longer means more time with no internet.
    'licence_days' => 90,

    // Used only when a plan has no max_devices of its own.
    'default_max_devices' => 1,
];
