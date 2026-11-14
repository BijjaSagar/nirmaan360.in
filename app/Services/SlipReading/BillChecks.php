<?php

namespace App\Services\SlipReading;

use App\Models\SlipRead;
use App\Models\VendorBill;
use App\Services\DocumentReading\Cell;
use App\Services\DocumentReading\PrintedNumber;
use App\Services\Finance\Gst;
use App\Support\Dec;
use Brick\Math\BigDecimal;

/**
 * Turns the reader's answer for a supplier bill into what a bill form is offered, by code. Each
 * printed figure is parsed on its own, and then the bill is added up the way it was printed: each
 * line's quantity times rate against its amount, the lines against the taxable value, the tax heads
 * against one of the GST rates the app knows, and taxable value plus tax plus round off against the
 * grand total. A figure that fails a check is not offered and the person types it.
 *
 * Two values are worked out rather than read, and only from what is printed: the GST rate (the tax
 * heads over the taxable value, offered only when exactly one known rate fits) and the supply type
 * (an IGST figure alone is between states; CGST and SGST alone is within the state). The states of
 * the two GSTINs only raise a warning; no tax rule is coded. TDS, purchase orders and goods receipts
 * are never filled.
 *
 * The values a form takes are kept in the same cells as the challan's: bill_no, bill_date (Y-m-d),
 * taxable_total and grand_total (two decimals), gst_pct (one of config modules.finance.gst_rates as
 * written there), supply_type (intra or inter), and the supplier found by its GSTIN under
 * suggestions.party.
 *
 * Runs inside the transaction that saves the page, with nobody signed in, so every query names the
 * company.
 */
class BillChecks
{
    use ReadsCells;

    public const DOC_KINDS = ['tax_invoice', 'bill_of_supply', 'delivery_challan', 'other', 'unreadable'];

    /** Papers a bill form can be filled from. */
    public const BILLS = ['tax_invoice', 'bill_of_supply'];

    /** A failed check of this kind is arithmetic, so a person may ask the stronger model to read the photo again. */
    public const ARITHMETIC = ['lines_do_not_add_up', 'taxable_does_not_add_up', 'total_does_not_add_up'];

    /** Warnings a person ticks before the form can be saved with this reading. */
    public const TICKED = ['same_photo'];

    /** The cells of a bill line. */
    public const LINE_PARTS = ['description', 'hsn', 'qty', 'unit', 'rate', 'amount'];

    public const TAX_HEADS = ['cgst', 'sgst', 'igst'];

    /** The most a printed figure may differ from what the others add up to, in rupees. */
    public const TOLERANCE = '1.00';

    /** At most this many lines are kept from one photo. */
    private const MOST_LINES = 40;

    /** Fields of the forms, with the most characters each may have. */
    private const TEXT_FIELDS = ['bill_no' => 50, 'supplier_name' => 160, 'eway_bill_no' => 30];

    /**
     * @param  array<string, mixed>  $answer  The reader's accepted answer.
     * @param  ?array{fields: array<string, mixed>, lines: list<array<string, mixed>>}  $earlier  The first reading, when this is the stronger model's second one.
     * @return array{doc_kind: string, fields: array<string, array<string, mixed>>, lines: list<array<string, mixed>>, warnings: list<array<string, mixed>>, suggestions: array<string, mixed>}
     */
    public function build(array $answer, SlipRead $slip, ?array $earlier = null): array
    {
        $organizationId = (int) $slip->organization_id;
        $units = $this->units($organizationId);
        $warnings = [];

        $fields = [
            'bill_no' => $this->text($answer['bill_no'] ?? [], self::TEXT_FIELDS['bill_no']),
            'bill_date' => $this->date($answer['bill_date'] ?? []),
            'supplier_name' => $this->text($answer['supplier_name'] ?? [], self::TEXT_FIELDS['supplier_name']),
            'supplier_gstin' => $this->gstin($answer['supplier_gstin'] ?? []),
            'buyer_gstin' => $this->gstin($answer['buyer_gstin'] ?? []),
            'eway_bill_no' => $this->text($answer['eway_bill_no'] ?? [], self::TEXT_FIELDS['eway_bill_no']),
            'taxable_total' => $this->number($answer['taxable_total'] ?? [], 'amount'),
            'cgst' => $this->number($answer['cgst'] ?? [], 'amount', zero: true),
            'sgst' => $this->number($answer['sgst'] ?? [], 'amount', zero: true),
            'igst' => $this->number($answer['igst'] ?? [], 'amount', zero: true),
            'round_off' => $this->roundOff($answer['round_off'] ?? []),
            'grand_total' => $this->number($answer['grand_total'] ?? [], 'amount'),
        ];

        $lines = [];

        foreach (array_slice(is_array($answer['lines'] ?? null) ? $answer['lines'] : [], 0, self::MOST_LINES) as $line) {
            $lines[] = [
                'description' => $this->text($line['description'] ?? [], 200),
                'hsn' => $this->text($line['hsn'] ?? [], 20),
                'qty' => $this->number($line['qty'] ?? []),
                'unit' => $this->unit($line['unit'] ?? [], $units),
                'rate' => $this->number($line['rate'] ?? [], 'rate'),
                'amount' => $this->number($line['amount'] ?? [], 'amount'),
            ];
        }

        if ($earlier !== null) {
            [$fields, $lines, $disagree] = $this->compare($fields, $lines, $earlier, self::LINE_PARTS);

            if ($disagree) {
                $warnings[] = ['code' => 'reads_disagree', 'params' => [], 'tick' => false];
            }
        }

        [$fields, $lines, $sums] = $this->addUp($fields, $lines);
        $warnings = [...$warnings, ...$sums];

        $docKind = in_array($answer['doc_kind'] ?? null, self::DOC_KINDS, true) ? $answer['doc_kind'] : 'other';

        if ($docKind === 'unreadable') {
            $warnings[] = ['code' => 'nothing_read', 'params' => [], 'tick' => false];
        } elseif (! in_array($docKind, self::BILLS, true)) {
            $warnings[] = ['code' => 'not_a_bill', 'params' => ['doc_kind' => $docKind], 'tick' => false];
        }

        $party = $this->party($organizationId, $fields['supplier_gstin'], ['vendor', 'subcontractor']);

        if ($party === false) {
            $fields['supplier_gstin']['warnings'][] = 'gstin_check_failed';
            $party = null;
        }

        return [
            'doc_kind' => $docKind,
            'fields' => $fields,
            'lines' => $lines,
            'warnings' => [...$warnings, ...$this->duplicates($slip, $fields, $party)],
            'suggestions' => ['party' => $party, 'materials' => []],
        ];
    }

    /**
     * Every sum on the bill, and the two values worked out from the tax heads. A figure that fails a
     * check keeps what was printed and is not offered.
     *
     * @return array{0: array<string, array<string, mixed>>, 1: list<array<string, mixed>>, 2: list<array<string, mixed>>}
     */
    private function addUp(array $fields, array $lines): array
    {
        $warnings = [];

        // Each line: quantity times rate against the amount printed beside them.
        $failed = [];

        foreach ($lines as $index => $line) {
            [$qty, $rate, $amount] = [$this->known($line['qty']), $this->known($line['rate']), $this->known($line['amount'])];

            if ($qty !== null && $rate !== null && $amount !== null && ! $this->near(Dec::of($qty)->multipliedBy(Dec::of($rate)), $amount)) {
                $lines[$index]['amount']['value'] = null;
                $lines[$index]['amount']['warnings'][] = 'qty_times_rate_differs';
                $failed[] = $index + 1;
            }
        }

        if ($failed !== []) {
            $warnings[] = ['code' => 'lines_do_not_add_up', 'params' => ['lines' => implode(', ', $failed)], 'tick' => false];
        }

        // The lines against the taxable value: only when every line's amount was read.
        $taxable = $this->known($fields['taxable_total']);
        $amounts = array_map(fn (array $line) => $this->known($line['amount']), $lines);
        $linesSum = $lines !== [] && ! in_array(null, $amounts, true) ? $this->sum($amounts) : null;
        $linesAgree = $linesSum !== null && $taxable !== null ? $this->near($linesSum, $taxable) : null;

        if ($linesAgree === false) {
            $fields['taxable_total']['warnings'][] = 'lines_differ';
            $warnings[] = ['code' => 'taxable_does_not_add_up', 'params' => [], 'tick' => false];
        }

        // The tax heads. One printed and not read as a figure leaves the tax unknown.
        $heads = [];
        $printed = [];
        $taxKnown = true;

        foreach (self::TAX_HEADS as $head) {
            $heads[$head] = $fields[$head]['raw'] === '' ? null : $this->known($fields[$head]);

            if ($fields[$head]['raw'] !== '') {
                $printed[] = $head;
                $taxKnown = $taxKnown && $heads[$head] !== null;
            }
        }

        $tax = $taxKnown ? $this->sum(array_values(array_filter($heads, fn ($value) => $value !== null))) : null;
        [$supply, $supplyWarnings] = $this->supplyType($heads, $taxKnown, $printed, $fields);
        $fields['supply_type'] = $this->worked($supply, self::TAX_HEADS, $fields, $supplyWarnings);

        if (in_array('states_differ', $supplyWarnings, true)) {
            $warnings[] = ['code' => 'states_differ', 'params' => [], 'tick' => false];
        }

        [$rate, $rateWarnings] = $this->gstRate($taxable, $tax, $heads, $printed);
        $fields['gst_pct'] = $this->worked($rate, ['taxable_total', ...self::TAX_HEADS], $fields, $rateWarnings);

        // Taxable value plus tax plus round off against the grand total.
        $roundOff = $fields['round_off']['raw'] === '' ? '0.00' : $this->known($fields['round_off']);
        $roundOffFits = $roundOff !== null && Dec::of($roundOff)->abs()->isLessThanOrEqualTo(Dec::of(self::TOLERANCE));

        if ($roundOff !== null && ! $roundOffFits) {
            $fields['round_off']['value'] = null;
            $fields['round_off']['warnings'][] = 'round_off_too_large';
        }

        // With no taxable value printed, a bill with no tax on it adds up from its lines.
        $base = $taxable ?? ($printed === [] ? $linesSum : null);
        $grand = $this->known($fields['grand_total']);
        $totalAgrees = null;

        if ($grand !== null && $base !== null && $tax !== null && $roundOff !== null) {
            $totalAgrees = $roundOffFits && $this->near(Dec::of($base)->plus(Dec::of($tax))->plus(Dec::of($roundOff)), $grand);
        }

        if ($totalAgrees === false) {
            $fields['grand_total']['warnings'][] = 'total_differs';
            $warnings[] = ['code' => 'total_does_not_add_up', 'params' => [], 'tick' => false];
        }

        if ($totalAgrees !== true) {
            $fields['grand_total'] = $this->unchecked($fields['grand_total']);
        }

        // The taxable value is offered when the lines add up to it, or, with no lines to add, when the grand total does.
        if (! ($linesAgree === true || ($linesAgree === null && $totalAgrees === true))) {
            $fields['taxable_total'] = $this->unchecked($fields['taxable_total']);
        }

        return [$fields, $lines, $warnings];
    }

    /**
     * Within the state or between states, from the tax heads printed and nothing else: IGST alone is
     * between states, CGST and SGST together without IGST are within the state. The two GSTINs'
     * states only add a warning when they say the other thing.
     *
     * @param  array<string, ?string>  $heads
     * @param  list<string>  $printed
     * @return array{0: ?string, 1: list<string>}
     */
    private function supplyType(array $heads, bool $taxKnown, array $printed, array $fields): array
    {
        $positive = fn (string $head) => $heads[$head] !== null && Dec::of($heads[$head])->isPositive();

        $supply = match (true) {
            ! $taxKnown => null,
            $positive('igst') && ! $positive('cgst') && ! $positive('sgst') => 'inter',
            $positive('cgst') && $positive('sgst') && ! $positive('igst') => 'intra',
            default => null,
        };

        // A head that was printed and not read as a figure carries its own warning.
        if ($supply === null) {
            return [null, $taxKnown && $printed !== [] ? ['tax_heads_mixed'] : []];
        }

        $from = Gst::codeFromGstin($fields['supplier_gstin']['value']);
        $to = Gst::codeFromGstin($fields['buyer_gstin']['value']);

        return [$supply, $from !== null && $to !== null && ($from === $to) !== ($supply === 'intra') ? ['states_differ'] : []];
    }

    /**
     * The GST rate: the tax over the taxable value must fit exactly one rate the app knows, within a
     * rupee, with CGST and SGST equal. Otherwise the person enters the rate.
     *
     * @param  array<string, ?string>  $heads
     * @param  list<string>  $printed
     * @return array{0: ?string, 1: list<string>}
     */
    private function gstRate(?string $taxable, ?string $tax, array $heads, array $printed): array
    {
        if ($taxable === null) {
            return [null, []];
        }

        if ($printed === []) {
            return [null, ['no_tax_printed']];
        }

        // A head that was printed and not read as a figure carries its own warning.
        if ($tax === null) {
            return [null, []];
        }

        if ($heads['cgst'] !== null && $heads['sgst'] !== null && ! $this->near($heads['cgst'], $heads['sgst'])) {
            return [null, ['gst_unusual']];
        }

        $fits = array_values(array_filter(
            array_map('strval', (array) config('modules.finance.gst_rates', [])),
            fn (string $rate) => $this->near(Dec::percentOf($taxable, $rate), $tax),
        ));

        return count($fits) === 1 ? [$fits[0], []] : [null, ['gst_unusual']];
    }

    /**
     * A round off may be printed with its sign before it: "-0.40", "(-) 0.40", "+0.30". Anything
     * else is a plain figure or not a figure at all.
     *
     * @return array<string, mixed>
     */
    private function roundOff(array $cell): array
    {
        $out = $this->cell($cell);
        $out['had_comma'] = false;
        $out['script'] = null;

        if ($out['raw'] === '') {
            return $out;
        }

        $sign = '';
        $text = $out['raw'];

        if (preg_match('/^(?:\(\s*([-+\x{2212}\x{2013}])\s*\)|([-+\x{2212}\x{2013}]))[\s\p{Z}]*(.+)$/u', $text, $match) === 1) {
            $sign = in_array($match[1] !== '' ? $match[1] : $match[2], ['+'], true) ? '' : '-';
            $text = $match[3];
        }

        $result = PrintedNumber::parse($text, 'amount');
        $out['had_comma'] = $result->hadComma;
        $out['script'] = $result->script;

        if (! $result->ok || $result->value === null) {
            $out['warnings'][] = 'not_a_number';
            $out['reason'] = $result->reason;

            return $out;
        }

        $out['parsed'] = $out['value'] = Dec::money($sign.$result->value);

        return $out;
    }

    /**
     * Saved records this photo may duplicate. The same photo used for a saved record is ticked; a
     * bill of this supplier with the same number is said, and the vendor bill form refuses it anyway.
     *
     * @param  ?array{id:int}  $party
     * @return list<array<string, mixed>>
     */
    private function duplicates(SlipRead $slip, array $fields, ?array $party): array
    {
        $warnings = [];
        $number = $fields['bill_no']['value'];

        if ($party !== null && $number !== null) {
            $same = VendorBill::withoutGlobalScopes()->where('organization_id', $slip->organization_id)->where('party_id', $party['id'])
                ->whereNotIn('status', ['CANCELLED', 'REJECTED', 'REVERSAL'])->get(['id', 'bill_no'])
                ->first(fn (VendorBill $bill) => ChallanChecks::plainText($bill->bill_no) === ChallanChecks::plainText($number));

            if ($same !== null) {
                $warnings[] = ['code' => 'bill_already_entered', 'params' => ['number' => (string) $same->bill_no], 'tick' => false];
            }
        }

        if (filled($slip->image_sha256) && SlipRead::withoutGlobalScopes()->where('organization_id', $slip->organization_id)
            ->where('image_sha256', $slip->image_sha256)->where('status', SlipRead::USED)->whereKeyNot($slip->id)->exists()) {
            $warnings[] = ['code' => 'same_photo', 'params' => [], 'tick' => true];
        }

        return $warnings;
    }

    /**
     * A value worked out by code from printed figures. It has no printed text of its own; "from"
     * names the figures it came from, and it is unclear when one of them is.
     *
     * @param  list<string>  $from
     * @param  list<string>  $warnings
     * @return array<string, mixed>
     */
    private function worked(?string $value, array $from, array $fields, array $warnings): array
    {
        $unclear = collect($from)->contains(fn (string $field) => ($fields[$field]['state'] ?? null) === Cell::UNCLEAR);

        return ['raw' => '', 'parsed' => $value, 'value' => $value, 'state' => $unclear ? Cell::UNCLEAR : Cell::READ, 'warnings' => $warnings, 'from' => $from, 'worked_out' => true];
    }

    /**
     * A printed figure that no check could confirm is shown and not offered.
     *
     * @return array<string, mixed>
     */
    private function unchecked(array $cell): array
    {
        if ($cell['value'] !== null && array_intersect($cell['warnings'], ['lines_differ', 'total_differs']) === []) {
            $cell['warnings'][] = 'cannot_be_checked';
        }

        $cell['value'] = null;

        return $cell;
    }

    /** What the code read a cell as, unless the two readings disagree on it. */
    private function known(array $cell): ?string
    {
        return in_array('reads_disagree', $cell['warnings'] ?? [], true) ? null : $cell['parsed'];
    }

    /** @param  list<string>  $values */
    private function sum(array $values): string
    {
        return (string) array_reduce($values, fn (BigDecimal $total, string $value) => $total->plus(Dec::of($value)), BigDecimal::zero());
    }

    private function near(mixed $a, mixed $b): bool
    {
        return Dec::of($a)->minus(Dec::of($b))->abs()->isLessThanOrEqualTo(Dec::of(self::TOLERANCE));
    }
}
