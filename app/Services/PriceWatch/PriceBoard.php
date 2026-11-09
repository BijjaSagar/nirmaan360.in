<?php

namespace App\Services\PriceWatch;

use App\Models\MaterialPrice;
use App\Models\PriceWatchMaterial;
use App\Support\Dec;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * The company price board: each watched material with its current price, the one before it,
 * the change between them and a short trend. Every price is shown with its date and source,
 * and a price older than the stale limit is marked out of date.
 *
 * Reads filter by organisation themselves, so the alert scanner can call this with nobody signed in.
 */
class PriceBoard
{
    /** Prices kept for the trend line. */
    public const TREND_POINTS = 8;

    public function __construct(private PriceWatchSettings $settings) {}

    /**
     * Puts the default materials on a company's board. A material the company already watches,
     * by key or by name, is left exactly as it is, so this can run every time the board opens
     * and still only ever add each default once. Returns how many were added.
     */
    public function seedDefaults(int $organizationId): int
    {
        $existing = PriceWatchMaterial::withoutGlobalScopes()->where('organization_id', $organizationId)->get();
        $sort = (int) $existing->max('sort');
        $added = 0;

        foreach (config('modules.price_watch.defaults', []) as $default) {
            if ($existing->firstWhere('key', $default['key'])) {
                continue;
            }

            $sameName = $existing->first(fn (PriceWatchMaterial $material) => $material->key === null && mb_strtolower($material->name) === mb_strtolower($default['name']));

            if ($sameName) {
                // The company's own row keeps its name and unit; it only gains the key the shared list looks for.
                $sameName->update(['key' => $default['key']]);

                continue;
            }

            $existing->push(PriceWatchMaterial::create([
                'organization_id' => $organizationId,
                'key' => $default['key'],
                'name' => $default['name'],
                'unit' => $default['unit'],
                'is_active' => true,
                'sort' => $sort += 10,
            ]));
            $added++;
        }

        return $added;
    }

    /**
     * One row per watched material, active ones first in board order.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function rows(int $organizationId, ?CarbonInterface $today = null, bool $activeOnly = true): Collection
    {
        $today ??= today();

        $materials = PriceWatchMaterial::withoutGlobalScopes()
            ->where('organization_id', $organizationId)
            ->when($activeOnly, fn ($query) => $query->where('is_active', true))
            ->orderByDesc('is_active')->orderBy('sort')->orderBy('name')->orderBy('id')
            ->get();

        $prices = MaterialPrice::withoutGlobalScopes()
            ->whereIn('price_watch_material_id', $materials->pluck('id'))
            ->orderByDesc('priced_on')->orderByDesc('id')
            ->get()
            ->groupBy('price_watch_material_id');

        return $materials->map(fn (PriceWatchMaterial $material) => $this->row($material, $prices->get($material->id, collect()), $today))->values();
    }

    /** The board row for one material, with its whole dated list. @return array<string, mixed> */
    public function history(PriceWatchMaterial $material, ?CarbonInterface $today = null): array
    {
        $today ??= today();
        $prices = $material->prices()->with('notedBy')->get();

        return [...$this->row($material, $prices, $today), 'prices' => $prices->map(fn (MaterialPrice $price) => [...$this->price($price, $today), 'noted_by' => $price->notedBy?->name])->values()->all()];
    }

    /** A price as the screens and the API show it. @return array<string, mixed>|null */
    public function price(?MaterialPrice $price, CarbonInterface $today): ?array
    {
        if ($price === null) {
            return null;
        }

        return [
            'id' => $price->id,
            'rate' => Dec::money($price->rate),
            'unit' => $price->unit,
            'priced_on' => $price->priced_on->toDateString(),
            'source' => $price->source,
            'source_kind' => $price->source_kind,
            'stale' => $this->isStale($price->priced_on, $today),
        ];
    }

    /** Out of date: older than the stale limit, counted in whole days. */
    public function isStale(CarbonInterface $pricedOn, CarbonInterface $today): bool
    {
        return $pricedOn->copy()->startOfDay()->addDays($this->settings->staleDays())->lt($today->copy()->startOfDay());
    }

    /**
     * Points for a small SVG trend line, 72 by 20, oldest price on the left. Display only.
     * Null with fewer than two prices.
     *
     * @param  list<array{rate: string}>  $trend
     */
    public static function sparkline(array $trend): ?string
    {
        $rates = array_map(fn (array $point) => Dec::of($point['rate']), $trend);
        $count = count($rates);

        if ($count < 2) {
            return null;
        }

        $min = array_reduce($rates, fn (?BigDecimal $min, BigDecimal $rate) => $min === null || $rate->isLessThan($min) ? $rate : $min);
        $max = array_reduce($rates, fn (?BigDecimal $max, BigDecimal $rate) => $max === null || $rate->isGreaterThan($max) ? $rate : $max);
        $span = $max->minus($min);
        $step = BigDecimal::of(68)->dividedBy($count - 1, 4, RoundingMode::HalfUp);
        $points = [];

        foreach ($rates as $index => $rate) {
            $x = BigDecimal::of(2)->plus($step->multipliedBy($index));
            $y = $span->isZero()
                ? BigDecimal::of(10)
                : BigDecimal::of(18)->minus($rate->minus($min)->multipliedBy(16)->dividedBy($span, 4, RoundingMode::HalfUp));
            $points[] = $x->toScale(1, RoundingMode::HalfUp).','.$y->toScale(1, RoundingMode::HalfUp);
        }

        return implode(' ', $points);
    }

    /** @param  Collection<int, MaterialPrice>  $prices  Newest first. */
    private function row(PriceWatchMaterial $material, Collection $prices, CarbonInterface $today): array
    {
        $current = $prices->first();
        $previous = $prices->skip(1)->first();
        $change = null;

        if ($current && $previous && Dec::of($previous->rate)->isPositive()) {
            $change = Dec::money(Dec::of($current->rate)->minus(Dec::of($previous->rate))->multipliedBy(100)->dividedBy(Dec::of($previous->rate), 6, RoundingMode::HalfUp));
        }

        return [
            'material' => $material,
            'id' => $material->id,
            'key' => $material->key,
            'name' => $material->label(),
            'unit' => $material->unit,
            'material_id' => $material->material_id,
            'is_active' => $material->is_active,
            'current' => $this->price($current, $today),
            'previous' => $this->price($previous, $today),
            'change_pct' => $change,
            'trend' => $prices->take(self::TREND_POINTS)->reverse()->values()
                ->map(fn (MaterialPrice $price) => ['rate' => Dec::money($price->rate), 'priced_on' => $price->priced_on->toDateString()])->all(),
            'count' => $prices->count(),
        ];
    }
}
