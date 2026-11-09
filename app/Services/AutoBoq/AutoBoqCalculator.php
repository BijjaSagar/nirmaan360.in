<?php

namespace App\Services\AutoBoq;

use App\Support\Dec;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Validation\ValidationException;

/**
 * Turns building dimensions into BOQ quantities with the standard estimating formulas, and shows
 * the working for every line. It reads numbers, never a drawing: the dimensions come typed in or
 * from takeoff totals. Structure and RCC are worked out in full. Finishing that follows from the
 * structure (plaster, flooring, painting) is worked out too. Tiles detail, doors, electrical and
 * plumbing need their own drawings, so those lines come out at zero for the engineer to fill in.
 *
 * Every figure is a Dec. Lines keep stable codes, so running again updates rather than duplicates.
 */
final class AutoBoqCalculator
{
    /** The largest quantity a BOQ line holds: NUMERIC(14,3). */
    public const MAX_QTY = '99999999999';

    public const GROUPS = ['structure' => 'Structure', 'finishing' => 'Finishing worked out from the structure', 'manual' => 'Needs its own drawing: fill in by hand'];

    /** code => [description, unit, category, group], in BOQ order. */
    public const LINES = [
        'AB-EXC' => ['Excavation for footings in all soils', 'cum', 'excavation', 'structure'],
        'AB-FILL' => ['Filling: backfilling of footing pits and plinth filling', 'cum', 'filling', 'structure'],
        'AB-PCC' => ['PCC 1:4:8 under footings and ground-floor flooring', 'cum', 'pcc', 'structure'],
        'AB-RCC-FTG' => ['RCC M25 in footings', 'cum', 'rcc', 'structure'],
        'AB-RCC-COL' => ['RCC M25 in columns', 'cum', 'rcc', 'structure'],
        'AB-RCC-BM' => ['RCC M25 in beams', 'cum', 'rcc', 'structure'],
        'AB-RCC-SLB' => ['RCC M25 in slabs', 'cum', 'rcc', 'structure'],
        'AB-RCC-STR' => ['RCC M25 in staircases', 'cum', 'rcc', 'structure'],
        'AB-RCC-CHJ' => ['RCC M25 in chajjas and lofts', 'cum', 'rcc', 'structure'],
        'AB-STL' => ['Steel reinforcement, TMT bars, cut, bent and placed', 'MT', 'steel', 'structure'],
        'AB-CEN-FTG' => ['Centering and shuttering: footing sides', 'sqm', 'rcc', 'structure'],
        'AB-CEN-COL' => ['Centering and shuttering: columns', 'sqm', 'rcc', 'structure'],
        'AB-CEN-BM' => ['Centering and shuttering: beam sides and soffit', 'sqm', 'rcc', 'structure'],
        'AB-CEN-SLB' => ['Centering and shuttering: slab soffit', 'sqm', 'rcc', 'structure'],
        'AB-CEN-STR' => ['Centering and shuttering: staircase', 'sqm', 'rcc', 'structure'],
        'AB-BRK' => ['Brick or block masonry in cement mortar', 'cum', 'brick_work', 'structure'],
        'AB-PL-INT' => ['Internal cement plaster 12 mm', 'sqm', 'plaster', 'finishing'],
        'AB-PL-EXT' => ['External sand-faced plaster 20 mm', 'sqm', 'plaster', 'finishing'],
        'AB-PL-CLG' => ['Ceiling plaster 6 mm', 'sqm', 'plaster', 'finishing'],
        'AB-FLR' => ['Flooring with tiles, including bedding', 'sqm', 'flooring', 'finishing'],
        'AB-PNT-INT' => ['Internal painting over putty', 'sqm', 'finishing', 'finishing'],
        'AB-PNT-EXT' => ['External painting', 'sqm', 'finishing', 'finishing'],
        'AB-WP' => ['Terrace waterproofing', 'sqm', 'other', 'finishing'],
        'AB-TIL-DADO' => ['Wall tiles (dado) in bathrooms and kitchen', 'sqm', 'flooring', 'manual'],
        'AB-SKT' => ['Skirting', 'rmt', 'flooring', 'manual'],
        'AB-DOOR' => ['Doors with frames and fittings', 'nos', 'finishing', 'manual'],
        'AB-WIN' => ['Windows with grills and glazing', 'sqm', 'finishing', 'manual'],
        'AB-RAIL' => ['Staircase and balcony railing', 'rmt', 'finishing', 'manual'],
        'AB-EL-PT' => ['Electrical points: light, fan and plug', 'nos', 'electrical', 'manual'],
        'AB-EL-WIR' => ['Electrical wiring, distribution board and earthing', 'LS', 'electrical', 'manual'],
        'AB-PLB-PT' => ['Plumbing: water supply and drainage points', 'nos', 'plumbing', 'manual'],
        'AB-SAN' => ['Sanitary fixtures and fittings', 'nos', 'plumbing', 'manual'],
    ];

    /** Which drawing each hand-filled line waits for. */
    public const MANUAL_NOTES = [
        'AB-TIL-DADO' => 'Needs the toilet and kitchen layouts.',
        'AB-SKT' => 'Needs the floor plan with room sizes.',
        'AB-DOOR' => 'Needs the door and window schedule.',
        'AB-WIN' => 'Needs the door and window schedule.',
        'AB-RAIL' => 'Needs the staircase and balcony details.',
        'AB-EL-PT' => 'Needs the electrical layout.',
        'AB-EL-WIR' => 'Needs the electrical layout.',
        'AB-PLB-PT' => 'Needs the plumbing layout.',
        'AB-SAN' => 'Needs the plumbing layout.',
    ];

    /** @return list<string> */
    public static function codes(): array
    {
        return array_keys(self::LINES);
    }

    /**
     * Every line, in BOQ order, keyed by code.
     *
     * @param  array<string, mixed>  $d  Dimensions as AutoBoqInput::dimensions() gives them.
     * @param  array<string, string>  $a  Assumptions as Assumptions::forOrganization() gives them.
     * @return array<string, AutoBoqLine>
     */
    public function lines(array $d, array $a): array
    {
        $as = fn (string $key) => Dec::of($a[$key] ?? Assumptions::CATALOGUE[$key][0]);
        $two = BigDecimal::of(2);

        $floors = BigDecimal::of(max(0, (int) ($d['floors'] ?? 0)));
        $floorHeight = $this->dec($d['floor_height'] ?? null);
        $plinthArea = $this->dec($d['plinth_area'] ?? null);
        $plinthHeight = $this->dec($d['plinth_height'] ?? null);
        $foundationDepth = $this->dec($d['foundation_depth'] ?? null);
        $stairArea = $this->dec($d['stair_area'] ?? null);
        $chajjaLength = $this->dec($d['chajja_length'] ?? null);
        $chajjaWidth = $this->dec($d['chajja_width'] ?? null);
        $chajjaThickness = $this->dec($d['chajja_thickness'] ?? null);

        $footings = $this->groups($d, 'footings', ['nos', 'length', 'breadth', 'depth'], $floorHeight);
        $columns = $this->groups($d, 'columns', ['nos', 'length', 'breadth', 'height', 'floors'], $floorHeight);
        $beams = $this->groups($d, 'beams', ['length', 'width', 'depth', 'floors'], $floorHeight);
        $slabs = $this->groups($d, 'slabs', ['area', 'openings', 'thickness', 'count'], $floorHeight);
        $walls = $this->groups($d, 'walls', ['length', 'height', 'thickness', 'openings', 'floors'], $floorHeight);

        $lines = [];
        $add = function (string $code, BigDecimal $total, array $working = [], ?string $note = null) use (&$lines) {
            [$description, $unit, $category, $group] = self::LINES[$code];

            if (count($working) > 1) {
                $working[] = __('Total :qty :unit', ['qty' => Dec::qty($total), 'unit' => $unit]);
            }

            if ($total->isGreaterThan(self::MAX_QTY)) {
                throw ValidationException::withMessages(['dimensions' => __('The quantity of :code is too large to hold. Check the dimensions.', ['code' => $code])]);
            }

            $lines[$code] = new AutoBoqLine($code, __($description), $unit, $total, $category, $group, $working, $note);
        };

        // Excavation: a pit around every footing, down to the bottom of the PCC.
        $margin = $as('excavation_margin');
        $excavation = BigDecimal::zero();
        $working = [__('nos × (length + 2 × margin) × (breadth + 2 × margin) × foundation depth')];

        foreach ($footings as $f) {
            $pit = $f['nos']->multipliedBy($f['length']->plus($margin->multipliedBy($two)))->multipliedBy($f['breadth']->plus($margin->multipliedBy($two)))->multipliedBy($foundationDepth);
            $excavation = $excavation->plus($pit);
            $working[] = sprintf('%s: %s × (%s + 2 × %s) × (%s + 2 × %s) × %s = %s', $f['label'], $this->n($f['nos']), $this->n($f['length']), $this->n($margin), $this->n($f['breadth']), $this->n($margin), $this->n($foundationDepth), Dec::qty($pit));
        }

        $add('AB-EXC', $excavation, $working, match (true) {
            $footings === [] => __('No footing groups entered.'),
            $foundationDepth->isZero() => __('Foundation depth is 0. Enter the depth from ground level to the bottom of the PCC.'),
            default => null,
        });

        // PCC: a bed under each footing, projecting past it, and a bed under the ground-floor flooring.
        $offset = $as('pcc_offset');
        $pccThickness = $as('pcc_thickness');
        $pccFloor = $as('pcc_floor_thickness');
        $pccFootings = BigDecimal::zero();
        $working = [__('nos × (length + 2 × projection) × (breadth + 2 × projection) × thickness')];

        foreach ($footings as $f) {
            $bed = $f['nos']->multipliedBy($f['length']->plus($offset->multipliedBy($two)))->multipliedBy($f['breadth']->plus($offset->multipliedBy($two)))->multipliedBy($pccThickness);
            $pccFootings = $pccFootings->plus($bed);
            $working[] = sprintf('%s: %s × (%s + 2 × %s) × (%s + 2 × %s) × %s = %s', $f['label'], $this->n($f['nos']), $this->n($f['length']), $this->n($offset), $this->n($f['breadth']), $this->n($offset), $this->n($pccThickness), Dec::qty($bed));
        }

        $pccUnderFloor = $plinthArea->multipliedBy($pccFloor);

        if (! $pccUnderFloor->isZero()) {
            $working[] = sprintf('%s: %s × %s = %s', __('Under the ground-floor flooring'), $this->n($plinthArea), $this->n($pccFloor), Dec::qty($pccUnderFloor));
        }

        // RCC in footings.
        $rccFootings = BigDecimal::zero();
        $ftgWorking = [__('nos × length × breadth × depth')];

        foreach ($footings as $f) {
            $volume = $f['nos']->multipliedBy($f['length'])->multipliedBy($f['breadth'])->multipliedBy($f['depth']);
            $rccFootings = $rccFootings->plus($volume);
            $ftgWorking[] = sprintf('%s: %s × %s × %s × %s = %s', $f['label'], $this->n($f['nos']), $this->n($f['length']), $this->n($f['breadth']), $this->n($f['depth']), Dec::qty($volume));
        }

        // Filling: the pits are filled back around the concrete, and the plinth is filled up to floor level.
        $backfill = $excavation->minus($pccFootings)->minus($rccFootings);
        $backfill = $backfill->isNegative() ? BigDecimal::zero() : $backfill;
        $plinthFill = $plinthArea->multipliedBy($plinthHeight);
        $add('AB-FILL', $backfill->plus($plinthFill), [
            sprintf('%s: %s − %s − %s = %s', __('Backfilling of pits (excavation − PCC − footing concrete)'), Dec::qty($excavation), Dec::qty($pccFootings), Dec::qty($rccFootings), Dec::qty($backfill)),
            sprintf('%s: %s × %s = %s', __('Plinth filling (plinth area × plinth height)'), $this->n($plinthArea), $this->n($plinthHeight), Dec::qty($plinthFill)),
        ], $plinthHeight->isZero() ? __('Plinth height is 0, so there is no plinth filling.') : null);

        $add('AB-PCC', $pccFootings->plus($pccUnderFloor), $working, $footings === [] ? __('No footing groups entered.') : null);
        $add('AB-RCC-FTG', $rccFootings, $ftgWorking, $footings === [] ? __('No footing groups entered.') : null);

        // RCC in columns, with each group repeated over the floors it spans.
        $rccColumns = BigDecimal::zero();
        $working = [__('nos × length × breadth × height × floors')];
        $centeringColumns = BigDecimal::zero();
        $colCentering = [__('nos × 2 × (length + breadth) × height × floors')];

        foreach ($columns as $c) {
            $volume = $c['nos']->multipliedBy($c['length'])->multipliedBy($c['breadth'])->multipliedBy($c['height'])->multipliedBy($c['floors']);
            $rccColumns = $rccColumns->plus($volume);
            $working[] = sprintf('%s: %s × %s × %s × %s × %s = %s', $c['label'], $this->n($c['nos']), $this->n($c['length']), $this->n($c['breadth']), $this->n($c['height']), $this->n($c['floors']), Dec::qty($volume));

            $area = $c['nos']->multipliedBy($two)->multipliedBy($c['length']->plus($c['breadth']))->multipliedBy($c['height'])->multipliedBy($c['floors']);
            $centeringColumns = $centeringColumns->plus($area);
            $colCentering[] = sprintf('%s: %s × 2 × (%s + %s) × %s × %s = %s', $c['label'], $this->n($c['nos']), $this->n($c['length']), $this->n($c['breadth']), $this->n($c['height']), $this->n($c['floors']), Dec::qty($area));
        }

        $add('AB-RCC-COL', $rccColumns, $working, $columns === [] ? __('No column groups entered.') : null);

        // RCC in beams.
        $rccBeams = BigDecimal::zero();
        $working = [__('length × width × depth × floors')];
        $centeringBeams = BigDecimal::zero();
        $beamCentering = [__('length × (2 × depth + width) × floors')];

        foreach ($beams as $b) {
            $volume = $b['length']->multipliedBy($b['width'])->multipliedBy($b['depth'])->multipliedBy($b['floors']);
            $rccBeams = $rccBeams->plus($volume);
            $working[] = sprintf('%s: %s × %s × %s × %s = %s', $b['label'], $this->n($b['length']), $this->n($b['width']), $this->n($b['depth']), $this->n($b['floors']), Dec::qty($volume));

            $area = $b['length']->multipliedBy($b['depth']->multipliedBy($two)->plus($b['width']))->multipliedBy($b['floors']);
            $centeringBeams = $centeringBeams->plus($area);
            $beamCentering[] = sprintf('%s: %s × (2 × %s + %s) × %s = %s', $b['label'], $this->n($b['length']), $this->n($b['depth']), $this->n($b['width']), $this->n($b['floors']), Dec::qty($area));
        }

        $add('AB-RCC-BM', $rccBeams, $working, $beams === [] ? __('No beam groups entered.') : null);

        // RCC in slabs: the cast area is the slab less its openings (open-to-sky, staircase and ducts).
        $rccSlabs = BigDecimal::zero();
        $slabArea = BigDecimal::zero();
        $working = [__('(area − openings) × thickness × count')];
        $slabWorking = [__('(area − openings) × count')];
        $topSlab = BigDecimal::zero();

        foreach ($slabs as $s) {
            $net = $s['area']->minus($s['openings']);
            $net = $net->isNegative() ? BigDecimal::zero() : $net;
            $volume = $net->multipliedBy($s['thickness'])->multipliedBy($s['count']);
            $rccSlabs = $rccSlabs->plus($volume);
            $working[] = sprintf('%s: (%s − %s) × %s × %s = %s', $s['label'], $this->n($s['area']), $this->n($s['openings']), $this->n($s['thickness']), $this->n($s['count']), Dec::qty($volume));

            $area = $net->multipliedBy($s['count']);
            $slabArea = $slabArea->plus($area);
            $slabWorking[] = sprintf('%s: (%s − %s) × %s = %s', $s['label'], $this->n($s['area']), $this->n($s['openings']), $this->n($s['count']), Dec::qty($area));
            $topSlab = $net;
        }

        $add('AB-RCC-SLB', $rccSlabs, $working, $slabs === [] ? __('No slabs entered.') : null);

        // Staircase: one flight set per floor, as an equivalent thickness over its plan area.
        $stairThickness = $as('stair_thickness');
        $rccStairs = $floors->multipliedBy($stairArea)->multipliedBy($stairThickness);
        $add('AB-RCC-STR', $rccStairs, [
            __('floors × plan area × equivalent thickness'),
            sprintf('%s × %s × %s = %s', $this->n($floors), $this->n($stairArea), $this->n($stairThickness), Dec::qty($rccStairs)),
        ], $stairArea->isZero() ? __('Staircase plan area is 0.') : null);

        // Chajjas and lofts.
        $rccChajja = $chajjaLength->multipliedBy($chajjaWidth)->multipliedBy($chajjaThickness);
        $add('AB-RCC-CHJ', $rccChajja, [
            __('total length × width × thickness'),
            sprintf('%s × %s × %s = %s', $this->n($chajjaLength), $this->n($chajjaWidth), $this->n($chajjaThickness), Dec::qty($rccChajja)),
        ]);

        // Steel: kilograms per cubic metre of each element, added up and shown in tonnes.
        $kg = BigDecimal::zero();
        $working = [__('concrete of each element × kg per cum, added up, ÷ 1000')];

        foreach ([
            ['AB-RCC-FTG', $rccFootings, 'steel_footing'],
            ['AB-RCC-COL', $rccColumns, 'steel_column'],
            ['AB-RCC-BM', $rccBeams, 'steel_beam'],
            ['AB-RCC-SLB', $rccSlabs, 'steel_slab'],
            ['AB-RCC-STR', $rccStairs, 'steel_stair'],
            ['AB-RCC-CHJ', $rccChajja, 'steel_chajja'],
        ] as [$code, $volume, $key]) {
            $part = $volume->multipliedBy($as($key));
            $kg = $kg->plus($part);
            $working[] = sprintf('%s: %s × %s = %s kg', __(self::LINES[$code][0]), Dec::qty($volume), $this->n($as($key)), Dec::qty($part));
        }

        $tonnes = $kg->dividedBy(1000, 6, RoundingMode::HalfUp);
        $working[] = sprintf('%s kg ÷ 1000 = %s MT', Dec::qty($kg), Dec::qty($tonnes));
        $add('AB-STL', $tonnes, $working);

        // Centering and shuttering.
        $centeringFootings = BigDecimal::zero();
        $working = [__('nos × 2 × (length + breadth) × depth')];

        foreach ($footings as $f) {
            $area = $f['nos']->multipliedBy($two)->multipliedBy($f['length']->plus($f['breadth']))->multipliedBy($f['depth']);
            $centeringFootings = $centeringFootings->plus($area);
            $working[] = sprintf('%s: %s × 2 × (%s + %s) × %s = %s', $f['label'], $this->n($f['nos']), $this->n($f['length']), $this->n($f['breadth']), $this->n($f['depth']), Dec::qty($area));
        }

        $add('AB-CEN-FTG', $centeringFootings, $working, $footings === [] ? __('No footing groups entered.') : null);
        $add('AB-CEN-COL', $centeringColumns, $colCentering, $columns === [] ? __('No column groups entered.') : null);
        $add('AB-CEN-BM', $centeringBeams, $beamCentering, $beams === [] ? __('No beam groups entered.') : null);
        $add('AB-CEN-SLB', $slabArea, $slabWorking, $slabs === [] ? __('No slabs entered.') : null);

        $stairCentering = $as('stair_centering');
        $centeringStairs = $floors->multipliedBy($stairArea)->multipliedBy($stairCentering);
        $add('AB-CEN-STR', $centeringStairs, [
            __('floors × plan area × centering per square metre'),
            sprintf('%s × %s × %s = %s', $this->n($floors), $this->n($stairArea), $this->n($stairCentering), Dec::qty($centeringStairs)),
        ], $stairArea->isZero() ? __('Staircase plan area is 0.') : null);

        // Masonry: wall area less openings, times thickness, repeated over the floors it spans.
        $masonry = BigDecimal::zero();
        $externalFace = BigDecimal::zero();
        $internalFace = BigDecimal::zero();
        $working = [__('(length × height − openings) × thickness × floors')];
        $notes = [];

        foreach ($walls as $w) {
            $gross = $w['length']->multipliedBy($w['height']);
            $net = $gross->minus($w['openings']);

            if ($net->isNegative()) {
                $net = BigDecimal::zero();
                $notes[] = __('Openings are larger than the wall in :label, so that group counts as 0.', ['label' => $w['label']]);
            }

            $volume = $net->multipliedBy($w['thickness'])->multipliedBy($w['floors']);
            $masonry = $masonry->plus($volume);
            $working[] = sprintf('%s: (%s × %s − %s) × %s × %s = %s', $w['label'], $this->n($w['length']), $this->n($w['height']), $this->n($w['openings']), $this->n($w['thickness']), $this->n($w['floors']), Dec::qty($volume));

            $face = $net->multipliedBy($w['floors']);

            if (($w['kind'] ?? 'external') === 'internal') {
                $internalFace = $internalFace->plus($face);
            } else {
                $externalFace = $externalFace->plus($face);
            }
        }

        $add('AB-BRK', $masonry, $working, $walls === [] ? __('No wall groups entered.') : ($notes === [] ? null : implode(' ', $notes)));

        // Plaster: the outer face of external walls outside; their inner face and both faces of internal walls inside; ceilings under every slab.
        $internalPlaster = $externalFace->plus($internalFace->multipliedBy($two));
        $add('AB-PL-INT', $internalPlaster, [
            __('inner face of external walls + both faces of internal walls'),
            sprintf('%s + 2 × %s = %s', Dec::qty($externalFace), Dec::qty($internalFace), Dec::qty($internalPlaster)),
        ], $walls === [] ? __('No wall groups entered.') : null);
        $add('AB-PL-EXT', $externalFace, [
            __('outer face of external walls, openings deducted'),
            sprintf('%s = %s', Dec::qty($externalFace), Dec::qty($externalFace)),
        ], $walls === [] ? __('No wall groups entered.') : null);
        $add('AB-PL-CLG', $slabArea, [
            __('soffit of every slab, openings deducted'),
            sprintf('%s = %s', Dec::qty($slabArea), Dec::qty($slabArea)),
        ], $slabs === [] ? __('No slabs entered.') : null);

        // Flooring: the ground floor sits on the plinth, each upper floor on the slab below it. The top slab is the terrace.
        $carpet = $as('carpet_share');
        $upperFloors = $slabArea->minus($topSlab);
        $upperFloors = $upperFloors->isNegative() ? BigDecimal::zero() : $upperFloors;
        $flooring = $carpet->multipliedBy($plinthArea->plus($upperFloors));
        $add('AB-FLR', $flooring, [
            __('carpet share × (plinth area + slab area under the upper floors)'),
            sprintf('%s × (%s + %s) = %s', $this->n($carpet), $this->n($plinthArea), Dec::qty($upperFloors), Dec::qty($flooring)),
        ], $slabs === [] ? __('With no slabs entered, only the ground floor is counted.') : null);

        // Painting follows the plaster, and the terrace is the top slab.
        $add('AB-PNT-INT', $internalPlaster->plus($slabArea), [
            __('internal plaster + ceiling plaster'),
            sprintf('%s + %s = %s', Dec::qty($internalPlaster), Dec::qty($slabArea), Dec::qty($internalPlaster->plus($slabArea))),
        ]);
        $add('AB-PNT-EXT', $externalFace, [__('same as the external plaster'), sprintf('%s = %s', Dec::qty($externalFace), Dec::qty($externalFace))]);
        $add('AB-WP', $topSlab, [__('the top slab, openings deducted'), sprintf('%s = %s', Dec::qty($topSlab), Dec::qty($topSlab))], $slabs === [] ? __('No slabs entered.') : null);

        // Finishing and MEP that need their own drawings.
        foreach (self::MANUAL_NOTES as $code => $note) {
            [$description, $unit, $category, $group] = self::LINES[$code];
            $lines[$code] = new AutoBoqLine($code, __($description), $unit, BigDecimal::zero(), $category, $group, [], __($note).' '.__('Enter the quantity by hand.'), false);
        }

        return $lines;
    }

    /**
     * The rows of one element group, every figure a Dec. A blank count of floors is one floor; a
     * blank height is the floor height. Rows with every figure blank are skipped.
     *
     * @param  list<string>  $fields
     * @return list<array<string, mixed>>
     */
    private function groups(array $d, string $group, array $fields, BigDecimal $floorHeight): array
    {
        $rows = [];

        foreach (array_values((array) ($d[$group] ?? [])) as $index => $row) {
            if (! is_array($row)) {
                continue;
            }

            $clean = ['label' => filled($row['label'] ?? null) ? trim((string) $row['label']) : __('Group :n', ['n' => $index + 1])];
            $blank = true;

            foreach ($fields as $field) {
                $value = $row[$field] ?? null;
                $blank = $blank && ($value === null || $value === '');
                $clean[$field] = $this->dec($value);

                if (in_array($field, ['floors', 'count'], true) && $clean[$field]->isZero()) {
                    $clean[$field] = BigDecimal::one();
                }

                if ($field === 'height' && $clean[$field]->isZero()) {
                    $clean[$field] = $floorHeight;
                }
            }

            if ($blank) {
                continue;
            }

            if ($group === 'walls') {
                $clean['kind'] = ($row['kind'] ?? 'external') === 'internal' ? 'internal' : 'external';
            }

            $rows[] = $clean;
        }

        return $rows;
    }

    private function dec(mixed $value): BigDecimal
    {
        $value = is_string($value) ? trim($value) : $value;

        if ($value === null || $value === '' || ! is_numeric($value)) {
            return BigDecimal::zero();
        }

        $dec = Dec::of($value);

        return $dec->isNegative() ? BigDecimal::zero() : $dec;
    }

    /** A figure as it reads in the working: up to three decimals, trailing zeros dropped. */
    private function n(BigDecimal $value): string
    {
        $fixed = (string) $value->toScale(3, RoundingMode::HalfUp);

        return str_contains($fixed, '.') ? rtrim(rtrim($fixed, '0'), '.') : $fixed;
    }
}
