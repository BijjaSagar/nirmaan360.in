<?php

namespace App\Services\BillCheck\Explain;

use App\Services\BillCheck\FlagCatalog;

/**
 * What each kind of finding means, in plain words. Findings that mean the same thing share a topic:
 * every line whose amount is not its quantity times its rate is one topic, however many lines a bill
 * has. An explanation says once, before the first finding of a topic, what that topic means and what
 * to look at; the findings follow in the order the check listed them.
 *
 * Each topic has its own sentence written here, which is the explanation whenever no model wrote one.
 * It states no figure, no percentage, no period and no clause, and it promises nothing: Guard A holds
 * it to the same rules as a model's text. Its Hindi and Marathi are in lang/modules/bill_check.
 */
final class FindingTopics
{
    /** The topic of a finding this version of the app does not know. */
    public const OTHER = 'other';

    /** Topic => the finding codes it covers and its own sentence. In the order of FlagCatalog. */
    public const TOPICS = [
        'line_amount' => [
            'codes' => ['A1.amount', 'A1.lump', 'A1.lump_gone'],
            'about' => 'The app works out each line again from its quantity and rate, or from its lump sum value, and compares it with the amount on the bill. Look at the rate and the quantity of the line, and at any change made by hand after the bill was made.',
        ],
        'cumulative' => [
            'codes' => ['A1.cum_qty', 'A1.cum_amount'],
            'about' => 'A cumulative figure is the previous figure plus this bill. When it does not add up, the line was changed by hand after the bill was made. Making the bill again from the measurements is safer than correcting the totals by hand.',
        ],
        'gross' => [
            'codes' => ['A2'],
            'about' => 'The gross value of a bill should be the total of its lines. When it is not, a line or the total was changed by hand. Check the lines first, then the total.',
        ],
        'deductions' => [
            'codes' => ['A3.deduction', 'A3.deduction_missing', 'A3.deduction_extra', 'A3.total', 'A3.gst', 'A3.net', 'A3.released', 'A3.terms'],
            'about' => 'The app works out each deduction, the GST and the net payable again from the terms saved with this bill. A difference means a figure on the bill does not follow from those terms. Check the terms saved with the bill and any deduction typed by hand.',
        ],
        'negative_net' => [
            'codes' => ['A4'],
            'about' => 'The deductions on this bill come to more than its value. Check the advance recovery and the other deductions before the bill goes out.',
        ],
        'previous' => [
            'codes' => ['B1'],
            'about' => 'Previous figures are taken from the earlier bills of the same contract. When an earlier bill was cancelled or changed after this one was made, the previous figures on this bill are out of date. Making this bill again brings them up to date.',
        ],
        'advance' => [
            'codes' => ['B2'],
            'about' => 'The advance still to be recovered follows from the advance given and the recoveries on earlier bills. When it has changed since this bill was made, the recovery on this bill may be wrong. Check the earlier recoveries, or make this bill again.',
        ],
        'earlier_open' => [
            'codes' => ['B3'],
            'about' => 'An earlier bill that is not approved yet is already counted as previous on this bill. If that bill is changed or rejected, this bill has to change too. It is safer to settle the earlier bill first.',
        ],
        'overlap' => [
            'codes' => ['B4'],
            'about' => 'Another live bill of this contract covers part of the same period, so the same work could be billed on both. Check the periods of both bills.',
        ],
        'terms_changed' => [
            'codes' => ['B5.pct', 'B5.amount', 'B5.order', 'B5.basis'],
            'about' => 'The contract form has changed since this bill was made, so its deductions were worked out with the old terms. Decide whether this bill should follow the new terms, and if so, make it again.',
        ],
        'rate_changed' => [
            'codes' => ['B6', 'B6.gone'],
            'about' => 'The rate on the BOQ or the work order has changed since this bill was made. Check which rate the bill should carry before it goes out.',
        ],
        'over_quantity' => [
            'codes' => ['C1', 'C1.zero', 'C2', 'C2.zero', 'C5'],
            'about' => 'The quantity billed so far is above the quantity in the BOQ or the work order. It can be genuine extra work, but extra work usually needs an approved variation or the agreement of the other party. Check the measurements and the variation papers before the bill goes out.',
        ],
        'deviation' => [
            'codes' => ['C3'],
            'about' => 'Measurements marked as a deviation from the BOQ are on this bill. Make sure the deviation was accepted before it is billed.',
        ],
        'over_measured' => [
            'codes' => ['C4'],
            'about' => 'More is billed so far than the approved measurements add up to. Every billed quantity should stand on an approved measurement. Look for measurements still in review and for quantities typed by hand.',
        ],
        'before_period' => [
            'codes' => ['D1'],
            'about' => 'Measurements dated before the start of this bill\'s period are on it. They may belong to an earlier bill. Check that they were not billed before.',
        ],
        'after_period' => [
            'codes' => ['D2'],
            'about' => 'Approved measurements dated after the period of this bill are not on it. They go on the next bill.',
        ],
        'unapproved' => [
            'codes' => ['D3'],
            'about' => 'Measurements within the period of this bill are still in draft or review, so they are not on the bill. Approve them first if they should be billed now.',
        ],
        'reports_vs_measured' => [
            'codes' => ['D4'],
            'about' => 'The approved daily reports show a different quantity of work than the approved measurements. The bill is made from the measurements. A large gap may mean work that is not measured yet, or reports that need a correction.',
        ],
        'no_photo' => [
            'codes' => ['D6'],
            'about' => 'Some measurements on this bill have no photo or sketch. Clients often ask for them when they check a bill.',
        ],
        'rate_or_item' => [
            'codes' => ['D7.rate', 'D7.removed'],
            'about' => 'A line on this bill has no rate, or its BOQ item has been removed. Check whether the line should be on the bill at all.',
        ],
        'duplicates' => [
            'codes' => ['E2'],
            'about' => 'Identical approved measurements of the same item on the same date are on this bill or on earlier ones. The same work may have been entered more than once. Check the measurement book before the bill goes out.',
        ],
        'reversal' => [
            'codes' => ['E3'],
            'about' => 'A measurement and its reversal are both on this bill, so they cancel each other out. That is right only if the reversal was meant to undo that measurement.',
        ],
        'no_approval' => [
            'codes' => ['F1'],
            'about' => 'No approval levels are set for RA bills, so this bill is approved as soon as it is submitted, without anyone else looking at it. An owner or admin can set approval levels.',
        ],
        'client_signs' => [
            'codes' => ['F2'],
            'about' => 'The bill waits for the client\'s signature before it is approved. Make sure the client\'s contact on the project is right.',
        ],
        'missing_details' => [
            'codes' => ['F3.client', 'F3.gstin', 'F3.state'],
            'about' => 'The bill prints a detail that is missing: the client of the project, the GSTIN of your company or the state of the project. Fill it in before the bill goes out.',
        ],
        'gst_mismatch' => [
            'codes' => ['F4.off', 'F4.on'],
            'about' => 'Whether this bill charges GST does not match the contract form. Check which of the two is right before the bill goes out.',
        ],
        'unconfirmed_term' => [
            'codes' => ['T1'],
            'about' => 'The figure used for this deduction was typed on the contract form, and nobody has checked it against the agreement yet. Once someone has, enter it as a confirmed contract term.',
        ],
        'term_differs' => [
            'codes' => ['T2'],
            'about' => 'The deduction on this bill does not match the contract term confirmed for this contract. Either the bill or the term is wrong. Check the agreement and correct the one that is wrong.',
        ],
        'other_deductions' => [
            'codes' => ['T3', 'T3.sub'],
            'about' => 'Other deductions are entered for this contract that are not on this bill. They are shown so you know what is likely to be held back. They do not change the bill.',
        ],
        'overdue' => [
            'codes' => ['T4'],
            'about' => 'An earlier bill is still unpaid after the payment period entered for this contract. You may want to follow it up.',
        ],
        'contract_gone' => [
            'codes' => ['X1'],
            'about' => 'The work order of this bill is no longer in the records, so the checks that need it were left out. Check this bill against the contract yourself.',
        ],
        self::OTHER => [
            'codes' => [],
            'about' => 'Look at this finding before the bill goes out.',
        ],
    ];

    /** The topic a finding belongs to. A code this version does not know gets the general one. */
    public static function of(string $code): string
    {
        foreach (self::TOPICS as $topic => $definition) {
            if (in_array($code, $definition['codes'], true)) {
                return $topic;
            }
        }

        return self::OTHER;
    }

    /** The topic's own sentence, in English. Its Hindi and Marathi come from the module's language files. */
    public static function about(string $topic): string
    {
        return self::TOPICS[$topic]['about'] ?? self::TOPICS[self::OTHER]['about'];
    }

    /** @return list<string> Every topic's sentence, for the translation files. */
    public static function sentences(): array
    {
        return array_values(array_map(fn (array $definition) => $definition['about'], self::TOPICS));
    }

    /** @return list<string> Codes of the catalogue that no topic names. Empty while every finding has a topic. */
    public static function unassigned(): array
    {
        $named = array_merge(...array_values(array_map(fn (array $definition) => $definition['codes'], self::TOPICS)));

        return array_values(array_diff(array_keys(FlagCatalog::DEFINITIONS), $named));
    }
}
