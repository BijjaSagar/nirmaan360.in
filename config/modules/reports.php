<?php

// Reports module: Excel BOQ, rate library, DPR and measurement PDFs, the Reports screen and its scheduled emails.
return [
    'feature' => 'reports',
    'plans' => ['contractor', 'business', 'enterprise'],
    'permissions' => [
        'pm' => ['report.*', 'rate.*'],
        'qs' => ['report.view', 'rate.*'],
        'accounts' => ['report.view'],
    ],
    'nav' => [
        ['place' => 'company', 'group' => 'Reports & rates', 'label' => 'Reports', 'route' => 'reports.index', 'active' => 'reports.*', 'can' => 'report.view', 'order' => 10],
        ['place' => 'company', 'group' => 'Reports & rates', 'label' => 'Rate library', 'route' => 'ratelib.index', 'active' => 'ratelib.*', 'can' => 'rate.view', 'order' => 20],
    ],
    'icons' => [],

    /*
     * Font families that print Marathi or Hindi without Unicode: the PDF holds Latin letters and the
     * font draws them as Devanagari, so the text cannot be read back as typed. A BOQ PDF whose table has
     * text drawn in one is refused; a font only listed beside a page, or used only above the table, is
     * not (App\Services\Reports\PdfTextLayer, BoqPdfReader). Each entry is words of a font name, in any
     * letter case, with or without spaces or hyphens between them.
     *
     * This is a starter list from general knowledge, not a complete one, and it needs a review by
     * someone who works with these files. Add a family here when one is met that the check missed.
     */
    'legacy_font_patterns' => [
        'shree-dev', 'shree-lipi', 'kruti dev', 'dv-tt', 'dvb-tt', 'dvbw-tt', 'akruti', 'aps-dv', 'chanakya', 'shivaji', 'devlys',
    ],
];
