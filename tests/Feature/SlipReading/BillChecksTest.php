<?php

namespace Tests\Feature\SlipReading;

use App\Models\Party;
use App\Models\SlipRead;
use App\Services\DocumentReading\ShapeCheck;
use App\Services\Finance\VendorBillService;
use App\Services\SlipReading\BillChecks;
use App\Services\SlipReading\BillReadKind;

/**
 * What the code makes of a supplier bill the reader copied: every sum and the two values worked out
 * from the tax heads are code, none is the model's. A figure that fails a check is not offered.
 */
class BillChecksTest extends SlipReadingTestCase
{
    private function build(array $form, ?SlipRead $slip = null, ?array $earlier = null): array
    {
        $slip ??= $this->storedReading($this->owner, [], null, SlipRead::BILL);

        return app(BillChecks::class)->build($form, $slip, $earlier);
    }

    /** @return array<string, ?string> What each field offers the form. */
    private function offered(array $built): array
    {
        return array_map(fn (array $cell) => $cell['value'], $built['fields']);
    }

    private function codes(array $built): array
    {
        return array_column($built['warnings'], 'code');
    }

    public function test_a_bill_that_adds_up_offers_its_figures_the_rate_and_the_supply(): void
    {
        $built = $this->build($this->billForm());
        $offered = $this->offered($built);

        $this->assertSame('tax_invoice', $built['doc_kind']);
        $this->assertSame([], $built['warnings']);
        $this->assertSame(['INV/0912', '2026-11-10', '60000.00', '70800.00', '18', 'intra'], [
            $offered['bill_no'], $offered['bill_date'], $offered['taxable_total'], $offered['grand_total'], $offered['gst_pct'], $offered['supply_type'],
        ]);
        $this->assertSame(['5400.00', '5400.00', null], [$offered['cgst'], $offered['sgst'], $offered['igst']]);
        $this->assertSame(['id' => $this->vendor->id, 'name' => $this->vendor->name, 'by' => 'gstin'], $built['suggestions']['party']);
        $this->assertSame([], $built['suggestions']['materials']);

        // The two worked-out values have no printed text of their own and say what they came from.
        $this->assertSame(['', true, ['taxable_total', 'cgst', 'sgst', 'igst']], [$built['fields']['gst_pct']['raw'], $built['fields']['gst_pct']['worked_out'], $built['fields']['gst_pct']['from']]);

        [$line] = $built['lines'];
        $this->assertSame(['200.000', 'bag', '300.00', '60000.00', '2523'], [$line['qty']['value'], $line['unit']['value'], $line['rate']['value'], $line['amount']['value'], $line['hsn']['value']]);
    }

    public function test_each_line_is_checked_quantity_times_rate_against_its_amount(): void
    {
        $built = $this->build($this->billForm([
            'lines' => [
                $this->billLine('OPC 53 Grade Cement', '200', '300.00', '60,000.00'),
                $this->billLine('Binding wire', '10', '55.00', '500.00', 'kg', '7217'),
            ],
            'taxable_total' => $this->cell('60,500.00'),
            'cgst' => $this->cell('5,445.00'),
            'sgst' => $this->cell('5,445.00'),
            'grand_total' => $this->cell('71,390.00'),
        ]));

        $this->assertNull($built['lines'][1]['amount']['value']);
        $this->assertContains('qty_times_rate_differs', $built['lines'][1]['amount']['warnings']);
        $this->assertSame('60000.00', $built['lines'][0]['amount']['value']);
        $this->assertSame([['code' => 'lines_do_not_add_up', 'params' => ['lines' => '2'], 'tick' => false]], $built['warnings']);

        // The printed amounts still add up to the taxable value, so the totals are offered.
        $this->assertSame(['60500.00', '71390.00', '18'], [$built['fields']['taxable_total']['value'], $built['fields']['grand_total']['value'], $built['fields']['gst_pct']['value']]);
    }

    public function test_the_lines_must_add_up_to_the_taxable_value_and_everything_to_the_grand_total_within_a_rupee(): void
    {
        // The lines say 60,000; the taxable value is printed 61,000.
        $built = $this->build($this->billForm(['taxable_total' => $this->cell('61,000.00'), 'grand_total' => $this->cell('71,800.00')]));
        $this->assertNull($built['fields']['taxable_total']['value']);
        $this->assertContains('lines_differ', $built['fields']['taxable_total']['warnings']);
        $this->assertSame(['taxable_does_not_add_up'], $this->codes($built));
        // 10,800 is not a known rate of 61,000.
        $this->assertNull($built['fields']['gst_pct']['value']);
        $this->assertSame(['gst_unusual'], $built['fields']['gst_pct']['warnings']);

        // A grand total a rupee off is within the tolerance; two rupees is not.
        $this->assertSame('70801.00', $this->build($this->billForm(['grand_total' => $this->cell('70,801.00')]))['fields']['grand_total']['value']);
        $off = $this->build($this->billForm(['grand_total' => $this->cell('70,802.00')]));
        $this->assertNull($off['fields']['grand_total']['value']);
        $this->assertSame(['total_differs'], $off['fields']['grand_total']['warnings']);
        $this->assertSame(['total_does_not_add_up'], $this->codes($off));
        // The lines still confirm the taxable value.
        $this->assertSame('60000.00', $off['fields']['taxable_total']['value']);

        // With no lines to add, the taxable value stands only when the grand total adds up.
        $this->assertSame('60000.00', $this->build($this->billForm(['lines' => []]))['fields']['taxable_total']['value']);
        $alone = $this->build($this->billForm(['lines' => [], 'grand_total' => $this->cell('')]));
        $this->assertSame([null, null], [$alone['fields']['taxable_total']['value'], $alone['fields']['grand_total']['value']]);
        $this->assertSame(['cannot_be_checked'], $alone['fields']['taxable_total']['warnings']);
    }

    public function test_a_round_off_counts_with_its_sign_and_more_than_a_rupee_is_not_offered(): void
    {
        $down = $this->build($this->billForm(['round_off' => $this->cell('(-) 0.40'), 'grand_total' => $this->cell('70,799.60')]));
        $this->assertSame(['-0.40', '70799.60'], [$down['fields']['round_off']['value'], $down['fields']['grand_total']['value']]);

        $up = $this->build($this->billForm(['round_off' => $this->cell('+0.30'), 'grand_total' => $this->cell('70,800.30')]));
        $this->assertSame(['0.30', '70800.30'], [$up['fields']['round_off']['value'], $up['fields']['grand_total']['value']]);

        $large = $this->build($this->billForm(['round_off' => $this->cell('2.00'), 'grand_total' => $this->cell('70,802.00')]));
        $this->assertNull($large['fields']['round_off']['value']);
        $this->assertSame(['round_off_too_large'], $large['fields']['round_off']['warnings']);
        $this->assertNull($large['fields']['grand_total']['value']);
        $this->assertSame(['total_does_not_add_up'], $this->codes($large));
    }

    public function test_the_gst_rate_must_fit_exactly_one_known_rate_with_equal_halves(): void
    {
        // CGST and SGST that are not equal halves.
        $uneven = $this->build($this->billForm(['cgst' => $this->cell('5,000.00'), 'sgst' => $this->cell('5,800.00')]));
        $this->assertNull($uneven['fields']['gst_pct']['value']);
        $this->assertSame(['gst_unusual'], $uneven['fields']['gst_pct']['warnings']);

        // A tax that is no known rate of the taxable value.
        $mixed = $this->build($this->billForm(['cgst' => $this->cell('4,500.00'), 'sgst' => $this->cell('4,500.00'), 'grand_total' => $this->cell('69,000.00')]));
        $this->assertNull($mixed['fields']['gst_pct']['value']);
        $this->assertSame('69000.00', $mixed['fields']['grand_total']['value']);

        // No tax printed at all: the rate is the person's to enter, and the supply is not known.
        $untaxed = $this->build($this->billForm(['doc_kind' => 'bill_of_supply', 'cgst' => $this->cell(''), 'sgst' => $this->cell(''), 'grand_total' => $this->cell('60,000.00')]));
        $this->assertSame([null, ['no_tax_printed'], null, []], [
            $untaxed['fields']['gst_pct']['value'], $untaxed['fields']['gst_pct']['warnings'], $untaxed['fields']['supply_type']['value'], $untaxed['fields']['supply_type']['warnings'],
        ]);
        $this->assertSame('60000.00', $untaxed['fields']['grand_total']['value']);
        $this->assertSame([], $untaxed['warnings']);
    }

    public function test_the_supply_comes_from_the_tax_heads_printed_and_the_states_only_warn(): void
    {
        // IGST printed, both GSTINs in the same state: still between states, with a warning.
        $igst = $this->build($this->billForm(['cgst' => $this->cell(''), 'sgst' => $this->cell(''), 'igst' => $this->cell('10,800.00')]));
        $this->assertSame(['inter', '18'], [$igst['fields']['supply_type']['value'], $igst['fields']['gst_pct']['value']]);
        $this->assertSame(['states_differ'], $igst['fields']['supply_type']['warnings']);
        $this->assertSame(['states_differ'], $this->codes($igst));

        // IGST printed for a buyer in another state: no warning.
        $other = $this->build($this->billForm(['buyer_gstin' => $this->cell('24LMNOP4321Q1ZA'), 'cgst' => $this->cell(''), 'sgst' => $this->cell(''), 'igst' => $this->cell('10,800.00')]));
        $this->assertSame(['inter', []], [$other['fields']['supply_type']['value'], $other['warnings']]);

        // CGST and SGST for a buyer in another state: within the state as printed, with a warning.
        $intra = $this->build($this->billForm(['buyer_gstin' => $this->cell('24LMNOP4321Q1ZA')]));
        $this->assertSame(['intra', ['states_differ']], [$intra['fields']['supply_type']['value'], $intra['fields']['supply_type']['warnings']]);

        // All three heads with tax: neither.
        $both = $this->build($this->billForm(['igst' => $this->cell('100.00')]));
        $this->assertSame([null, ['tax_heads_mixed']], [$both['fields']['supply_type']['value'], $both['fields']['supply_type']['warnings']]);

        // A head printed as something other than a figure leaves the tax unknown: no rate, no supply, no total.
        $nil = $this->build($this->billForm(['igst' => $this->cell('Nil')]));
        $this->assertSame([null, null, null], [$nil['fields']['supply_type']['value'], $nil['fields']['gst_pct']['value'], $nil['fields']['grand_total']['value']]);
        $this->assertSame(['not_a_number'], $nil['fields']['igst']['warnings']);
        $this->assertSame([], $nil['fields']['supply_type']['warnings']);
    }

    public function test_the_supplier_is_found_only_by_an_exact_gstin_among_vendors_and_subcontractors(): void
    {
        $subcontractor = Party::create(['organization_id' => $this->organization->id, 'type' => 'subcontractor', 'name' => 'Tapi Fabricators', 'gstin' => '29PQRST6789K1ZS']);
        $this->assertSame($subcontractor->id, $this->build($this->billForm(['supplier_gstin' => $this->cell('29 PQRST 6789 K1ZS')]))['suggestions']['party']['id']);

        // The vendor's name printed with another GSTIN finds nobody.
        $named = $this->build($this->billForm(['supplier_gstin' => $this->cell('27ABCDE1234F1Z9')]));
        $this->assertNull($named['suggestions']['party']);
        $this->assertContains('gstin_check_failed', $named['fields']['supplier_gstin']['warnings']);

        // A client of the company with that GSTIN is not a supplier.
        $subcontractor->update(['type' => 'client']);
        $this->assertNull($this->build($this->billForm(['supplier_gstin' => $this->cell('29PQRST6789K1ZS')]))['suggestions']['party']);
    }

    public function test_a_bill_already_entered_is_said_and_a_photo_already_used_is_ticked(): void
    {
        $bill = app(VendorBillService::class)->create($this->project, [
            'party_id' => $this->vendor->id, 'bill_no' => 'inv/0912', 'bill_date' => '2026-11-10', 'cost_head' => 'Materials',
            'supply_type' => 'intra', 'taxable_amount' => '60000', 'gst_pct' => '18',
        ], $this->owner);

        $this->assertSame([['code' => 'bill_already_entered', 'params' => ['number' => 'inv/0912'], 'tick' => false]], $this->build($this->billForm())['warnings']);

        $bill->update(['status' => 'CANCELLED']);
        $this->assertSame([], $this->build($this->billForm())['warnings']);

        $used = $this->storedReading($this->owner, ['status' => SlipRead::USED, 'image_sha256' => str_repeat('b', 64)], null, SlipRead::BILL);
        $again = $this->storedReading($this->owner, ['image_sha256' => $used->image_sha256], null, SlipRead::BILL);
        $this->assertSame([['code' => 'same_photo', 'params' => [], 'tick' => true]], $this->build($this->billForm(), $again)['warnings']);
    }

    public function test_a_paper_that_is_not_a_bill_is_said_to_be_so_and_a_date_after_today_is_not_offered(): void
    {
        $this->assertSame(['not_a_bill'], $this->codes($this->build($this->billForm(['doc_kind' => 'delivery_challan']))));
        $this->assertSame(['nothing_read'], $this->codes($this->build($this->billForm(['doc_kind' => 'unreadable']))));
        $this->assertSame([], $this->codes($this->build($this->billForm(['doc_kind' => 'bill_of_supply']))));

        $later = $this->build($this->billForm(['bill_date' => $this->cell('20/11/2026')]));
        $this->assertNull($later['fields']['bill_date']['value']);
        $this->assertSame(['date_in_future'], $later['fields']['bill_date']['warnings']);
    }

    public function test_the_readers_form_asks_for_printed_figures_only_and_follows_the_schema_rules(): void
    {
        $schema = BillReadKind::schema();

        $this->assertSame([], ShapeCheck::schemaProblems($schema));
        $this->assertSame(
            ['doc_kind', 'supplier_name', 'supplier_gstin', 'buyer_gstin', 'bill_no', 'bill_date', 'lines', 'taxable_total', 'cgst', 'sgst', 'igst', 'round_off', 'grand_total', 'eway_bill_no'],
            array_keys($schema['properties']),
        );
        $this->assertSame(['description', 'hsn', 'qty', 'unit', 'rate', 'amount'], array_keys($schema['properties']['lines']['items']['properties']));
        $this->assertTrue(ShapeCheck::passes($this->billForm(), $schema));

        // No rate of tax, TDS, purchase order or receipt is asked for: those are the person's to enter.
        $this->assertDoesNotMatchRegularExpression('/"(gst_pct|gst_rate|tds\w*|purchase_order\w*|grn\w*|supply_type)"/', json_encode($schema));

        foreach (['/\b\d+(\.\d+)?\s?%/', '/\bclause\b/i', '/\bnotice period\b/i'] as $pattern) {
            $this->assertDoesNotMatchRegularExpression($pattern, BillReadKind::INSTRUCTIONS);
        }
    }

    public function test_a_second_reading_offers_only_what_both_readings_agree_on(): void
    {
        $first = $this->build($this->billForm(['grand_total' => $this->cell('70,880.00')]));
        $this->assertContains('total_does_not_add_up', $this->codes($first));

        $second = $this->build($this->billForm(['bill_no' => $this->cell('INV/0812')]), null, ['fields' => $first['fields'], 'lines' => $first['lines']]);

        // The two readings differ on the number and the grand total: neither is offered. The rest agree and add up.
        $this->assertSame([null, null], [$second['fields']['bill_no']['value'], $second['fields']['grand_total']['value']]);
        $this->assertContains('reads_disagree', $second['fields']['grand_total']['warnings']);
        $this->assertSame(['60000.00', '18', 'intra', '2026-11-10'], [
            $second['fields']['taxable_total']['value'], $second['fields']['gst_pct']['value'], $second['fields']['supply_type']['value'], $second['fields']['bill_date']['value'],
        ]);
        $this->assertSame('reads_disagree', $second['warnings'][0]['code']);
    }
}
