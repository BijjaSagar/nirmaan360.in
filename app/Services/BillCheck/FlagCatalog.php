<?php

namespace App\Services\BillCheck;

/**
 * Every finding the pre-check can make: its level, the figures it carries and the sentence that states
 * it. Each sentence is a fixed template. The app places the figures; nothing else writes them.
 *
 * A finding with a rupee figure, a contract percentage or words copied from a contract term has two
 * sentences: "money" for a reader who may see money, and "plain" for everyone else, which names none
 * of them. A finding without any has one sentence, "text".
 *
 * Figure types: money, pct (a contract percentage) and source (words typed on a contract term) are
 * shown only with money.view. qty, ratio (a share of a quantity), count, date, text and head (the name
 * of a deduction) are shown to everyone who may open the bill.
 */
final class FlagCatalog
{
    /** Figure types a reader without money.view never receives. */
    public const PRIVATE_TYPES = ['money', 'pct', 'source'];

    /** The deductions a bill carries, by the name the bill gives them. */
    public const HEADS = [
        'retention' => 'Retention',
        'advance' => 'Advance recovery',
        'advance_recovery' => 'Advance recovery',
        'material' => 'Material recovery',
        'tds' => 'TDS',
        'gst' => 'GST',
    ];

    private const B = Flag::BLOCKING;

    private const T = Flag::TICK;

    private const N = Flag::NOTE;

    public const DEFINITIONS = [
        // Arithmetic
        'A1.amount' => [
            'level' => self::B,
            'params' => ['item' => 'text', 'qty' => 'qty', 'rate' => 'money', 'stored' => 'money', 'expected' => 'money'],
            'money' => 'Item :item: :qty at a rate of :rate comes to :expected, but the bill shows :stored.',
            'plain' => 'Item :item: the amount on the bill is not its quantity times its rate.',
        ],
        'A1.lump' => [
            'level' => self::B,
            'params' => ['item' => 'text', 'value' => 'money', 'stored' => 'money', 'expected' => 'money'],
            'money' => 'Item :item: a lump sum line earns up to its fixed value of :value, which gives :expected for this bill, but the bill shows :stored.',
            'plain' => 'Item :item: the amount on the bill is not what its lump sum value gives.',
        ],
        'A1.lump_gone' => [
            'level' => self::B,
            'params' => ['item' => 'text'],
            'text' => 'Item :item is no longer on the work order, so its lump sum value could not be worked out again.',
        ],
        'A1.cum_qty' => [
            'level' => self::B,
            'params' => ['item' => 'text', 'prev' => 'qty', 'current' => 'qty', 'stored' => 'qty', 'expected' => 'qty'],
            'text' => 'Item :item: previous :prev plus this bill :current is :expected, but the cumulative quantity shows :stored.',
        ],
        'A1.cum_amount' => [
            'level' => self::B,
            'params' => ['item' => 'text', 'stored' => 'money', 'expected' => 'money'],
            'money' => 'Item :item: the previous amount plus this bill is :expected, but the cumulative amount shows :stored.',
            'plain' => 'Item :item: the cumulative amount is not the previous amount plus this bill.',
        ],
        'A2' => [
            'level' => self::B,
            'params' => ['stored' => 'money', 'expected' => 'money'],
            'money' => 'The lines of this bill add up to :expected, but its gross value shows :stored.',
            'plain' => 'The gross value of this bill is not the total of its lines.',
        ],
        'A3.deduction' => [
            'level' => self::B,
            'params' => ['head' => 'head', 'stored' => 'money', 'expected' => 'money'],
            'money' => ':head worked out again from the terms saved with this bill is :expected, but the bill shows :stored.',
            'plain' => ':head on this bill is not what the terms saved with it give.',
        ],
        'A3.deduction_missing' => [
            'level' => self::B,
            'params' => ['head' => 'head', 'expected' => 'money'],
            'money' => ':head of :expected follows from the terms saved with this bill, but it is not on the bill.',
            'plain' => ':head follows from the terms saved with this bill, but it is not on the bill.',
        ],
        'A3.deduction_extra' => [
            'level' => self::B,
            'params' => ['head' => 'head', 'stored' => 'money'],
            'money' => 'The bill shows :head of :stored, which the terms saved with it do not give.',
            'plain' => 'The bill shows :head, which the terms saved with it do not give.',
        ],
        'A3.total' => [
            'level' => self::B,
            'params' => ['stored' => 'money', 'expected' => 'money'],
            'money' => 'The deductions worked out again total :expected, but the bill shows :stored.',
            'plain' => 'The total of deductions on this bill is not what the terms saved with it give.',
        ],
        'A3.gst' => [
            'level' => self::B,
            'params' => ['stored' => 'money', 'expected' => 'money'],
            'money' => 'GST worked out again is :expected, but the bill shows :stored.',
            'plain' => 'GST on this bill is not what the terms saved with it give.',
        ],
        'A3.net' => [
            'level' => self::B,
            'params' => ['stored' => 'money', 'expected' => 'money'],
            'money' => 'Net payable worked out again is :expected, but the bill shows :stored.',
            'plain' => 'Net payable on this bill is not what its own figures give.',
        ],
        'A3.released' => [
            'level' => self::B,
            'params' => ['stored' => 'money', 'expected' => 'money'],
            'money' => 'Retention held on the earlier bills is :expected, but this bill releases :stored.',
            'plain' => 'The retention released on this bill is not the retention held on the earlier bills.',
        ],
        'A3.terms' => [
            'level' => self::B,
            'params' => [],
            'text' => 'The terms saved with this bill name a deduction the app cannot work out, so its deductions were not checked.',
        ],
        'A4' => [
            'level' => self::B,
            'params' => ['net' => 'money'],
            'money' => 'Net payable is below zero: :net.',
            'plain' => 'Net payable is below zero.',
        ],

        // Against earlier bills
        'B1' => [
            'level' => self::B,
            'params' => ['item' => 'text', 'stored_qty' => 'qty', 'now_qty' => 'qty', 'stored_amount' => 'money', 'now_amount' => 'money'],
            'money' => 'Item :item: the earlier bills now add up to :now_qty and :now_amount, but this bill carries :stored_qty and :stored_amount as previous.',
            'plain' => 'Item :item: the earlier bills now add up to :now_qty, and this bill carries :stored_qty as previous. Its previous figures no longer match.',
        ],
        'B2' => [
            'level' => self::B,
            'params' => ['stored' => 'money', 'now' => 'money'],
            'money' => 'The advance still to be recovered is now :now, but this bill was worked out with :stored.',
            'plain' => 'The advance still to be recovered has changed since this bill was worked out.',
        ],
        'B3' => [
            'level' => self::T,
            'params' => ['number' => 'text'],
            'text' => 'Earlier bill :number is not approved yet. Its quantities are already counted as previous on this bill.',
        ],
        'B4' => [
            'level' => self::T,
            'params' => ['number' => 'text', 'from' => 'date', 'to' => 'date'],
            'text' => 'Bill :number covers :from to :to, which overlaps the period of this bill.',
        ],
        'B5.pct' => [
            'level' => self::T,
            'params' => ['head' => 'head', 'bill' => 'pct', 'now' => 'pct'],
            'money' => ':head on this bill is :bill, but the contract form now says :now.',
            'plain' => ':head on the contract form has changed since this bill was worked out.',
        ],
        'B5.amount' => [
            'level' => self::T,
            'params' => ['bill' => 'money', 'now' => 'money'],
            'money' => 'The advance on this bill is :bill, but the contract form now says :now.',
            'plain' => 'The advance on the contract form has changed since this bill was worked out.',
        ],
        'B5.order' => [
            'level' => self::T,
            'params' => [],
            'text' => 'The order of deductions on the contract form has changed since this bill was worked out.',
        ],
        'B5.basis' => [
            'level' => self::T,
            'params' => [],
            'text' => 'The amount that deductions are worked out on has changed on the contract form since this bill was worked out.',
        ],
        'B6' => [
            'level' => self::T,
            'params' => ['item' => 'text', 'bill' => 'money', 'now' => 'money'],
            'money' => 'Item :item is billed at :bill, but its rate is now :now.',
            'plain' => 'Item :item: its rate has changed since this bill was worked out.',
        ],
        'B6.gone' => [
            'level' => self::T,
            'params' => ['item' => 'text'],
            'text' => 'Item :item is no longer on the work order, so its rate today could not be compared.',
        ],

        // Quantities
        'C1' => [
            'level' => self::T,
            'params' => ['item' => 'text', 'unit' => 'text', 'cumulative' => 'qty', 'limit' => 'qty', 'excess' => 'qty', 'over' => 'ratio'],
            'text' => 'Item :item: billed so far :cumulative :unit against a BOQ quantity of :limit :unit. That is :excess :unit more, :over above it.',
        ],
        'C1.zero' => [
            'level' => self::T,
            'params' => ['item' => 'text', 'unit' => 'text', 'cumulative' => 'qty'],
            'text' => 'Item :item: billed so far :cumulative :unit, but its BOQ quantity is zero.',
        ],
        'C2' => [
            'level' => self::T,
            'params' => ['item' => 'text', 'unit' => 'text', 'cumulative' => 'qty', 'limit' => 'qty', 'excess' => 'qty', 'over' => 'ratio'],
            'text' => 'Item :item: billed so far :cumulative :unit against a work order quantity of :limit :unit. That is :excess :unit more, :over above it.',
        ],
        'C2.zero' => [
            'level' => self::N,
            'params' => ['item' => 'text'],
            'text' => 'Item :item has no quantity on the work order, so billing above it could not be checked.',
        ],
        'C3' => [
            'level' => self::T,
            'params' => ['item' => 'text', 'unit' => 'text', 'count' => 'count', 'qty' => 'qty'],
            'text' => 'Item :item: measurements on this bill that were marked as a deviation from the BOQ quantity: :count, together :qty :unit.',
        ],
        'C4' => [
            'level' => self::T,
            'params' => ['item' => 'text', 'unit' => 'text', 'cumulative' => 'qty', 'measured' => 'qty'],
            'text' => 'Item :item: billed so far :cumulative :unit, but approved measurements add up to :measured :unit.',
        ],
        'C5' => [
            'level' => self::T,
            'params' => ['item' => 'text', 'unit' => 'text', 'cumulative' => 'qty', 'limit' => 'qty', 'allowed' => 'pct', 'source' => 'source', 'from' => 'date'],
            'money' => 'Item :item: billed so far :cumulative :unit. The term entered for this contract allows :allowed above the quantity, which is :limit :unit (:source, in force from :from).',
            'plain' => 'Item :item: billed so far :cumulative :unit, which is above the :limit :unit that the term entered for this contract allows.',
        ],

        // Measurements
        'D1' => [
            'level' => self::T,
            'params' => ['count' => 'count', 'from' => 'date', 'earliest' => 'date'],
            'text' => 'Measurements on this bill dated before its period starts on :from: :count. The earliest is dated :earliest.',
        ],
        'D2' => [
            'level' => self::N,
            'params' => ['count' => 'count'],
            'text' => 'Approved measurements dated after the period of this bill, which are not on it: :count.',
        ],
        'D3' => [
            'level' => self::T,
            'params' => ['count' => 'count'],
            'text' => 'Measurements dated within the period of this bill that are still in draft or review, and so not on it: :count.',
        ],
        'D4' => [
            'level' => self::N,
            'params' => ['item' => 'text', 'unit' => 'text', 'measured' => 'qty', 'reported' => 'qty'],
            'text' => 'Item :item: approved measurements up to the end of the period add up to :measured :unit, but approved daily reports show :reported :unit.',
        ],
        'D6' => [
            'level' => self::N,
            'params' => ['count' => 'count', 'total' => 'count'],
            'text' => 'Measurements on this bill with no photo or sketch: :count of :total.',
        ],
        'D7.rate' => [
            'level' => self::T,
            'params' => ['item' => 'text'],
            'text' => 'Item :item is billed at a rate of zero.',
        ],
        'D7.removed' => [
            'level' => self::T,
            'params' => ['item' => 'text'],
            'text' => 'Item :item has been removed from the BOQ, but it is on this bill.',
        ],

        // Duplicates
        'E2' => [
            'level' => self::T,
            'params' => ['item' => 'text', 'unit' => 'text', 'date' => 'date', 'qty' => 'qty', 'count' => 'count'],
            'text' => 'Item :item: identical approved measurements dated :date, each :qty :unit: :count. Check that the same work was not entered more than once.',
        ],
        'E3' => [
            'level' => self::T,
            'params' => ['item' => 'text', 'unit' => 'text', 'qty' => 'qty'],
            'text' => 'Item :item: a measurement of :qty :unit and its reversal are both on this bill, so they cancel each other out.',
        ],

        // Process
        'F1' => [
            'level' => self::T,
            'params' => [],
            'text' => 'No approval levels are set for RA bills, so submitting this bill approves it at once.',
        ],
        'F2' => [
            'level' => self::N,
            'params' => [],
            'text' => 'The client signs this bill through a link before it is approved.',
        ],
        'F3.client' => [
            'level' => self::T,
            'params' => [],
            'text' => 'This bill names no client, because the project had none when the bill was made.',
        ],
        'F3.gstin' => [
            'level' => self::T,
            'params' => [],
            'text' => 'This bill charges GST, but the GSTIN of your company is not entered.',
        ],
        'F3.state' => [
            'level' => self::T,
            'params' => [],
            'text' => 'This bill charges GST, but the state of the project is not entered.',
        ],
        'F4.off' => [
            'level' => self::T,
            'params' => ['pct' => 'pct'],
            'money' => 'This bill is raised without GST, but the contract form says GST :pct.',
            'plain' => 'This bill is raised without GST, but the contract form has GST on it.',
        ],
        'F4.on' => [
            'level' => self::T,
            'params' => ['pct' => 'pct'],
            'money' => 'This bill charges GST :pct, but the contract form now has no GST.',
            'plain' => 'This bill charges GST, but the contract form now has no GST.',
        ],

        // Contract terms
        'T1' => [
            'level' => self::T,
            'params' => ['head' => 'head', 'pct' => 'pct'],
            'money' => ':head :pct: this percentage is the one typed on the contract form. Nobody has confirmed it against the agreement.',
            'plain' => ':head: this percentage is the one typed on the contract form. Nobody has confirmed it against the agreement.',
        ],
        'T2' => [
            'level' => self::B,
            'params' => ['head' => 'head', 'bill' => 'pct', 'term' => 'pct', 'source' => 'source', 'from' => 'date'],
            'money' => ':head on this bill is :bill, but the term confirmed for this contract says :term (:source, in force from :from).',
            'plain' => ':head on this bill is not the percentage of the term confirmed for this contract, in force from :from.',
        ],
        'T3' => [
            'level' => self::T,
            'params' => ['count' => 'count', 'total' => 'money', 'receipt' => 'money'],
            'money' => 'Other deductions entered for this contract that are not on this bill: :count. Those with a figure come to :total, which leaves an expected receipt of :receipt.',
            'plain' => 'Other deductions entered for this contract that are not on this bill: :count.',
        ],
        'T3.sub' => [
            'level' => self::T,
            'params' => ['count' => 'count', 'total' => 'money', 'receipt' => 'money'],
            'money' => 'Other deductions entered for this work order that are not on this bill: :count. Those with a figure come to :total, which leaves an expected payment of :receipt.',
            'plain' => 'Other deductions entered for this work order that are not on this bill: :count.',
        ],
        'T4' => [
            'level' => self::T,
            'params' => ['number' => 'text', 'due' => 'date', 'outstanding' => 'money', 'source' => 'source'],
            'money' => 'Bill :number was due for payment on :due under the payment period entered for this contract (:source), and :outstanding is still unpaid.',
            'plain' => 'Bill :number was due for payment on :due under the payment period entered for this contract, and it is not fully paid.',
        ],

        // The contract itself
        'X1' => [
            'level' => self::T,
            'params' => [],
            'text' => 'The work order of this bill is no longer in the records, so the checks against its contract were left out.',
        ],
    ];

    /** @return array{level: string, params: array<string, string>, text?: string, money?: string, plain?: string}|null */
    public static function definition(string $code): ?array
    {
        return self::DEFINITIONS[$code] ?? null;
    }

    public static function level(string $code): string
    {
        return self::DEFINITIONS[$code]['level'];
    }

    /** The English sentence for a finding: with its figures for a reader who may see money, without them otherwise. */
    public static function template(string $code, bool $money): ?string
    {
        $definition = self::definition($code);

        return $definition === null ? null : ($definition['text'] ?? $definition[$money ? 'money' : 'plain']);
    }

    /** @return list<string> The figures of this finding that a reader without money.view never receives. */
    public static function privateParams(string $code): array
    {
        return array_keys(array_filter(
            self::definition($code)['params'] ?? [],
            fn (string $type) => in_array($type, self::PRIVATE_TYPES, true),
        ));
    }

    /** @return list<string> Every sentence in the catalogue, for the translation files. */
    public static function sentences(): array
    {
        $sentences = [];

        foreach (self::DEFINITIONS as $definition) {
            foreach (['text', 'money', 'plain'] as $form) {
                if (isset($definition[$form])) {
                    $sentences[] = $definition[$form];
                }
            }
        }

        return array_values(array_unique($sentences));
    }
}
