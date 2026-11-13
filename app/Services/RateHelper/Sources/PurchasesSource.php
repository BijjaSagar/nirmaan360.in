<?php

namespace App\Services\RateHelper\Sources;

use App\Models\BoqItem;
use App\Models\BoqMaterialNorm;
use App\Models\DeliveryChallan;
use App\Models\Organization;
use App\Models\PurchaseOrder;
use App\Models\User;
use App\Services\RateHelper\Contracts\BatchedRateSource;
use App\Services\RateHelper\RateCandidate;
use App\Services\RateHelper\RateFigure;
use App\Support\Dec;
use App\Support\Money;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;

/**
 * What the materials for one unit of a BOQ line cost at the company's own latest purchase prices.
 * It exists only for a line that has material norms: for each norm, the quantity one unit of work
 * needs, plus wastage, times the newest price paid per base unit. A purchase order gives the price
 * before GST; a challan bill gives it as the bill was entered.
 *
 * This is the material part of a rate and nothing more, so it is shown for reference and can never
 * be accepted as the rate. When a material has no purchase the figure says it is incomplete.
 *
 * Purchase orders and challans carry a project_id, so only purchases of projects the person can
 * reach are read, and each kind only by someone whose role may open it.
 */
class PurchasesSource implements BatchedRateSource
{
    public const KEY = 'purchases';

    /** A purchase order counts once it has been issued to the supplier. */
    private const PLACED = ['ISSUED', 'PARTIAL', 'RECEIVED', 'CLOSED'];

    /** @var array<int, array{rate:BigDecimal,date:CarbonInterface,document:string,basis:string}|null> Material id => its newest purchase. */
    private array $latest = [];

    /** @var array<int, RateFigure|null> BOQ line id => its figure. */
    private array $figures = [];

    /** @var array<int, BoqItem|null> BOQ lines read ahead for a screen that shows many. */
    private array $items = [];

    /** @var array<int, Collection<int|string, Collection<int, BoqMaterialNorm>>> Project id => its norms by BOQ line. */
    private array $norms = [];

    public function key(): string
    {
        return self::KEY;
    }

    public function label(): string
    {
        return 'Your purchases, for reference only';
    }

    public function isConnected(Organization $organization): bool
    {
        return $organization->allowsFeature('procurement') || $organization->allowsFeature('site_control');
    }

    public function allows(User $viewer): bool
    {
        return $this->readsOrders($viewer) || $this->readsChallans($viewer);
    }

    /** At most one: the line's own material cost, which is not matched by description and so has no score. */
    public function candidates(User $viewer, BoqItem $item): iterable
    {
        // The line was just read as this person by whoever asks, so it is not read again (one query per line on a long run).
        if ($this->answers($viewer) && ! array_key_exists((int) $item->id, $this->items)) {
            $this->items[(int) $item->id] = $item;
        }

        if ($this->rateOf($viewer, (int) $item->id) !== null) {
            yield new RateCandidate(self::KEY, (int) $item->id);
        }
    }

    public function prime(User $viewer, array $rowIds): void
    {
        if (! $this->answers($viewer)) {
            return;
        }

        $missing = array_values(array_diff(array_unique(array_map('intval', $rowIds)), array_keys($this->items)));

        if ($missing === []) {
            return;
        }

        $this->items += array_fill_keys($missing, null);

        foreach (BoqItem::query()->whereKey($missing)->get() as $item) {
            $this->items[(int) $item->id] = $item;
        }
    }

    /** $rowId is the BOQ line itself. Null when it has no norms, or no purchase of any of its materials can be read. */
    public function rateOf(User $viewer, int $rowId): ?RateFigure
    {
        if (! $this->answers($viewer)) {
            return null;
        }

        if (! array_key_exists($rowId, $this->figures)) {
            $this->figures[$rowId] = $this->figure($viewer, $rowId);
        }

        return $this->figures[$rowId];
    }

    private function figure(User $viewer, int $itemId): ?RateFigure
    {
        $this->prime($viewer, [$itemId]);
        $item = $this->items[$itemId] ?? null;

        if ($item === null) {
            return null;
        }

        // One read per project, however many of its lines are asked about.
        $this->norms[(int) $item->project_id] ??= BoqMaterialNorm::query()
            ->where('project_id', $item->project_id)
            ->with('material.baseUnit')
            ->orderBy('id')
            ->get()
            ->groupBy('boq_item_id');

        $norms = $this->norms[(int) $item->project_id]->get($item->id, collect());
        $total = BigDecimal::zero();
        $parts = [];
        $dates = [];
        $incomplete = false;

        foreach ($norms as $norm) {
            $material = $norm->material;
            $purchase = $material === null ? null : $this->latestPurchase($viewer, (int) $material->id);

            if ($material === null || $purchase === null) {
                $incomplete = true;
                $parts[] = ['label' => $material?->name ?? __('A material that was removed'), 'amount' => null, 'note' => __('No purchase of this material was found.')];

                continue;
            }

            $needed = Dec::of($norm->qty_per_unit)->multipliedBy(
                Dec::of($norm->wastage_pct)->dividedBy(100, 6, RoundingMode::HalfUp)->plus(1)
            );
            $amount = Dec::money($needed->multipliedBy($purchase['rate']));
            $total = $total->plus(Dec::of($amount));
            $dates[] = $purchase['date'];
            $unit = (string) ($material->baseUnit?->code ?? '');

            $parts[] = [
                'label' => $material->name,
                'amount' => $amount,
                'note' => __(':qty :unit for one :work, plus :wastage% wastage, at ₹:rate per :unit. :document, :date, :basis.', [
                    'qty' => (string) Dec::of($norm->qty_per_unit)->strippedOfTrailingZeros(),
                    'unit' => $unit,
                    'work' => $item->unit,
                    'wastage' => (string) Dec::of($norm->wastage_pct)->strippedOfTrailingZeros(),
                    'rate' => self::price($purchase['rate']),
                    'document' => $purchase['document'],
                    'date' => $purchase['date']->format('d M Y'),
                    'basis' => $purchase['basis'],
                ]),
            ];
        }

        if ($dates === []) {
            return null;
        }

        usort($dates, fn (CarbonInterface $a, CarbonInterface $b) => $a <=> $b);

        return new RateFigure(
            source: self::KEY,
            rowId: (int) $item->id,
            description: $item->description,
            unit: $item->unit,
            rate: Dec::money($total),
            date: $dates[0],
            dateLabel: 'oldest purchase used',
            basis: 'material cost only, from your purchases',
            acceptable: false,
            incomplete: $incomplete,
            parts: $parts,
        );
    }

    /**
     * A price per base unit, written with every decimal it was worked out to (at least two), so the
     * quantity and price in the note multiply out to the amount beside it. 7.235 stays 7.235, not 7.24.
     */
    private static function price(BigDecimal $rate): string
    {
        return Dec::indian($rate, max(2, $rate->strippedOfTrailingZeros()->getScale()));
    }

    /**
     * The newest price paid for a material, per base unit, from the purchases this person can reach.
     *
     * @return array{rate:BigDecimal,date:CarbonInterface,document:string,basis:string}|null
     */
    private function latestPurchase(User $viewer, int $materialId): ?array
    {
        if (array_key_exists($materialId, $this->latest)) {
            return $this->latest[$materialId];
        }

        $found = array_filter([
            $this->readsOrders($viewer) ? $this->fromOrders($materialId) : null,
            $this->readsChallans($viewer) ? $this->fromChallans($materialId) : null,
        ]);

        // Newest first. On the same day the purchase order wins, because its price is before GST.
        usort($found, fn (array $a, array $b) => $b['date'] <=> $a['date']);

        return $this->latest[$materialId] = $found[0] ?? null;
    }

    /** @return array{rate:BigDecimal,date:CarbonInterface,document:string,basis:string}|null */
    private function fromOrders(int $materialId): ?array
    {
        $priced = fn ($lines) => $lines->where('material_id', $materialId)->where('base_qty', '>', 0)->where('amount', '>', 0);

        $order = PurchaseOrder::query()
            ->whereIn('status', self::PLACED)
            ->whereHas('items', $priced)
            ->orderByDesc('po_date')
            ->orderByDesc('id')
            ->first();

        $line = $order === null ? null : $priced($order->items()->reorder()->orderByDesc('id'))->first();

        if ($line === null) {
            return null;
        }

        return [
            'rate' => Dec::of($line->amount)->dividedBy(Dec::of($line->base_qty), 6, RoundingMode::HalfUp),
            'date' => $order->po_date,
            'document' => __('Purchase order :number', ['number' => $order->number]),
            'basis' => __('before GST'),
        ];
    }

    /** @return array{rate:BigDecimal,date:CarbonInterface,document:string,basis:string}|null */
    private function fromChallans(int $materialId): ?array
    {
        $challan = DeliveryChallan::query()
            ->where('material_id', $materialId)
            ->where('status', 'POSTED')
            ->where('bill_amount', '>', 0)
            ->where('base_qty', '>', 0)
            ->orderByRaw('coalesce(bill_date, challan_date) desc')
            ->orderByDesc('id')
            ->first();

        if ($challan === null) {
            return null;
        }

        return [
            'rate' => Dec::of($challan->bill_amount)->dividedBy(Dec::of($challan->base_qty), 6, RoundingMode::HalfUp),
            'date' => $challan->bill_date ?? $challan->challan_date,
            'document' => __('Challan bill :number', ['number' => $challan->bill_no ?: $challan->number]),
            'basis' => __('as entered on the bill'),
        ];
    }

    private function answers(User $viewer): bool
    {
        return Auth::id() !== null
            && (int) Auth::id() === (int) $viewer->getKey()
            && Money::visible($viewer)
            && $viewer->organization !== null
            && $this->isConnected($viewer->organization)
            && $this->allows($viewer);
    }

    private function readsOrders(User $viewer): bool
    {
        return $viewer->hasPermission('procurement.view') && (bool) $viewer->organization?->allowsFeature('procurement');
    }

    private function readsChallans(User $viewer): bool
    {
        return $viewer->hasPermission('material_control.view') && (bool) $viewer->organization?->allowsFeature('site_control');
    }
}
