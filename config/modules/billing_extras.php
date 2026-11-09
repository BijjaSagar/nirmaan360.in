<?php

return [
    // Only the contract position page is on the menu, and it needs billing. No plan gains anything here.
    'feature' => 'billing',
    'plans' => [],

    'permissions' => [
        'pm' => ['bill_note.*'],
        'qs' => ['bill_note.*'],
        'accounts' => ['bill_note.view', 'bill_note.approve'],
        'viewer' => ['bill_note.view'],
    ],

    'nav' => [
        // Money from end to end, so the page and its menu entry need money.view rather than ra_bill.view.
        ['place' => 'project', 'label' => 'Contract position', 'route' => 'billing-extras.position', 'active' => 'billing-extras.*', 'can' => 'money.view', 'order' => 20],
    ],
];
