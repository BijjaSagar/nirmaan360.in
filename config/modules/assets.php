<?php

/*
 * Assets and plant (field_ops, FR-AST-01 and FR-AST-02). See docs/MODULES.md.
 * Labels below go through __() where they are shown, so each one needs a Hindi and Marathi line.
 */
return [
    'feature' => 'assets',
    'plans' => ['business', 'enterprise'],

    'permissions' => [
        'pm' => ['asset.*'],
        'engineer' => ['asset.view', 'asset.log'],
        'store' => ['asset.view', 'asset.log'],
        'qs' => ['asset.view'],
        'accounts' => ['asset.view', 'asset.bill'],
        'viewer' => ['asset.view'],
    ],

    'nav' => [
        ['place' => 'company', 'group' => 'Plant & equipment', 'label' => 'Assets', 'route' => 'assets.index', 'active' => 'assets.*', 'can' => 'asset.view', 'order' => 10],
        ['place' => 'company', 'group' => 'Plant & equipment', 'label' => 'Machine log', 'route' => 'machine-logs.index', 'active' => 'machine-logs.*', 'can' => 'asset.view', 'order' => 20],
        ['place' => 'company', 'group' => 'Plant & equipment', 'label' => 'Maintenance', 'route' => 'asset-maintenance.index', 'active' => 'asset-maintenance.*', 'can' => 'asset.view', 'order' => 30],
        ['place' => 'company', 'group' => 'Plant & equipment', 'label' => 'Hire bills', 'route' => 'hire-bills.index', 'active' => 'hire-bills.*', 'can' => 'asset.bill', 'order' => 40],
    ],

    'icons' => [
        'qr' => '<rect x="4" y="4" width="6" height="6" rx="1"/><rect x="14" y="4" width="6" height="6" rx="1"/><rect x="4" y="14" width="6" height="6" rx="1"/><path d="M14 14h2v2h-2zM18 14h2M14 18h2M18 18h2v2h-2z"/>',
        'wrench' => '<path d="M14.7 6.3a4 4 0 0 0 5 5.1L11 20.1a2.1 2.1 0 0 1-3-3l8.7-8.7z"/><path d="M14.7 6.3 17 4l3 3-2.3 2.3"/>',
    ],

    'categories' => [
        'excavator' => 'Excavator',
        'backhoe' => 'JCB / Backhoe loader',
        'crane' => 'Crane',
        'mixer' => 'Concrete mixer',
        'transit_mixer' => 'Transit mixer',
        'pump' => 'Concrete pump',
        'roller' => 'Roller / Compactor',
        'tipper' => 'Tipper / Truck',
        'dg_set' => 'DG set',
        'compressor' => 'Compressor',
        'vehicle' => 'Vehicle',
        'formwork' => 'Shuttering / Formwork',
        'scaffolding' => 'Scaffolding',
        'tools' => 'Tools and small plant',
        'other' => 'Other',
    ],

    'ownerships' => ['owned' => 'Owned', 'hired' => 'Hired'],

    // How the owner charges. The quantity comes from the machine log.
    'hire_bases' => ['hour' => 'Per hour', 'day' => 'Per day worked', 'month' => 'Per month', 'km' => 'Per km'],

    'hire_types' => ['dry' => 'Dry hire (we supply fuel)', 'wet' => 'Wet hire (owner supplies fuel)'],

    'meter_units' => ['hours' => 'Hours', 'km' => 'Kilometres'],

    'statuses' => ['active' => 'Active', 'under_repair' => 'Under repair', 'retired' => 'Retired'],

    'maintenance' => [
        // A service shows as due soon this many days ahead, or once this share of its interval is used.
        'due_soon_days' => 7,
        'due_soon_usage_pct' => 90,
    ],

    // Hours logged for one machine on one day can never pass this.
    'max_hours_per_day' => 24,
];
