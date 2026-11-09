<?php

return [
    'feature' => 'drawings',
    'plans' => ['business', 'enterprise'],
    'permissions' => [
        'pm' => ['drawings.*'],
        'qs' => ['drawings.*'],
        'engineer' => ['drawings.view', 'drawings.bbs'],
        'store' => ['drawings.view'],
        'accounts' => ['drawings.view'],
        'viewer' => ['drawings.view'],
    ],
    'nav' => [
        ['place' => 'project', 'label' => 'Drawings', 'route' => 'drawings.register.index', 'active' => 'drawings.register.*', 'can' => 'drawings.view', 'order' => 10],
        ['place' => 'project', 'label' => 'Takeoff', 'route' => 'drawings.takeoff.index', 'active' => 'drawings.takeoff.*', 'can' => 'drawings.view', 'order' => 11],
        ['place' => 'project', 'label' => 'Transmittals', 'route' => 'drawings.transmittals.index', 'active' => 'drawings.transmittals.*', 'can' => 'drawings.view', 'order' => 12],
        ['place' => 'project', 'label' => 'Bar bending', 'route' => 'drawings.bbs.index', 'active' => 'drawings.bbs.*', 'can' => 'drawings.view', 'order' => 13],
    ],

    'disciplines' => ['Architectural', 'Structural', 'Civil', 'Electrical', 'Plumbing', 'HVAC', 'Fire', 'Landscape', 'Contract', 'Other'],
    'version_statuses' => ['for_information', 'for_review', 'approved', 'for_construction', 'as_built'],
    'transmittal_purposes' => ['for_information', 'for_review', 'for_approval', 'for_construction', 'for_tender', 'as_built'],

    // Largest file a register entry may hold, in kilobytes.
    'max_upload_kb' => 51200,

    // Colours offered for a new takeoff layer.
    'layer_colours' => ['#b4451f', '#1b4a66', '#3b6a4c', '#e2a31a', '#7a3e9d', '#0f8b8d', '#c2185b', '#5d4037'],
];
