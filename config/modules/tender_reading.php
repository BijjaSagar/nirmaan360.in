<?php

/*
 * The tender reader: the text of a tender PDF is taken out page by page, read by the AI model into
 * a sheet of dates, deposits, periods and conditions, each with its page and the line it was read
 * from, and a person confirms every fact with the page drawn beside it.
 *
 * It rides on the CRM plan feature and on the tender's own permissions: crm.view reads the sheet,
 * crm.manage starts a read and confirms facts. Starting a read also needs the AI features and
 * agents.use, which the routes check. It has no menu entry: it is a card on the tender's own page.
 *
 * Its model, output limit and time limit are in config/modules/document_reading.php under
 * kinds.tender, with the other readers' figures.
 */
return [
    'feature' => 'crm',
    'plans' => [],

    /*
     * The reader is deployed by listing App\Services\TenderReading\TenderReadKind::class here. The
     * list is empty until the shared reader tests (tests/Feature/DocumentReading) stop registering a
     * stand-in under the same "tender" key next to whatever a module ships: two classes under one key
     * stop every reader. Everything else of the module is in place and works the moment it is listed.
     */
    'read_kinds' => [],

    /*
     * What the reader looks for. Labels only, never a value: the app knows no deposit, period or
     * percentage of any tender. "kind" says how a value is compared and shown; "group" where it sits on
     * the sheet. A "repeatable" field can appear many times with different labels, one per deduction,
     * document or clause, and is compared per label.
     */
    'fields' => [
        'reference' => ['kind' => 'text', 'group' => 'tender', 'label' => 'Tender reference'],
        'title' => ['kind' => 'text', 'group' => 'tender', 'label' => 'Name of work'],
        'authority' => ['kind' => 'text', 'group' => 'tender', 'label' => 'Authority'],
        'location' => ['kind' => 'text', 'group' => 'tender', 'label' => 'Location'],
        'state' => ['kind' => 'text', 'group' => 'tender', 'label' => 'State'],
        'estimated_value' => ['kind' => 'amount', 'group' => 'money', 'label' => 'Estimated value'],
        'published_on' => ['kind' => 'date', 'group' => 'dates', 'label' => 'Published on'],
        'pre_bid_at' => ['kind' => 'datetime', 'group' => 'dates', 'label' => 'Pre-bid meeting'],
        'pre_bid_venue' => ['kind' => 'text', 'group' => 'dates', 'label' => 'Pre-bid meeting place'],
        'due_at' => ['kind' => 'datetime', 'group' => 'dates', 'label' => 'Submission deadline'],
        'opens_at' => ['kind' => 'datetime', 'group' => 'dates', 'label' => 'Bid opening'],
        'financial_opens_at' => ['kind' => 'datetime', 'group' => 'dates', 'label' => 'Financial bid opening'],
        'portal_url' => ['kind' => 'url', 'group' => 'tender', 'label' => 'Portal link'],
        'emd_amount' => ['kind' => 'amount', 'group' => 'money', 'label' => 'EMD amount'],
        'emd_percent' => ['kind' => 'percent', 'group' => 'money', 'label' => 'EMD percentage'],
        'emd_mode' => ['kind' => 'text', 'group' => 'money', 'label' => 'EMD form of payment'],
        'emd_validity' => ['kind' => 'period', 'group' => 'periods', 'label' => 'EMD validity'],
        'tender_fee' => ['kind' => 'amount', 'group' => 'money', 'label' => 'Tender fee'],
        'processing_fee' => ['kind' => 'amount', 'group' => 'money', 'label' => 'Processing fee'],
        'security_deposit' => ['kind' => 'text', 'group' => 'money', 'label' => 'Security deposit'],
        'additional_security' => ['kind' => 'text', 'group' => 'money', 'label' => 'Additional security'],
        'retention' => ['kind' => 'text', 'group' => 'money', 'label' => 'Retention'],
        'completion_period' => ['kind' => 'period', 'group' => 'periods', 'label' => 'Completion period'],
        'defect_liability_period' => ['kind' => 'period', 'group' => 'periods', 'label' => 'Defect liability period'],
        'bid_validity' => ['kind' => 'period', 'group' => 'periods', 'label' => 'Bid validity'],
        'eligibility_class' => ['kind' => 'text', 'group' => 'eligibility', 'label' => 'Contractor class'],
        'eligibility_turnover' => ['kind' => 'text', 'group' => 'eligibility', 'label' => 'Turnover asked for'],
        'eligibility_similar_work' => ['kind' => 'text', 'group' => 'eligibility', 'label' => 'Similar work asked for'],
        'eligibility_bid_capacity' => ['kind' => 'text', 'group' => 'eligibility', 'label' => 'Bid capacity'],
        'deduction' => ['kind' => 'text', 'group' => 'conditions', 'label' => 'Deduction', 'repeatable' => true],
        'checklist_item' => ['kind' => 'text', 'group' => 'documents', 'label' => 'Document to submit', 'repeatable' => true],
        'clause' => ['kind' => 'text', 'group' => 'conditions', 'label' => 'Condition', 'repeatable' => true],
        'other_date' => ['kind' => 'date', 'group' => 'dates', 'label' => 'Other date', 'repeatable' => true],
    ],

    // The order of the groups on the sheet, with their headings.
    'groups' => [
        'tender' => 'The tender',
        'dates' => 'Dates',
        'money' => 'Deposits and fees',
        'periods' => 'Periods',
        'eligibility' => 'Eligibility',
        'documents' => 'Documents to submit',
        'conditions' => 'Conditions and deductions',
    ],

    /*
     * Taking the text out of a PDF. The size and page limits are the ones the BOQ text import already
     * runs with (App\Services\Reports\BoqPdfReader); raise them only after the dry run's measurements
     * of memory and time on the admin screen show it is safe. A longer PDF can only be read as page pictures.
     */
    'max_bytes' => 10 * 1024 * 1024,
    'max_pages' => 50,
    'prepare_seconds' => 60,

    // Text pages sent in one call: at most this many pages, and at most this many characters.
    'run_pages' => 8,
    'run_chars' => 24000,

    // A page with fewer letters and digits than this has no text worth reading: a scan, a drawing, a blank page.
    'min_page_chars' => 20,

    /*
     * A page on which at least this share of the text, out of 100, is drawn in an old non-Unicode
     * Marathi or Hindi font is read only as a page picture: that text is not what the page shows.
     */
    'legacy_share' => 20,

    /*
     * When the person says the document is in Marathi or Hindi but fewer than this share of its letters,
     * out of 100, are Devanagari, its text layer is not the printed text, so every page needs a picture.
     */
    'devanagari_share' => 30,

    // Prepares a company may have going at once, and may start in an hour.
    'prepares_at_once' => 1,
    'prepares_per_hour' => 10,

    // A matched line must be at least this long, and hold a word of this many letters besides the value.
    'line_min_chars' => 20,
    'line_word_letters' => 3,

    // Lengths the reader's answer is cut to.
    'quote_chars' => 300,
    'label_chars' => 160,
    'value_chars' => 500,
];
