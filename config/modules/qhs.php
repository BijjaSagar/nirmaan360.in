<?php

/*
 * Quality, safety and site checks (field_ops, FR-QHS-01, FR-QHS-02, FR-SITE-03). See docs/MODULES.md.
 * Labels below go through __() where they are shown, so each one needs a Hindi and Marathi line.
 */
return [
    'feature' => 'qhs',
    'plans' => ['business', 'enterprise'],

    // rfi.approve is what the approval engine asks for, because RFIs are approved as the "rfi" document type.
    'permissions' => [
        'pm' => ['qhs.*', 'safety.*', 'checklist.*', 'rfi.*'],
        'qs' => ['qhs.view', 'safety.view', 'checklist.view', 'rfi.view'],
        'engineer' => ['qhs.view', 'qhs.manage', 'safety.view', 'safety.manage', 'checklist.view', 'checklist.fill', 'rfi.view', 'rfi.manage'],
        'store' => ['safety.view', 'checklist.view'],
        'accounts' => ['qhs.view'],
        'viewer' => ['qhs.view', 'safety.view', 'checklist.view', 'rfi.view'],
    ],

    'nav' => [
        ['place' => 'project', 'label' => 'NCRs', 'route' => 'ncrs.index', 'active' => 'ncrs.*', 'can' => 'qhs.view', 'order' => 10],
        ['place' => 'project', 'label' => 'Quality tests', 'route' => 'quality-tests.index', 'active' => 'quality-tests.*', 'can' => 'qhs.view', 'order' => 20],
        ['place' => 'project', 'label' => 'Safety', 'route' => 'incidents.index', 'active' => 'incidents.*', 'can' => 'safety.view', 'order' => 30],
        ['place' => 'project', 'label' => 'Inspections', 'route' => 'inspections.index', 'active' => 'inspections.*', 'can' => 'checklist.view', 'order' => 40],
        ['place' => 'project', 'label' => 'RFIs', 'route' => 'rfis.index', 'active' => 'rfis.*', 'can' => 'rfi.view', 'order' => 50],
        ['place' => 'company', 'group' => 'Quality & safety', 'label' => 'Checklists', 'route' => 'checklists.index', 'active' => 'checklists.*', 'can' => 'checklist.view', 'order' => 10],
    ],

    'icons' => [],

    'ncr_categories' => [
        'workmanship' => 'Workmanship',
        'material' => 'Material',
        'design' => 'Drawing or design',
        'procedure' => 'Procedure',
        'safety' => 'Safety',
        'other' => 'Other',
    ],

    'ncr_severities' => ['minor' => 'Minor', 'major' => 'Major', 'critical' => 'Critical'],

    'ncr_statuses' => ['open' => 'Open', 'in_progress' => 'In progress', 'resolved' => 'Resolved', 'closed' => 'Closed'],

    // The usual six causes behind a defect.
    'root_causes' => [
        'man' => 'People (skill, supervision)',
        'machine' => 'Machine or tool',
        'material' => 'Material',
        'method' => 'Method or procedure',
        'measurement' => 'Measurement or drawing',
        'environment' => 'Environment or weather',
    ],

    'test_types' => [
        'cube' => 'Concrete cube',
        'cylinder' => 'Concrete cylinder',
        'brick' => 'Brick or block',
        'steel' => 'Steel',
        'soil' => 'Soil or compaction',
        'other' => 'Other',
    ],

    // Cube strength is load over the face of a 150 mm cube.
    'cube_area_mm2' => 22500,

    // Flagged when the mean is below the grade, or any one specimen is more than this far below it (N/mm2).
    'individual_tolerance' => 3,

    // The 7-day mean is expected to reach about this share of the grade strength. An indicator, not a verdict.
    'early_strength_pct' => 65,

    'incident_kinds' => ['incident' => 'Incident', 'near_miss' => 'Near miss'],

    'incident_severities' => ['low' => 'Low', 'medium' => 'Medium', 'high' => 'High', 'critical' => 'Critical'],

    'injuries' => [
        'none' => 'No injury',
        'first_aid' => 'First aid',
        'medical' => 'Medical treatment',
        'lost_time' => 'Lost time',
        'fatal' => 'Fatal',
    ],

    'incident_statuses' => ['open' => 'Open', 'investigating' => 'Investigating', 'closed' => 'Closed'],

    'checklist_categories' => [
        'pour_card' => 'Pour card',
        'shuttering' => 'Shuttering',
        'reinforcement' => 'Reinforcement',
        'ppe' => 'PPE',
        'safety' => 'Safety',
        'general' => 'General',
    ],

    'inspection_results' => ['pending' => 'Pending', 'pass' => 'Pass', 'fail' => 'Fail', 'na' => 'Not applicable'],

    // Approver roles for an RFI when the company has not set a chain for the "rfi" document type.
    'rfi_default_levels' => ['pm'],

    'attachment_max_kb' => 8192,
];
