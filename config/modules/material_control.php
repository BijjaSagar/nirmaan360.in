<?php

return [
    'feature' => 'site_control',
    'plans' => ['contractor', 'business', 'enterprise'],
    // Site staff enter and see quantities. Bill details and values also need money.view.
    'permissions' => [
        'pm' => ['material_control.*'],
        'qs' => ['material_control.view', 'material_control.reason', 'material_control.extra', 'material_control.suppliers'],
        'engineer' => ['material_control.view', 'material_control.challan', 'material_control.consume', 'material_control.count', 'material_control.reason', 'material_control.extra', 'material_control.bills'],
        'store' => ['material_control.view', 'material_control.challan', 'material_control.consume', 'material_control.count', 'material_control.reason'],
        'accounts' => ['material_control.view', 'material_control.bills'],
        'viewer' => ['material_control.view'],
    ],
    'nav' => [
        ['place' => 'project', 'group' => 'Procurement', 'label' => 'Material control', 'route' => 'material_control.index', 'active' => 'material_control.*', 'can' => 'material_control.view', 'order' => 5],
        ['place' => 'company', 'group' => 'Procurement', 'label' => 'Supplier records', 'route' => 'material_control.suppliers.index', 'active' => 'material_control.suppliers.*', 'can' => 'material_control.view', 'order' => 15],
    ],
];
