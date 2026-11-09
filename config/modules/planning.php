<?php

return [
    'feature' => 'planning',
    'plans' => ['contractor', 'business', 'enterprise'],
    'permissions' => [
        'pm' => ['planning.*'],
        'qs' => ['planning.*'],
        'engineer' => ['planning.view'],
        'accounts' => ['planning.view'],
        'viewer' => ['planning.view'],
    ],
    'nav' => [
        ['place' => 'project', 'label' => 'Planning', 'route' => 'planning.index', 'active' => 'planning.*', 'can' => 'planning.view', 'order' => 10],
    ],

    // The heads a budget is set against. "petty" lists the petty cash categories that count
    // towards a head. A category not listed anywhere counts as "other".
    'cost_heads' => [
        'material' => ['label' => 'Material', 'petty' => ['Materials']],
        'labour' => ['label' => 'Labour', 'petty' => ['Labour']],
        'subcontract' => ['label' => 'Subcontract', 'petty' => []],
        'plant' => ['label' => 'Plant and machinery', 'petty' => ['Fuel', 'Repairs', 'Tools']],
        'overheads' => ['label' => 'Site overheads', 'petty' => ['Food', 'Travel']],
        'other' => ['label' => 'Other', 'petty' => ['Other']],
    ],
];
