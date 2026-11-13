<?php

use App\Services\SiteDiary\NoticeDeadlineSource;

/*
 * The hindrance register: dated entries made from what the daily reports say held the work up, the
 * calendar days each one lasted, and the date a notice falls on. Pure code, on the site_control feature.
 *
 * The app knows no notice period of its own. A date is worked out only from a notice period a person
 * typed and confirmed under Contract terms (config/modules/contract_terms.php), and is shown with the
 * period it came from. No clause and no period from any agreement is written in this module.
 */
return [
    'feature' => 'site_control',
    'plans' => ['contractor', 'business', 'enterprise'],

    // Nothing here is money, so site staff may keep the register. Letters come with their own permission.
    'permissions' => [
        'pm' => ['site_diary.*'],
        'engineer' => ['site_diary.view', 'site_diary.manage'],
        'qs' => ['site_diary.view'],
    ],

    'nav' => [
        ['place' => 'project', 'label' => 'Hindrance register', 'route' => 'site-diary.hindrances.index', 'active' => 'site-diary.hindrances.*', 'can' => 'site_diary.view', 'order' => 12],
    ],

    // Names for the choice on the form. A category says what held the work up, not whose fault it was.
    'categories' => [
        'drawings_or_decisions' => 'Drawings or decisions awaited',
        'site_handover' => 'Site not handed over',
        'client_material' => 'Material to be given by the client',
        'payment' => 'Payment not received',
        'weather' => 'Weather',
        'utilities_or_permissions' => 'Utilities or permissions',
        'other_agency' => 'Work of another agency',
        'other' => 'Other',
    ],

    'statuses' => [
        'proposed' => 'Proposed',
        'confirmed' => 'Confirmed',
        'dismissed' => 'Dismissed',
    ],

    'alert_sources' => [
        NoticeDeadlineSource::class,
    ],

    // When the app starts reminding people of a notice date it worked out, and how long it keeps the
    // reminder open once that date has passed. These are the app's own reminder settings, counted in
    // calendar days. They are not a period from any agreement and change no date that is shown.
    'remind_days_before' => 7,
    'remind_days_after' => 7,

    // Daily reports offered on the register at one time, newest first.
    'candidates_shown' => 200,
];
