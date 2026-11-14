<?php

use App\Models\RateSuggestionRanking;
use App\Services\RateHelper\Ranking\RankCall;
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
 *
 * "Rank with AI" is the one part that calls a model, and so also needs the AI plan feature and
 * agents.use. The model is sent the descriptions and units of the lines and their candidates, never
 * a rate, and only puts the candidates in order with a short note. Every rate is still read by the
 * app from the record, at display and again at accept.
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

    // The text call that ranks candidates. Its model and limits are under purposes.rate_match in config/modules/document_reading.php.
    'text_calls' => [
        'rate_match' => [RateSuggestionRanking::class => RankCall::class],
    ],

    'rank' => [
        // Lines sent in one call. A press over more lines is sent in parts, each a call of its own.
        'lines_per_call' => 20,
        // Characters of a description that are sent, for the BOQ line and for each candidate.
        'line_chars' => 600,
        'candidate_chars' => 400,
        // Characters of a model's note that are kept.
        'note_chars' => 240,
    ],
];
