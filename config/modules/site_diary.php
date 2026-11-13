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
        ['place' => 'project', 'label' => 'Letters', 'route' => 'site-diary.letters.index', 'active' => 'site-diary.letters.*', 'can' => 'site_diary.letters', 'order' => 13],
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

    // The letters the app drafts. Every sentence that states a figure is written in App\Services\SiteDiary\LetterLayout,
    // and the figure is read from the records. site_diary.letters is held by the project manager through the wildcard above.
    'letter_types' => [
        'eot_request' => 'Request for extension of time',
        'delay_notice' => 'Notice of delay',
        'payment_followup' => 'Payment follow-up',
    ],

    'letter_statuses' => [
        'draft' => 'Draft',
        'finalised' => 'Finalised',
    ],

    // The languages a letter may be prepared in on the live site are listed with every other document a client
    // or another party reads, under wording.reviewed_languages.site_letters (App\Services\Wording\ReviewedLanguages).

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
