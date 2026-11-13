<?php

use App\Models\BillCheckExplanation;
use App\Services\BillCheck\Explain\ExplanationWording;

/*
 * RA bill pre-check: the app adds a draft bill up again and holds it against earlier bills, the BOQ or
 * work order, the measurements, the approval rule and the confirmed contract terms. Pure code.
 *
 * It rides on the billing plan feature, so no plan gains anything, and on the permissions a bill
 * already has: ra_bill.generate runs a check and acknowledges findings, ra_bill.view reads one, and
 * settings.manage (owner and admin) decides whether the check is required before a bill is submitted.
 * It has no menu entry: the check is a card on the bill's own page.
 *
 * The findings can be explained in plain words in English, Hindi or Marathi. The app's own words need
 * nothing more; having a model write what each kind of finding means is a text call (purpose "wording")
 * and needs the AI plan feature and agents.use as well.
 */
return [
    'feature' => 'billing',
    'plans' => [],

    'text_calls' => [
        'wording' => [BillCheckExplanation::class => ExplanationWording::class],
    ],

    'explain' => [
        // Kinds of finding a model is asked to explain in one call. Past these the app's own sentence stands,
        // so a long check never asks for more than one call can write.
        'model_topics' => 8,
    ],
];
