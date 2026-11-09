<?php

namespace Tests\Feature\AutoBoq;

use App\Services\AutoBoq\Assumptions;
use App\Services\AutoBoq\AutoBoqCalculator;
use App\Services\AutoBoq\AutoBoqLine;
use App\Services\ProjectSetup\BoqCategories;
use Illuminate\Validation\ValidationException;

/** Every formula against a figure worked out by hand, with invented dimensions. */
class CalculatorTest extends AutoBoqTestCase
{
    /** @return array<string, AutoBoqLine> */
    private function lines(array $dimensions, array $assumptions = []): array
    {
        return app(AutoBoqCalculator::class)->lines($dimensions, [...Assumptions::defaults(), ...$assumptions]);
    }

    public function test_every_formula_gives_the_hand_worked_quantity(): void
    {
        $lines = $this->lines($this->dimensions());

        $this->assertSame(array_keys(AutoBoqCalculator::LINES), array_keys($lines));

        foreach ($this->expected() as $code => $qty) {
            $this->assertSame($qty, $lines[$code]->qty, $code);
            $this->assertTrue($lines[$code]->computed, $code);
        }

        foreach (['AB-TIL-DADO', 'AB-SKT', 'AB-DOOR', 'AB-WIN', 'AB-RAIL', 'AB-EL-PT', 'AB-EL-WIR', 'AB-PLB-PT', 'AB-SAN'] as $code) {
            $this->assertSame('0.000', $lines[$code]->qty, $code);
            $this->assertFalse($lines[$code]->computed, $code);
            $this->assertSame('manual', $lines[$code]->group);
        }

        $this->assertSame('MT', $lines['AB-STL']->unit);
        $this->assertSame('steel', $lines['AB-STL']->category);
        $this->assertSame('rcc', $lines['AB-CEN-SLB']->category);
    }

    public function test_the_working_shows_the_arithmetic_of_every_group(): void
    {
        $lines = $this->lines($this->dimensions());

        $this->assertSame([
            'nos × length × breadth × depth',
            'F1: 4 × 1.5 × 1.5 × 0.45 = 4.050',
            'F2: 2 × 1.2 × 1 × 0.4 = 0.960',
            'Total 5.010 cum',
        ], $lines['AB-RCC-FTG']->working);

        $this->assertStringContainsString('F1: 4 × (1.5 + 2 × 0.3) × (1.5 + 2 × 0.3) × 1.5 = 26.460', $lines['AB-EXC']->details());
        $this->assertStringContainsString('C2: 2 × 0.23 × 0.3 × 3 × 1 = 0.414', $lines['AB-RCC-COL']->details());
        $this->assertStringContainsString('Typical: (100 − 10) × 0.125 × 2 = 22.500', $lines['AB-RCC-SLB']->details());
        $this->assertStringContainsString('Ext: (40 × 3 − 8) × 0.23 × 2 = 51.520', $lines['AB-BRK']->details());
        $this->assertStringContainsString('RCC M25 in slabs: 22.500 × 90 = 2025.000 kg', $lines['AB-STL']->details());
        $this->assertStringContainsString('5054.940 kg ÷ 1000 = 5.055 MT', $lines['AB-STL']->details());
        $this->assertStringContainsString('0.85 × (100 + 90.000) = 161.500', $lines['AB-FLR']->details());
        $this->assertSame('Needs the electrical layout. Enter the quantity by hand.', $lines['AB-EL-PT']->details());
    }

    public function test_zero_and_missing_inputs_give_zero_lines_without_errors(): void
    {
        $cases = [
            [],
            ['floors' => 0, 'footings' => [], 'walls' => [['label' => '', 'length' => '', 'height' => '']]],
            ['floors' => 3, 'floor_height' => '3', 'plinth_area' => '0', 'footings' => [['nos' => 'abc', 'length' => null]], 'slabs' => 'not a list'],
        ];

        foreach ($cases as $dimensions) {
            $lines = $this->lines($dimensions);
            $this->assertCount(count(AutoBoqCalculator::LINES), $lines);

            foreach ($lines as $line) {
                $this->assertSame('0.000', $line->qty, $line->code);
            }
        }

        $this->assertSame('No footing groups entered.', $this->lines([])['AB-EXC']->note);
        $this->assertSame('No wall groups entered.', $this->lines([])['AB-BRK']->note);

        // Footings without a foundation depth: the concrete is there but no pit can be worked out.
        $lines = $this->lines(['footings' => [['length' => '1', 'breadth' => '1', 'depth' => '0.3', 'nos' => '2']]]);
        $this->assertSame('0.600', $lines['AB-RCC-FTG']->qty);
        $this->assertSame('0.000', $lines['AB-EXC']->qty);
        $this->assertStringContainsString('Foundation depth is 0', $lines['AB-EXC']->note);

        // Negative figures count as nothing rather than taking quantity away.
        $this->assertSame('0.000', $this->lines(['footings' => [['length' => '-1', 'breadth' => '1', 'depth' => '0.3', 'nos' => '2']]])['AB-RCC-FTG']->qty);
    }

    public function test_openings_are_deducted_from_masonry_and_plaster_and_never_go_negative(): void
    {
        $wall = fn (string $openings, string $kind = 'external') => ['floor_height' => '3', 'walls' => [
            ['label' => 'W', 'kind' => $kind, 'length' => '10', 'height' => '3', 'thickness' => '0.2', 'openings' => $openings, 'floors' => '1'],
        ]];

        $solid = $this->lines($wall('0'));
        $this->assertSame('6.000', $solid['AB-BRK']->qty);
        $this->assertSame('30.000', $solid['AB-PL-EXT']->qty);
        $this->assertSame('30.000', $solid['AB-PL-INT']->qty);
        $this->assertSame('30.000', $solid['AB-PNT-EXT']->qty);

        $withDoors = $this->lines($wall('6'));
        $this->assertSame('4.800', $withDoors['AB-BRK']->qty);
        $this->assertSame('24.000', $withDoors['AB-PL-EXT']->qty);
        $this->assertSame('24.000', $withDoors['AB-PL-INT']->qty);

        $internal = $this->lines($wall('6', 'internal'));
        $this->assertSame('4.800', $internal['AB-BRK']->qty);
        $this->assertSame('0.000', $internal['AB-PL-EXT']->qty);
        $this->assertSame('48.000', $internal['AB-PL-INT']->qty);

        $impossible = $this->lines($wall('50'));
        $this->assertSame('0.000', $impossible['AB-BRK']->qty);
        $this->assertSame('0.000', $impossible['AB-PL-EXT']->qty);
        $this->assertSame('Openings are larger than the wall in W, so that group counts as 0.', $impossible['AB-BRK']->note);
    }

    public function test_a_blank_height_takes_the_floor_height_and_a_blank_floor_count_is_one(): void
    {
        $lines = $this->lines([
            'floor_height' => '3.5',
            'columns' => [['length' => '0.3', 'breadth' => '0.3', 'height' => '', 'nos' => '1', 'floors' => '']],
            'walls' => [['kind' => 'external', 'length' => '10', 'height' => null, 'thickness' => '0.2', 'openings' => '0']],
        ]);

        $this->assertSame('0.315', $lines['AB-RCC-COL']->qty);
        $this->assertStringContainsString('Group 1: 1 × 0.3 × 0.3 × 3.5 × 1 = 0.315', $lines['AB-RCC-COL']->details());
        $this->assertSame('7.000', $lines['AB-BRK']->qty);
    }

    public function test_slabs_are_listed_bottom_up_and_the_top_one_is_the_terrace(): void
    {
        $lines = $this->lines([
            'floors' => 3, 'plinth_area' => '100', 'stair_area' => '8',
            'slabs' => [
                ['label' => 'Typical', 'area' => '120', 'openings' => '20', 'thickness' => '0.15', 'count' => '2'],
                ['label' => 'Terrace', 'area' => '80', 'openings' => '0', 'thickness' => '0.12', 'count' => '1'],
            ],
        ]);

        $this->assertSame('39.600', $lines['AB-RCC-SLB']->qty);   // 100 × 0.15 × 2 + 80 × 0.12
        $this->assertSame('280.000', $lines['AB-CEN-SLB']->qty);  // 200 + 80
        $this->assertSame('280.000', $lines['AB-PL-CLG']->qty);
        $this->assertSame('255.000', $lines['AB-FLR']->qty);      // 0.85 × (100 + 200): the terrace carries no floor
        $this->assertSame('80.000', $lines['AB-WP']->qty);
        $this->assertSame('7.200', $lines['AB-RCC-STR']->qty);    // 3 × 8 × 0.3
        $this->assertSame('36.000', $lines['AB-CEN-STR']->qty);   // 3 × 8 × 1.5
    }

    public function test_steel_follows_the_companys_kg_per_cum_for_each_element(): void
    {
        $this->assertSame('5.280', $this->lines($this->dimensions(), ['steel_slab' => '100'])['AB-STL']->qty); // + 22.5 × 10 kg
        $this->assertSame('0.000', $this->lines($this->dimensions(), array_fill_keys(['steel_footing', 'steel_column', 'steel_beam', 'steel_slab', 'steel_stair', 'steel_chajja'], '0'))['AB-STL']->qty);
    }

    public function test_the_earthwork_assumptions_change_excavation_pcc_and_filling(): void
    {
        $lines = $this->lines($this->dimensions(), ['excavation_margin' => '0', 'pcc_offset' => '0', 'pcc_thickness' => '0.075', 'pcc_floor_thickness' => '0']);

        $this->assertSame('17.100', $lines['AB-EXC']->qty);  // 4 × 1.5 × 1.5 × 1.5 + 2 × 1.2 × 1 × 1.5
        $this->assertSame('0.855', $lines['AB-PCC']->qty);   // 4 × 2.25 × 0.075 + 2 × 1.2 × 0.075
        $this->assertSame('71.235', $lines['AB-FILL']->qty); // 17.1 − 0.855 − 5.01 + 60
        $this->assertStringNotContainsString('Under the ground-floor flooring', $lines['AB-PCC']->details());
    }

    public function test_an_impossible_quantity_is_refused_rather_than_stored_wrongly(): void
    {
        $this->expectException(ValidationException::class);

        $this->lines(['foundation_depth' => '50', 'footings' => [['length' => '100', 'breadth' => '100', 'depth' => '20', 'nos' => '99999999']]]);
    }

    public function test_codes_are_stable_short_and_in_known_categories(): void
    {
        $codes = AutoBoqCalculator::codes();

        $this->assertSame($codes, array_unique($codes));
        $this->assertCount(32, $codes);

        foreach (AutoBoqCalculator::LINES as $code => [$description, $unit, $category, $group]) {
            $this->assertMatchesRegularExpression('/^AB-[A-Z-]{2,10}$/', $code);
            $this->assertLessThanOrEqual(32, strlen($code));
            $this->assertContains($category, BoqCategories::keys(), $code);
            $this->assertContains($unit, config('buildos.units'), $code);
            $this->assertArrayHasKey($group, AutoBoqCalculator::GROUPS, $code);
        }

        $manual = array_keys(array_filter(AutoBoqCalculator::LINES, fn (array $line) => $line[3] === 'manual'));
        $this->assertSame($manual, array_keys(AutoBoqCalculator::MANUAL_NOTES));
        $this->assertSame(Assumptions::keys(), array_keys(Assumptions::defaults()));
    }
}
