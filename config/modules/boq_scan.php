<?php

use App\Services\BoqScan\BoqReadKind;
use App\Services\BoqScan\BoqScanOffer;

/*
 * Reader for scanned or photographed BOQs and estimates. See docs/MODULES.md and docs/AI_READERS.md.
 *
 * A person uploads the pages of a scan or photos; each page is read twice by the AI service (the
 * rows with quantity and rate, then separately the amount column), the code multiplies and adds,
 * and every line is checked and confirmed by a person before it is written to the project's BOQ
 * through the Excel import. Nothing the reader returns is saved as a BOQ line by itself.
 *
 * The module has no plan feature, permission or menu entry of its own. Its routes ask for the reports
 * feature and the AI feature, boq.manage and money.view (the whole screen is money), and agents.use
 * where a page is sent. It is opened from the project's BOQ in Excel page.
 */
return [
    /*
     * The reader kind is deployed here. That also registers the scan screen (boq-scans.create, see
     * routes/modules/boq_scan.php), which the website's card, the refusal of a scanned PDF and the offer
     * on the BOQ in Excel page wait for. Being deployed is one part of the one test of whether the readers
     * are on (AiSpend::readersOn): until the platform admin has made a dry run and released the readers,
     * and the company's plan has the AI features, nothing offers a scan and nothing is sent. Its models and
     * limits are in config/modules/document_reading.php under kinds.boq.
     */
    'read_kinds' => [BoqReadKind::class],

    // Answers the field app's "may this person scan BOQ pages?" (status.can.boq_scan). False while the readers are off for the company.
    'reader_offers' => ['boq_scan' => BoqScanOffer::class],

    // Two figures, two lines or a line and a printed total "agree" when they differ by no more than this, in rupees: the rule of the estimate import.
    'tolerance' => '1.00',

    // A reason typed for an override or a total that does not add up is at least this long.
    'reason_min' => 10,
    'reason_max' => 500,

    // Rows one page may hold. An answer with more is not used.
    'max_rows_per_page' => 300,

    // A row read near the top and one near the bottom are paired by height only when they lie within this many parts per thousand of the page.
    'pair_within' => 15,

    /*
     * columns_suspect: when at least "rows" item rows of a page print decimals in both quantity and
     * rate, and this share of them has two decimals in the quantity and three in the rate, the two
     * columns may have been read the wrong way round.
     */
    'columns_suspect' => ['rows' => 3, 'share' => '0.60'],
];
