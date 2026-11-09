<?php

// Company API keys and the read-only public API. Business and Enterprise.
// Only owners and admins manage keys: they hold every permission, so no role is listed here.
return [
    'feature' => 'public_api',
    'plans' => ['business', 'enterprise'],
    'permissions' => [],
    'nav' => [
        ['place' => 'company', 'group' => 'Developers', 'label' => 'API keys', 'route' => 'api-keys.index', 'active' => 'api-keys.*', 'can' => 'api_key.manage', 'order' => 10],
        ['place' => 'company', 'group' => 'Developers', 'label' => 'API documentation', 'route' => 'api-docs', 'active' => 'api-docs', 'can' => 'api_key.manage', 'order' => 20],
    ],
    'icons' => [],
];
