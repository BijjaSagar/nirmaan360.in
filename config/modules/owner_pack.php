<?php

/*
 * Home-owner pack: a quotation with a payment schedule and a weekly update that a contractor gives
 * a client, each shared as a link the contractor sends himself. Every figure is worked out by code
 * from the company's own records; no model is called by this part of the module, and the app sends
 * nothing to a client.
 */
return [
    'feature' => 'owner_pack',
    'plans' => ['contractor', 'business', 'enterprise'],

    // A quotation is nothing but money, so every quotation route also needs money.view (config/modules/site_control.php).
    // owner_pack.progress prepares a weekly update. A site engineer holds it and may prepare one that carries no money;
    // the payment position, the approval and the link need money.view as well.
    'permissions' => [
        'pm' => ['owner_pack.*'],
        'accounts' => ['owner_pack.view'],
        'qs' => ['owner_pack.view'],
        'engineer' => ['owner_pack.progress'],
    ],

    // Offered only to someone who may open the page: a custom role can hold owner_pack.view without money.view.
    'nav' => [
        ['place' => 'project', 'group' => 'Home owner', 'label' => 'Quotations', 'route' => 'quotations.index', 'active' => 'quotations.*', 'can' => ['owner_pack.view', 'money.view'], 'order' => 10],
        ['place' => 'project', 'group' => 'Home owner', 'label' => 'Weekly updates', 'route' => 'owner-updates.index', 'active' => 'owner-updates.*', 'can' => 'owner_pack.progress', 'order' => 20],
    ],
    'icons' => [],

    // Days a shared link stays open. The date is kept on the row, so a link can also be withdrawn sooner.
    'share_link_days' => 14,

    // Limits that keep a quotation a document a person can check line by line.
    'max_lines' => 200,
    'max_stages' => 24,

    // A weekly update: photos a person may pick, work rows it lists (the rest are counted, not dropped
    // silently), and the longest edge of a photo as it is drawn again for the client.
    'max_photos' => 12,
    'max_work_lines' => 40,
    'photo_edge' => 1600,
    // The largest stored photo the server opens to draw it again, in pixels. Opening one takes about
    // four bytes of memory a pixel, so this follows the memory a web request may use.
    'photo_max_pixels' => 17_000_000,
];
