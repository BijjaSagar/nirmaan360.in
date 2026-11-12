<?php

/*
 * RA bill pre-check: the app adds a draft bill up again and holds it against earlier bills, the BOQ or
 * work order, the measurements, the approval rule and the confirmed contract terms. Pure code.
 *
 * It rides on the billing plan feature, so no plan gains anything, and on the permissions a bill
 * already has: ra_bill.generate runs a check and acknowledges findings, ra_bill.view reads one, and
 * settings.manage (owner and admin) decides whether the check is required before a bill is submitted.
 * It has no menu entry: the check is a card on the bill's own page.
 */
return [
    'feature' => 'billing',
    'plans' => [],
];
