<?php

return [

    'brand' => 'Nirmaan360',

    'locales' => [
        'en' => 'English',
        'mr' => 'मराठी',
        'hi' => 'हिन्दी',
    ],

    // Extra quantity allowed over the BOQ before an entry is flagged DEVIATION.
    'measurement_tolerance_pct' => 5,

    'default_deduction_order' => ['retention', 'advance', 'material', 'tds'],

    // The orders offered on project and work order forms.
    'deduction_orders' => [
        'retention,advance,material,tds' => 'Retention → Advance → Material → TDS',
        'advance,retention,material,tds' => 'Advance → Retention → Material → TDS',
        'tds,retention,advance,material' => 'TDS → Retention → Advance → Material',
    ],

    // Trial length for new organisations, in days.
    'trial_days' => 30,

    // Days a lapsed paid plan keeps working before it falls back to free.
    'payment_grace_days' => 3,

    'subscription_gst_pct' => 18,

    // plus: GST is added to the plan price. included: the plan price already has GST in it. none: no GST.
    'subscription_gst_mode' => 'plus',

    // Comma-separated emails that can open the platform admin screen.
    'platform_admins' => array_values(array_filter(array_map(
        fn ($email) => strtolower(trim($email)),
        explode(',', (string) env('PLATFORM_ADMIN_EMAILS', ''))
    ))),

    // Prices are in rupees before GST. Null means priced by conversation.
    'plans' => [
        'free' => [
            'label' => 'Free / Site',
            'tagline' => 'For one engineer on one site',
            'monthly' => 0,
            'yearly' => 0,
            'buyable' => false,
            'max_users' => 3,
            'max_projects' => 1,
            'features' => ['site', 'measurement', 'labour', 'petty_cash'],
        ],
        'contractor' => [
            'label' => 'Contractor',
            'tagline' => 'For contractors running several sites',
            'monthly' => 2999,
            'yearly' => 29990,
            'buyable' => true,
            'max_users' => 25,
            'max_projects' => 5,
            'features' => ['site', 'measurement', 'labour', 'petty_cash', 'billing', 'parties'],
        ],
        'business' => [
            'label' => 'Business',
            'tagline' => 'For builders and consultants at scale',
            'monthly' => 7999,
            'yearly' => 79990,
            'buyable' => true,
            'max_users' => null,
            'max_projects' => null,
            'features' => ['site', 'measurement', 'labour', 'petty_cash', 'billing', 'parties', 'api'],
        ],
        'enterprise' => [
            'label' => 'Enterprise / Infra',
            'tagline' => 'For EPC and infrastructure companies',
            'monthly' => null,
            'yearly' => null,
            'buyable' => false,
            'max_users' => null,
            'max_projects' => null,
            'features' => ['site', 'measurement', 'labour', 'petty_cash', 'billing', 'parties', 'api'],
        ],
        // Runs on one phone with no server copy: the server signs a device licence and never syncs the company.
        // Everything else about it lives in config/modules/offline_plan.php.
        'offline' => [
            'label' => 'Offline',
            'tagline' => 'For one person who keeps everything on one phone',
            'monthly' => 499,
            'yearly' => 4990,
            'buyable' => true,
            'max_users' => 1,
            'max_projects' => null,
            // Phones that may hold a licence at the same time. Editable in the admin panel; null means no limit.
            'max_devices' => 1,
            'features' => ['site', 'measurement', 'labour', 'petty_cash'],
        ],
    ],

    // Permissions are module.action strings. A trailing .* grants the whole module.
    'roles' => [
        'owner' => ['label' => 'Owner', 'permissions' => ['*']],
        'admin' => ['label' => 'Admin', 'permissions' => ['*']],
        'pm' => [
            'label' => 'Project Manager',
            'permissions' => [
                'dashboard.view', 'project.*', 'boq.*', 'dpr.*', 'issue.*', 'attendance.*', 'worker.*',
                'petty_cash.*', 'measurement.*', 'work_order.*', 'ra_bill.*', 'party.view',
            ],
        ],
        'qs' => [
            'label' => 'Estimator / QS',
            'permissions' => [
                'dashboard.view', 'project.view', 'boq.*', 'dpr.view', 'measurement.*',
                'work_order.*', 'ra_bill.*', 'party.view',
            ],
        ],
        'engineer' => [
            'label' => 'Site Engineer',
            'permissions' => [
                'dashboard.view', 'project.view', 'boq.view', 'dpr.view', 'dpr.create', 'issue.*',
                'attendance.*', 'worker.view', 'worker.manage', 'petty_cash.view', 'petty_cash.manage',
                'measurement.view', 'measurement.create',
            ],
        ],
        'store' => [
            'label' => 'Store Keeper',
            'permissions' => ['dashboard.view', 'project.view', 'boq.view', 'dpr.view', 'issue.view', 'attendance.view'],
        ],
        'accounts' => [
            'label' => 'Accounts',
            'permissions' => [
                'dashboard.view', 'project.view', 'boq.view', 'petty_cash.*', 'work_order.view',
                'ra_bill.view', 'ra_bill.approve', 'party.*', 'measurement.view',
            ],
        ],
        'viewer' => [
            'label' => 'Viewer',
            'permissions' => [
                'dashboard.view', 'project.view', 'boq.view', 'dpr.view', 'issue.view', 'attendance.view',
                'measurement.view', 'work_order.view', 'ra_bill.view',
            ],
        ],
        // A subcontractor's own login. It reaches the contractor portal and nothing else: ContractorPortalGate
        // turns it away from every other screen and endpoint. "portal" keeps it off the team screens, and
        // "managed_by" names the permission that hands it out, from the contractor's page.
        'contractor' => [
            'label' => 'Contractor',
            'portal' => true,
            'managed_by' => 'contractor_portal.manage',
            'permissions' => ['contractor_portal.view'],
        ],
    ],

    // Roles that can be used as an approval level. "client" signs off through a signed link.
    'approval_level_roles' => [
        'qs' => 'Estimator / QS',
        'pm' => 'Project Manager',
        'accounts' => 'Accounts',
        'owner' => 'Owner',
        'client' => 'Client (signed link)',
    ],

    // OTP sign-in stays hidden in production until a real SMS driver is added.
    'sms_driver' => env('SMS_DRIVER', 'log'),

    // Ordered approver roles per document type. Editable per organisation in Settings.
    'approval_defaults' => [
        'measurement' => ['qs', 'pm'],
        'ra_bill' => ['qs', 'pm', 'accounts'],
        'dpr' => ['pm'],
    ],

    // These roles may act at any approval level, including on their own submissions.
    'approver_override_roles' => ['owner', 'admin'],

    'issue_priorities' => ['low', 'medium', 'high', 'critical'],
    'issue_statuses' => ['open', 'in_progress', 'resolved', 'closed'],
    'project_statuses' => ['planning', 'active', 'on_hold', 'completed', 'closed'],
    'party_types' => ['client', 'consultant', 'subcontractor', 'vendor'],
    'attendance_statuses' => ['present', 'half', 'absent', 'overtime'],
    'expense_categories' => ['Fuel', 'Food', 'Travel', 'Tools', 'Materials', 'Labour', 'Repairs', 'Other'],
    'trades' => ['Mason', 'Helper', 'Carpenter', 'Bar bender', 'Electrician', 'Plumber', 'Painter', 'Welder', 'Operator', 'Supervisor'],
    'units' => ['cum', 'sqm', 'rmt', 'nos', 'kg', 'MT', 'ltr', 'bag', 'LS'],
    'weather' => ['Clear', 'Cloudy', 'Rain', 'Windy', 'Hot'],

    // How far back a device's first pull reaches for transactional records.
    'sync_window_days' => (int) env('SYNC_WINDOW_DAYS', 60),
];
