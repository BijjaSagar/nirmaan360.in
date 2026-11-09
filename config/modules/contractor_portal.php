<?php

return [
    'feature' => 'contractor_portal',
    'plans' => ['contractor', 'business', 'enterprise'],

    // contractor_portal.manage: invite a contractor from their page and take the login away again.
    // contractor_portal.view is held only by the contractor role in config/buildos.php, and
    // ContractorPortalGate keeps that role out of every screen and endpoint but the portal.
    'permissions' => [
        'pm' => ['contractor_portal.manage'],
    ],

    // Bills a contractor may open. Drafts and bills still in the company's own approval chain stay private.
    'visible_bill_statuses' => ['APPROVED', 'PAID'],

    'nav' => [],
];
