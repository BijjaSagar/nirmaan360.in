<?php

/*
 * Home-owner pack: a quotation with a payment schedule that a contractor gives a client, shared as a
 * link the contractor sends himself. Every figure is worked out by code from the company's own
 * records; no model is called by this part of the module.
 */
return [
    'feature' => 'owner_pack',
    'plans' => ['contractor', 'business', 'enterprise'],

    // A quotation is nothing but money, so every quotation route also needs money.view (config/modules/site_control.php).
    'permissions' => [
        'pm' => ['owner_pack.*'],
        'accounts' => ['owner_pack.view'],
        'qs' => ['owner_pack.view'],
    ],

    'nav' => [
        ['place' => 'project', 'group' => 'Home owner', 'label' => 'Quotations', 'route' => 'quotations.index', 'active' => 'quotations.*', 'can' => 'owner_pack.view', 'order' => 10],
    ],
    'icons' => [],

    // Days a shared link stays open. The date is kept on the row, so a link can also be withdrawn sooner.
    'share_link_days' => 14,

    // Limits that keep a quotation a document a person can check line by line.
    'max_lines' => 200,
    'max_stages' => 24,
];
