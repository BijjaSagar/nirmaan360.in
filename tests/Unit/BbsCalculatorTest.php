<?php

namespace Tests\Unit;

use App\Services\Drawings\BbsCalculator;
use App\Support\Dec;
use Brick\Math\RoundingMode;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** Worked examples checked by hand against IS 2502:1963. */
class BbsCalculatorTest extends TestCase
{
    private function calculator(): BbsCalculator
    {
        return new BbsCalculator;
    }

    /**
     * Every row of Table II: "Min" columns up to 25 mm and "Recommended" columns above it.
     *
     * @return array<string, array{int, array{int,int,int}, array{int,int,int}}>
     */
    public static function tableTwo(): array
    {
        return [
            '5 mm' => [5, [75, 75, 75], [75, 75, 75]],
            '6 mm' => [6, [75, 75, 75], [75, 75, 75]],
            '8 mm' => [8, [75, 90, 105], [75, 75, 75]],
            '10 mm' => [10, [90, 110, 130], [75, 75, 75]],
            '12 mm' => [12, [110, 130, 155], [75, 75, 75]],
            '16 mm' => [16, [145, 175, 210], [80, 90, 95]],
            '20 mm' => [20, [180, 220, 260], [100, 110, 120]],
            '22 mm' => [22, [200, 240, 285], [110, 120, 130]],
            '25 mm' => [25, [225, 275, 325], [125, 140, 150]],
            '28 mm' => [28, [310, 365, 475], [155, 170, 195]],
            '32 mm' => [32, [350, 415, 545], [175, 190, 225]],
            '36 mm' => [36, [395, 470, 610], [200, 215, 250]],
            '40 mm' => [40, [440, 520, 680], [220, 240, 280]],
            '45 mm' => [45, [495, 585, 765], [250, 270, 315]],
            '50 mm' => [50, [550, 650, 850], [275, 300, 350]],
        ];
    }

    /** @param array{int,int,int} $hooks @param array{int,int,int} $bends */
    #[DataProvider('tableTwo')]
    public function test_allowances_match_table_two(int $size, array $hooks, array $bends): void
    {
        foreach (['mild', 'medium', 'deformed'] as $index => $steel) {
            $this->assertSame((string) $hooks[$index], (string) $this->calculator()->hookAllowance($steel, $size), "hook {$steel} {$size}");
            $this->assertSame((string) $bends[$index], (string) $this->calculator()->bendAllowance($steel, $size), "bend {$steel} {$size}");
        }
    }

    /** An independent check on the typed-in table: its footnote's rule must give the same figures. */
    public function test_table_two_agrees_with_its_footnote_apart_from_the_one_cell_printed_differently(): void
    {
        $multiples = ['mild' => [['9', '11'], ['5', '5.5']], 'medium' => [['11', '13'], ['5.5', '6']], 'deformed' => [['13', '17'], ['6', '7']]];

        foreach (BbsCalculator::SIZES as $size) {
            $band = $size > 25 ? 1 : 0;

            foreach ($multiples as $steel => [$hook, $bend]) {
                $expectedHook = $this->footnoteValue($hook[$band], $size);
                $expectedBend = $this->footnoteValue($bend[$band], $size);

                // The standard prints 75 where its footnote works out to 80. The table governs.
                if ($steel === 'deformed' && $size === 6) {
                    $this->assertSame('80', $expectedHook);
                    $this->assertSame('75', (string) $this->calculator()->hookAllowance($steel, $size));
                } else {
                    $this->assertSame($expectedHook, (string) $this->calculator()->hookAllowance($steel, $size), "hook {$steel} {$size}");
                }

                $this->assertSame($expectedBend, (string) $this->calculator()->bendAllowance($steel, $size), "bend {$steel} {$size}");
            }
        }
    }

    private function footnoteValue(string $multiple, int $size): string
    {
        $rounded = Dec::of($multiple)->multipliedBy($size)->dividedBy(5, 0, RoundingMode::HalfUp)->multipliedBy(5);

        return (string) ($rounded->isLessThan(75) ? Dec::of(75) : $rounded);
    }

    public function test_the_table_one_example_column_bar(): void
    {
        // Table I: 25 mm mild steel, 5 sets of 4, straight, 3000 mm.
        $result = $this->calculator()->calculate('III-A', 'mild', 25, ['l' => 3000], 5, 4);

        $this->assertSame(20, $result['total_bars']);
        $this->assertSame('3000.000', $result['cutting_length_mm']);
        $this->assertSame('60.000', $result['total_length_m']);
        // 25 x 25 / 162 = 3.858024 kg/m. 625 x 60 / 162 = 231.48148 kg.
        $this->assertSame('3.858', $result['unit_weight_kg_m']);
        $this->assertSame('231.481', $result['total_weight_kg']);
    }

    /** @return array<string, array{string, string, int, array<string, int|string>, string, string}> */
    public static function workedLengths(): array
    {
        return [
            // shape, steel, size, dimensions, sum of dimensions and allowances, cutting length to the next 25 mm
            'hook one end: 2000 + H175' => ['III-B', 'medium', 16, ['l' => 2000], '2175.000', '2175.000'],
            'hooks: 3000 + 2 x H110' => ['III-C', 'mild', 12, ['l' => 3000], '3220.000', '3225.000'],
            'bend one end: 1000 + B75' => ['III-D', 'mild', 10, ['l' => 1000], '1075.000', '1075.000'],
            'bends: 1500 + 2 x B120' => ['III-E', 'deformed', 20, ['l' => 1500], '1740.000', '1750.000'],
            'crank: 1000 + 600 + 1000' => ['IV-A', 'deformed', 16, ['A' => 1000, 'C' => 600, 'E' => 1000, 'D' => 150], '2600.000', '2600.000'],
            'crank with hooks: 2500 + 2 x H210' => ['IV-B', 'deformed', 16, ['A' => 1000, 'C' => 500, 'E' => 1000, 'D' => 300], '2920.000', '2925.000'],
            'double crank: 3200 + 2 x H155' => ['IV-C', 'deformed', 12, ['A' => 800, 'C1' => 500, 'E' => 600, 'C2' => 500, 'F' => 800, 'D1' => 300, 'D2' => 300], '3510.000', '3525.000'],
            'L bar: 3000 - 64/2 - 16' => ['V-A', 'deformed', 16, ['A' => 600, 'E' => 2400], '2952.000', '2975.000'],
            'L bar with a stated radius: 3000 - 80/2 - 16' => ['V-A', 'deformed', 16, ['A' => 600, 'E' => 2400, 'R' => 80], '2944.000', '2950.000'],
            'L bar, 32 mm uses k = 6: 2000 - 192/2 - 32' => ['V-A', 'deformed', 32, ['A' => 1000, 'E' => 1000], '1872.000', '1875.000'],
            'L with bends: 3000 - 12 - 12 + 2 x B75' => ['V-B', 'mild', 12, ['A' => 1000, 'E' => 2000], '3126.000', '3150.000'],
            'L with hooks: 3000 - 12 - 12 + 2 x H110' => ['V-C', 'mild', 12, ['A' => 1000, 'E' => 2000], '3196.000', '3200.000'],
            'stirrup 24d: 2 x 650 + 192' => ['VIII-A', 'deformed', 8, ['A' => 400, 'E' => 250], '1492.000', '1500.000'],
            'stirrup 20d: 2 x 650 + 160' => ['VIII-B', 'deformed', 8, ['A' => 400, 'E' => 250], '1460.000', '1475.000'],
            'open stirrup: 600 + 200 + 280' => ['VIII-C', 'deformed', 10, ['A' => 300, 'E' => 200], '1080.000', '1100.000'],
            'stirrup with arm 12d: 1300 + 120 + B75' => ['VIII-D', 'deformed', 10, ['A' => 300, 'E' => 200, 'C' => 500], '1495.000', '1500.000'],
            'stirrup with arm 9d: 1300 + 90 + B75' => ['VIII-E', 'deformed', 10, ['A' => 300, 'E' => 200, 'C' => 500], '1465.000', '1475.000'],
            'diamond 24d: 1000 + 192' => ['VIII-F', 'deformed', 8, ['C' => 250], '1192.000', '1200.000'],
            'diamond 20d: 1000 + 160' => ['VIII-G', 'deformed', 8, ['C' => 250], '1160.000', '1175.000'],
            'long link: 800 + 450 + 220' => ['IX-A', 'deformed', 10, ['A' => 400, 'D' => 150], '1470.000', '1475.000'],
            'hooked link: 800 + 450 + 220' => ['IX-B', 'deformed', 10, ['A' => 400, 'D' => 150], '1470.000', '1475.000'],
            'helix: 10 x pi x 308 + 64' => ['IX-C', 'deformed', 8, ['N' => 10, 'D' => 300, 'P' => 50], '9740.105', '9750.000'],
        ];
    }

    /** @param array<string, int|string> $dims */
    #[DataProvider('workedLengths')]
    public function test_worked_lengths(string $shape, string $steel, int $size, array $dims, string $raw, string $cut): void
    {
        $result = $this->calculator()->calculate($shape, $steel, $size, $dims, 1, 1);

        $this->assertSame($raw, $result['raw_length_mm']);
        $this->assertSame($cut, $result['cutting_length_mm']);
    }

    public function test_every_listed_shape_has_a_worked_example(): void
    {
        $tested = array_unique(array_column(self::workedLengths(), 0));
        sort($tested);
        $listed = array_values(array_diff(array_keys(BbsCalculator::shapes()), ['III-A']));
        sort($listed);

        $this->assertSame($listed, $tested);
    }

    public function test_stirrup_schedule_totals(): void
    {
        // 200 stirrups of 8 mm at 1500 mm: 300 m. 64 x 300 / 162 = 118.5185 kg.
        $result = $this->calculator()->calculate('VIII-A', 'deformed', 8, ['A' => 400, 'E' => 250], 10, 20);

        $this->assertSame(200, $result['total_bars']);
        $this->assertSame('300.000', $result['total_length_m']);
        $this->assertSame('0.395', $result['unit_weight_kg_m']);
        $this->assertSame('118.519', $result['total_weight_kg']);
    }

    public function test_weight_is_divided_once_so_rounding_kg_per_metre_does_not_skew_it(): void
    {
        // 12 mm, 1000 bars of 10 m. 144 x 10000 / 162 = 8888.889 kg, not 0.889 x 10000 = 8890.
        $result = $this->calculator()->calculate('III-A', 'deformed', 12, ['l' => 10000], 1, 1000);

        $this->assertSame('0.889', $result['unit_weight_kg_m']);
        $this->assertSame('8888.889', $result['total_weight_kg']);
    }

    public function test_cutting_length_goes_up_to_the_next_25_mm(): void
    {
        $this->assertSame('1000.000', $this->calculator()->calculate('III-A', 'mild', 10, ['l' => 1000], 1, 1)['cutting_length_mm']);
        $this->assertSame('1025.000', $this->calculator()->calculate('III-A', 'mild', 10, ['l' => '1000.5'], 1, 1)['cutting_length_mm']);
        $this->assertSame('1025.000', $this->calculator()->calculate('III-A', 'mild', 10, ['l' => 1001], 1, 1)['cutting_length_mm']);
    }

    /** @return array<string, array{string, array<string, int>}> */
    public static function outsideTheFormula(): array
    {
        return [
            'crank slope C is not more than 3D' => ['IV-A', ['A' => 1000, 'C' => 450, 'E' => 1000, 'D' => 150]],
            'crank steeper than 45 degrees' => ['IV-B', ['A' => 1000, 'C' => 500, 'E' => 1000, 'D' => 400]],
            'second crank steeper than 45 degrees' => ['IV-C', ['A' => 800, 'C1' => 500, 'E' => 600, 'C2' => 500, 'F' => 800, 'D1' => 300, 'D2' => 400]],
            'helix pitch above D/5' => ['IX-C', ['N' => 10, 'D' => 300, 'P' => 61]],
        ];
    }

    /** @param array<string, int> $dims */
    #[DataProvider('outsideTheFormula')]
    public function test_a_formula_is_refused_outside_its_stated_limits(string $shape, array $dims): void
    {
        $this->expectException(ValidationException::class);

        $this->calculator()->calculate($shape, 'deformed', 12, $dims, 1, 1);
    }

    public function test_a_missing_dimension_is_refused(): void
    {
        $this->expectException(ValidationException::class);

        $this->calculator()->calculate('V-A', 'deformed', 12, ['A' => 1000], 1, 1);
    }

    public function test_dimensions_that_leave_no_bar_are_refused(): void
    {
        $this->expectException(ValidationException::class);

        $this->calculator()->calculate('V-A', 'deformed', 12, ['A' => 1, 'E' => 1, 'R' => 500], 1, 1);
    }

    public function test_only_the_dimensions_the_shape_uses_are_kept(): void
    {
        $result = $this->calculator()->calculate('III-A', 'mild', 10, ['l' => 1000, 'A' => 55], 1, 1);

        $this->assertSame(['l' => '1000'], $result['dims']);
    }
}
