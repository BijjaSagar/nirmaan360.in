<?php

/*
 * Site progress and the plumbing and electrical stage tracker (SRS sections 6 and 9). See docs/MODULES.md.
 * It runs on the site_control feature and the progress.* permissions that config/modules/site_control.php grants,
 * so there is nothing to add to the roles here. No screen or endpoint of this module shows a rate, an amount or a budget.
 * Labels below go through __() where they are shown, so each one needs a Hindi and Marathi line.
 */
return [
    'feature' => 'site_control',
    'plans' => ['contractor', 'business', 'enterprise'],
    'permissions' => [],

    'nav' => [
        ['place' => 'project', 'label' => 'Site progress', 'route' => 'site-progress.index', 'active' => 'site-progress.*', 'can' => 'progress.view', 'order' => 10],
        ['place' => 'project', 'label' => 'Stage tracker', 'route' => 'site-stages.index', 'active' => 'site-stages.*', 'can' => 'progress.view', 'order' => 11],
    ],

    'icons' => [],

    // Cell statuses of the stage matrix. "na" is only offered on a stage marked optional below.
    'mep_statuses' => [
        'not_started' => 'Not started',
        'in_progress' => 'In progress',
        'done' => 'Done',
        'pending' => 'Pending',
        'na' => 'Not applicable',
    ],

    // Stage lists, in the order the work is done. Do not reorder: the "earlier stage" warning follows this order.
    'mep_trades' => [
        'plumbing' => [
            'label' => 'Plumbing',
            'stages' => [
                'core_cutting' => ['label' => 'Core cutting and internal pipes', 'hint' => 'Breaking walls and laying pipes inside'],
                'bathroom_bed' => ['label' => 'IPS / bathroom bed and complete waterproofing underneath'],
                'internal_fitting' => ['label' => 'Internal water fitting / piping'],
                'outer_piping' => ['label' => 'Outer piping and CP fitting'],
                'sanitary_ware' => ['label' => 'Sanitary ware fitting'],
                'drainage' => ['label' => 'Drainage line (final stage)'],
            ],
        ],
        'electrical' => [
            'label' => 'Electrical',
            'stages' => [
                'slab_piping' => ['label' => 'Slab piping', 'hint' => 'Concealed conduits laid in the slab before concreting'],
                'wall_piping' => ['label' => 'Concealed piping in walls'],
                'wiring' => ['label' => 'Wiring'],
                'boards' => ['label' => 'Button boards / switch boards'],
                'final_fittings' => ['label' => 'Final fittings: fans, lights etc.', 'hint' => 'Only if included in the scope; otherwise not applicable', 'optional' => true],
            ],
        ],
    ],

    // Finishing work the pre-finishing checklist is shown for.
    'finishing_works' => [
        'plaster' => 'Plaster',
        'pop' => 'POP',
        'paint' => 'Paint',
    ],
];
