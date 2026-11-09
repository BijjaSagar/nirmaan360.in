<?php

namespace App\Services\PriceWatch;

use App\Models\Material;
use App\Models\MaterialUnitConversion;
use App\Models\PriceWatchMaterial;
use App\Models\PriceWatchProjectRate;
use App\Models\Project;
use App\Models\User;
use App\Services\MaterialControl\MaterialCheckService;
use App\Services\ProjectSetup\BoqBudget;
use App\Support\Dec;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Section 10 for one project: each watched material's BOQ rate against today's price, and
 * what the difference does to the budget. The water tank quoted at ₹6 a litre.
 *
 *   impact = (current rate − BOQ rate) × quantity still to buy
 *   quantity still to buy = BOQ need − received, never below zero
 *
 * The BOQ need comes from the material norms over the whole BOQ and received from the deliveries,
 * both as material_control's three-way check works them out, converted into the unit the price
 * is quoted per. Where the BOQ has no norm for a material, the quantity is typed in. A material
 * with no price, no BOQ rate or no quantity has no impact, and says which it is missing.
 *
 * Every figure here is money, so the screens that show it need money.overview.
 */
class ProjectPriceImpact
{
    public const NO_PRICE = 'no_price';

    public const NO_BOQ_RATE = 'no_boq_rate';

    public const NO_QTY = 'no_qty';

    public const UNIT_MISMATCH = 'unit_mismatch';

    public function __construct(
        private PriceBoard $board,
        private MaterialCheckService $check,
        private BoqBudget $budget,
        private PriceWatchSettings $settings,
    ) {}

    public function rateRules(): array
    {
        return [
            'rates' => ['nullable', 'array'],
            'rates.*' => ['nullable', 'numeric', 'min:0', 'max:9999999999999', 'decimal:0,2'],
            'qtys' => ['nullable', 'array'],
            'qtys.*' => ['nullable', 'numeric', 'min:0', 'max:99999999999', 'decimal:0,3'],
        ];
    }

    /**
     * One row per active watched material.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function rows(Project $project, ?CarbonInterface $today = null): Collection
    {
        $today ??= today();
        $board = $this->board->rows($project->organization_id, $today);
        $rates = PriceWatchProjectRate::withoutGlobalScopes()->where('project_id', $project->id)->get()->keyBy('price_watch_material_id');
        $linked = $board->pluck('material_id')->filter()->unique()->values();
        $check = $linked->isEmpty() ? collect() : collect($this->check->check($project)['rows'])->keyBy('material_id');
        $factors = $this->factors($board->pluck('material')->filter(fn (PriceWatchMaterial $material) => $material->material_id !== null));

        return $board->map(fn (array $row) => $this->row($row, $rates->get($row['id']), $check->get($row['material_id']), $factors[$row['id']] ?? null))->values();
    }

    /**
     * The totals: impact over every material with one, the same over fresh prices only, and what
     * that is as a share of the approved budget, or of the BOQ value when no budget is set.
     *
     * @return array<string, mixed>
     */
    public function summary(Project $project, ?Collection $rows = null): array
    {
        $rows ??= $this->rows($project);
        $impact = BigDecimal::zero();
        $fresh = BigDecimal::zero();

        foreach ($rows as $row) {
            if ($row['impact'] === null) {
                continue;
            }

            $impact = $impact->plus(Dec::of($row['impact']));

            if (! $row['stale']) {
                $fresh = $fresh->plus(Dec::of($row['impact']));
            }
        }

        $budget = $this->budget->summary($project);
        $basis = $budget['budget'] !== null ? 'budget' : (Dec::of($budget['boq'])->isPositive() ? 'boq' : null);
        $base = match ($basis) {
            'budget' => Dec::of($budget['budget']),
            'boq' => Dec::of($budget['boq']),
            default => null,
        };
        $rise = Dec::of($this->settings->risePct());

        return [
            'impact' => Dec::money($impact),
            'fresh_impact' => Dec::money($fresh),
            'impact_pct' => $base === null ? null : $this->percent($impact, $base),
            'fresh_impact_pct' => $base === null ? null : $this->percent($fresh, $base),
            'basis' => $basis,
            'budget' => $budget['budget'],
            'ceiling' => $budget['ceiling'],
            'margin_pct' => $budget['margin_pct'],
            'boq' => $budget['boq'],
            // The BOQ with the rest of the material bought at today's prices.
            'projected' => Dec::money(Dec::of($budget['boq'])->plus($impact)),
            'beyond_margin' => $budget['ceiling'] !== null && Dec::of($budget['boq'])->plus($fresh)->isGreaterThan(Dec::of($budget['ceiling'])),
            'materials' => $rows->count(),
            'priced' => $rows->whereNotNull('current')->count(),
            'with_boq_rate' => $rows->whereNotNull('boq_rate')->count(),
            'with_impact' => $rows->whereNotNull('impact')->count(),
            'stale' => $rows->filter(fn (array $row) => $row['current'] !== null && $row['stale'])->count(),
            'rising' => $rows->filter(fn (array $row) => $row['above_boq_pct'] !== null && Dec::of($row['above_boq_pct'])->isGreaterThanOrEqualTo($rise))->count(),
            'rise_pct' => $this->settings->risePct(),
            'impact_alert_pct' => $this->settings->impactPct(),
            'stale_days' => $this->settings->staleDays(),
        ];
    }

    /**
     * Save the BOQ rate, and the typed quantity, for each material. Blank clears. Returns how many changed.
     *
     * @param  array<int|string, mixed>  $rates  Watched material id => BOQ rate
     * @param  array<int|string, mixed>  $qtys  Watched material id => BOQ quantity in the price unit
     */
    public function saveRates(Project $project, array $rates, array $qtys, User $by): int
    {
        $ids = array_values(array_unique(array_map('intval', [...array_keys($rates), ...array_keys($qtys)])));
        $own = PriceWatchMaterial::withoutGlobalScopes()->where('organization_id', $project->organization_id)->whereIn('id', $ids)->pluck('id')->all();

        if (array_diff($ids, $own) !== []) {
            throw ValidationException::withMessages(['rates' => __('One of these materials is not on your board.')]);
        }

        $existing = PriceWatchProjectRate::withoutGlobalScopes()->where('project_id', $project->id)->get()->keyBy('price_watch_material_id');
        $changed = 0;

        foreach ($ids as $id) {
            $rate = filled($rates[$id] ?? null) ? Dec::money($rates[$id]) : null;
            $qty = filled($qtys[$id] ?? null) ? Dec::qty($qtys[$id]) : null;
            $row = $existing->get($id);

            if ($row === null) {
                if ($rate === null && $qty === null) {
                    continue;
                }

                PriceWatchProjectRate::create([
                    'organization_id' => $project->organization_id,
                    'project_id' => $project->id,
                    'price_watch_material_id' => $id,
                    'boq_rate' => $rate,
                    'boq_qty' => $qty,
                    'noted_by' => $by->id,
                ]);
                $changed++;

                continue;
            }

            $current = [$row->boq_rate === null ? null : Dec::money($row->boq_rate), $row->boq_qty === null ? null : Dec::qty($row->boq_qty)];

            if ($current === [$rate, $qty]) {
                continue;
            }

            $row->update(['boq_rate' => $rate, 'boq_qty' => $qty, 'noted_by' => $by->id]);
            $changed++;
        }

        return $changed;
    }

    /**
     * Base units per price unit for each linked material: 1 when the price is per the catalogue's
     * base unit, the conversion factor when one is known (1 bag = 50 kg), null when neither.
     *
     * @param  Collection<int, PriceWatchMaterial>  $linked
     * @return array<int, ?string> Watched material id => factor
     */
    private function factors(Collection $linked): array
    {
        if ($linked->isEmpty()) {
            return [];
        }

        $catalogue = Material::withoutGlobalScopes()->with('baseUnit')->whereIn('id', $linked->pluck('material_id'))->get()->keyBy('id');
        $conversions = MaterialUnitConversion::withoutGlobalScopes()->with('unit')->whereIn('material_id', $linked->pluck('material_id'))->get()->groupBy('material_id');
        $factors = [];

        foreach ($linked as $watched) {
            $material = $catalogue->get($watched->material_id);
            $factor = null;

            if ($material?->baseUnit && strcasecmp($material->baseUnit->code, $watched->unit) === 0) {
                $factor = '1';
            } elseif ($material) {
                $conversion = $conversions->get($material->id, collect())->first(fn (MaterialUnitConversion $conversion) => $conversion->unit && strcasecmp($conversion->unit->code, $watched->unit) === 0);
                $factor = $conversion && Dec::of($conversion->factor)->isPositive() ? (string) $conversion->factor : null;
            }

            $factors[$watched->id] = $factor;
        }

        return $factors;
    }

    /** @return array<string, mixed> */
    private function row(array $board, ?PriceWatchProjectRate $rate, ?array $check, ?string $factor): array
    {
        $current = $board['current'];
        $linked = $board['material_id'] !== null;
        $boqRate = $rate?->boq_rate === null ? null : Dec::money($rate->boq_rate);
        $boqQty = $rate?->boq_qty === null ? null : Dec::qty($rate->boq_qty);
        $normNeed = $check['full_need'] ?? null;
        $unitKnown = $factor !== null;

        $need = null;
        $basis = null;
        $received = '0.000';

        if ($linked && $normNeed !== null && $unitKnown) {
            $need = $this->inPriceUnit($normNeed, $factor);
            $basis = 'norms';
        }

        if ($need === null && $boqQty !== null) {
            $need = $boqQty;
            $basis = 'typed';
        }

        if ($linked && $check !== null && $unitKnown) {
            $received = $this->inPriceUnit($check['received'], $factor);
        }

        $remaining = null;

        if ($need !== null) {
            $left = Dec::of($need)->minus(Dec::of($received));
            $remaining = Dec::qty($left->isNegative() ? BigDecimal::zero() : $left);
        }

        $difference = $current !== null && $boqRate !== null ? Dec::of($current['rate'])->minus(Dec::of($boqRate)) : null;
        $abovePct = $difference !== null && Dec::of($boqRate)->isPositive() ? $this->percent($difference, Dec::of($boqRate)) : null;
        $impact = $difference !== null && $remaining !== null ? Dec::money($difference->multipliedBy(Dec::of($remaining))) : null;

        $why = match (true) {
            $current === null => self::NO_PRICE,
            $boqRate === null => self::NO_BOQ_RATE,
            $remaining === null => $linked && $normNeed !== null && ! $unitKnown ? self::UNIT_MISMATCH : self::NO_QTY,
            default => null,
        };

        return [
            ...$board,
            'boq_rate' => $boqRate,
            'boq_qty' => $boqQty,
            'difference' => $difference === null ? null : Dec::money($difference),
            'above_boq_pct' => $abovePct,
            'need' => $need,
            'need_basis' => $basis,
            'received' => $received,
            'remaining' => $remaining,
            'impact' => $impact,
            'stale' => $current['stale'] ?? false,
            'why' => $why,
        ];
    }

    private function inPriceUnit(mixed $baseQty, string $factor): string
    {
        return Dec::qty(Dec::of($baseQty)->dividedBy(Dec::of($factor), 3, RoundingMode::HalfUp));
    }

    private function percent(BigDecimal $part, BigDecimal $whole): ?string
    {
        if ($whole->isZero()) {
            return null;
        }

        return Dec::money($part->multipliedBy(100)->dividedBy($whole, 6, RoundingMode::HalfUp));
    }
}
