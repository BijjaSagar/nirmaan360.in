<?php

/*
 * Dated contract terms: what the agreement says, typed by a person with the date it applies from and
 * the clause or page it was read on. It rides on the billing plan feature, so no plan gains anything.
 *
 * Everything below is a name for a choice on the form. No percentage, amount, number of days or clause
 * is written here or anywhere else in the module, and no term is ever filled in for the user.
 */
return [
    'feature' => 'billing',
    'plans' => [],

    // The page shows contract amounts, so it also needs money.view, which these three roles hold.
    'permissions' => [
        'pm' => ['contract_terms.*'],
        'qs' => ['contract_terms.*'],
        'accounts' => ['contract_terms.view'],
    ],

    // Offered only to someone who may open the page: a custom role can hold contract_terms.view without money.view.
    'nav' => [
        ['place' => 'project', 'group' => 'Finance', 'label' => 'Contract terms', 'route' => 'contract-terms.index', 'active' => 'contract-terms.*', 'can' => ['contract_terms.view', 'money.view'], 'order' => 25],
    ],

    'kinds' => [
        'deduction' => 'Deduction from bills',
        'notice' => 'Notice period',
        'excess_quantity' => 'Quantity above the BOQ',
        'payment_period' => 'Payment period',
        'reference' => 'Agreement details',
    ],

    // Kind => code => the name shown for it. "other" takes the name the person types.
    'codes' => [
        'deduction' => [
            'retention' => 'Retention',
            'tds' => 'TDS',
            'gst' => 'GST',
            'advance_recovery' => 'Advance recovery',
            'labour_cess' => 'Labour cess',
            'royalty' => 'Royalty',
            'gst_tds' => 'GST TDS',
            'security_deposit' => 'Security deposit',
            'insurance' => 'Insurance',
            'testing' => 'Testing charges',
            'other' => 'Other deduction',
        ],
        'notice' => [
            'eot_notice' => 'Notice for extension of time',
            'delay_notice' => 'Notice of delay',
        ],
        'excess_quantity' => [
            'excess_quantity' => 'Quantity allowed above the BOQ',
        ],
        'payment_period' => [
            'payment_due' => 'Payment due',
        ],
        'reference' => [
            'agreement_no' => 'Agreement number',
            'agreement_date' => 'Agreement date',
            'stipulated_completion' => 'Stipulated completion date',
            'extension_granted' => 'Extension granted up to',
        ],
    ],

    'methods' => [
        'percent' => 'A percentage',
        'fixed_per_bill' => 'A fixed amount on every bill',
        'typed_each_bill' => 'An amount worked out on each bill',
    ],

    'bases' => [
        'gross' => 'The gross bill amount',
        'running' => 'What is left after the earlier deductions',
        'gross_plus_gst' => 'The gross bill amount plus GST',
    ],

    'natures' => [
        'withheld_refundable' => 'Held back and paid later',
        'tax_credit' => 'Tax that is credited to us',
        'cost' => 'A cost to us',
        'recovery' => 'Recovery of money or material given earlier',
    ],

    // Kind => the starting points a number of days may be counted from.
    'counted_from' => [
        'notice' => [
            'hindrance_start' => 'The day the hindrance started',
            'hindrance_end' => 'The day the hindrance ended',
            'stipulated_completion' => 'The stipulated completion date',
        ],
        'payment_period' => [
            'bill_date' => 'The bill date',
        ],
    ],

    'combine' => [
        'earliest' => 'Whichever comes first',
        'latest' => 'Whichever comes later',
    ],
];
