<?php

use App\Services\RateHelper\Sources\OwnBoqSource;
use App\Services\RateHelper\Sources\OwnTenderSource;
use App\Services\RateHelper\Sources\PurchasesSource;
use App\Services\RateHelper\Sources\RateLibrarySource;
use App\Services\RateHelper\Sources\ScheduleSource;

/*
 * Rate helper: for the BOQ lines a person picks, lists rates the company already holds for the same
 * kind of work, each with its source and date, and lets the person accept one line at a time.
 * See docs/MODULES.md.
 *
 * Every rate comes from the company's own records: its rate library, the BOQs of its other projects,
 * its past tenders and its purchases. Nothing is worked out from outside data and no government
 * schedule of rates is loaded. A source that has nothing behind it says it is not connected.
 *
 * The module has no plan feature, permission or menu entry of its own. It opens from the project BOQ
 * and its routes ask for the reports feature, boq.manage and money.view.
 */
return [
    // Where candidates come from, in the order they are asked. Each class implements App\Services\RateHelper\Contracts\RateSource.
    'sources' => [
        RateLibrarySource::class,
        OwnBoqSource::class,
        OwnTenderSource::class,
        PurchasesSource::class,
        ScheduleSource::class,
    ],

    // BOQ lines one run may hold.
    'max_lines' => 100,

    // Candidates kept for one line, best score first.
    'shortlist_size' => 5,

    // How alike two descriptions must be, from 0 to 100, before a row is listed at all.
    'min_score' => 20,

    // Rows one source reads for a run, newest first.
    'pool_limit' => 5000,

    // A rate whose date is older than this many days is marked "old" on the screen.
    'stale_after_days' => 180,

    // Units that mean a lump sum, in lower case without dots or spaces. Such a line gets no candidates, and such a row is never one.
    'lump_sum_units' => ['ls', 'lumpsum', 'job'],
];
