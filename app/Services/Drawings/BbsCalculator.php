<?php

namespace App\Services\Drawings;

use App\Support\Dec;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Validation\ValidationException;

/**
 * Bar lengths and weights by IS 2502:1963, Code of Practice for Bending and Fixing of Bars.
 *
 * Shape codes are the standard's table and reference: "V-A" is Table V, reference A.
 * Hook and bend allowances are Table II's printed values, cutting lengths follow clause 5.1.1,
 * and weight is d²/162 kg/m. Results are a calculation aid: an engineer must check them.
 * Tables VI and VII (special loops and chairs) are not included.
 */
final class BbsCalculator
{
    /** Nominal sizes listed in Table II, in mm. */
    public const SIZES = [5, 6, 8, 10, 12, 16, 20, 22, 25, 28, 32, 36, 40, 45, 50];

    private const PI = '3.14159265358979323846';

    private const WEIGHT_DIVISOR = 162;

    private const CUT_STEP = 25;

    private const LARGE_BAR_OVER = 25;

    private const MAX_CUT_MM = 1000000;

    private const MAX_TOTAL_BARS = 1000000;

    /**
     * k from the notes to Figure 1, and the position of the steel in the Table II columns.
     * Above 25 mm the note says k should be raised, which gives the "Recommended" columns.
     */
    private const STEELS = [
        'mild' => ['label' => 'Mild steel (IS 432)', 'k' => [2, 3], 'column' => 0],
        'medium' => ['label' => 'Medium tensile steel (IS 432)', 'k' => [3, 4], 'column' => 1],
        'deformed' => ['label' => 'Cold worked or TMT (IS 1786)', 'k' => [4, 6], 'column' => 2],
    ];

    /**
     * Table II hook allowance H in mm, for mild, medium tensile and cold worked steel.
     * "Min" columns up to 25 mm and "Recommended" above. Clause 3.2.1 makes the printed
     * table binding, so it is held as data: its footnote would give 80, not 75, at 6 mm cold worked.
     */
    private const HOOK = [
        5 => [75, 75, 75], 6 => [75, 75, 75], 8 => [75, 90, 105], 10 => [90, 110, 130], 12 => [110, 130, 155],
        16 => [145, 175, 210], 20 => [180, 220, 260], 22 => [200, 240, 285], 25 => [225, 275, 325],
        28 => [310, 365, 475], 32 => [350, 415, 545], 36 => [395, 470, 610], 40 => [440, 520, 680],
        45 => [495, 585, 765], 50 => [550, 650, 850],
    ];

    /** Table II bend allowance B in mm, arranged as for the hook allowance. */
    private const BEND = [
        5 => [75, 75, 75], 6 => [75, 75, 75], 8 => [75, 75, 75], 10 => [75, 75, 75], 12 => [75, 75, 75],
        16 => [80, 90, 95], 20 => [100, 110, 120], 22 => [110, 120, 130], 25 => [125, 140, 150],
        28 => [155, 170, 195], 32 => [175, 190, 225], 36 => [200, 215, 250], 40 => [220, 240, 280],
        45 => [250, 270, 315], 50 => [275, 300, 350],
    ];

    /** @return array<string, string> */
    public static function steelTypes(): array
    {
        return array_map(fn (array $steel) => $steel['label'], self::STEELS);
    }

    /**
     * Every shape, with what the user enters and the formula as the standard prints it.
     *
     * @return array<string, array{name:string, dims:list<string>, optional?:list<string>, formula:string, condition?:string, sketch:string}>
     */
    public static function shapes(): array
    {
        return [
            'III-A' => ['name' => 'Straight', 'dims' => ['l'], 'formula' => 'l', 'sketch' => 'M10 30H110'],
            'III-B' => ['name' => 'Straight, hook at one end', 'dims' => ['l'], 'formula' => 'l + H', 'sketch' => 'M110 36H22a7 7 0 0 1 0-14h8'],
            'III-C' => ['name' => 'Straight, hooks at both ends', 'dims' => ['l'], 'formula' => 'l + 2H', 'sketch' => 'M30 22h-8a7 7 0 0 0 0 14H98a7 7 0 0 0 0-14h-8'],
            'III-D' => ['name' => 'Straight, bend at one end', 'dims' => ['l'], 'formula' => 'l + B', 'sketch' => 'M14 16V42H110'],
            'III-E' => ['name' => 'Straight, bends at both ends', 'dims' => ['l'], 'formula' => 'l + 2B', 'sketch' => 'M14 16V42H106V16'],
            'IV-A' => ['name' => 'Cranked bar', 'dims' => ['A', 'C', 'E', 'D'], 'formula' => 'A + C + E', 'condition' => 'C must be more than 3D', 'sketch' => 'M8 18H44L72 42H112'],
            'IV-B' => ['name' => 'Cranked bar with hooks', 'dims' => ['A', 'C', 'E', 'D'], 'formula' => 'A + C + E + 2H', 'condition' => 'Slope must be 45 degrees or less', 'sketch' => 'M26 8h-8a6 6 0 0 0 0 12H44L72 44H102a6 6 0 0 0 0-12h-8'],
            'IV-C' => ['name' => 'Double cranked bar with hooks', 'dims' => ['A', 'C1', 'E', 'C2', 'F', 'D1', 'D2'], 'formula' => 'A + C1 + E + C2 + F + 2H', 'condition' => 'Both slopes must be 45 degrees or less', 'sketch' => 'M22 10h-6a5 5 0 0 0 0 10H34L50 44H70L86 20H104a5 5 0 0 0 0-10h-6'],
            'V-A' => ['name' => 'L bar', 'dims' => ['A', 'E'], 'optional' => ['R'], 'formula' => 'A + E - R/2 - d', 'sketch' => 'M14 8V46H110'],
            'V-B' => ['name' => 'L bar, bends at both ends', 'dims' => ['A', 'E'], 'optional' => ['R'], 'formula' => 'A + E - R/2 - d + 2B', 'sketch' => 'M26 8H14V46H106V34'],
            'V-C' => ['name' => 'L bar, hooks at both ends', 'dims' => ['A', 'E'], 'optional' => ['R'], 'formula' => 'A + E - R/2 - d + 2H', 'sketch' => 'M26 24v-10a6 6 0 0 0-12 0V46H100a6 6 0 0 0 0-12h-8'],
            'VIII-A' => ['name' => 'Rectangular stirrup, ends turned in', 'dims' => ['A', 'E'], 'formula' => '2(A + E) + 24d', 'sketch' => 'M38 10H82V50H38ZM38 20L50 10M46 10L38 18'],
            'VIII-B' => ['name' => 'Rectangular stirrup, ends bent', 'dims' => ['A', 'E'], 'formula' => '2(A + E) + 20d', 'sketch' => 'M38 10H82V50H38ZM38 10V22M38 10H50'],
            'VIII-C' => ['name' => 'Open stirrup with hooks', 'dims' => ['A', 'E'], 'formula' => '2A + E + 28d', 'sketch' => 'M50 20v-6a6 6 0 0 0-12 0V50H82V14a6 6 0 0 0-12 0v6'],
            'VIII-D' => ['name' => 'Stirrup with arm, ends turned in', 'dims' => ['A', 'E', 'C'], 'formula' => '2A + E + C + 12d + B', 'sketch' => 'M10 22V12H64V50H98V12H64M64 20L74 12'],
            'VIII-E' => ['name' => 'Stirrup with arm', 'dims' => ['A', 'E', 'C'], 'formula' => '2A + E + C + 9d + B', 'sketch' => 'M10 22V12H64V50H98V12H64'],
            'VIII-F' => ['name' => 'Diamond link, ends turned in', 'dims' => ['C'], 'formula' => '4C + 24d', 'sketch' => 'M60 8L100 30L60 52L20 30ZM20 30L34 22M20 30L34 38'],
            'VIII-G' => ['name' => 'Diamond link, ends bent', 'dims' => ['C'], 'formula' => '4C + 20d', 'sketch' => 'M60 8L100 30L60 52L20 30Z'],
            'IX-A' => ['name' => 'Long link, crossed ends', 'dims' => ['A', 'D'], 'formula' => '2A + 3D + 22d', 'sketch' => 'M48 12H72a10 10 0 0 1 10 10V38a10 10 0 0 1-10 10H48a10 10 0 0 1-10-10V22a10 10 0 0 1 10-10ZM52 6L68 20M68 6L52 20'],
            'IX-B' => ['name' => 'Long link, hooked ends', 'dims' => ['A', 'D'], 'formula' => '2A + 3D + 22d', 'sketch' => 'M48 12H72a10 10 0 0 1 10 10V38a10 10 0 0 1-10 10H48a10 10 0 0 1-10-10V22a10 10 0 0 1 10-10Z'],
            'IX-C' => ['name' => 'Helix', 'dims' => ['N', 'D', 'P'], 'formula' => 'N x pi x (D + d) + 8d', 'condition' => 'Pitch P must not exceed D/5', 'sketch' => 'M14 14C24 14 24 46 34 46S44 14 54 14S64 46 74 46S84 14 94 14S104 46 110 46'],
        ];
    }

    /** Table II hook allowance H for a bar, in mm. */
    public function hookAllowance(string $steel, int $dia): BigDecimal
    {
        return Dec::of(self::HOOK[$dia][self::STEELS[$steel]['column']]);
    }

    /** Table II bend allowance B for a bar, in mm. */
    public function bendAllowance(string $steel, int $dia): BigDecimal
    {
        return Dec::of(self::BEND[$dia][self::STEELS[$steel]['column']]);
    }

    /** Internal bend radius kd from Figure 1, in mm. */
    public function bendRadius(string $steel, int $dia): BigDecimal
    {
        return Dec::of(self::STEELS[$steel]['k'][$this->band($dia)])->multipliedBy($dia);
    }

    /**
     * Work out one line of the schedule. All values are strings.
     *
     * @param  array<string, mixed>  $dims  dimensions in mm, or turns for N
     * @return array{dims:array<string,string>, hook_allowance_mm:string, bend_allowance_mm:string, raw_length_mm:string, cutting_length_mm:string, total_bars:int, total_length_m:string, unit_weight_kg_m:string, total_weight_kg:string}
     */
    public function calculate(string $shape, string $steel, int $dia, array $dims, int $sets, int $perSet): array
    {
        $definition = self::shapes()[$shape];
        $values = $this->readDimensions($definition, $dims);
        $this->assertConditions($shape, $values);

        $hook = $this->hookAllowance($steel, $dia);
        $bend = $this->bendAllowance($steel, $dia);
        $radius = $values['R'] ?? $this->bendRadius($steel, $dia);

        $raw = $this->rawLength($shape, $values, Dec::of($dia), $hook, $bend, $radius);

        if (! $raw->isPositive()) {
            throw ValidationException::withMessages(['dims' => __('These dimensions do not give a usable bar length.')]);
        }

        $cut = $this->roundUpToCutStep($raw);

        if ($cut->isGreaterThan(self::MAX_CUT_MM)) {
            throw ValidationException::withMessages(['dims' => __('This bar is too long. Check the dimensions.')]);
        }

        $totalBars = $sets * $perSet;

        if ($totalBars > self::MAX_TOTAL_BARS) {
            throw ValidationException::withMessages(['per_set' => __('The total number of bars is too large.')]);
        }

        $totalLength = $cut->multipliedBy($totalBars)->dividedBy(1000, 3, RoundingMode::Unnecessary);
        $square = Dec::of($dia * $dia);

        return [
            'dims' => array_map(fn (BigDecimal $value) => (string) $value->strippedOfTrailingZeros(), $values),
            'hook_allowance_mm' => Dec::qty($hook),
            'bend_allowance_mm' => Dec::qty($bend),
            'raw_length_mm' => Dec::qty($raw),
            'cutting_length_mm' => Dec::qty($cut),
            'total_bars' => $totalBars,
            'total_length_m' => Dec::qty($totalLength),
            'unit_weight_kg_m' => Dec::qty($square->dividedBy(self::WEIGHT_DIVISOR, 12, RoundingMode::HalfUp)),
            // One division at the very end, so rounding the kg/m figure cannot skew the total.
            'total_weight_kg' => Dec::qty($square->multipliedBy($totalLength)->dividedBy(self::WEIGHT_DIVISOR, 12, RoundingMode::HalfUp)),
        ];
    }

    private function band(int $dia): int
    {
        return $dia > self::LARGE_BAR_OVER ? 1 : 0;
    }

    /** Clause 5.1.1: the next whole 25 mm. A length already on a 25 mm step stays as it is. */
    private function roundUpToCutStep(BigDecimal $raw): BigDecimal
    {
        return $raw->dividedBy(self::CUT_STEP, 0, RoundingMode::Ceiling)->multipliedBy(self::CUT_STEP);
    }

    /** @return array<string, BigDecimal> */
    private function readDimensions(array $definition, array $dims): array
    {
        $values = [];

        foreach ($definition['dims'] as $name) {
            if (! isset($dims[$name]) || $dims[$name] === '') {
                throw ValidationException::withMessages(["dims.{$name}" => __('Enter dimension :name.', ['name' => $name])]);
            }

            $values[$name] = Dec::of(trim((string) $dims[$name]));
        }

        foreach ($definition['optional'] ?? [] as $name) {
            if (isset($dims[$name]) && $dims[$name] !== '') {
                $values[$name] = Dec::of(trim((string) $dims[$name]));
            }
        }

        return $values;
    }

    /** The standard limits some formulas to certain geometry. Outside it the bar must be calculated by hand. */
    private function assertConditions(string $shape, array $v): void
    {
        $slopeWithin45 = fn (BigDecimal $rise, BigDecimal $slope) => $rise->multipliedBy($rise)->multipliedBy(2)
            ->isLessThanOrEqualTo($slope->multipliedBy($slope));

        $valid = match ($shape) {
            'IV-A' => $v['C']->isGreaterThan($v['D']->multipliedBy(3)),
            'IV-B' => $slopeWithin45($v['D'], $v['C']),
            'IV-C' => $slopeWithin45($v['D1'], $v['C1']) && $slopeWithin45($v['D2'], $v['C2']),
            'IX-C' => $v['P']->multipliedBy(5)->isLessThanOrEqualTo($v['D']),
            default => true,
        };

        if (! $valid) {
            throw ValidationException::withMessages(['dims' => __('The IS 2502 formula for this shape does not apply to these dimensions: :condition.', [
                'condition' => __(self::shapes()[$shape]['condition']),
            ])]);
        }
    }

    /** @param array<string, BigDecimal> $v */
    private function rawLength(string $shape, array $v, BigDecimal $d, BigDecimal $H, BigDecimal $B, BigDecimal $R): BigDecimal
    {
        $legs = fn () => $v['A']->plus($v['E'])->minus($R->multipliedBy('0.5'))->minus($d);

        return match ($shape) {
            'III-A' => $v['l'],
            'III-B' => $v['l']->plus($H),
            'III-C' => $v['l']->plus($H->multipliedBy(2)),
            'III-D' => $v['l']->plus($B),
            'III-E' => $v['l']->plus($B->multipliedBy(2)),
            'IV-A' => $v['A']->plus($v['C'])->plus($v['E']),
            'IV-B' => $v['A']->plus($v['C'])->plus($v['E'])->plus($H->multipliedBy(2)),
            'IV-C' => $v['A']->plus($v['C1'])->plus($v['E'])->plus($v['C2'])->plus($v['F'])->plus($H->multipliedBy(2)),
            'V-A' => $legs(),
            'V-B' => $legs()->plus($B->multipliedBy(2)),
            'V-C' => $legs()->plus($H->multipliedBy(2)),
            'VIII-A' => $v['A']->plus($v['E'])->multipliedBy(2)->plus($d->multipliedBy(24)),
            'VIII-B' => $v['A']->plus($v['E'])->multipliedBy(2)->plus($d->multipliedBy(20)),
            'VIII-C' => $v['A']->multipliedBy(2)->plus($v['E'])->plus($d->multipliedBy(28)),
            'VIII-D' => $v['A']->multipliedBy(2)->plus($v['E'])->plus($v['C'])->plus($d->multipliedBy(12))->plus($B),
            'VIII-E' => $v['A']->multipliedBy(2)->plus($v['E'])->plus($v['C'])->plus($d->multipliedBy(9))->plus($B),
            'VIII-F' => $v['C']->multipliedBy(4)->plus($d->multipliedBy(24)),
            'VIII-G' => $v['C']->multipliedBy(4)->plus($d->multipliedBy(20)),
            'IX-A', 'IX-B' => $v['A']->multipliedBy(2)->plus($v['D']->multipliedBy(3))->plus($d->multipliedBy(22)),
            'IX-C' => $v['N']->multipliedBy(self::PI)->multipliedBy($v['D']->plus($d))->plus($d->multipliedBy(8)),
        };
    }
}
