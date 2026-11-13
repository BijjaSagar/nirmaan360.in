<?php

namespace Tests\Feature\SlipReading;

use App\Models\DeliveryChallan;
use App\Models\Material;
use App\Models\Party;
use App\Models\SlipRead;
use App\Services\DocumentReading\ShapeCheck;
use App\Services\MaterialControl\DeliveryChallanService;
use App\Services\SlipReading\ChallanChecks;
use App\Services\SlipReading\Gstin;
use App\Services\SlipReading\SlipReadKind;

/** What the code makes of a challan or weighbridge slip the reader copied: every check is code, none is the model's. */
class ChallanChecksTest extends SlipReadingTestCase
{
    private function build(array $form, ?SlipRead $slip = null, ?array $earlier = null): array
    {
        $slip ??= $this->storedReading($this->owner);

        return app(ChallanChecks::class)->build($form, $slip, $earlier);
    }

    public function test_a_gstin_is_checked_by_its_shape_its_state_its_pan_and_its_check_character(): void
    {
        // Invented GSTINs. The check character is the commonly published base-36 rule.
        $this->assertSame('0', Gstin::checkCharacter('27ABCDE1234F1Z'));
        $this->assertSame('S', Gstin::checkCharacter('29PQRST6789K1Z'));
        $this->assertSame([], Gstin::problems('27ABCDE1234F1Z0'));
        $this->assertSame([], Gstin::problems(' 27abcde1234f1z0 '));
        $this->assertSame([], Gstin::problems('29PQRST6789K1ZS', 'PQRST6789K'));

        $this->assertSame(['check'], Gstin::problems('27ABCDE1234F1Z9'));
        $this->assertSame(['pan'], Gstin::problems('27ABCDE1234F1Z0', 'ABCDE9999F'));
        $this->assertSame(['shape'], Gstin::problems('27ABCDE1234F1X0'));
        $this->assertSame(['shape'], Gstin::problems('27ABCDE1234F'));
        $this->assertContains('state', Gstin::problems('99ABCDE1234F1Z'.Gstin::checkCharacter('99ABCDE1234F1Z')));
    }

    public function test_the_fields_are_parsed_as_printed_and_nothing_is_worked_out(): void
    {
        $built = $this->build($this->challanForm([
            'challan_date' => $this->cell('११/११/२०२६'),
            'vehicle_no' => $this->cell('mh-12 ab 1234'),
            'lines' => [
                ['description' => $this->cell('OPC 53 Grade Cement'), 'qty' => $this->cell('1,200'), 'unit' => $this->cell('Bags')],
                ['description' => $this->cell('Sand'), 'qty' => $this->cell('12,50'), 'unit' => $this->cell('brass')],
                ['description' => $this->cell('TMT 12'), 'qty' => $this->cell('२.५', 'unclear'), 'unit' => $this->cell('M.T.')],
            ],
        ]));

        $this->assertSame('2026-11-11', $built['fields']['challan_date']['value']);
        $this->assertSame('MH12AB1234', $built['fields']['vehicle_no']['value']);

        [$cement, $sand, $steel] = $built['lines'];
        $this->assertSame(['1200.000', true, 'ascii'], [$cement['qty']['value'], $cement['qty']['had_comma'], $cement['qty']['script']]);
        $this->assertSame(['bag', $this->bag->id], [$cement['unit']['value'], $cement['unit']['unit_id']]);

        // "12,50" could be twelve and a half or twelve hundred and fifty: not a number, so not offered.
        $this->assertNull($sand['qty']['value']);
        $this->assertSame(['not_a_number'], $sand['qty']['warnings']);
        // A unit the company does not have is shown as printed and not offered.
        $this->assertNull($sand['unit']['unit_id']);
        $this->assertContains('unit_unknown', $sand['unit']['warnings']);

        // Devanagari digits are read; a value the reader was unsure of is still offered, marked.
        $this->assertSame(['2.500', 'devanagari'], [$steel['qty']['value'], $steel['qty']['script']]);
        $this->assertContains('unclear', $steel['qty']['warnings']);
        $this->assertSame($this->mt->id, $steel['unit']['unit_id']);
    }

    public function test_a_date_after_today_is_not_offered_and_an_old_or_last_years_date_is_marked(): void
    {
        $future = $this->build($this->challanForm(['challan_date' => $this->cell('20/11/2026')]))['fields']['challan_date'];
        $this->assertSame([null, ['date_in_future']], [$future['value'], $future['warnings']]);

        $old = $this->build($this->challanForm(['challan_date' => $this->cell('01/10/2026')]))['fields']['challan_date'];
        $this->assertSame(['2026-10-01', ['date_old']], [$old['value'], $old['warnings']]);

        $lastYear = $this->build($this->challanForm(['challan_date' => $this->cell('28-03-2026')]))['fields']['challan_date'];
        $this->assertSame('2026-03-28', $lastYear['value']);
        $this->assertEqualsCanonicalizing(['date_old', 'date_other_year'], $lastYear['warnings']);

        $unreadable = $this->build($this->challanForm(['challan_date' => $this->cell('11/13/2026')]))['fields']['challan_date'];
        $this->assertSame([null, ['date_unreadable']], [$unreadable['value'], $unreadable['warnings']]);
    }

    public function test_weights_are_offered_only_when_gross_less_tare_is_exactly_net(): void
    {
        $agree = $this->build($this->challanForm(['gross_weight' => $this->cell('18,450'), 'tare_weight' => $this->cell('6,200'), 'net_weight' => $this->cell('12,250'), 'weight_unit' => $this->cell('kg')]));
        $this->assertSame(['18450.000', '6200.000', '12250.000'], [$agree['fields']['gross_weight']['value'], $agree['fields']['tare_weight']['value'], $agree['fields']['net_weight']['value']]);
        $this->assertSame($this->kg->id, $agree['fields']['weight_unit']['unit_id']);

        $off = $this->build($this->challanForm(['gross_weight' => $this->cell('18,450'), 'tare_weight' => $this->cell('6,200'), 'net_weight' => $this->cell('12,350')]));

        foreach (['gross_weight', 'tare_weight', 'net_weight'] as $weight) {
            $this->assertNull($off['fields'][$weight]['value'], $weight);
        }

        $this->assertSame(['weights_do_not_add_up'], array_column($off['warnings'], 'code'));
        $this->assertSame(ChallanChecks::ARITHMETIC, ['weights_do_not_add_up']);

        // Net alone is offered as printed; nothing is worked out from gross and tare.
        $netOnly = $this->build($this->challanForm(['net_weight' => $this->cell('12.35'), 'weight_unit' => $this->cell('MT')]));
        $this->assertSame('12.350', $netOnly['fields']['net_weight']['value']);
        $missing = $this->build($this->challanForm(['gross_weight' => $this->cell('18,450'), 'tare_weight' => $this->cell('6,200')]));
        $this->assertNull($missing['fields']['net_weight']['value']);
    }

    public function test_the_weight_unit_is_not_offered_when_the_weights_do_not_add_up(): void
    {
        $off = $this->build($this->challanForm(['gross_weight' => $this->cell('25,000'), 'tare_weight' => $this->cell('10,000'), 'net_weight' => $this->cell('14,000'), 'weight_unit' => $this->cell('kg')]));

        $this->assertSame([null, null], [$off['fields']['weight_unit']['value'], $off['fields']['weight_unit']['unit_id']]);
        $this->assertContains('weights_do_not_add_up', $off['fields']['weight_unit']['warnings']);
        // What was printed is still shown.
        $this->assertSame('kg', $off['fields']['weight_unit']['raw']);
    }

    public function test_a_possible_duplicate_in_a_project_the_person_does_not_see_is_marked_without_its_number(): void
    {
        $store = $this->member($this->organization, 'store');
        $this->limitTo($store, $this->project);
        app(DeliveryChallanService::class)->create($this->otherProject, ['party_id' => $this->vendor->id, 'material_id' => $this->cement->id, 'qty' => '100', 'challan_date' => '2026-11-10', 'challan_no' => 'DC-0457'], $this->owner);
        $number = DeliveryChallan::sole()->number;

        $hidden = collect($this->build($this->challanForm(), $this->storedReading($store->refresh()))['warnings'])->firstWhere('code', 'possible_duplicate');
        $this->assertSame(['code' => 'possible_duplicate', 'params' => [], 'tick' => true], $hidden);

        // Someone who sees that project is told which challan it is.
        $seen = collect($this->build($this->challanForm(), $this->storedReading($this->owner))['warnings'])->firstWhere('code', 'possible_duplicate');
        $this->assertSame(['number' => $number], $seen['params']);

        $this->limitTo($store, $this->otherProject);
        $now = collect($this->build($this->challanForm(), $this->storedReading($store->refresh()))['warnings'])->firstWhere('code', 'possible_duplicate');
        $this->assertSame(['number' => $number], $now['params']);
    }

    public function test_the_supplier_is_found_only_by_an_exact_gstin_that_passes_and_never_by_its_name(): void
    {
        $this->assertSame(['id' => $this->vendor->id, 'name' => $this->vendor->name, 'by' => 'gstin'], $this->build($this->challanForm())['suggestions']['party']);

        // The same name with no GSTIN on the photo: no supplier is picked.
        $this->assertNull($this->build($this->challanForm(['supplier_gstin' => $this->cell('')]))['suggestions']['party']);

        // A GSTIN that fails its check picks nobody and says why.
        $failed = $this->build($this->challanForm(['supplier_gstin' => $this->cell('27ABCDE1234F1Z9')]));
        $this->assertNull($failed['suggestions']['party']);
        $this->assertSame(['gstin_check_failed'], $failed['fields']['supplier_gstin']['warnings']);

        // The stored PAN of the supplier does not match the GSTIN: nobody is picked.
        $this->vendor->update(['pan' => 'ABCDE9999F']);
        $mismatch = $this->build($this->challanForm());
        $this->assertNull($mismatch['suggestions']['party']);
        $this->assertSame(['gstin_check_failed'], $mismatch['fields']['supplier_gstin']['warnings']);

        // Two suppliers with the same GSTIN: neither is picked.
        $this->vendor->update(['pan' => null]);
        Party::create(['organization_id' => $this->organization->id, 'type' => 'vendor', 'name' => 'Godavari Traders (old)', 'gstin' => self::GSTIN]);
        $this->assertNull($this->build($this->challanForm())['suggestions']['party']);
    }

    public function test_materials_whose_names_share_words_with_a_line_are_suggested_up_to_five(): void
    {
        foreach (['PPC cement', 'White cement', 'Cement 43 grade', 'Cement bags old', 'Cement paint'] as $index => $name) {
            Material::create(['organization_id' => $this->organization->id, 'code' => 'M'.$index, 'name' => $name, 'base_unit_id' => $this->bag->id]);
        }

        $materials = $this->build($this->challanForm())['suggestions']['materials'];

        $this->assertCount(5, $materials);
        $this->assertSame($this->cement->id, $materials[0]['id'], 'The closest name comes first.');
        $this->assertNotContains($this->steel->id, array_column($materials, 'id'));
        $this->assertSame([], $this->build($this->challanForm(['lines' => []]))['suggestions']['materials']);
    }

    public function test_a_photo_that_may_repeat_a_saved_challan_is_marked_for_a_tick(): void
    {
        $service = app(DeliveryChallanService::class);
        $service->create($this->project, ['party_id' => $this->vendor->id, 'material_id' => $this->cement->id, 'qty' => '100', 'challan_qty' => '120', 'challan_date' => '2026-11-11', 'challan_no' => 'dc-0457', 'vehicle_no' => 'MH 12 AB 1234'], $this->owner);

        $codes = array_column($this->build($this->challanForm())['warnings'], 'tick', 'code');
        $this->assertSame(['possible_duplicate' => true, 'same_delivery' => true], $codes);

        // A reversed challan is not a duplicate.
        DeliveryChallan::query()->update(['status' => 'REVERSED']);
        $this->assertSame([], $this->build($this->challanForm())['warnings']);

        // The same photo already used for a saved record.
        $used = $this->storedReading($this->owner, ['status' => SlipRead::USED, 'image_sha256' => str_repeat('a', 64)]);
        $again = $this->storedReading($this->owner, ['image_sha256' => $used->image_sha256]);
        $this->assertSame(['same_photo'], array_column($this->build($this->challanForm(), $again)['warnings'], 'code'));
    }

    public function test_a_paper_that_is_not_a_challan_is_said_to_be_so(): void
    {
        $this->assertSame(['not_a_challan'], array_column($this->build($this->challanForm(['doc_kind' => 'tax_invoice']))['warnings'], 'code'));
        $this->assertSame([], $this->build($this->challanForm(['doc_kind' => 'weighbridge_slip']))['warnings']);
    }

    public function test_the_readers_form_holds_no_money_driver_or_phone_field_and_follows_the_schema_rules(): void
    {
        $schema = SlipReadKind::schema();

        $this->assertSame([], ShapeCheck::schemaProblems($schema));
        $this->assertSame(['doc_kind', 'supplier_name', 'supplier_gstin', 'challan_no', 'challan_date', 'vehicle_no', 'lines', 'gross_weight', 'tare_weight', 'net_weight', 'weight_unit'], array_keys($schema['properties']));
        $this->assertSame(['description', 'qty', 'unit'], array_keys($schema['properties']['lines']['items']['properties']));
        $this->assertTrue(ShapeCheck::passes($this->challanForm(), $schema));

        foreach (['/\b\d+(\.\d+)?\s?%/', '/\bclause\b/i', '/\bnotice period\b/i'] as $pattern) {
            $this->assertDoesNotMatchRegularExpression($pattern, SlipReadKind::INSTRUCTIONS);
        }
    }

    public function test_a_second_reading_offers_only_what_both_readings_agree_on(): void
    {
        $first = $this->build($this->challanForm());
        $second = $this->build(
            $this->challanForm(['challan_no' => $this->cell('DC-0451'), 'lines' => [['description' => $this->cell('OPC 53 Grade Cement'), 'qty' => $this->cell('126'), 'unit' => $this->cell('Bags')]]]),
            null,
            ['fields' => $first['fields'], 'lines' => $first['lines']],
        );

        $this->assertNull($second['fields']['challan_no']['value']);
        $this->assertSame('2026-11-11', $second['fields']['challan_date']['value']);
        $this->assertNull($second['lines'][0]['qty']['value']);
        $this->assertSame('bag', $second['lines'][0]['unit']['value']);
        $this->assertSame('reads_disagree', $second['warnings'][0]['code']);
    }
}
