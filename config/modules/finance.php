<?php

return [
    'feature' => 'finance',
    'plans' => ['contractor', 'business', 'enterprise'],
    'permissions' => [
        'pm' => ['finance.view', 'finance.manage', 'vendor_bill.approve'],
        'accounts' => ['finance.*', 'vendor_bill.approve'],
        'qs' => ['finance.view'],
    ],
    'nav' => [
        ['place' => 'project', 'label' => 'Finance', 'route' => 'finance.project.ledger', 'active' => 'finance.project.*', 'can' => 'finance.view', 'order' => 10],
        ['place' => 'company', 'group' => 'Finance', 'label' => 'GST reports', 'route' => 'finance.reports.gstr1', 'active' => 'finance.reports.gstr1*', 'can' => 'finance.report', 'order' => 10],
        ['place' => 'company', 'group' => 'Finance', 'label' => 'TDS reports', 'route' => 'finance.reports.tds', 'active' => 'finance.reports.tds*', 'can' => 'finance.report', 'order' => 20],
        ['place' => 'company', 'group' => 'Finance', 'label' => 'Cash-flow forecast', 'route' => 'finance.reports.cash-flow', 'active' => 'finance.reports.cash-flow', 'can' => 'finance.report', 'order' => 30],
        ['place' => 'company', 'group' => 'Finance', 'label' => 'Tally export', 'route' => 'finance.reports.tally', 'active' => 'finance.reports.tally*', 'can' => 'finance.report', 'order' => 40],
    ],

    // Used the first time a company submits a vendor bill. Saved per company as an approval rule.
    'vendor_bill_approval_levels' => ['pm', 'accounts'],

    'cost_heads' => ['Materials', 'Labour', 'Subcontractors', 'Plant & machinery', 'Site expenses', 'Overheads', 'Other'],

    // Where petty cash categories land in budget versus actual.
    'petty_cost_heads' => [
        'Fuel' => 'Plant & machinery',
        'Materials' => 'Materials',
        'Labour' => 'Labour',
    ],
    'petty_default_cost_head' => 'Site expenses',

    'gst_rates' => ['0', '5', '12', '18', '28', '40'],
    'tds_sections' => ['194C', '194J', '194I', '194H', '194Q'],
    'payment_modes' => ['bank' => 'Bank', 'cheque' => 'Cheque', 'upi' => 'UPI', 'cash' => 'Cash'],

    // SAC 9954 covers construction services. The invoice form lets the user enter a finer code.
    'default_sac' => '9954',
    'default_invoice_description' => 'Works contract services',

    // GSTR-1: an invoice to an unregistered buyer in another state is B2CL above this value.
    'b2cl_limit' => '100000',

    // Days after approval that a bill with no due date is assumed to fall due.
    'default_credit_days' => 30,
];
