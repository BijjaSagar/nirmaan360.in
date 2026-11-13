<?php

namespace App\Services\Procurement;

use App\Models\BoqItem;
use App\Models\BoqMaterialNorm;
use App\Models\Material;
use App\Models\Project;
use App\Models\StockLedgerEntry;
use App\Models\StockLevel;
use App\Support\Dec;
use Brick\Math\RoundingMode;
use Illuminate\Support\Collection;

/** Reorder alerts, and material actually used against what the BOQ work should have used. */
class ReconciliationService
{
    public const BASES = ['measured' => 'measured_qty', 'approved' => 'approved_qty', 'dpr' => 'executed_qty'];

    public function __construct(private ProcurementAlerts $alerts) {}

    /**
     * One row per BOQ item and material with a norm, plus any issued without one.
     * "Actual" is the net of issues, returns and reversals tagged to the BOQ item.
     *
     * Worked example: RCC M25, 50 cum measured, norm 8.4 bags/cum, 2% allowed wastage.
     * Theoretical = 420 bags, allowed = 428.4. Issuing 450 is 7.14% over: a wastage alert.
     *
     * @return list<array<string, mixed>>
     */
    public function consumption(Project $project, string $basis = 'measured'): array
    {
        $norms = BoqMaterialNorm::with('material.baseUnit')->where('project_id', $project->id)->get();
        $actuals = $this->actuals($project);

        $boqIds = $norms->pluck('boq_item_id')
            ->merge($actuals->keys()->map(fn (string $key) => (int) explode(':', $key)[0]))
            ->unique();
        $boq = BoqItem::withTrashed()->withLedger()->whereIn('id', $boqIds)->get()->keyBy('id');

        $rows = $norms->map(function (BoqMaterialNorm $norm) use ($boq, $actuals, $basis) {
            $item = $boq->get($norm->boq_item_id);

            return $this->row($item, $norm->material, $norm, $this->executed($item, $basis), $actuals->get($norm->boq_item_id.':'.$norm->material_id, '0'));
        })->all();

        $withNorm = $norms->map(fn (BoqMaterialNorm $norm) => $norm->boq_item_id.':'.$norm->material_id)->flip();
        $unplanned = $actuals->reject(fn ($qty, $key) => $withNorm->has($key));
        $materials = Material::with('baseUnit')->whereIn('id', $unplanned->keys()->map(fn (string $key) => (int) explode(':', $key)[1]))->get()->keyBy('id');

        foreach ($unplanned as $key => $actual) {
            [$boqId, $materialId] = array_map('intval', explode(':', $key));
            $rows[] = $this->row($boq->get($boqId), $materials->get($materialId), null, $this->executed($boq->get($boqId), $basis), $actual);
        }

        usort($rows, fn ($a, $b) => [$a['boq_code'], $a['material_name']] <=> [$b['boq_code'], $b['material_name']]);

        return $rows;
    }

    /**
     * Material issued to an activity with no BOQ item, so it cannot be reconciled by norm.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function unallocated(Project $project): Collection
    {
        $net = StockLedgerEntry::where('project_id', $project->id)
            ->whereNull('boq_item_id')
            ->whereNotNull('activity')
            ->selectRaw('material_id, activity, SUM(qty) AS net')
            ->groupBy('material_id', 'activity')
            ->get();

        $materials = Material::with('baseUnit')->whereIn('id', $net->pluck('material_id'))->get()->keyBy('id');

        return $net->map(fn ($row) => [
            'material' => $materials->get($row->material_id),
            'activity' => $row->activity,
            'actual' => Dec::qty(Dec::of($row->net)->negated()),
        ])->filter(fn ($row) => Dec::of($row['actual'])->isPositive())->values();
    }

    /**
     * Store and material pairs that are under their reorder level.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function belowReorder(Project $project, Collection $balances): Collection
    {
        return StockLevel::with('store', 'material.baseUnit')
            ->whereNotNull('reorder_level')
            ->whereHas('store', fn ($q) => $q->where('project_id', $project->id))
            ->get()
            ->map(function (StockLevel $level) use ($balances) {
                $balance = $balances->get($level->store_id.':'.$level->material_id, Dec::qty(0));

                return [
                    'store' => $level->store,
                    'material' => $level->material,
                    'balance' => $balance,
                    'reorder_level' => $level->reorder_level,
                    'shortfall' => Dec::qty(Dec::of($level->reorder_level)->minus(Dec::of($balance))),
                ];
            })
            ->filter(fn ($row) => Dec::of($row['shortfall'])->isPositive())
            ->values();
    }

    /**
     * Alert only when this issue is what pushed a BOQ item over its allowed wastage,
     * so the same overrun is not repeated on every later issue.
     *
     * @param  list<StockLedgerEntry>  $rows
     */
    public function alertOnIssue(Project $project, array $rows): void
    {
        $issued = [];

        foreach ($rows as $row) {
            if ($row->boq_item_id !== null) {
                $key = $row->boq_item_id.':'.$row->material_id;
                $issued[$key] = Dec::of($issued[$key] ?? 0)->minus(Dec::of($row->qty));
            }
        }

        if ($issued === []) {
            return;
        }

        $actuals = $this->actuals($project);

        foreach ($issued as $key => $qty) {
            [$boqId, $materialId] = array_map('intval', explode(':', $key));
            $norm = BoqMaterialNorm::with('material.baseUnit')->where('boq_item_id', $boqId)->where('material_id', $materialId)->first();

            if (! $norm) {
                continue;
            }

            $item = BoqItem::withLedger()->findOrFail($boqId);
            $executed = $this->executed($item, 'measured');
            $after = $actuals->get($key, '0');
            $before = Dec::qty(Dec::of($after)->minus($qty));
            $afterRow = $this->row($item, $norm->material, $norm, $executed, $after);

            if ($afterRow['status'] === 'wastage' && $this->row($item, $norm->material, $norm, $executed, $before)['status'] !== 'wastage') {
                $this->alerts->wastage($project->organization_id, $project->id, $item->code, $norm->material, $afterRow['variance_pct'], $norm->wastage_pct);
            }
        }
    }

    /** @return Collection<string, string> Net consumption keyed "boqItem:material". */
    private function actuals(Project $project): Collection
    {
        return StockLedgerEntry::where('project_id', $project->id)
            ->whereNotNull('boq_item_id')
            ->selectRaw('boq_item_id, material_id, SUM(qty) AS net')
            ->groupBy('boq_item_id', 'material_id')
            ->get()
            ->mapWithKeys(fn ($row) => [$row->boq_item_id.':'.$row->material_id => Dec::qty(Dec::of($row->net)->negated())]);
    }

    private function executed(BoqItem $item, string $basis): string
    {
        return Dec::qty($item->{self::BASES[$basis] ?? self::BASES['measured']} ?? 0);
    }

    /** @return array<string, mixed> */
    private function row(BoqItem $item, Material $material, ?BoqMaterialNorm $norm, string $executed, string $actual): array
    {
        $base = [
            'boq_item_id' => $item->id,
            'boq_code' => (string) $item->code,
            'boq_description' => $item->description,
            'boq_unit' => $item->unit,
            'executed' => $executed,
            'material_id' => $material->id,
            'material_name' => $material->name,
            'unit' => $material->baseUnit->code,
            'actual' => Dec::qty($actual),
            'norm' => $norm?->qty_per_unit,
            'wastage_pct' => $norm?->wastage_pct,
        ];

        if (! $norm) {
            return [...$base, 'theoretical' => null, 'allowed' => null, 'variance' => null, 'variance_pct' => null, 'status' => 'no_norm'];
        }

        $theoretical = WastageAllowance::theoretical($executed, $norm->qty_per_unit);
        $allowed = WastageAllowance::allowed($theoretical, $norm->wastage_pct);
        $used = Dec::of($actual);
        $variance = $used->minus($theoretical);

        return [
            ...$base,
            'theoretical' => Dec::qty($theoretical),
            'allowed' => Dec::qty($allowed),
            'variance' => Dec::qty($variance),
            'variance_pct' => $theoretical->isZero() ? null : Dec::money($variance->multipliedBy(100)->dividedBy($theoretical, 6, RoundingMode::HalfUp)),
            'status' => WastageAllowance::status($theoretical, $allowed, $used),
        ];
    }
}
