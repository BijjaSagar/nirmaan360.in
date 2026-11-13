<?php

namespace App\Services\MaterialControl;

use App\Models\BoqItem;
use App\Models\BoqMaterialNorm;
use App\Models\DeliveryChallan;
use App\Models\Material;
use App\Models\MaterialExtraWork;
use App\Models\MaterialFlagReason;
use App\Models\MaterialShortDelivery;
use App\Models\MaterialStockCheck;
use App\Models\Project;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\StockLedgerEntry;
use App\Models\User;
use App\Services\Procurement\ProcurementRules;
use App\Services\Procurement\StockLedgerService;
use App\Services\Procurement\WastageAllowance;
use App\Services\SiteControl\WorkProgress;
use App\Support\ClientUuidRepeat;
use App\Support\Dec;
use App\Support\Idempotent;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The three-way check: BOQ estimate for the work done, against received, against used.
 *
 * Section 13 formulas, all in the material's base unit:
 *   Material variance    = used - BOQ for completed work
 *   Unaccounted material = received - used - stock on site
 *   Wastage %            = (used - BOQ) / BOQ * 100
 *
 * Material recorded against extra work is taken out of "used" for wastage, so it is not
 * counted as waste. "Over used" is raised only beyond what the norms allow: the theoretical
 * quantity plus each norm's allowed wastage %, worked out by Procurement\WastageAllowance, the
 * same calculation as the reconciliation page and its wastage alert. A material with no norm, or
 * no work done against one, has no allowance, so any use above the BOQ is flagged as before.
 * Stock on site is the ledger balance, which a physical count corrects.
 * A flag is never stored: it is worked out from these figures, and only the written reason
 * is kept. A reason covers the gap as it stood, so if the gap grows a new one is needed.
 */
class MaterialCheckService
{
    public const OVER_USED = 'over_used';

    public const UNDER_USED = 'under_used';

    public const UNACCOUNTED = 'unaccounted';

    public const OVER_RECEIVED = 'over_received';

    public const SHORT_DELIVERY = 'short_delivery';

    public const FLAGS = [self::OVER_USED, self::UNDER_USED, self::UNACCOUNTED, self::OVER_RECEIVED, self::SHORT_DELIVERY];

    /** Movements that brought material onto the project, net of what went back. */
    private const RECEIVED = [
        StockLedgerService::OPENING,
        StockLedgerService::GRN,
        StockLedgerService::RETURN_OUT,
        StockLedgerService::TRANSFER_IN,
        StockLedgerService::TRANSFER_OUT,
    ];

    private const USED = [StockLedgerService::ISSUE, StockLedgerService::RETURN_IN];

    public function __construct(private WorkProgress $progress) {}

    /**
     * @return array{rows: list<array<string, mixed>>, open_flags: int, wastage_pct: ?string, wastage_basis: ?string, left_out: int}
     */
    public function check(Project $project, bool $withMoney = false): array
    {
        [$estimate, $fullNeed, $allowance] = $this->boqNeeds($project);
        $movements = $this->movements($project);
        $extra = $this->extraWork($project);
        $short = $this->shorts($project);
        $counted = MaterialStockCheck::where('project_id', $project->id)->where('status', 'POSTED')->selectRaw('material_id, MAX(check_date) AS last_count')->groupBy('material_id')->pluck('last_count', 'material_id');
        $reasons = MaterialFlagReason::with('creator')->where('project_id', $project->id)->orderBy('id')->get()->keyBy(fn (MaterialFlagReason $reason) => $reason->material_id.':'.$reason->flag_type);

        $ids = collect(array_keys($estimate))->merge(array_keys($fullNeed))->merge(array_keys($movements))->merge(array_keys($extra))->merge(array_keys($short))->unique();
        $materials = Material::with('baseUnit')->whereIn('id', $ids)->get()->keyBy('id');
        $rates = $this->rates($project);

        $rows = [];

        foreach ($ids as $id) {
            if ($material = $materials->get($id)) {
                $rows[] = $this->row($material, $estimate[$id] ?? null, $fullNeed[$id] ?? null, $allowance[$id] ?? null, $movements[$id] ?? null, $extra[$id] ?? null, $short[$id] ?? null, $counted[$id] ?? null, $reasons, $rates[$id] ?? null, $withMoney);
            }
        }

        usort($rows, fn ($a, $b) => [$b['open_flag_count'], $a['material']] <=> [$a['open_flag_count'], $b['material']]);

        $openFlags = array_sum(array_column($rows, 'open_flag_count'));
        ['pct' => $wastagePct, 'basis' => $basis, 'left_out' => $leftOut] = $this->projectWastage($rows, $rates);

        return ['rows' => $rows, 'open_flags' => $openFlags, 'wastage_pct' => $wastagePct, 'wastage_basis' => $basis, 'left_out' => $leftOut];
    }

    public function reasonRules(): array
    {
        return [
            'material_id' => ['bail', 'required', 'integer', org_exists('materials')],
            'flag_type' => ['required', Rule::in(self::FLAGS)],
            'reason' => ['required', 'string', 'max:2000'],
            'client_uuid' => ProcurementRules::clientUuid(),
        ];
    }

    /** Write down why a flagged gap exists. */
    public function addReason(Project $project, array $data, User $by): MaterialFlagReason
    {
        $uuid = $data['client_uuid'] ?? null;

        if ($uuid && ($existing = $this->findReason($project->organization_id, $uuid))) {
            return $existing;
        }

        $row = collect($this->check($project)['rows'])->firstWhere('material_id', (int) $data['material_id']);
        $flag = $row ? collect($row['flags'])->firstWhere('type', $data['flag_type']) : null;

        if (! $flag) {
            throw ValidationException::withMessages(['flag_type' => __('This gap has since closed, so no reason is needed.')]);
        }

        return Idempotent::create(
            fn () => MaterialFlagReason::create([
                'organization_id' => $project->organization_id,
                'project_id' => $project->id,
                'material_id' => $row['material_id'],
                'flag_type' => $flag['type'],
                'gap_qty' => $flag['gap'],
                'reason' => $data['reason'],
                'client_uuid' => $uuid,
                'created_by' => $by->id,
            ]),
            fn () => $this->findReason($project->organization_id, (string) $uuid),
        );
    }

    /**
     * BOQ need for work done, for the whole BOQ, and the wastage the norms allow on the work done.
     *
     * @return array{0: array<int, BigDecimal>, 1: array<int, BigDecimal>, 2: array<int, BigDecimal>}
     */
    private function boqNeeds(Project $project): array
    {
        $norms = BoqMaterialNorm::where('project_id', $project->id)->get(['boq_item_id', 'material_id', 'qty_per_unit', 'wastage_pct']);
        $done = $this->progress->doneByItem($project);
        $items = BoqItem::where('project_id', $project->id)->whereIn('id', $norms->pluck('boq_item_id'))->get(['id', 'qty'])->keyBy('id');
        $estimate = [];
        $fullNeed = [];
        $allowance = [];

        foreach ($norms as $norm) {
            $id = $norm->material_id;
            $perUnit = Dec::of($norm->qty_per_unit);
            $completed = Dec::of($done[$norm->boq_item_id] ?? 0);
            $item = $items->get($norm->boq_item_id);

            if ($completed->isPositive()) {
                $estimate[$id] = ($estimate[$id] ?? BigDecimal::zero())->plus($completed->multipliedBy($perUnit));
                $theoretical = WastageAllowance::theoretical($completed, $perUnit);
                $allowance[$id] = ($allowance[$id] ?? BigDecimal::zero())->plus(WastageAllowance::allowance($theoretical, $norm->wastage_pct));
            }

            if ($item) {
                $fullNeed[$id] = ($fullNeed[$id] ?? BigDecimal::zero())->plus(Dec::of($item->qty)->multipliedBy($perUnit));
            }
        }

        return [$estimate, $fullNeed, $allowance];
    }

    /**
     * Net movements per material. A reversal counts as the kind of movement it undoes.
     *
     * @return array<int, array{received: BigDecimal, used: BigDecimal, stock: BigDecimal}>
     */
    private function movements(Project $project): array
    {
        $rows = StockLedgerEntry::query()
            ->leftJoin('stock_ledger as reversed', 'reversed.id', '=', 'stock_ledger.reverses_id')
            ->where('stock_ledger.project_id', $project->id)
            ->selectRaw('stock_ledger.material_id AS material_id, COALESCE(reversed.movement_type, stock_ledger.movement_type) AS kind, SUM(stock_ledger.qty) AS net')
            ->groupByRaw('stock_ledger.material_id, COALESCE(reversed.movement_type, stock_ledger.movement_type)')
            ->toBase()
            ->get();

        $totals = [];

        foreach ($rows as $row) {
            $net = Dec::of(Dec::qty($row->net));
            $total = $totals[$row->material_id] ?? ['received' => BigDecimal::zero(), 'used' => BigDecimal::zero(), 'stock' => BigDecimal::zero()];

            $total['stock'] = $total['stock']->plus($net);

            if (in_array($row->kind, self::RECEIVED, true)) {
                $total['received'] = $total['received']->plus($net);
            } elseif (in_array($row->kind, self::USED, true)) {
                $total['used'] = $total['used']->minus($net);
            }

            $totals[$row->material_id] = $total;
        }

        return $totals;
    }

    /** @return array<int, BigDecimal> Extra-work quantity per material, leaving out reversed issues. */
    private function extraWork(Project $project): array
    {
        return MaterialExtraWork::where('project_id', $project->id)
            ->where('status', 'POSTED')
            ->where(fn ($query) => $query->whereNull('material_issue_id')->orWhereHas('issue', fn ($issue) => $issue->where('status', 'POSTED')))
            ->selectRaw('material_id, SUM(base_qty) AS qty')
            ->groupBy('material_id')
            ->pluck('qty', 'material_id')
            ->map(fn ($qty) => Dec::of(Dec::qty($qty)))
            ->all();
    }

    /** @return array<int, BigDecimal> */
    private function shorts(Project $project): array
    {
        return MaterialShortDelivery::where('project_id', $project->id)
            ->where('status', 'POSTED')
            ->selectRaw('material_id, SUM(short_base_qty) AS qty')
            ->groupBy('material_id')
            ->pluck('qty', 'material_id')
            ->map(fn ($qty) => Dec::of(Dec::qty($qty)))
            ->all();
    }

    /**
     * The latest known cost of one base unit. Only used to weight the project figure and for money.view.
     *
     * @return array<int, BigDecimal>
     */
    private function rates(Project $project): array
    {
        $rates = [];

        DeliveryChallan::where('project_id', $project->id)
            ->where('status', 'POSTED')
            ->whereNotNull('bill_amount')
            ->orderBy('id')
            ->get(['material_id', 'bill_amount', 'base_qty'])
            ->each(function (DeliveryChallan $challan) use (&$rates) {
                $rates[$challan->material_id] = Dec::of($challan->bill_amount)->dividedBy(Dec::of($challan->base_qty), 6, RoundingMode::HalfUp);
            });

        // A purchase order rate is firmer than a one-off bill, so it wins.
        PurchaseOrderItem::whereIn('purchase_order_id', PurchaseOrder::where('project_id', $project->id)->where('status', '!=', 'DRAFT')->select('id'))
            ->where('base_qty', '>', 0)
            ->orderBy('id')
            ->get(['material_id', 'amount', 'base_qty'])
            ->each(function (PurchaseOrderItem $line) use (&$rates) {
                $rates[$line->material_id] = Dec::of($line->amount)->dividedBy(Dec::of($line->base_qty), 6, RoundingMode::HalfUp);
            });

        return $rates;
    }

    /** @return array<string, mixed> */
    private function row(Material $material, ?BigDecimal $estimate, ?BigDecimal $fullNeed, ?BigDecimal $allowance, ?array $movement, ?BigDecimal $extra, ?BigDecimal $short, ?string $lastCount, $reasons, ?BigDecimal $rate, bool $withMoney): array
    {
        $estimate = Dec::of(Dec::qty($estimate ?? 0));
        $received = $movement['received'] ?? BigDecimal::zero();
        $used = $movement['used'] ?? BigDecimal::zero();
        $stock = $movement['stock'] ?? BigDecimal::zero();
        $extraUsed = ($extra ?? BigDecimal::zero());
        $extraCapped = $extraUsed->isGreaterThan($used) ? $used : $extraUsed;
        $wasteUsed = $used->minus($extraCapped);
        $variance = $used->minus($estimate);
        $waste = $wasteUsed->minus($estimate);
        $unaccounted = $received->minus($used)->minus($stock);
        $hasEstimate = $estimate->isPositive();
        $wastagePct = $hasEstimate ? $this->progress->percent($waste, $estimate) : null;

        $flags = $this->flags($material, $estimate, $fullNeed, $received, $used, $waste, $allowance ?? BigDecimal::zero(), $unaccounted, $short ?? BigDecimal::zero(), $reasons);

        $row = [
            'material_id' => $material->id,
            'material' => $material->name,
            'unit' => $material->baseUnit->code,
            'estimate' => Dec::qty($estimate),
            'full_need' => $fullNeed ? Dec::qty($fullNeed) : null,
            'received' => Dec::qty($received),
            'used' => Dec::qty($used),
            'extra_work' => Dec::qty($extraCapped),
            'should_be_in_stock' => Dec::qty($received->minus($used)),
            'stock_on_site' => Dec::qty($stock),
            'last_counted' => $lastCount ? substr($lastCount, 0, 10) : null,
            'variance' => Dec::qty($variance),
            'unaccounted' => Dec::qty($unaccounted),
            'wastage_pct' => $wastagePct,
            'short_delivered' => Dec::qty($short ?? 0),
            'flags' => $flags,
            'open_flag_count' => count(array_filter($flags, fn ($flag) => $flag['open'])),
        ];

        if ($withMoney) {
            $row['rate'] = $rate ? Dec::money($rate) : null;
            $row['wastage_value'] = ($rate && $hasEstimate) ? Dec::money($waste->multipliedBy($rate)) : null;
        }

        return $row;
    }

    /** @return list<array<string, mixed>> */
    private function flags(Material $material, BigDecimal $estimate, ?BigDecimal $fullNeed, BigDecimal $received, BigDecimal $used, BigDecimal $waste, BigDecimal $allowance, BigDecimal $unaccounted, BigDecimal $short, $reasons): array
    {
        $flags = [];
        $unit = $material->baseUnit->code;
        $words = fn (BigDecimal $qty) => ['gap' => $this->words($qty->abs()), 'unit' => $unit];

        // Use within the norms' allowed wastage is neither over nor under: the allowance is the line, as on
        // the reconciliation page. The gap still reads against the BOQ, which is what the message says.
        if (WastageAllowance::exceeds($waste, $allowance)) {
            $flags[] = $this->flag(self::OVER_USED, $material, $waste, $estimate->isZero()
                ? __(':gap :unit used with no BOQ estimate for the work done. Where is it going, and why?', $words($waste))
                : __('Used :gap :unit more than the BOQ for the work done. Where is it going, and why?', $words($waste)), $reasons);
        } elseif ($waste->isNegative()) {
            $flags[] = $this->flag(self::UNDER_USED, $material, $waste, __('Used :gap :unit less than the BOQ for the work done. Is some use not entered, or did the work need less?', $words($waste)), $reasons);
        }

        if ($unaccounted->isPositive()) {
            $flags[] = $this->flag(self::UNACCOUNTED, $material, $unaccounted, __(':gap :unit unaccounted: received, but neither used nor in stock. Where did it go?', $words($unaccounted)), $reasons);
        } elseif ($unaccounted->isNegative()) {
            $flags[] = $this->flag(self::UNACCOUNTED, $material, $unaccounted, __(':gap :unit more on site than the records show. Is a challan missing?', $words($unaccounted)), $reasons);
        }

        // Anything already used is answered by the over-use flag, so only the unused excess counts.
        $ceiling = $fullNeed && $used->isGreaterThan($fullNeed) ? $used : $fullNeed;

        if ($ceiling && $ceiling->isPositive() && $received->isGreaterThan($ceiling)) {
            $over = $received->minus($ceiling);
            $flags[] = $this->flag(self::OVER_RECEIVED, $material, $over, __('Received :gap :unit more than the whole BOQ needs. Why?', $words($over)), $reasons);
        }

        if ($short->isPositive()) {
            $flags[] = $this->flag(self::SHORT_DELIVERY, $material, $short, __('Delivered :gap :unit short of the challan. What does the supplier say?', $words($short)), $reasons);
        }

        return $flags;
    }

    /** @return array<string, mixed> */
    private function flag(string $type, Material $material, BigDecimal $gap, string $message, $reasons): array
    {
        $reason = $reasons->get($material->id.':'.$type);
        $covered = $reason !== null && $this->covers(Dec::of($reason->gap_qty), $gap);

        return [
            'type' => $type,
            'gap' => Dec::qty($gap),
            'message' => $message,
            'open' => ! $covered,
            'reason' => $covered ? $reason->reason : null,
            'reason_by' => $covered ? $reason->creator?->name : null,
            'reason_at' => $covered ? $reason->created_at?->toDateString() : null,
        ];
    }

    private function covers(BigDecimal $explained, BigDecimal $gap): bool
    {
        return $explained->isPositive() === $gap->isPositive() && $gap->abs()->isLessThanOrEqualTo($explained->abs());
    }

    /**
     * Project wastage, (used - BOQ) / BOQ. Quantities in bags and tons cannot be added up, so
     * the two sides are valued at each material's known rate. With no rate known at all, it
     * falls back to quantities, which is exact for a one-material project.
     *
     * @param  array<int, BigDecimal>  $rates
     * @return array{pct: ?string, basis: ?string, left_out: int}
     */
    private function projectWastage(array $rows, array $rates): array
    {
        $withEstimate = array_filter($rows, fn ($row) => Dec::of($row['estimate'])->isPositive());

        if ($withEstimate === []) {
            return ['pct' => null, 'basis' => null, 'left_out' => 0];
        }

        $priced = array_filter($withEstimate, fn ($row) => isset($rates[$row['material_id']]));

        $basis = $priced === [] ? 'quantity' : 'value';
        $total = BigDecimal::zero();
        $over = BigDecimal::zero();

        foreach ($priced === [] ? $withEstimate : $priced as $row) {
            $weight = $basis === 'value' ? $rates[$row['material_id']] : BigDecimal::one();
            $estimate = Dec::of($row['estimate'])->multipliedBy($weight);
            $used = Dec::of($row['used'])->minus(Dec::of($row['extra_work']))->multipliedBy($weight);
            $total = $total->plus($estimate);
            $over = $over->plus($used->minus($estimate));
        }

        return [
            'pct' => $this->progress->percent($over, $total),
            'basis' => $basis,
            'left_out' => $basis === 'value' ? count($withEstimate) - count($priced) : 0,
        ];
    }

    private function words(BigDecimal $qty): string
    {
        return preg_replace('/\.?0+$/', '', Dec::indian($qty, 3));
    }

    private function findReason(int $organizationId, string $uuid): ?MaterialFlagReason
    {
        return ClientUuidRepeat::reachable(MaterialFlagReason::withoutGlobalScopes()->where('organization_id', $organizationId)->where('client_uuid', $uuid)->first());
    }
}
